<?php

namespace App\Modules\Email\Jobs;

use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRuleExecutionAttempt;
use App\Modules\Email\Services\EmailProviderMessageIdentity;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\DTOs\InboundEmailNotificationIntent;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run local post-import automation only after reconciliation accepted every
 * stored artifact. This job has no provider-authority property by design: its
 * nested ProcessInboundRules invocation always uses the fail-closed default.
 */
class ProcessEmailProviderReconciliationAutomation implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const ABANDONED_CLAIM_SECONDS = 120;

    public const NESTED_AI_TIME_CAP_SECONDS = 45;

    public const RESULT_SCOPE_DRIFT_CODE = 'provider_reconciliation_automation_result_drift';

    private const MAX_ATTEMPTS = 10;

    public int $timeout = 60;

    public int $tries = 12;

    public int $maxExceptions = 10;

    public int $uniqueFor = 900;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public function __construct(public int $itemId)
    {
        $this->onQueue('email');
    }

    public function uniqueId(): string
    {
        return 'email-provider-reconciliation-automation:'.$this->itemId;
    }

    public function handle(): void
    {
        $readiness = app(InboundEmailNotificationFanoutReadiness::class);
        if (! $readiness->ready()) {
            return;
        }

        $fanouts = app(DispatchInboundEmailNotification::class);
        if ($fanouts->recoverReconciliationItem($this->itemId)) {
            return;
        }

        $runId = (int) EmailProviderReconciliationItem::query()
            ->whereKey($this->itemId)
            ->value('email_provider_reconciliation_run_id');
        $claim = $this->claim();

        try {
            if ($claim === null) {
                return;
            }

            // Missing/legacy jobs can never opt into provider mutations. The
            // authority bit is not serialized on this wrapper at all.
            $notificationIntent = app()->call([new ProcessInboundRules(
                $claim['email_message_id'],
                allowProviderMutation: false,
                aiTimeCapSeconds: self::NESTED_AI_TIME_CAP_SECONDS,
                deferInboundNotification: true,
            ), 'handle']);

            $unresolvedAttempt = EmailRuleExecutionAttempt::query()
                ->where('email_message_id', $claim['email_message_id'])
                ->where('id', '>', $claim['rule_attempt_floor_id'])
                ->whereIn('status', [
                    EmailRuleExecutionAttempt::STATUS_RUNNING,
                    EmailRuleExecutionAttempt::STATUS_FAILED,
                ])
                ->orderBy('id')
                ->first(['id', 'status']);
            if ($unresolvedAttempt) {
                $this->completeClaim(
                    $runId,
                    $claim['token'],
                    EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    $unresolvedAttempt->status === EmailRuleExecutionAttempt::STATUS_RUNNING
                        ? 'provider_reconciliation_rule_attempt_unresolved'
                        : 'provider_reconciliation_rule_attempt_failed',
                );

                return;
            }

            if ($notificationIntent instanceof InboundEmailNotificationIntent) {
                $fanout = $fanouts->attachReconciliationIntent(
                    $runId,
                    $this->itemId,
                    $claim['token'],
                    $notificationIntent,
                );
                if ($fanout === null) {
                    $this->completeClaim(
                        $runId,
                        $claim['token'],
                        EmailProviderReconciliationItem::AUTOMATION_FAILED,
                        NotificationInboundEmailFanout::ERROR_ITEM_SCOPE_STALE,
                    );
                }

                return;
            }

            $this->completeClaim(
                $runId,
                $claim['token'],
                EmailProviderReconciliationItem::AUTOMATION_COMPLETED,
                null,
            );
        } catch (Throwable) {
            if ($claim !== null) {
                $this->completeClaim(
                    $runId,
                    $claim['token'],
                    EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'provider_reconciliation_automation_failed',
                );
            }

            // Sever any rule/notification persistence exception. Provider
            // message data must never enter failed-job logs as a previous
            // exception or SQL binding.
            throw new EmailProviderReconciliationReadException(
                'provider_reconciliation_automation_failed',
            );
        } finally {
            if ($runId > 0) {
                FinalizeEmailProviderReconciliation::dispatch($runId);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $runId = (int) EmailProviderReconciliationItem::query()
            ->whereKey($this->itemId)
            ->value('email_provider_reconciliation_run_id');

        $outcome = DB::transaction(function () use ($runId): ?string {
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($runId);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($this->itemId);
            if (! $run || ! $item || ! $item->automation_required
                || $item->automationTerminal()
                || ! in_array($item->automation_status, [
                    EmailProviderReconciliationItem::AUTOMATION_PENDING,
                    EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                ], true)) {
                return null;
            }

            if ($run->cancellation_requested_at !== null
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING) {
                $progressAt = now();
                if ($item->automation_status === EmailProviderReconciliationItem::AUTOMATION_PENDING) {
                    $item->forceFill([
                        'automation_status' => EmailProviderReconciliationItem::AUTOMATION_CANCELLED,
                        'automation_claim_token' => null,
                        'automation_completed_at' => $progressAt,
                        'automation_error_code' => 'provider_reconciliation_automation_cancelled',
                    ])->save();
                } else {
                    // A worker-owned RUNNING claim has already crossed the
                    // irreversible side-effect boundary. Record its actual
                    // terminal failure rather than pretending cancellation
                    // prevented the work.
                    $item->forceFill([
                        'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                        'automation_claim_token' => null,
                        'automation_completed_at' => $progressAt,
                        'automation_error_code' => 'provider_reconciliation_automation_failed',
                    ])->save();
                }
                $this->recordDurableProgress($run, $progressAt);

                return 'transition';
            }

            $activePhase = ($run->status === EmailProviderReconciliationRun::STATUS_RUNNING
                    && $run->phase === EmailProviderReconciliationRun::PHASE_DISCOVER_END)
                || ($run->status === EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS
                    && $run->phase === EmailProviderReconciliationRun::PHASE_IMPORTS);
            if ($run->terminal() || (int) $run->active_slot !== 1 || ! $activePhase) {
                return null;
            }

            $progressAt = now();
            $item->forceFill([
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                'automation_claim_token' => null,
                'automation_completed_at' => $progressAt,
                'automation_error_code' => 'provider_reconciliation_automation_failed',
            ])->save();
            $this->recordDurableProgress($run, $progressAt);

            return 'finalize';
        }, 3);

        if ($outcome === 'transition' && $runId > 0) {
            TransitionEmailProviderReconciliationCancellation::dispatch($runId);
        } elseif ($outcome === 'finalize' && $runId > 0) {
            FinalizeEmailProviderReconciliation::dispatch($runId);
        }
    }

    /** @return null|array{email_message_id:int,rule_attempt_floor_id:int,token:string} */
    private function claim(): ?array
    {
        $reference = EmailProviderReconciliationItem::query()
            ->select(['id', 'email_provider_reconciliation_run_id'])
            ->find($this->itemId);
        if (! $reference) {
            return null;
        }

        $token = hash('sha256', random_bytes(32));

        return DB::transaction(function () use ($reference, $token): ?array {
            // Preserve the run -> item -> placement lock order shared by
            // finalization, cancellation, and import recovery.
            $run = EmailProviderReconciliationRun::query()
                ->lockForUpdate()
                ->find($reference->email_provider_reconciliation_run_id);
            $item = EmailProviderReconciliationItem::query()
                ->lockForUpdate()
                ->find($reference->id);
            if (! $item || ! $item->automation_required
                || $item->automationTerminal()
                || $item->status !== EmailProviderReconciliationItem::STATUS_PROJECTED
                || ! in_array($item->automation_status, [
                    EmailProviderReconciliationItem::AUTOMATION_PENDING,
                    EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                ], true)) {
                return null;
            }

            if (! $run
                || (int) $item->email_provider_reconciliation_run_id !== (int) $run->id
                || $run->terminal()
                || $run->status === EmailProviderReconciliationRun::STATUS_CANCELLING
                || $run->cancellation_requested_at !== null) {
                $progressAt = now();
                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_CANCELLED,
                    'automation_claim_token' => null,
                    'automation_completed_at' => $progressAt,
                    'automation_error_code' => 'provider_reconciliation_automation_cancelled',
                ])->save();
                if ($run) {
                    $this->recordDurableProgress($run, $progressAt);
                }

                return null;
            }
            $activePhase = ($run->status === EmailProviderReconciliationRun::STATUS_RUNNING
                    && $run->phase === EmailProviderReconciliationRun::PHASE_DISCOVER_END)
                || ($run->status === EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS
                    && $run->phase === EmailProviderReconciliationRun::PHASE_IMPORTS);
            if ((int) $run->active_slot !== 1 || ! $activePhase) {
                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'automation_claim_token' => null,
                    'automation_completed_at' => now(),
                    'automation_error_code' => 'provider_reconciliation_automation_scope_invalid',
                ])->save();
                $this->recordDurableProgress($run, now());

                return null;
            }

            if ($item->automation_status === EmailProviderReconciliationItem::AUTOMATION_RUNNING
                && $item->automation_last_attempt_at?->gt(
                    now()->subSeconds(self::ABANDONED_CLAIM_SECONDS),
                )) {
                return null;
            }

            if ($item->automation_status === EmailProviderReconciliationItem::AUTOMATION_RUNNING) {
                // The lost worker may have completed an AI request, created a
                // Ticket/Signal, or sent a notification before its hard loss.
                // Without a per-action outbox, replay cannot be proven safe.
                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'automation_claim_token' => null,
                    'automation_completed_at' => now(),
                    'automation_error_code' => 'provider_reconciliation_automation_worker_lost',
                ])->save();
                $this->recordDurableProgress($run, now());

                return null;
            }

            if ((int) $item->automation_attempt_count >= self::MAX_ATTEMPTS) {
                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'automation_claim_token' => null,
                    'automation_completed_at' => now(),
                    'automation_error_code' => 'provider_reconciliation_automation_attempt_limit',
                ])->save();
                $this->recordDurableProgress($run, now());

                return null;
            }

            $placement = EmailMailboxPlacement::query()
                ->whereKey($item->result_placement_id)
                ->lockForUpdate()
                ->first();
            if (! $placement) {
                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'automation_claim_token' => null,
                    'automation_completed_at' => now(),
                    'automation_error_code' => 'provider_reconciliation_automation_scope_missing',
                ])->save();
                $this->recordDurableProgress($run, now());

                return null;
            }

            if (! $this->resultPlacementIsStillAuthoritative($run, $item, $placement)) {
                // Correlation and execution are separate durable jobs. Recheck
                // the exact imported occurrence here so another active copy of
                // the same message can never authorize stale target work.
                $item->forceFill([
                    'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
                    'automation_claim_token' => null,
                    'automation_completed_at' => now(),
                    'automation_error_code' => self::RESULT_SCOPE_DRIFT_CODE,
                ])->save();
                $this->recordDurableProgress($run, now());

                return null;
            }

            $ruleAttemptFloorId = $item->automation_rule_attempt_floor_id;
            if ($ruleAttemptFloorId === null) {
                $ruleAttemptFloorId = (int) EmailRuleExecutionAttempt::query()
                    ->where('email_message_id', $placement->email_message_id)
                    ->max('id');
            }

            $item->forceFill([
                'automation_status' => EmailProviderReconciliationItem::AUTOMATION_RUNNING,
                'automation_claim_token' => $token,
                'automation_attempt_count' => (int) $item->automation_attempt_count + 1,
                'automation_last_attempt_at' => now(),
                'automation_error_code' => null,
                'automation_rule_attempt_floor_id' => $ruleAttemptFloorId,
            ])->save();

            return [
                'email_message_id' => (int) $placement->email_message_id,
                'rule_attempt_floor_id' => (int) $ruleAttemptFloorId,
                'token' => $token,
            ];
        }, 3);
    }

    /**
     * Commit a claimed automation outcome and its run progress under the
     * global run -> item lock order. A lost/stale claim remains a true no-op.
     */
    private function completeClaim(
        int $runId,
        string $claimToken,
        string $status,
        ?string $errorCode,
    ): bool {
        return DB::transaction(function () use ($claimToken, $errorCode, $runId, $status): bool {
            $run = EmailProviderReconciliationRun::query()
                ->whereKey($runId)
                ->lockForUpdate()
                ->first();
            $item = EmailProviderReconciliationItem::query()
                ->whereKey($this->itemId)
                ->lockForUpdate()
                ->first();
            if (! $run || ! $item
                || (int) $item->email_provider_reconciliation_run_id !== (int) $run->id
                || $item->automation_status !== EmailProviderReconciliationItem::AUTOMATION_RUNNING
                || ! is_string($item->automation_claim_token)
                || ! hash_equals($item->automation_claim_token, $claimToken)) {
                return false;
            }

            $progressAt = now();
            $item->forceFill([
                'automation_status' => $status,
                'automation_claim_token' => null,
                'automation_completed_at' => $progressAt,
                'automation_error_code' => $errorCode,
            ])->save();
            $this->recordDurableProgress($run, $progressAt);

            return true;
        }, 3);
    }

    /** Record an automation commit, never a no-op, retry, or cancellation poll. */
    private function recordDurableProgress(
        EmailProviderReconciliationRun $run,
        \DateTimeInterface $progressAt,
    ): void {
        $activePhase = in_array($run->phase, [
            EmailProviderReconciliationRun::PHASE_DISCOVER_END,
            EmailProviderReconciliationRun::PHASE_IMPORTS,
        ], true);
        $activeStatus = in_array($run->status, [
            EmailProviderReconciliationRun::STATUS_RUNNING,
            EmailProviderReconciliationRun::STATUS_WAITING_FOR_IMPORTS,
            EmailProviderReconciliationRun::STATUS_CANCELLING,
        ], true);
        if ($run->terminal()
            || (int) $run->active_slot !== 1
            || ! $activePhase
            || ! $activeStatus) {
            return;
        }

        $run->forceFill(['last_progress_at' => $progressAt])->save();
    }

    private function resultPlacementIsStillAuthoritative(
        EmailProviderReconciliationRun $run,
        EmailProviderReconciliationItem $item,
        EmailMailboxPlacement $placement,
    ): bool {
        $message = EmailMessage::query()
            ->withTrashed()
            ->lockForUpdate()
            ->find($placement->email_message_id);
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
            ->first();
        $namespace = EmailFolderUidNamespace::query()
            ->whereKey($placement->uid_namespace_id)
            ->where('account_id', $run->account_id)
            ->where('email_folder_id', $placement->email_folder_id)
            ->where('uid_validity', $placement->imap_uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->first();
        $frozenIdentity = $item->identity_hash;
        $observedIdentity = $placement->last_provider_observed_identity_hash;
        $currentIdentity = $message
            ? app(EmailProviderMessageIdentity::class)->forMessage($message)
            : null;

        return $message
            && ! $message->trashed()
            && $folderRun
            && $folder
            && $namespace
            && $item->kind === EmailProviderReconciliationItem::KIND_IMPORT
            && (int) $item->result_placement_id === (int) $placement->id
            && (int) $item->uid_namespace_id === (int) $placement->uid_namespace_id
            && (int) $placement->account_id === (int) $run->account_id
            && $placement->local_state === EmailMailboxPlacement::LOCAL_ACTIVE
            && $placement->sync_status === EmailMailboxPlacement::SYNC_SYNCED
            && $placement->provider_missing_at === null
            && (int) $placement->last_provider_reconciliation_run_id === (int) $run->id
            && $placement->last_provider_observed_sync_version !== null
            && $placement->last_provider_observed_at !== null
            && (int) $placement->last_provider_observed_sync_version
                === (int) $placement->sync_version
            && $item->placement_sync_version_after !== null
            && (int) $placement->sync_version === (int) $item->placement_sync_version_after
            && (int) $placement->imap_uid === (int) $item->imap_uid
            && (int) $placement->imap_uid_validity === (int) $folderRun->expected_uid_validity
            && (string) $placement->folder_path === (string) $folderRun->folder_path
            && $folderRun->import_policy === EmailProviderReconciliationFolder::IMPORT_LIVE
            && (string) $folder->path === (string) $placement->folder_path
            && $folder->role === EmailFolder::ROLE_INBOX
            && (int) $folder->uid_validity === (int) $placement->imap_uid_validity
            && (int) $namespace->uid_validity === (int) $placement->imap_uid_validity
            && (int) $message->account_id === (int) $run->account_id
            && (string) $message->mailbox === (string) $placement->folder_path
            && (int) $message->imap_uid_validity === (int) $placement->imap_uid_validity
            && (int) $message->imap_uid === (int) $placement->imap_uid
            && $this->canonicalIdentity($frozenIdentity)
            && $this->canonicalIdentity($observedIdentity)
            && $this->canonicalIdentity($currentIdentity)
            && hash_equals($frozenIdentity, $observedIdentity)
            && hash_equals($frozenIdentity, $currentIdentity)
            && ! app(EmailProviderRemoteOperationObserver::class)
                ->hasUnresolvedForPlacement((int) $placement->id);
    }

    private function canonicalIdentity(mixed $identity): bool
    {
        return is_string($identity)
            && preg_match('/\A[0-9a-f]{64}\z/D', $identity) === 1;
    }
}
