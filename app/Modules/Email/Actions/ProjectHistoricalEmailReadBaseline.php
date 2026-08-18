<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserReadBaseline;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailMessageUserState;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailOrdinaryMailboxEntitlementResolver;
use App\Modules\Email\Services\EmailProviderDraftSyncService;
use App\Modules\Email\Services\EmailProviderReconciliationStore;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\EmailUnreadAccessEpochService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ProjectHistoricalEmailReadBaseline
{
    public const MAX_RECONCILIATION_BATCH_SIZE = 100;

    public const ABANDONED_RECONCILIATION_CLAIM_SECONDS = 75;

    public const RECONCILIATION_PENDING = 'pending';

    public const RECONCILIATION_COMPLETED = 'completed';

    public const RECONCILIATION_CANCELLED = 'cancelled';

    public const RECONCILIATION_INACTIVE = 'inactive';

    public const FAILURE_PROJECTION = 'reconciliation_historical_baseline_projection_failed';

    public function __construct(
        private readonly EmailOrdinaryMailboxEntitlementResolver $entitlements,
        private readonly EmailUnreadAccessEpochService $epochs,
        private readonly EmailConversationProjector $conversations,
        private readonly EmailProviderDraftSyncService $drafts,
        private readonly EmailSentReconciliationService $sent,
    ) {}

    /**
     * Insert-only initialization for newly imported history. It never derives
     * from provider Seen and never overwrites a user's current-epoch choice.
     */
    public function handle(EmailAccount $account, EmailMessage $message): int
    {
        if ((int) $message->account_id !== (int) $account->id) {
            return 0;
        }

        $rows = [];
        $now = now();

        EmailAccountUserReadBaseline::query()
            ->where('email_account_id', $account->id)
            ->where('ordinary_view_entitled', true)
            ->with('user')
            ->orderBy('id')
            ->get()
            ->each(function (EmailAccountUserReadBaseline $baseline) use ($account, $message, $now, &$rows): void {
                $user = $baseline->user;

                if (! $user instanceof User) {
                    return;
                }

                if ($user->isSystemActor()) {
                    $this->epochs->closeSystemActorEntitlement($account, $user, $now);

                    return;
                }

                if (! $this->entitlements->hasViewEntitlement($account, $user)) {
                    return;
                }

                // A delegation may have expired and a later one become active
                // without any interactive mailbox request in between. Close
                // that unattended gap before projecting into the epoch.
                $currentBaseline = $this->epochs->ensureCurrentEntitlement($account, $user);

                if (! $currentBaseline) {
                    return;
                }

                $rows[] = [
                    'email_message_id' => $message->id,
                    'user_id' => $user->id,
                    'access_epoch' => $currentBaseline->access_epoch,
                    'last_opened_placement_id' => null,
                    'is_unread' => false,
                    'opened_count' => 0,
                    'first_opened_at' => null,
                    'last_opened_at' => null,
                    'marked_read_at' => null,
                    'marked_unread_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });

        return $rows === []
            ? 0
            : DB::table((new EmailMessageUserState)->getTable())->insertOrIgnore($rows);
    }

    /**
     * Claim one bounded reconciliation page. A running claim is recoverable
     * only after it outlives the 45-second job timeout plus cleanup margin.
     */
    public function claimReconciliationBatch(int $itemId): ?string
    {
        if ($itemId < 1) {
            return null;
        }

        $reference = EmailProviderReconciliationItem::query()
            ->select(['id', 'email_provider_reconciliation_run_id'])
            ->find($itemId);
        if (! $reference) {
            return null;
        }

        $token = hash('sha256', random_bytes(32));
        $claimed = DB::transaction(function () use ($reference, $token): bool {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_run_id);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($reference->id);
            if (! $run || ! $item
                || (int) $item->email_provider_reconciliation_run_id !== (int) $run->id
                || ! $item->historical_baseline_required
                || $item->status !== EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE) {
                return false;
            }

            if (in_array($run->status, [
                EmailProviderReconciliationRun::STATUS_CANCELLING,
                EmailProviderReconciliationRun::STATUS_CANCELLED,
            ], true) || $run->cancellation_requested_at !== null) {
                $this->cancelLockedReconciliationItem($item);

                return false;
            }

            if (! $this->activeBaselineRun($run)) {
                $this->failLockedReconciliationItem(
                    $item,
                    'reconciliation_historical_baseline_run_stale',
                );

                return false;
            }

            $pending = $item->historical_baseline_status
                === EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING;
            $abandoned = $item->historical_baseline_status
                    === EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING
                && $item->historical_baseline_last_attempt_at?->lte(
                    now()->subSeconds(self::ABANDONED_RECONCILIATION_CLAIM_SECONDS),
                );
            if (! $pending && ! $abandoned) {
                return false;
            }

            $now = now();
            $item->forceFill([
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING,
                'historical_baseline_claim_token' => $token,
                'historical_baseline_attempt_count' => (int) $item->historical_baseline_attempt_count + 1,
                'historical_baseline_first_attempt_at' => $item->historical_baseline_first_attempt_at ?? $now,
                'historical_baseline_last_attempt_at' => $now,
                'historical_baseline_error_code' => null,
            ])->save();

            return true;
        }, 3);

        return $claimed ? $token : null;
    }

    /**
     * Project at most one hard-capped viewer page and activate only after an
     * independent run -> item -> placement transaction proves the cursor is
     * complete. The optional smaller limit exists for deterministic loss and
     * resume tests; production always uses the hard maximum.
     */
    public function projectReconciliationBatch(
        int $itemId,
        string $claimToken,
        int $batchSize = self::MAX_RECONCILIATION_BATCH_SIZE,
    ): string {
        if ($itemId < 1 || ! preg_match('/^[a-f0-9]{64}$/', $claimToken)) {
            return self::RECONCILIATION_INACTIVE;
        }

        $batchSize = max(1, min(self::MAX_RECONCILIATION_BATCH_SIZE, $batchSize));
        $reference = $this->reconciliationReference($itemId, $claimToken);
        if ($reference === null) {
            return self::RECONCILIATION_INACTIVE;
        }

        $page = DB::transaction(function () use (
            $batchSize,
            $claimToken,
            $itemId,
            $reference,
        ): string {
            // This is a short DB-only lock. It serializes entitlement epoch
            // changes but never overlaps the provider/cache lock or provider
            // I/O held by the importer.
            $account = EmailAccount::query()
                ->lockForUpdate()
                ->find($reference['account_id']);
            if (! $account) {
                throw new RuntimeException(self::FAILURE_PROJECTION);
            }

            $run = EmailProviderReconciliationRun::query()
                ->whereKey($reference['run_id'])
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();
            $item = EmailProviderReconciliationItem::query()
                ->whereKey($itemId)
                ->where('status', EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE)
                ->where('historical_baseline_required', true)
                ->where('historical_baseline_status', EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING)
                ->where('historical_baseline_claim_token', $claimToken)
                ->lockForUpdate()
                ->first();
            $placement = $item?->result_placement_id
                ? EmailMailboxPlacement::query()
                    ->lockForUpdate()
                    ->find($item->result_placement_id)
                : null;
            if ($item && $run && ($run->cancellation_requested_at !== null
                || in_array($run->status, [
                    EmailProviderReconciliationRun::STATUS_CANCELLING,
                    EmailProviderReconciliationRun::STATUS_CANCELLED,
                ], true))) {
                $this->cancelLockedReconciliationItem($item);

                return self::RECONCILIATION_CANCELLED;
            }
            if (! $item || ! $this->activeBaselineRun($run) || ! $placement
                || (int) $placement->email_message_id !== $reference['message_id']
                || ! $this->hasExactCompletionScope($run, $item, $placement)) {
                throw new RuntimeException(self::FAILURE_PROJECTION);
            }

            $cursor = (int) $item->historical_baseline_cursor_id;
            $maximum = (int) $item->historical_baseline_max_id;
            $baselineReferences = EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $account->id)
                ->where('id', '>', $cursor)
                ->where('id', '<=', $maximum)
                ->orderBy('id')
                ->limit($batchSize)
                ->get(['id', 'user_id']);
            $now = now();
            $rows = [];
            $lastScannedId = $cursor;

            foreach ($baselineReferences as $baselineReference) {
                $lastScannedId = (int) $baselineReference->id;
                // Entitlement mutation paths use this same account -> user ->
                // baseline order, so a concurrent revoke cannot deadlock or
                // leak a stale epoch into the eventual activation.
                $user = User::query()->lockForUpdate()->find($baselineReference->user_id);
                if (! $user) {
                    continue;
                }
                $baseline = EmailAccountUserReadBaseline::query()
                    ->whereKey($baselineReference->id)
                    ->where('email_account_id', $account->id)
                    ->where('user_id', $user->id)
                    ->lockForUpdate()
                    ->first();
                if (! $baseline) {
                    continue;
                }

                if ($user->isSystemActor()) {
                    // The locked epoch service closes stale personal-state
                    // authority and deliberately returns no current epoch.
                    $this->epochs->ensureCurrentEntitlementWithLockedRows(
                        $account,
                        $user,
                        $baseline,
                        at: $now,
                    );

                    continue;
                }
                if (! $user->isActive()) {
                    continue;
                }

                $currentBaseline = $this->epochs->ensureCurrentEntitlementWithLockedRows(
                    $account,
                    $user,
                    $baseline,
                    at: $now,
                );
                if (! $currentBaseline) {
                    continue;
                }

                $rows[] = [
                    'email_message_id' => $reference['message_id'],
                    'user_id' => $user->id,
                    'access_epoch' => $currentBaseline->access_epoch,
                    'last_opened_placement_id' => null,
                    'is_unread' => false,
                    'opened_count' => 0,
                    'first_opened_at' => null,
                    'last_opened_at' => null,
                    'marked_read_at' => null,
                    'marked_unread_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            if ($rows !== []) {
                DB::table((new EmailMessageUserState)->getTable())->insertOrIgnore($rows);
            }

            $hasMore = EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $account->id)
                ->where('id', '>', $lastScannedId)
                ->where('id', '<=', $maximum)
                ->exists();
            $updates = [
                'historical_baseline_cursor_id' => $lastScannedId,
                'historical_baseline_last_attempt_at' => $now,
                'historical_baseline_error_code' => null,
                'updated_at' => $now,
            ];
            if ($hasMore) {
                $updates['historical_baseline_status'] = EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING;
                $updates['historical_baseline_claim_token'] = null;
            }

            $advanced = EmailProviderReconciliationItem::query()
                ->whereKey($itemId)
                ->where('status', EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE)
                ->where('historical_baseline_status', EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING)
                ->where('historical_baseline_claim_token', $claimToken)
                ->where('historical_baseline_cursor_id', $cursor)
                ->update($updates);
            if ($advanced !== 1) {
                throw new RuntimeException(self::FAILURE_PROJECTION);
            }

            return $hasMore ? self::RECONCILIATION_PENDING : 'ready_to_complete';
        }, 3);

        return $page === 'ready_to_complete'
            ? $this->completeReconciliationBatch($itemId, $claimToken)
            : $page;
    }

    public function releaseReconciliationClaim(
        int $itemId,
        string $claimToken,
        string $safeCode = self::FAILURE_PROJECTION,
    ): void {
        $reference = EmailProviderReconciliationItem::query()
            ->select(['id', 'email_provider_reconciliation_run_id'])
            ->find($itemId);
        if (! $reference) {
            return;
        }

        DB::transaction(function () use ($claimToken, $reference, $safeCode): void {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_run_id);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($reference->id);
            if (! $item
                || $item->status !== EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE
                || $item->historical_baseline_status
                    !== EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING
                || ! hash_equals((string) $item->historical_baseline_claim_token, $claimToken)) {
                return;
            }
            if ($run && ($run->cancellation_requested_at !== null
                || in_array($run->status, [
                    EmailProviderReconciliationRun::STATUS_CANCELLING,
                    EmailProviderReconciliationRun::STATUS_CANCELLED,
                ], true))) {
                $this->cancelLockedReconciliationItem($item);

                return;
            }
            if (! $this->activeBaselineRun($run)) {
                return;
            }

            $item->forceFill([
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                'historical_baseline_claim_token' => null,
                'historical_baseline_last_attempt_at' => now(),
                'historical_baseline_error_code' => $this->safeCode($safeCode),
            ])->save();
        }, 3);
    }

    /** Mark an irrecoverably failed queue delivery without exposing its Throwable. */
    public function failReconciliation(
        int $itemId,
        string $safeCode = self::FAILURE_PROJECTION,
    ): void {
        $reference = EmailProviderReconciliationItem::query()
            ->select(['id', 'email_provider_reconciliation_run_id', 'result_placement_id'])
            ->find($itemId);
        if (! $reference) {
            return;
        }

        DB::transaction(function () use ($reference, $safeCode): void {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_run_id);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($reference->id);
            if ($reference->result_placement_id) {
                EmailMailboxPlacement::query()
                    ->lockForUpdate()
                    ->find($reference->result_placement_id);
            }
            if (! $item || ! $item->historical_baseline_required
                || $item->historicalBaselineTerminal()) {
                return;
            }

            if ($run && (in_array($run->status, [
                EmailProviderReconciliationRun::STATUS_CANCELLING,
                EmailProviderReconciliationRun::STATUS_CANCELLED,
            ], true) || $run->cancellation_requested_at !== null)) {
                $this->cancelLockedReconciliationItem($item);

                return;
            }

            if ($this->activeBaselineRun($run)) {
                $this->failLockedReconciliationItem($item, $this->safeCode($safeCode));
            }
        }, 3);
    }

    private function completeReconciliationBatch(int $itemId, string $claimToken): string
    {
        $reference = EmailProviderReconciliationItem::query()
            ->select(['id', 'email_provider_reconciliation_run_id', 'result_placement_id'])
            ->find($itemId);
        if (! $reference || ! $reference->result_placement_id) {
            throw new RuntimeException(self::FAILURE_PROJECTION);
        }

        return DB::transaction(function () use ($claimToken, $reference): string {
            // Final activation uses the coordinator's global lock order.
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_run_id);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($reference->id);
            $placement = EmailMailboxPlacement::query()
                ->lockForUpdate()
                ->find($reference->result_placement_id);
            if (! $run || ! $item || ! $placement
                || ! $item->historical_baseline_required
                || $item->status !== EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE
                || $item->historical_baseline_status
                    !== EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING
                || ! hash_equals((string) $item->historical_baseline_claim_token, $claimToken)) {
                return self::RECONCILIATION_INACTIVE;
            }

            if (in_array($run->status, [
                EmailProviderReconciliationRun::STATUS_CANCELLING,
                EmailProviderReconciliationRun::STATUS_CANCELLED,
            ], true) || $run->cancellation_requested_at !== null) {
                $this->cancelLockedReconciliationItem($item);

                return self::RECONCILIATION_CANCELLED;
            }

            if (! $this->activeBaselineRun($run)
                || ! $this->hasExactCompletionScope($run, $item, $placement)) {
                throw new RuntimeException(self::FAILURE_PROJECTION);
            }

            $hasMore = EmailAccountUserReadBaseline::query()
                ->where('email_account_id', $run->account_id)
                ->where('id', '>', $item->historical_baseline_cursor_id)
                ->where('id', '<=', $item->historical_baseline_max_id)
                ->exists();
            if ($hasMore) {
                $item->forceFill([
                    'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_PENDING,
                    'historical_baseline_claim_token' => null,
                    'historical_baseline_error_code' => null,
                ])->save();

                return self::RECONCILIATION_PENDING;
            }

            $now = now();
            $placement->forceFill([
                'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                'sync_error_code' => null,
                'sync_error_message' => null,
                'last_reconciled_at' => $now,
            ])->save();
            $this->projectDeferredDraftOrSentState($run, $item, $placement->refresh());
            // Pending Store never published this placement through the
            // conversation preview. First/latest metadata, distinct message
            // count, attachments, and visibility counters therefore join the
            // same activation commit as the personal read baseline.
            if (! $this->conversations->refreshForPlacement(
                $placement->refresh(),
            )) {
                throw new RuntimeException(self::FAILURE_PROJECTION);
            }

            $item->forceFill([
                'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
                'error_code' => null,
                'completed_at' => $now,
                'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_COMPLETED,
                'historical_baseline_claim_token' => null,
                'historical_baseline_completed_at' => $now,
                'historical_baseline_error_code' => null,
                'automation_required' => false,
                'automation_status' => null,
                'automation_claim_token' => null,
            ])->save();

            return self::RECONCILIATION_COMPLETED;
        }, 3);
    }

    /**
     * Complete Draft/Sent reservation state through the local-only observed
     * placement seams before history becomes visible. Those seams revalidate
     * the exact frozen binding and cannot resolve or mutate a provider.
     */
    private function projectDeferredDraftOrSentState(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $placement,
    ): void {
        $folder = EmailFolder::query()
            ->whereKey($placement->email_folder_id)
            ->where('account_id', $run->account_id)
            ->firstOrFail();
        $arguments = [
            $placement,
            (int) $run->account_id,
            (int) $folder->id,
            (int) $item->uid_namespace_id,
            (int) $placement->imap_uid_validity,
            (int) $item->imap_uid,
            (int) $run->provider_binding_version,
        ];

        if ($folder->role === EmailFolder::ROLE_DRAFTS) {
            $this->drafts->reconcileObservedPlacementLocally(...$arguments);
        }
        if ($folder->role === EmailFolder::ROLE_SENT) {
            $this->sent->reconcileObservedPlacementLocally(...$arguments);
        }
    }

    /** @return array{run_id:int, account_id: int, message_id: int}|null */
    private function reconciliationReference(int $itemId, string $claimToken): ?array
    {
        $item = EmailProviderReconciliationItem::query()
            ->whereKey($itemId)
            ->where('status', EmailProviderReconciliationItem::STATUS_WAITING_FOR_BASELINE)
            ->where('historical_baseline_required', true)
            ->where('historical_baseline_status', EmailProviderReconciliationItem::HISTORICAL_BASELINE_RUNNING)
            ->where('historical_baseline_claim_token', $claimToken)
            ->first(['email_provider_reconciliation_run_id', 'result_placement_id']);
        if (! $item || ! $item->result_placement_id) {
            return null;
        }

        $run = EmailProviderReconciliationRun::query()->find(
            $item->email_provider_reconciliation_run_id,
            ['id', 'account_id'],
        );
        $placement = EmailMailboxPlacement::query()->find(
            $item->result_placement_id,
            ['id', 'email_message_id'],
        );
        if (! $run || ! $placement) {
            return null;
        }

        return [
            'run_id' => (int) $run->id,
            'account_id' => (int) $run->account_id,
            'message_id' => (int) $placement->email_message_id,
        ];
    }

    private function hasExactCompletionScope(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $placement,
    ): bool {
        $folderRun = EmailProviderReconciliationFolder::query()
            ->whereKey($item->email_provider_reconciliation_folder_id)
            ->where('email_provider_reconciliation_run_id', $run->id)
            ->where('account_id', $run->account_id)
            ->where('email_folder_id', $placement->email_folder_id)
            ->where('uid_namespace_id', $item->uid_namespace_id)
            ->first();
        if (! $folderRun
            || $item->kind !== EmailProviderReconciliationItem::KIND_IMPORT
            || (int) $item->result_placement_id !== (int) $placement->id
            || (int) $placement->account_id !== (int) $run->account_id
            || (int) $placement->uid_namespace_id !== (int) $item->uid_namespace_id
            || (int) $placement->imap_uid_validity !== (int) $folderRun->expected_uid_validity
            || (int) $placement->imap_uid !== (int) $item->imap_uid
            || (string) $placement->folder_path !== (string) $folderRun->folder_path
            || $placement->local_state !== EmailMailboxPlacement::LOCAL_HIDDEN
            || $placement->sync_status !== EmailMailboxPlacement::SYNC_PENDING
            || $placement->sync_error_code
                !== EmailProviderReconciliationStore::HISTORICAL_BASELINE_PENDING_CODE) {
            return false;
        }

        $folderValid = EmailFolder::query()
            ->whereKey($placement->email_folder_id)
            ->where('account_id', $run->account_id)
            ->where('active_uid_namespace_id', $item->uid_namespace_id)
            ->where('path', $folderRun->folder_path)
            ->exists();
        $namespaceValid = EmailFolderUidNamespace::query()
            ->whereKey($item->uid_namespace_id)
            ->where('account_id', $run->account_id)
            ->where('email_folder_id', $placement->email_folder_id)
            ->where('uid_validity', $folderRun->expected_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();
        $messageValid = EmailMessage::query()
            ->whereKey($placement->email_message_id)
            ->where('account_id', $run->account_id)
            ->where('mailbox', $folderRun->folder_path)
            ->where('imap_uid_validity', $folderRun->expected_uid_validity)
            ->where('imap_uid', $item->imap_uid)
            ->exists();

        return $folderValid && $namespaceValid && $messageValid;
    }

    private function cancelLockedReconciliationItem(EmailProviderReconciliationItem $item): void
    {
        $now = now();
        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_CANCELLED,
            'error_code' => 'cancelled',
            'completed_at' => $now,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_CANCELLED,
            'historical_baseline_claim_token' => null,
            'historical_baseline_completed_at' => $now,
            'historical_baseline_error_code' => 'cancelled',
        ])->save();
    }

    private function failLockedReconciliationItem(
        EmailProviderReconciliationItem $item,
        string $safeCode,
    ): void {
        $now = now();
        $item->forceFill([
            'status' => EmailProviderReconciliationItem::STATUS_FAILED,
            'error_code' => $safeCode,
            'completed_at' => $now,
            'historical_baseline_status' => EmailProviderReconciliationItem::HISTORICAL_BASELINE_FAILED,
            'historical_baseline_claim_token' => null,
            'historical_baseline_completed_at' => $now,
            'historical_baseline_error_code' => $safeCode,
        ])->save();
    }

    private function safeCode(string $code): string
    {
        $safe = preg_replace('/[^a-z0-9_]+/', '_', mb_strtolower($code)) ?: self::FAILURE_PROJECTION;

        return mb_substr(trim($safe, '_'), 0, 80) ?: self::FAILURE_PROJECTION;
    }

    private function activeBaselineRun(?EmailProviderReconciliationRun $run): bool
    {
        return $run
            && ! $run->terminal()
            && (int) $run->active_slot === 1
            && $run->cancellation_requested_at === null
            && $run->final_summary_status === null
            && in_array($run->phase, [
                EmailProviderReconciliationRun::PHASE_SCAN,
                EmailProviderReconciliationRun::PHASE_IMPORTS,
            ], true)
            && in_array($run->status, [
                EmailProviderReconciliationRun::STATUS_RUNNING,
                EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            ], true);
    }
}
