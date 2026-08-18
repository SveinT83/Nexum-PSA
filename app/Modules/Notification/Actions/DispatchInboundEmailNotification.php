<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Notification\DTOs\InboundEmailNotificationIntent;
use App\Modules\Notification\Jobs\DeliverInboundEmailExternalNotification;
use App\Modules\Notification\Jobs\ProcessInboundEmailNotificationFanout;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/** Create and advance one durable, bounded inbound-notification fanout. */
class DispatchInboundEmailNotification
{
    public const PAGE_SIZE = 100;

    public const ABANDONED_CLAIM_SECONDS = 90;

    private const PAGE_TIME_BUDGET_MILLISECONDS = 8000;

    private const MAX_PAGE_ATTEMPTS = 3;

    public const ERROR_FANOUT_MISSING = 'provider_reconciliation_notification_fanout_missing';

    public const ERROR_FANOUT_FAILED = 'provider_reconciliation_notification_fanout_failed';

    public const ERROR_COMPLETED_AFTER_CANCELLATION = 'provider_reconciliation_notification_fanout_completed_after_cancellation';

    public function __construct(
        private readonly ResolveInboundEmailNotificationRecipients $recipients,
        private readonly RecordCanonicalNotification $recordNotification,
        private readonly EmailProviderBindingSnapshot $providerBindings,
        private readonly EmailProviderMessageIdentity $identities,
        private readonly EmailProviderRemoteOperationObserver $operations,
        private readonly InboundEmailNotificationFanoutReadiness $readiness,
    ) {}

    /** Create the durable recipient cursor and queue its bounded first page. */
    public function handle(EmailMessage $email): ?NotificationInboundEmailFanout
    {
        if (! $this->readiness->ready()) {
            throw new \RuntimeException('inbound_notification_fanout_schema_not_ready');
        }

        $fanout = $this->createForEmail($email);
        if (! $fanout) {
            return null;
        }

        ProcessInboundEmailNotificationFanout::dispatch((int) $fanout->id)->afterCommit();

        return $fanout->fresh();
    }

    /**
     * Link the post-rule notification intent without a RUNNING->completed
     * crash gap. The run row serializes cancellation and finalization.
     */
    public function attachReconciliationIntent(
        int $runId,
        int $itemId,
        string $automationToken,
        InboundEmailNotificationIntent $intent,
    ): ?NotificationInboundEmailFanout {
        if (! $this->readiness->ready()) {
            return null;
        }

        try {
            $fanoutId = DB::transaction(function () use (
                $automationToken,
                $intent,
                $itemId,
                $runId,
            ): ?int {
                $run = EmailProviderReconciliationRun::query()
                    ->whereKey($runId)
                    ->lockForUpdate()
                    ->first();
                $item = EmailProviderReconciliationItem::query()
                    ->whereKey($itemId)
                    ->lockForUpdate()
                    ->first();
                if (! $run || ! $item
                    || (int) $item->email_provider_reconciliation_run_id !== (int) $run->id
                    || ! $item->automation_required
                    || $item->status !== EmailProviderReconciliationItem::STATUS_PROJECTED
                    || $item->automation_status !== EmailProviderReconciliationItem::AUTOMATION_RUNNING
                    || ! is_string($item->automation_claim_token)
                    || ! hash_equals($item->automation_claim_token, $automationToken)) {
                    return null;
                }

                if ($run->terminal()
                    || (int) $run->active_slot !== 1
                    || $run->cancellation_requested_at !== null
                    || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING) {
                    $this->failRunningItem(
                        $item,
                        $automationToken,
                        'provider_reconciliation_automation_cancelled_after_rules',
                    );

                    return null;
                }

                $placement = EmailMailboxPlacement::query()
                    ->whereKey($item->result_placement_id)
                    ->lockForUpdate()
                    ->first();
                if (! $placement
                    || ! $this->reconciliationTargetIsStillAuthoritative(
                        $run,
                        $item,
                        $placement,
                        $intent->emailMessageId,
                    )) {
                    $this->failRunningItem(
                        $item,
                        $automationToken,
                        NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE,
                    );

                    return null;
                }

                $email = EmailMessage::query()
                    ->whereKey($intent->emailMessageId)
                    ->lockForUpdate()
                    ->first();
                if (! $email) {
                    $this->failRunningItem(
                        $item,
                        $automationToken,
                        NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE,
                    );

                    return null;
                }

                $email->load('tags');
                if ($this->isSuppressed($email)) {
                    $item->forceFill([
                        'automation_status' => EmailProviderReconciliationItem::AUTOMATION_COMPLETED,
                        'automation_claim_token' => null,
                        'automation_completed_at' => now(),
                        'automation_error_code' => null,
                    ])->save();

                    return null;
                }

                // Every creator locks the source Email before this unique
                // lookup, so a concurrent ordinary fanout is observed rather
                // than surfacing as a retry-losing SQL exception.
                $fanout = NotificationInboundEmailFanout::query()
                    ->where('source_email_message_id', $intent->emailMessageId)
                    ->lockForUpdate()
                    ->first() ?: $this->createSnapshot($email);

                if ($fanout->email_provider_reconciliation_item_id !== null
                    && (int) $fanout->email_provider_reconciliation_item_id !== (int) $item->id) {
                    $this->failRunningItem(
                        $item,
                        $automationToken,
                        NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE,
                    );

                    return null;
                }

                if ($fanout->terminal()) {
                    $item->forceFill([
                        'automation_status' => $fanout->status
                            === NotificationInboundEmailFanout::STATUS_COMPLETED
                            ? EmailProviderReconciliationItem::AUTOMATION_COMPLETED
                            : EmailProviderReconciliationItem::AUTOMATION_FAILED,
                        'automation_claim_token' => null,
                        'automation_completed_at' => now(),
                        'automation_error_code' => $fanout->status
                            === NotificationInboundEmailFanout::STATUS_COMPLETED
                            ? null
                            : self::ERROR_FANOUT_FAILED,
                    ])->save();

                    return null;
                }

                if ($fanout->email_provider_reconciliation_item_id === null) {
                    $fanout->forceFill([
                        'email_provider_reconciliation_item_id' => $item->id,
                        'automation_claim_token' => $automationToken,
                    ])->save();
                } elseif (! is_string($fanout->automation_claim_token)
                    || ! hash_equals($fanout->automation_claim_token, $automationToken)) {
                    $this->failRunningItem(
                        $item,
                        $automationToken,
                        NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE,
                    );

                    return null;
                }

                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT,
                    'automation_error_code' => null,
                ])->save();

                return (int) $fanout->id;
            }, 3);
        } catch (QueryException) {
            // Unique fanout races contain only identifiers, but still sever
            // database details from the queue failure boundary.
            return null;
        }

        if (! $fanoutId) {
            return null;
        }

        ProcessInboundEmailNotificationFanout::dispatch($fanoutId)->afterCommit();

        return NotificationInboundEmailFanout::query()->find($fanoutId);
    }

    /**
     * Settle or wake an awaiting reconciliation item without replaying rules.
     * Returns true when the item belonged to the fanout-owned state.
     */
    public function recoverReconciliationItem(int $itemId): bool
    {
        if (! $this->readiness->ready()) {
            return false;
        }

        $item = EmailProviderReconciliationItem::query()
            ->select(['id', 'email_provider_reconciliation_run_id', 'automation_status'])
            ->find($itemId);
        if (! $item
            || $item->automation_status
                !== EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT) {
            return false;
        }

        $fanout = NotificationInboundEmailFanout::query()
            ->where('email_provider_reconciliation_item_id', $itemId)
            ->first(['id', 'status']);
        if (! $fanout) {
            $this->failAwaitingItemWithoutFanout($itemId);

            return true;
        }

        if ($fanout->terminal()) {
            $this->settleReconciliationItem((int) $fanout->id);
        } else {
            ProcessInboundEmailNotificationFanout::dispatch((int) $fanout->id)->afterCommit();
        }

        return true;
    }

    /** Claim and commit at most one recipient page; no exception may escape. */
    public function advance(int $fanoutId): void
    {
        if (! $this->readiness->ready()) {
            return;
        }

        $token = $this->claimPage($fanoutId);
        if ($token === null) {
            $this->settleReconciliationItem($fanoutId);

            return;
        }

        try {
            $result = $this->commitPage($fanoutId, $token);
        } catch (Throwable) {
            $this->releaseFailedPage($fanoutId, $token);
            $this->settleReconciliationItem($fanoutId);

            return;
        }

        foreach ($result['external_delivery_ids'] as $deliveryId) {
            DeliverInboundEmailExternalNotification::dispatch($deliveryId)->afterCommit();
        }
        if ($result['pending']) {
            ProcessInboundEmailNotificationFanout::dispatch($fanoutId)->afterCommit();
        }

        $this->settleReconciliationItem($fanoutId);
    }

    private function createForEmail(EmailMessage $email): ?NotificationInboundEmailFanout
    {
        $fresh = $email->fresh() ?? $email;
        $fresh->loadMissing('tags');
        if ($this->isSuppressed($fresh)) {
            return null;
        }

        $existing = NotificationInboundEmailFanout::query()
            ->where('source_email_message_id', $fresh->id)
            ->first();
        if ($existing) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($fresh): ?NotificationInboundEmailFanout {
                $email = EmailMessage::query()->whereKey($fresh->id)->lockForUpdate()->first();
                if (! $email) {
                    return null;
                }
                $email->load('tags');
                if ($this->isSuppressed($email)) {
                    return null;
                }

                $existing = NotificationInboundEmailFanout::query()
                    ->where('source_email_message_id', $email->id)
                    ->lockForUpdate()
                    ->first();

                return $existing ?: $this->createSnapshot($email);
            }, 3);
        } catch (QueryException) {
            return NotificationInboundEmailFanout::query()
                ->where('source_email_message_id', $fresh->id)
                ->first();
        }
    }

    private function createSnapshot(EmailMessage $email): NotificationInboundEmailFanout
    {
        $ticket = $email->ticket_id
            ? Ticket::query()->whereKey($email->ticket_id)->lockForUpdate()->first()
            : null;
        $ticketMessageId = null;
        if ($ticket) {
            $ticketMessages = TicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->where('source_inbound_email_message_id', $email->id)
                ->orderBy('id')
                ->limit(2)
                ->pluck('id');
            if ($ticketMessages->count() === 1) {
                $ticketMessageId = (int) $ticketMessages->first();
            }
        }

        $throughId = (int) NotificationSetting::query()
            ->where(
                'notification_type',
                ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            )
            ->max('id');

        return NotificationInboundEmailFanout::query()->create([
            'email_message_id' => $email->id,
            'source_email_message_id' => $email->id,
            'email_account_id' => $email->account_id,
            'ticket_id' => $ticket?->id,
            'ticket_queue_id' => $ticket?->queue_id,
            'ticket_owner_user_id' => $ticket?->owner_id,
            'ticket_message_id' => $ticketMessageId,
            'notification_setting_through_id' => $throughId,
            'notification_setting_cursor_id' => 0,
            'owner_candidate_processed' => false,
            'owner_priority_reserved' => false,
            'status' => NotificationInboundEmailFanout::STATUS_PENDING,
        ]);
    }

    private function claimPage(int $fanoutId): ?string
    {
        $token = hash('sha256', random_bytes(32));

        return DB::transaction(function () use ($fanoutId, $token): ?string {
            $authority = $this->lockFanoutPageAuthority($fanoutId);
            $fanout = $authority['fanout'];
            if (! $fanout || $fanout->terminal()) {
                return null;
            }

            if ($fanout->status === NotificationInboundEmailFanout::STATUS_RUNNING
                && $fanout->last_attempt_at?->gt(now()->subSeconds(self::ABANDONED_CLAIM_SECONDS))) {
                return null;
            }

            if ((int) $fanout->page_attempt_count >= self::MAX_PAGE_ATTEMPTS) {
                $fanout->forceFill([
                    'status' => NotificationInboundEmailFanout::STATUS_FAILED,
                    'claim_token' => null,
                    ...$this->clearedPageWitness(),
                    'completed_at' => now(),
                    'error_code' => NotificationInboundEmailFanout::ERROR_ATTEMPTS_EXHAUSTED,
                ])->save();

                return null;
            }

            $pageWitness = $fanout->status === NotificationInboundEmailFanout::STATUS_RUNNING
                ? [
                    'page_setting_through_id' => $fanout->page_setting_through_id,
                    'page_setting_row_count' => $fanout->page_setting_row_count,
                    'page_owner_pending' => $fanout->page_owner_pending,
                    'page_owner_candidate_included' => $fanout->page_owner_candidate_included,
                ]
                : $this->storedPageWitness($this->recipients->pageWitness(
                    $fanout,
                    self::PAGE_SIZE,
                ));

            $fanout->forceFill([
                'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
                'claim_token' => $token,
                ...$pageWitness,
                'page_attempt_count' => (int) $fanout->page_attempt_count + 1,
                'last_attempt_at' => now(),
                'completed_at' => null,
                'error_code' => null,
            ])->save();

            if (! $authority['authoritative']) {
                $fanout->forceFill([
                    'status' => NotificationInboundEmailFanout::STATUS_FAILED,
                    'claim_token' => null,
                    ...$this->clearedPageWitness(),
                    'completed_at' => now(),
                    'error_code' => $authority['linked']
                        ? NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE
                        : NotificationInboundEmailFanout::ERROR_SOURCE_MISSING,
                ])->save();

                return null;
            }

            return $token;
        }, 3);
    }

    /** @return array{external_delivery_ids:array<int,int>,pending:bool} */
    private function commitPage(int $fanoutId, string $token): array
    {
        return DB::transaction(function () use ($fanoutId, $token): array {
            $authority = $this->lockFanoutPageAuthority($fanoutId);
            $fanout = $authority['fanout'];
            if (! $fanout
                || $fanout->status !== NotificationInboundEmailFanout::STATUS_RUNNING
                || ! is_string($fanout->claim_token)
                || ! hash_equals($fanout->claim_token, $token)) {
                return ['external_delivery_ids' => [], 'pending' => false];
            }

            if (! $authority['authoritative']) {
                $fanout->forceFill([
                    'status' => NotificationInboundEmailFanout::STATUS_FAILED,
                    'claim_token' => null,
                    ...$this->clearedPageWitness(),
                    'completed_at' => now(),
                    'error_code' => $authority['linked']
                        ? NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE
                        : NotificationInboundEmailFanout::ERROR_SOURCE_MISSING,
                ])->save();

                return ['external_delivery_ids' => [], 'pending' => false];
            }

            $page = $this->recipients->candidatePage($fanout);
            $externalDeliveryIds = [];
            $ownerReserved = (bool) $fanout->owner_priority_reserved;
            $processedCursorId = (int) $fanout->notification_setting_cursor_id;
            $processedCandidates = 0;
            $processedAll = true;
            $deadline = hrtime(true) + ($this->pageTimeBudgetMilliseconds() * 1_000_000);

            foreach ($page['candidates'] as $candidate) {
                if ($processedCandidates > 0 && hrtime(true) >= $deadline) {
                    $processedAll = false;

                    break;
                }
                $processedCandidates++;

                if (! $candidate['owner_candidate']
                    && $ownerReserved
                    && (int) $candidate['user_id'] === (int) $fanout->ticket_owner_user_id) {
                    $processedCursorId = (int) $candidate['notification_setting_id'];

                    continue;
                }

                $decision = $this->recipients->authorizeExact(
                    $fanout,
                    $candidate['user_id'],
                    $candidate['notification_type'],
                    $candidate['owner_candidate'],
                    $candidate['notification_setting_id'],
                );
                if ($candidate['owner_candidate'] && ($decision['reserve_owner'] ?? false)) {
                    $ownerReserved = true;
                }
                if (! $decision['authorized']) {
                    if (! $candidate['owner_candidate']) {
                        $processedCursorId = (int) $candidate['notification_setting_id'];
                    }

                    continue;
                }

                $recorded = $this->recordRecipient(
                    $fanout,
                    $decision['user'],
                    $decision['email'],
                    $decision['ticket'],
                    $candidate['notification_type'],
                    $decision['notification_setting_id'],
                    $decision['channels'],
                );
                if (! $candidate['owner_candidate']) {
                    $processedCursorId = (int) $candidate['notification_setting_id'];
                }
                if ($recorded['external_delivery_status']
                    === NotificationInboundExternalDelivery::STATUS_PENDING
                    && (int) $recorded['external_delivery_id'] > 0) {
                    $externalDeliveryIds[] = (int) $recorded['external_delivery_id'];
                }
            }

            if ($processedAll) {
                $processedCursorId = (int) $page['cursor_id'];
            }

            $completed = $page['owner_processed']
                && $processedCursorId >= (int) $fanout->notification_setting_through_id;
            $fanout->forceFill([
                'notification_setting_cursor_id' => $processedCursorId,
                'owner_candidate_processed' => $page['owner_processed'],
                'owner_priority_reserved' => $ownerReserved,
                'status' => $completed
                    ? NotificationInboundEmailFanout::STATUS_COMPLETED
                    : NotificationInboundEmailFanout::STATUS_PENDING,
                'claim_token' => null,
                ...$this->clearedPageWitness(),
                'page_attempt_count' => 0,
                'page_count' => (int) $fanout->page_count + 1,
                'completed_at' => $completed ? now() : null,
                'error_code' => null,
            ])->save();

            return [
                'external_delivery_ids' => array_values(array_unique($externalDeliveryIds)),
                'pending' => ! $completed,
            ];
        }, 3);
    }

    /**
     * Lock the durable page authority in one global order. Pre-reads choose
     * only lock keys; every fact is revalidated after its owning row is locked.
     *
     * @return array{fanout:?NotificationInboundEmailFanout,authoritative:bool,linked:bool}
     */
    private function lockFanoutPageAuthority(int $fanoutId): array
    {
        $reference = NotificationInboundEmailFanout::query()
            ->whereKey($fanoutId)
            ->first([
                'id',
                'email_message_id',
                'source_email_message_id',
                'email_provider_reconciliation_item_id',
                'automation_claim_token',
            ]);
        if (! $reference) {
            return ['fanout' => null, 'authoritative' => false, 'linked' => false];
        }

        $itemId = (int) ($reference->email_provider_reconciliation_item_id ?? 0);
        if ($itemId < 1) {
            $email = EmailMessage::query()
                ->whereKey($reference->source_email_message_id)
                ->lockForUpdate()
                ->first();
            $fanout = NotificationInboundEmailFanout::query()
                ->whereKey($fanoutId)
                ->lockForUpdate()
                ->first();
            $authoritative = $email
                && $fanout
                && $fanout->email_provider_reconciliation_item_id === null
                && (int) $fanout->source_email_message_id === (int) $email->id
                && (int) $fanout->email_message_id === (int) $email->id;

            return [
                'fanout' => $fanout,
                'authoritative' => (bool) $authoritative,
                'linked' => false,
            ];
        }

        $itemReference = EmailProviderReconciliationItem::query()
            ->whereKey($itemId)
            ->first(['id', 'email_provider_reconciliation_run_id']);
        if (! $itemReference) {
            $fanout = NotificationInboundEmailFanout::query()
                ->whereKey($fanoutId)
                ->lockForUpdate()
                ->first();

            return ['fanout' => $fanout, 'authoritative' => false, 'linked' => true];
        }

        $run = EmailProviderReconciliationRun::query()
            ->whereKey($itemReference->email_provider_reconciliation_run_id)
            ->lockForUpdate()
            ->first();
        $item = EmailProviderReconciliationItem::query()
            ->whereKey($itemId)
            ->lockForUpdate()
            ->first();
        $placement = $item?->result_placement_id
            ? EmailMailboxPlacement::query()
                ->whereKey($item->result_placement_id)
                ->lockForUpdate()
                ->first()
            : null;
        $targetAuthoritative = $run
            && $item
            && $placement
            && ! $run->terminal()
            && (int) $run->active_slot === 1
            && (int) $item->email_provider_reconciliation_run_id === (int) $run->id
            && $item->automation_required
            && $item->status === EmailProviderReconciliationItem::STATUS_PROJECTED
            && $item->automation_status
                === EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT
            && is_string($item->automation_claim_token)
            && is_string($reference->automation_claim_token)
            && hash_equals($item->automation_claim_token, $reference->automation_claim_token)
            && $this->reconciliationTargetIsStillAuthoritative(
                $run,
                $item,
                $placement,
                (int) $reference->source_email_message_id,
            );
        $fanout = NotificationInboundEmailFanout::query()
            ->whereKey($fanoutId)
            ->lockForUpdate()
            ->first();
        $authoritative = $targetAuthoritative
            && $fanout
            && (int) $fanout->email_provider_reconciliation_item_id === (int) $item->id
            && (int) $fanout->source_email_message_id === (int) $reference->source_email_message_id
            && (int) $fanout->email_message_id === (int) $reference->source_email_message_id
            && is_string($fanout->automation_claim_token)
            && hash_equals($item->automation_claim_token, $fanout->automation_claim_token);

        return [
            'fanout' => $fanout,
            'authoritative' => (bool) $authoritative,
            'linked' => true,
        ];
    }

    /**
     * @param  array{database:bool,mail:bool,web_push:bool,nextcloud_talk:bool,preview:bool}  $channels
     * @return array{notification:mixed,created:bool,external_delivery_status:?string,external_delivery_id:?int}
     */
    private function recordRecipient(
        NotificationInboundEmailFanout $fanout,
        User $user,
        EmailMessage $email,
        ?Ticket $ticket,
        string $notificationType,
        ?int $notificationSettingId,
        array $channels,
    ): array {
        $ticketMessage = $fanout->ticket_message_id
            ? TicketMessage::query()
                ->whereKey($fanout->ticket_message_id)
                ->where('ticket_id', $fanout->ticket_id)
                ->where('source_inbound_email_message_id', $email->id)
                ->first()
            : null;
        $payload = $this->payload(
            $fanout,
            $email,
            $ticket,
            $ticketMessage,
            $user,
            $notificationType,
            $notificationSettingId,
        );
        $externalRequired = $channels['mail']
            || $channels['web_push']
            || $channels['nextcloud_talk'];
        $channelRequest = [
            'mail' => $channels['mail'],
            'web_push' => $channels['web_push'],
            'nextcloud_talk' => $channels['nextcloud_talk'],
        ];
        $mailSnapshot = $channels['mail']
            ? $this->mailSnapshot($ticket ? 'tickets' : 'system')
            : null;

        return $this->recordNotification->handleWithStatus(
            user: $user,
            notificationClass: InboundEmailRoutedNotification::class,
            deliveryIdentity: (string) $payload['delivery_identity'],
            data: $payload,
            unread: $channels['database'],
            externalDeliveryRequired: $externalRequired,
            externalChannelRequest: $channelRequest,
            externalMailSnapshot: $mailSnapshot,
        );
    }

    private function releaseFailedPage(int $fanoutId, string $token): void
    {
        DB::transaction(function () use ($fanoutId, $token): void {
            $fanout = NotificationInboundEmailFanout::query()
                ->whereKey($fanoutId)
                ->lockForUpdate()
                ->first();
            if (! $fanout
                || $fanout->status !== NotificationInboundEmailFanout::STATUS_RUNNING
                || ! is_string($fanout->claim_token)
                || ! hash_equals($fanout->claim_token, $token)) {
                return;
            }

            $exhausted = (int) $fanout->page_attempt_count >= self::MAX_PAGE_ATTEMPTS;
            $fanout->forceFill([
                'status' => $exhausted
                    ? NotificationInboundEmailFanout::STATUS_FAILED
                    : NotificationInboundEmailFanout::STATUS_PENDING,
                'claim_token' => null,
                ...$this->clearedPageWitness(),
                'completed_at' => $exhausted ? now() : null,
                'error_code' => $exhausted
                    ? NotificationInboundEmailFanout::ERROR_ATTEMPTS_EXHAUSTED
                    : null,
            ])->save();
        }, 3);
    }

    /**
     * @param array{
     *     setting_through_id:int,
     *     setting_row_count:int,
     *     owner_pending:bool,
     *     owner_candidate_included:bool
     * } $witness
     * @return array<string, int|bool>
     */
    private function storedPageWitness(array $witness): array
    {
        return [
            'page_setting_through_id' => $witness['setting_through_id'],
            'page_setting_row_count' => $witness['setting_row_count'],
            'page_owner_pending' => $witness['owner_pending'],
            'page_owner_candidate_included' => $witness['owner_candidate_included'],
        ];
    }

    /** @return array<string, null> */
    private function clearedPageWitness(): array
    {
        return [
            'page_setting_through_id' => null,
            'page_setting_row_count' => null,
            'page_owner_pending' => null,
            'page_owner_candidate_included' => null,
        ];
    }

    public function settleReconciliationItem(int $fanoutId): void
    {
        if (! $this->readiness->ready()) {
            return;
        }

        $reference = NotificationInboundEmailFanout::query()
            ->whereKey($fanoutId)
            ->first(['id', 'email_provider_reconciliation_item_id']);
        if (! $reference?->email_provider_reconciliation_item_id) {
            return;
        }
        $itemReference = EmailProviderReconciliationItem::query()
            ->whereKey($reference->email_provider_reconciliation_item_id)
            ->first(['id', 'email_provider_reconciliation_run_id']);
        if (! $itemReference) {
            return;
        }

        $runId = (int) $itemReference->email_provider_reconciliation_run_id;
        $settled = DB::transaction(function () use ($fanoutId, $itemReference, $runId): bool {
            $run = EmailProviderReconciliationRun::query()->whereKey($runId)->lockForUpdate()->first();
            $item = EmailProviderReconciliationItem::query()
                ->whereKey($itemReference->id)
                ->lockForUpdate()
                ->first();
            $fanout = NotificationInboundEmailFanout::query()
                ->whereKey($fanoutId)
                ->lockForUpdate()
                ->first();
            if (! $run || ! $item || ! $fanout || ! $fanout->terminal()
                || $item->automation_status
                    !== EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT
                || ! is_string($item->automation_claim_token)
                || ! is_string($fanout->automation_claim_token)
                || ! hash_equals($item->automation_claim_token, $fanout->automation_claim_token)) {
                return false;
            }

            $cancelled = $run->cancellation_requested_at !== null
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING;
            $completed = $fanout->status === NotificationInboundEmailFanout::STATUS_COMPLETED
                && ! $cancelled;
            $item->forceFill([
                'automation_status' => $completed
                    ? EmailProviderReconciliationItem::AUTOMATION_COMPLETED
                    : EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'automation_claim_token' => null,
                'automation_completed_at' => now(),
                'automation_error_code' => $completed
                    ? null
                    : ($cancelled && $fanout->status === NotificationInboundEmailFanout::STATUS_COMPLETED
                        ? self::ERROR_COMPLETED_AFTER_CANCELLATION
                        : self::ERROR_FANOUT_FAILED),
            ])->save();

            return true;
        }, 3);

        if ($settled) {
            \App\Modules\Email\Jobs\FinalizeEmailProviderReconciliation::dispatch($runId)->afterCommit();
        }
    }

    private function failAwaitingItemWithoutFanout(int $itemId): void
    {
        $reference = EmailProviderReconciliationItem::query()
            ->whereKey($itemId)
            ->first(['id', 'email_provider_reconciliation_run_id']);
        if (! $reference) {
            return;
        }

        $runId = (int) $reference->email_provider_reconciliation_run_id;
        $updated = DB::transaction(function () use ($itemId, $runId): bool {
            $run = EmailProviderReconciliationRun::query()->whereKey($runId)->lockForUpdate()->first();
            $item = EmailProviderReconciliationItem::query()->whereKey($itemId)->lockForUpdate()->first();
            if (! $run || ! $item
                || $item->automation_status
                    !== EmailProviderReconciliationItem::AUTOMATION_AWAITING_NOTIFICATION_FANOUT) {
                return false;
            }

            $item->forceFill([
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'automation_claim_token' => null,
                'automation_completed_at' => now(),
                'automation_error_code' => self::ERROR_FANOUT_MISSING,
            ])->save();

            return true;
        }, 3);

        if ($updated) {
            \App\Modules\Email\Jobs\FinalizeEmailProviderReconciliation::dispatch($runId)->afterCommit();
        }
    }

    private function failRunningItem(
        EmailProviderReconciliationItem $item,
        string $token,
        string $errorCode,
    ): void {
        if ($item->automation_status !== EmailProviderReconciliationItem::AUTOMATION_RUNNING
            || ! is_string($item->automation_claim_token)
            || ! hash_equals($item->automation_claim_token, $token)) {
            return;
        }

        $item->forceFill([
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
            'automation_claim_token' => null,
            'automation_completed_at' => now(),
            'automation_error_code' => $errorCode,
        ])->save();
    }

    private function reconciliationTargetIsStillAuthoritative(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $placement,
        int $emailMessageId,
    ): bool {
        $message = EmailMessage::query()->withTrashed()->lockForUpdate()->find($placement->email_message_id);
        $folderRun = EmailProviderReconciliationFolder::query()
            ->whereKey($item->email_provider_reconciliation_folder_id)
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('account_id', $run->account_id)
            ->where('email_folder_id', $placement->email_folder_id)
            ->where('uid_namespace_id', $placement->uid_namespace_id)
            ->where('status', EmailProviderReconciliationFolder::STATUS_COMPLETE)
            ->first();
        $folder = EmailFolder::query()
            ->whereKey($placement->email_folder_id)
            ->where('account_id', $run->account_id)
            ->where('active_uid_namespace_id', $placement->uid_namespace_id)
            ->where('role', EmailFolder::ROLE_INBOX)
            ->first();
        $namespace = EmailFolderUidNamespace::query()
            ->whereKey($placement->uid_namespace_id)
            ->where('account_id', $run->account_id)
            ->where('email_folder_id', $placement->email_folder_id)
            ->where('uid_validity', $placement->imap_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->first();
        $currentIdentity = $message ? $this->identities->forMessage($message) : null;

        return $message
            && ! $message->trashed()
            && $folderRun
            && $folder
            && $namespace
            && (int) $message->id === $emailMessageId
            && (int) $item->result_placement_id === (int) $placement->id
            && (int) $placement->account_id === (int) $run->account_id
            && (int) $message->account_id === (int) $run->account_id
            && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
            && $placement->sync_status === EmailMailboxPlacement::SYNC_SYNCED
            && $placement->provider_missing_at === null
            && $item->placement_sync_version_after !== null
            && (int) $placement->sync_version === (int) $item->placement_sync_version_after
            && (int) $placement->last_provider_reconciliation_run_id === (int) $run->id
            && (int) $placement->last_provider_observed_sync_version === (int) $placement->sync_version
            && $placement->last_provider_observed_at !== null
            && is_string($item->identity_hash)
            && is_string($placement->last_provider_observed_identity_hash)
            && is_string($currentIdentity)
            && hash_equals($item->identity_hash, $placement->last_provider_observed_identity_hash)
            && hash_equals($item->identity_hash, $currentIdentity)
            && (int) $placement->imap_uid === (int) $item->imap_uid
            && (int) $placement->imap_uid_validity === (int) $folderRun->expected_uid_validity
            && $folderRun->import_policy === EmailProviderReconciliationFolder::IMPORT_LIVE
            && ! $this->operations->hasUnresolvedForPlacement((int) $placement->id);
    }

    private function isSuppressed(EmailMessage $email): bool
    {
        if ($email->state === 'archived') {
            return true;
        }

        return $email->tags->contains(
            fn (Tag $tag): bool => in_array(
                strtolower((string) ($tag->slug ?: $tag->name)),
                ['spam', 'junk', 'not-ticket'],
                true,
            ),
        );
    }

    /** Keep a page comfortably inside the queue timeout; tests may lower it. */
    private function pageTimeBudgetMilliseconds(): int
    {
        return max(1, min(
            self::PAGE_TIME_BUDGET_MILLISECONDS,
            (int) config(
                'notification.inbound_email_fanout_page_time_budget_ms',
                self::PAGE_TIME_BUDGET_MILLISECONDS,
            ),
        ));
    }

    /** @return array{scope:string,account_id:?int,provider_binding_version:?int,failure_code:?string} */
    private function mailSnapshot(string $scope): array
    {
        try {
            $snapshot = $this->providerBindings->captureScope($scope);
            $accountId = (int) ($snapshot['account_id'] ?? 0);
            $bindingVersion = (int) ($snapshot['provider_binding_version'] ?? 0);
            if ($accountId < 1 || $bindingVersion < 1) {
                return [
                    'scope' => $scope,
                    'account_id' => null,
                    'provider_binding_version' => null,
                    'failure_code' => 'provider_binding_snapshot_missing',
                ];
            }

            return [
                'scope' => $scope,
                'account_id' => $accountId,
                'provider_binding_version' => $bindingVersion,
                'failure_code' => null,
            ];
        } catch (Throwable) {
            return [
                'scope' => $scope,
                'account_id' => null,
                'provider_binding_version' => null,
                'failure_code' => 'provider_binding_snapshot_unavailable',
            ];
        }
    }

    /** @return array<string, mixed> */
    private function payload(
        NotificationInboundEmailFanout $fanout,
        EmailMessage $email,
        ?Ticket $ticket,
        ?TicketMessage $ticketMessage,
        User $user,
        string $notificationType,
        ?int $notificationSettingId,
    ): array {
        $deliveryIdentity = 'inbound-email:'.$email->id.':user:'.$user->id;
        $targetUrl = $ticket
            ? route('tech.tickets.show', $ticket, false)
            : route('tech.inbox.show', $email, false);
        $ticketKey = $ticket?->ticket_key;
        $title = $notificationType
            === ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED
            ? 'Customer reply received'.($ticketKey ? ' on '.$ticketKey : '')
            : 'New inbound Email';

        return [
            'type' => $notificationType,
            'delivery_identity' => $deliveryIdentity,
            'inbound_notification_fanout_id' => $fanout->id,
            'notification_setting_id' => $notificationSettingId,
            'title' => $title,
            'ticket_id' => $ticket?->id,
            'ticket_key' => $ticketKey,
            'ticket_message_id' => $ticketMessage?->id,
            'ticket_queue_id' => $ticket?->queue_id,
            'email_message_id' => $email->id,
            'email_account_id' => $email->account_id,
            'source_type' => $ticketMessage ? 'ticket_message' : 'email_message',
            'source_id' => $ticketMessage?->id ?? $email->id,
            'source_label' => $ticket ? 'Ticket customer reply' : 'Inbox email',
            'url' => $targetUrl,
            'action_label' => $ticket ? 'Open Ticket' : 'Open Email',
            'mail_summary' => $ticket
                ? 'A customer reply was linked to Ticket '.$ticket->ticket_key.'.'
                : 'A new inbound email is available in the Nexum inbox.',
            'push_title' => 'Nexum',
            'push_body' => $ticket
                ? 'A customer reply is ready in Nexum.'
                : 'A new inbound email is ready in Nexum.',
            'preview_sender_name' => Str::limit(trim((string) $email->from_name), 80, ''),
            'preview_subject' => Str::limit(trim((string) $email->subject), 100, ''),
            'web_push_tag' => 'nexum-'.$notificationType.'-'.$email->id.'-'.$user->id,
        ];
    }
}
