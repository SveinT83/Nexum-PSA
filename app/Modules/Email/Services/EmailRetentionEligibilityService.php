<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EmailRetentionEligibilityService
{
    public const REASON_NOT_EXPIRED = 'not_expired';

    public const REASON_ACTIVE_PROVIDER_PLACEMENT = 'active_provider_placement';

    public const REASON_UNRESOLVED_PROVIDER_PLACEMENT = 'unresolved_provider_placement';

    public const REASON_REMOTE_OPERATION = 'remote_operation_unresolved';

    public const REASON_TICKET_EVIDENCE = 'ticket_evidence';

    public const REASON_LEGAL_HOLD = 'legal_hold';

    public const REASON_RECONCILIATION = 'reconciliation_or_ambiguity_unresolved';

    public const REASON_NOTIFICATION_FANOUT = 'inbound_notification_fanout_nonterminal';

    public const REASON_UNSUPPORTED_STORAGE = 'unsupported_attachment_storage';

    public const REASON_PROJECTION_UNAVAILABLE = 'protection_projection_unavailable';

    public const REASON_CANONICAL_CUTOVER = 'canonical_projection_or_cutover_audit';

    /** @var array<string, bool> */
    private array $tableCache = [];

    /** @var array<string, bool> */
    private array $columnCache = [];

    public function __construct(
        private readonly InboundEmailNotificationFanoutReadiness $fanoutReadiness,
    ) {}

    public function cutoff(int $months): CarbonImmutable
    {
        return CarbonImmutable::instance(now())
            ->subMonthsNoOverflow(max(1, $months));
    }

    /**
     * Build a non-mutating retention preview from the same checks used by the purge job.
     *
     * @return array{
     *     retention_months: int,
     *     cutoff_at: CarbonImmutable,
     *     expired_count: int,
     *     eligible_count: int,
     *     protected_count: int,
     *     reason_counts: array<string, int>,
     *     reason_breakdown: array<int, array{code: string, label: string, count: int}>,
     *     generated_at: CarbonImmutable
     * }
     */
    public function preview(int $months): array
    {
        $months = max(1, $months);
        $cutoff = $this->cutoff($months);
        $expiredCount = 0;
        $eligibleCount = 0;
        $protectedCount = 0;
        $reasonCounts = [];

        EmailMessage::query()
            ->withTrashed()
            ->where('received_at', '<', $cutoff)
            ->with([
                'attachments:id,message_id,disk,path',
                'placements:id,email_message_id,email_conversation_id,local_state,sync_status,provider_deleted,provider_missing_at',
            ])
            ->chunkById(100, function (EloquentCollection $messages) use (
                $cutoff,
                &$expiredCount,
                &$eligibleCount,
                &$protectedCount,
                &$reasonCounts,
            ): void {
                foreach ($messages as $message) {
                    $expiredCount++;
                    $assessment = $this->assess($message, $cutoff);

                    if ($assessment['eligible']) {
                        $eligibleCount++;

                        continue;
                    }

                    $protectedCount++;

                    foreach ($assessment['reasons'] as $reason) {
                        $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                    }
                }
            });

        ksort($reasonCounts);

        $reasonBreakdown = [];

        foreach ($reasonCounts as $code => $count) {
            $reasonBreakdown[] = [
                'code' => $code,
                'label' => $this->reasonLabel($code),
                'count' => $count,
            ];
        }

        return [
            'retention_months' => $months,
            'cutoff_at' => $cutoff,
            'expired_count' => $expiredCount,
            'eligible_count' => $eligibleCount,
            'protected_count' => $protectedCount,
            'reason_counts' => $reasonCounts,
            'reason_breakdown' => $reasonBreakdown,
            'generated_at' => CarbonImmutable::instance(now()),
        ];
    }

    /**
     * @return array{eligible: bool, reasons: array<int, string>}
     */
    public function assess(EmailMessage $message, CarbonInterface $cutoff): array
    {
        $reasons = [];
        $receivedAt = $message->received_at;

        if (! $receivedAt || ! $receivedAt->lt($cutoff)) {
            $reasons[] = self::REASON_NOT_EXPIRED;
        }

        if (! $this->hasTable('email_mailbox_placements')) {
            // Without the provider projection, absence of a live provider occurrence cannot be proven.
            $reasons[] = self::REASON_PROJECTION_UNAVAILABLE;
            $placements = collect();
        } else {
            $placements = $message->relationLoaded('placements')
                ? $message->placements
                : $message->placements()->get([
                    'id',
                    'email_message_id',
                    'email_conversation_id',
                    'local_state',
                    'sync_status',
                    'provider_deleted',
                    'provider_missing_at',
                ]);

            if ($placements->contains(
                fn (EmailMailboxPlacement $placement): bool => $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
                    && ! $placement->provider_deleted,
            )) {
                $reasons[] = self::REASON_ACTIVE_PROVIDER_PLACEMENT;
            }

            if ($placements->contains(
                fn (EmailMailboxPlacement $placement): bool => $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE
                    || $placement->provider_deleted,
            )) {
                // A deleted/hidden flag is not proof that provider disappearance was safely reconciled.
                $reasons[] = self::REASON_UNRESOLVED_PROVIDER_PLACEMENT;
            }
        }

        $placementIds = $placements->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $conversationIds = $placements
            ->pluck('email_conversation_id')
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($this->hasUnresolvedRemoteOperation($placementIds)) {
            $reasons[] = self::REASON_REMOTE_OPERATION;
        }

        if ($this->hasTicketEvidence($message)) {
            $reasons[] = self::REASON_TICKET_EVIDENCE;
        }

        if ($this->hasLegalHold($message)) {
            $reasons[] = self::REASON_LEGAL_HOLD;
        }

        if ($this->hasUnresolvedReconciliation($message, $placements, $conversationIds)) {
            $reasons[] = self::REASON_RECONCILIATION;
        }

        if ($this->hasNonterminalInboundNotificationEvidence((int) $message->id)) {
            $reasons[] = self::REASON_NOTIFICATION_FANOUT;
        }

        if ($this->hasCanonicalCutoverProtection($message)) {
            // Canonical projections and their durable preview/apply audit intentionally retain
            // source foreign keys. Physical canonical-aware deletion belongs to a later reviewed
            // migration, so retention must report protection before deleting any private files.
            $reasons[] = self::REASON_CANONICAL_CUTOVER;
        }

        $attachments = $message->relationLoaded('attachments')
            ? $message->attachments
            : $message->attachments()->get(['id', 'message_id', 'disk', 'path']);

        if ($attachments->contains(fn ($attachment): bool => (string) $attachment->disk !== 'local')) {
            // This slice knows how to remove local cache files only. Unknown stores stay protected.
            $reasons[] = self::REASON_UNSUPPORTED_STORAGE;
        }

        $reasons = array_values(array_unique($reasons));

        return [
            'eligible' => $reasons === [],
            'reasons' => $reasons,
        ];
    }

    public function reasonLabel(string $reason): string
    {
        return match ($reason) {
            self::REASON_NOT_EXPIRED => 'Not past the retention cutoff',
            self::REASON_ACTIVE_PROVIDER_PLACEMENT => 'Active provider placement',
            self::REASON_UNRESOLVED_PROVIDER_PLACEMENT => 'Provider placement not safely reconciled for purge',
            self::REASON_REMOTE_OPERATION => 'Pending, failed, or ambiguous provider operation',
            self::REASON_TICKET_EVIDENCE => 'Ticket link or captured Ticket evidence',
            self::REASON_LEGAL_HOLD => 'Explicit legal hold',
            self::REASON_RECONCILIATION => 'Unresolved reconciliation or ambiguity review',
            self::REASON_NOTIFICATION_FANOUT => 'Inbound notification recipient fanout is incomplete',
            self::REASON_UNSUPPORTED_STORAGE => 'Attachment uses unsupported storage',
            self::REASON_PROJECTION_UNAVAILABLE => 'Required protection projection is unavailable',
            self::REASON_CANONICAL_CUTOVER => 'Canonical projection or cutover audit retains the source',
            default => str($reason)->replace('_', ' ')->title()->toString(),
        };
    }

    private function hasCanonicalCutoverProtection(EmailMessage $message): bool
    {
        if ($this->hasTable('email_canonical_message_sources')
            && $this->hasColumn('email_canonical_message_sources', 'source_email_message_id')
            && DB::table('email_canonical_message_sources')
                ->where('source_email_message_id', $message->id)
                ->exists()) {
            return true;
        }

        if ($this->hasTable('email_canonical_messages')
            && $this->hasColumn('email_canonical_messages', 'root_source_email_message_id')
            && DB::table('email_canonical_messages')
                ->where('root_source_email_message_id', $message->id)
                ->exists()) {
            return true;
        }

        if ($this->hasTable('email_canonical_cutover_items')) {
            $sourceColumn = $this->hasColumn('email_canonical_cutover_items', 'source_email_message_id');
            $rootColumn = $this->hasColumn('email_canonical_cutover_items', 'proposed_root_source_message_id');
            if (($sourceColumn || $rootColumn)
                && DB::table('email_canonical_cutover_items')
                    ->where(function ($query) use ($message, $sourceColumn, $rootColumn): void {
                        if ($sourceColumn) {
                            $query->where('source_email_message_id', $message->id);
                        }
                        if ($rootColumn) {
                            $sourceColumn
                                ? $query->orWhere('proposed_root_source_message_id', $message->id)
                                : $query->where('proposed_root_source_message_id', $message->id);
                        }
                    })
                    ->exists()) {
                return true;
            }
        }

        return $this->hasTable('email_canonical_read_modes')
            && $this->hasColumn('email_canonical_read_modes', 'email_account_id')
            && $this->hasColumn('email_canonical_read_modes', 'mode')
            && DB::table('email_canonical_read_modes')
                ->where('email_account_id', $message->account_id)
                ->where('mode', '!=', 'legacy')
                ->exists();
    }

    /**
     * @param  array<int, int>  $placementIds
     */
    private function hasUnresolvedRemoteOperation(array $placementIds): bool
    {
        if ($placementIds === [] || ! $this->hasTable('email_remote_operations')) {
            return false;
        }

        return DB::table('email_remote_operations')
            ->whereIn('email_mailbox_placement_id', $placementIds)
            ->whereIn('status', ['pending', 'running', 'failed', 'ambiguous'])
            ->exists();
    }

    private function hasTicketEvidence(EmailMessage $message): bool
    {
        if ($message->ticket_id !== null) {
            return true;
        }

        if (! $this->fanoutReadiness->ready()) {
            // Before the indexed pointer and its bounded repair are sealed,
            // retention must protect the source without scanning legacy JSON.
            return true;
        }

        if ($this->hasTable('email_ticket_conversation_links')
            && DB::table('email_ticket_conversation_links')->where('email_message_id', $message->id)->exists()) {
            return true;
        }

        $repairReady = DB::table('notification_inbound_ticket_message_repairs')
            ->where('id', 1)
            ->where('status', 'completed')
            ->exists();
        if (! $repairReady) {
            return true;
        }

        if (DB::table('ticket_messages')
            ->where('source_inbound_email_message_id', (int) $message->id)
            ->exists()) {
            return true;
        }

        return false;
    }

    /** Protect both an unfinished recipient page and its unfinished external outbox. */
    private function hasNonterminalInboundNotificationEvidence(int $messageId): bool
    {
        if (! $this->hasTable('notification_inbound_email_fanouts')) {
            return false;
        }

        $hasExternalAuthority = $this->hasTable('notification_inbound_external_deliveries')
            && $this->hasColumn(
                'notification_inbound_external_deliveries',
                'inbound_notification_fanout_id',
            );

        return DB::table('notification_inbound_email_fanouts as fanouts')
            ->where('fanouts.source_email_message_id', $messageId)
            ->where(function ($query) use ($hasExternalAuthority): void {
                $query->whereIn('fanouts.status', ['pending', 'running']);
                if (! $hasExternalAuthority) {
                    return;
                }

                $query->orWhereExists(function ($external): void {
                    $external->selectRaw('1')
                        ->from('notification_inbound_external_deliveries as external')
                        ->whereColumn(
                            'external.inbound_notification_fanout_id',
                            'fanouts.id',
                        )
                        ->whereIn('external.status', ['pending', 'running']);
                });
            })
            ->exists();
    }

    private function hasLegalHold(EmailMessage $message): bool
    {
        foreach (['is_legal_hold', 'legal_hold_at', 'legal_hold_until', 'retention_hold_at', 'retention_hold_until'] as $column) {
            if (! $this->hasColumn('email_messages', $column)) {
                continue;
            }

            $value = $message->getAttribute($column);

            if ($value !== null && $value !== false && $value !== 0 && $value !== '0' && $value !== '') {
                return true;
            }
        }

        foreach (['email_legal_holds', 'email_retention_holds'] as $table) {
            if (! $this->hasTable($table) || ! $this->hasColumn($table, 'email_message_id')) {
                continue;
            }

            $query = DB::table($table)->where('email_message_id', $message->id);

            if ($this->hasColumn($table, 'released_at')) {
                $query->whereNull('released_at');
            }

            if ($this->hasColumn($table, 'status')) {
                $query->whereNotIn('status', ['released', 'cancelled', 'expired']);
            }

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, EmailMailboxPlacement>  $placements
     * @param  array<int, int>  $conversationIds
     */
    private function hasUnresolvedReconciliation(
        EmailMessage $message,
        $placements,
        array $conversationIds,
    ): bool {
        if ($placements->contains(
            fn (EmailMailboxPlacement $placement): bool => $placement->sync_status !== EmailMailboxPlacement::SYNC_SYNCED
                || $placement->provider_missing_at !== null,
        )) {
            return true;
        }

        if ($this->hasTable('email_sent_reconciliations')
            && DB::table('email_sent_reconciliations')
                ->where(function ($query) use ($message): void {
                    $query
                        ->where('source_email_message_id', $message->id)
                        ->orWhere('sent_email_message_id', $message->id);
                })
                ->whereNotIn('status', ['reconciled', 'appended'])
                ->exists()) {
            return true;
        }

        foreach ([
            'email_conversation_correlation_issues',
            'email_conversation_classification_migration_issues',
        ] as $table) {
            if ($this->hasOpenIssue($table, (int) $message->id, $conversationIds)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, int>  $conversationIds
     */
    private function hasOpenIssue(string $table, int $messageId, array $conversationIds): bool
    {
        if (! $this->hasTable($table)) {
            return false;
        }

        $messageColumns = array_values(array_filter(
            ['email_message_id', 'source_email_message_id', 'target_email_message_id'],
            fn (string $column): bool => $this->hasColumn($table, $column),
        ));
        $conversationColumns = array_values(array_filter(
            ['email_conversation_id', 'source_email_conversation_id', 'target_email_conversation_id'],
            fn (string $column): bool => $this->hasColumn($table, $column),
        ));

        if ($messageColumns === [] && ($conversationColumns === [] || $conversationIds === [])) {
            return false;
        }

        $query = DB::table($table);

        if ($this->hasColumn($table, 'status')) {
            $query->whereNotIn('status', ['resolved', 'dismissed', 'closed']);
        } elseif ($this->hasColumn($table, 'resolved_at')) {
            $query->whereNull('resolved_at');
        }

        return $query
            ->where(function ($query) use ($messageColumns, $messageId, $conversationColumns, $conversationIds): void {
                foreach ($messageColumns as $column) {
                    $query->orWhere($column, $messageId);
                }

                foreach ($conversationColumns as $column) {
                    $query->orWhereIn($column, $conversationIds);
                }
            })
            ->exists();
    }

    private function hasTable(string $table): bool
    {
        return $this->tableCache[$table] ??= Schema::hasTable($table);
    }

    private function hasColumn(string $table, string $column): bool
    {
        $key = $table.'.'.$column;

        return $this->columnCache[$key] ??= Schema::hasColumn($table, $column);
    }
}
