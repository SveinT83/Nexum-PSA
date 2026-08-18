<?php

namespace App\Modules\Email\Jobs;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailHistoricalImportItem;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailHistoricalImportPolicy;
use App\Modules\Email\Services\EmailHistoricalImportSnapshotVerifier;
use App\Modules\Email\Services\EmailHistoricalImportStorageReadiness;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ImportHistoricalEmailMessages implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 60;

    public int $maxExceptions = 3;

    public int $uniqueFor = 1200;

    /** @var array<int, int> */
    public array $backoff = [30, 120];

    public string $queuedAt;

    protected int $expectedProviderBindingVersion = 1;

    public function __construct(public int $runId)
    {
        $this->queuedAt = now()->toIso8601String();
        $this->onQueue('email');
    }

    /**
     * Shared-lock releases consume attempts too. Keep enough attempts to wait
     * for a normal poll while the absolute timestamp prevents infinite wait.
     */
    public function retryUntil(): DateTimeInterface
    {
        return Carbon::parse($this->queuedAt)->addMinutes(15);
    }

    public function uniqueId(): string
    {
        return 'email-historical-import:'.$this->runId;
    }

    public function middleware(): array
    {
        $accountId = (int) EmailHistoricalImportRun::query()
            ->whereKey($this->runId)
            ->value('account_id');

        return [EmailAccountProviderLock::middleware($accountId, $this->timeout)];
    }

    public function handle(
        EmailMailboxMaintenanceAuthorization $authorization,
        EmailHistoricalImportSnapshotVerifier $snapshotVerifier,
        EmailHistoricalImportPolicy $policy,
        EmailHistoricalImportStorageReadiness $storageReadiness,
    ): void {
        $run = $this->claimRun();

        if (! $run || $run->terminal()) {
            return;
        }

        if ($run->status === EmailHistoricalImportRun::STATUS_CANCELLING) {
            $this->finishCancelled($run);

            return;
        }

        $actor = User::query()->whereKey($run->requested_by)->first();
        $account = EmailAccount::query()->whereKey($run->account_id)->first();

        if (! $actor || ! $account) {
            $this->finishCancelled($run, 'HISTORICAL_IMPORT_AUTHORIZATION_REVOKED');

            return;
        }

        $this->expectedProviderBindingVersion = (int) $run->provider_binding_version;
        if ($this->expectedProviderBindingVersion < 1) {
            $this->finishStale($run, 'HISTORICAL_IMPORT_PROVIDER_BINDING_MISSING');

            return;
        }
        if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account)
            !== $this->expectedProviderBindingVersion) {
            $this->finishStale($run, 'HISTORICAL_IMPORT_PROVIDER_BINDING_STALE');

            return;
        }

        try {
            $authorization->authorize($actor, $account);
        } catch (Throwable) {
            $this->finishCancelled($run, 'HISTORICAL_IMPORT_AUTHORIZATION_REVOKED');

            return;
        }

        if (! $policy->permits($run)) {
            $this->finishPolicyStale($run);

            return;
        }
        if (! $storageReadiness->check()['safe']) {
            $this->finishStorageFailed($run);

            return;
        }

        $client = $this->makeImapClient($account);

        try {
            $client->connect();

            while (true) {
                $run = EmailHistoricalImportRun::query()->find($this->runId);
                if (! $run || $run->terminal()) {
                    return;
                }
                if ($run->status === EmailHistoricalImportRun::STATUS_CANCELLING
                    || $run->cancellation_requested_at) {
                    $this->finishCancelled($run);

                    return;
                }

                $currentActor = User::query()->whereKey($run->requested_by)->first();
                $currentAccount = EmailAccount::query()->whereKey($run->account_id)->first();
                try {
                    if (! $currentActor || ! $currentAccount) {
                        throw new RuntimeException('Historical import authorization is no longer available.');
                    }
                    $authorization->authorize($currentActor, $currentAccount);
                } catch (Throwable) {
                    $this->finishCancelled($run, 'HISTORICAL_IMPORT_AUTHORIZATION_REVOKED');

                    return;
                }
                $account = $currentAccount;

                if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($currentAccount)
                    !== $this->expectedProviderBindingVersion) {
                    $this->finishStale($run, 'HISTORICAL_IMPORT_PROVIDER_BINDING_STALE');

                    return;
                }

                if (! $policy->permits($run)) {
                    $this->finishPolicyStale($run);

                    return;
                }
                if (! $storageReadiness->check()['safe']) {
                    $this->finishStorageFailed($run);

                    return;
                }

                // Every batch starts from the exact immutable metadata-only
                // preview. A changed namespace or provider snapshot is stale,
                // never a reason to widen the historical scope.
                if (! $snapshotVerifier->verify($run, $client)) {
                    $this->finishStale($run, 'HISTORICAL_IMPORT_SNAPSHOT_CHANGED');

                    return;
                }

                $items = $run->items()
                    ->where('status', EmailHistoricalImportItem::STATUS_PENDING)
                    ->orderBy('id')
                    ->limit(EmailHistoricalImportRun::MAX_BATCH_SIZE)
                    ->get();

                if ($items->isEmpty()) {
                    $this->finishCompleted($run);

                    return;
                }

                foreach ($items as $item) {
                    try {
                        $this->processItem($run, $account, $client, $item);
                    } catch (HistoricalImportStaleException) {
                        $this->markItemSkippedStale($item);
                        $this->finishStale($run, 'HISTORICAL_IMPORT_ITEM_STALE');

                        return;
                    } catch (HistoricalImportStorageIncompleteException $exception) {
                        $this->markItemFailed($item, 'HISTORICAL_IMPORT_STORAGE_INCOMPLETE');
                        Log::warning('Historical Email import storage evidence was incomplete.', [
                            'historical_import_run_id' => $run->id,
                            'historical_import_item_id' => $item->id,
                            'error_code' => 'HISTORICAL_IMPORT_STORAGE_INCOMPLETE',
                            'exception' => $exception::class,
                        ]);
                    } catch (Throwable $exception) {
                        $attempts = $this->recordTransientFailure($item);
                        Log::warning('Historical Email import item failed.', [
                            'historical_import_run_id' => $run->id,
                            'historical_import_item_id' => $item->id,
                            'error_code' => 'HISTORICAL_IMPORT_PROVIDER_READ_FAILED',
                            'exception' => $exception::class,
                        ]);

                        if ($attempts < $this->maxExceptions) {
                            throw new RuntimeException('Historical Email import provider read failed.');
                        }

                        $this->markItemFailed($item, 'HISTORICAL_IMPORT_PROVIDER_READ_FAILED');
                    }
                }

                $this->refreshCounters($run);
            }
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = EmailHistoricalImportRun::query()->find($this->runId);
        if (! $run || $run->terminal()) {
            return;
        }

        $this->refreshCounters($run);
        $run->forceFill([
            'status' => EmailHistoricalImportRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_code' => 'HISTORICAL_IMPORT_RETRY_EXHAUSTED',
            'error_message' => 'The provider could not complete the bounded historical import.',
        ])->save();
    }

    private function claimRun(): ?EmailHistoricalImportRun
    {
        return DB::transaction(function (): ?EmailHistoricalImportRun {
            $run = EmailHistoricalImportRun::query()->lockForUpdate()->find($this->runId);
            if (! $run || $run->terminal()) {
                return $run;
            }

            if ($run->status === EmailHistoricalImportRun::STATUS_QUEUED) {
                $run->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_RUNNING,
                    'started_at' => $run->started_at ?? now(),
                ])->save();
            } elseif (! in_array($run->status, [
                EmailHistoricalImportRun::STATUS_RUNNING,
                EmailHistoricalImportRun::STATUS_CANCELLING,
            ], true)) {
                $run->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_FAILED,
                    'finished_at' => now(),
                    'error_code' => 'HISTORICAL_IMPORT_INVALID_JOB_STATE',
                    'error_message' => 'The queued historical import did not have an executable state.',
                ])->save();
            }

            return $run->refresh();
        });
    }

    private function processItem(
        EmailHistoricalImportRun $run,
        EmailAccount $account,
        ImapClient $client,
        EmailHistoricalImportItem $item,
    ): void {
        $folder = EmailFolder::query()
            ->whereKey($item->email_folder_id)
            ->where('account_id', $account->id)
            ->where('active_uid_namespace_id', $item->uid_namespace_id)
            ->where('uid_validity', $item->uid_validity)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->first();
        $namespaceIsCurrent = EmailFolderUidNamespace::query()
            ->whereKey($item->uid_namespace_id)
            ->where('account_id', $account->id)
            ->where('email_folder_id', $item->email_folder_id)
            ->where('uid_validity', $item->uid_validity)
            ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
            ->exists();

        if (! $folder || ! $namespaceIsCurrent || (string) $folder->path !== (string) $item->folder_path) {
            throw new HistoricalImportStaleException;
        }

        $existing = EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_namespace_id', $item->uid_namespace_id)
            ->where('imap_uid', $item->imap_uid)
            ->first();
        if ($existing) {
            $this->completeItem($item, EmailHistoricalImportItem::STATUS_ALREADY_PRESENT, $existing->message_id, $existing->id);

            return;
        }

        $this->beginAttempt($item);
        $before = $client->folderState($folder->path);
        if ((int) ($before['uid_validity'] ?? 0) !== (int) $item->uid_validity) {
            throw new HistoricalImportStaleException;
        }

        $payload = $client->payloadByUid((int) $item->imap_uid, $folder->path);
        $after = $client->folderState($folder->path);
        if (! is_array($payload)
            || (int) ($payload['imap_uid'] ?? 0) !== (int) $item->imap_uid
            || (int) ($after['uid_validity'] ?? 0) !== (int) $item->uid_validity) {
            throw new HistoricalImportStaleException;
        }

        $limitMb = max(1, (int) (CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', 'size_limit_mb')
            ->value('value') ?? 25));
        $oversize = isset($payload['size_bytes'])
            && (int) $payload['size_bytes'] > $limitMb * 1024 * 1024;

        $storePayload = array_merge($payload, [
            'account_id' => $account->id,
            'mailbox' => $folder->path,
            'uid_validity' => (int) $item->uid_validity,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $item->uid_namespace_id,
            'is_oversize' => $oversize,
            'run_inbound_rules' => false,
            'run_provider_reconciliation' => false,
            'allow_provider_mutation' => false,
            'require_exact_provider_identity' => true,
            'historical_import_run_id' => $run->id,
            'provider_binding_version' => $this->expectedProviderBindingVersion,
        ]);
        EmailAccountProviderLockContext::withinHeld(
            (int) $account->id,
            fn () => StoreInboundMessage::dispatchSync($storePayload),
        );

        $message = EmailMessage::query()
            ->withTrashed()
            ->where('account_id', $account->id)
            ->where('mailbox', $folder->path)
            ->where('imap_uid_validity', $item->uid_validity)
            ->where('imap_uid', $item->imap_uid)
            ->first();
        $placement = EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('email_folder_id', $folder->id)
            ->where('uid_namespace_id', $item->uid_namespace_id)
            ->where('imap_uid', $item->imap_uid)
            ->first();

        if (! $message || ! $placement || (int) $placement->email_message_id !== (int) $message->id) {
            throw new RuntimeException('Historical message projection did not produce exact namespace evidence.');
        }

        $this->assertStoredArtifacts($message, $oversize);

        $this->completeItem($item, EmailHistoricalImportItem::STATUS_IMPORTED, $message->id, $placement->id);
    }

    private function beginAttempt(EmailHistoricalImportItem $item): void
    {
        EmailHistoricalImportItem::query()
            ->whereKey($item->id)
            ->where('status', EmailHistoricalImportItem::STATUS_PENDING)
            ->update([
                'attempt_count' => DB::raw('attempt_count + 1'),
                'first_attempt_at' => $item->first_attempt_at ?? now(),
                'last_attempt_at' => now(),
                'error_code' => null,
                'updated_at' => now(),
            ]);
    }

    private function completeItem(EmailHistoricalImportItem $item, string $status, ?int $messageId, ?int $placementId): void
    {
        EmailHistoricalImportItem::query()
            ->whereKey($item->id)
            ->where('status', EmailHistoricalImportItem::STATUS_PENDING)
            ->update([
                'status' => $status,
                'email_message_id' => $messageId,
                'email_mailbox_placement_id' => $placementId,
                'completed_at' => now(),
                'error_code' => null,
                'updated_at' => now(),
            ]);
    }

    private function recordTransientFailure(EmailHistoricalImportItem $item): int
    {
        $current = EmailHistoricalImportItem::query()->find($item->id);
        $attempts = max(1, (int) $current?->attempt_count);
        EmailHistoricalImportItem::query()
            ->whereKey($item->id)
            ->where('status', EmailHistoricalImportItem::STATUS_PENDING)
            ->update([
                'last_attempt_at' => now(),
                'error_code' => 'HISTORICAL_IMPORT_PROVIDER_READ_FAILED',
                'updated_at' => now(),
            ]);

        return $attempts;
    }

    private function markItemFailed(EmailHistoricalImportItem $item, string $errorCode): void
    {
        EmailHistoricalImportItem::query()->whereKey($item->id)->update([
            'status' => EmailHistoricalImportItem::STATUS_FAILED,
            'completed_at' => now(),
            'error_code' => $errorCode,
            'updated_at' => now(),
        ]);
    }

    private function assertStoredArtifacts(EmailMessage $message, bool $oversize): void
    {
        $disk = Storage::disk('local');
        if (! $oversize
            && (blank($message->raw_path) || ! $disk->exists((string) $message->raw_path))) {
            throw new HistoricalImportStorageIncompleteException;
        }

        $attachments = $message->attachments()->get(['id', 'path']);
        if ($attachments->count() !== (int) $message->attachments_count
            || $attachments->contains(fn ($attachment): bool => blank($attachment->path)
                || ! $disk->exists((string) $attachment->path))) {
            throw new HistoricalImportStorageIncompleteException;
        }
    }

    private function markItemSkippedStale(EmailHistoricalImportItem $item): void
    {
        EmailHistoricalImportItem::query()
            ->whereKey($item->id)
            ->where('status', EmailHistoricalImportItem::STATUS_PENDING)
            ->update([
                'status' => EmailHistoricalImportItem::STATUS_SKIPPED_STALE,
                'completed_at' => now(),
                'error_code' => 'HISTORICAL_IMPORT_ITEM_STALE',
                'updated_at' => now(),
            ]);
    }

    private function refreshCounters(EmailHistoricalImportRun $run): void
    {
        $counts = $run->items()
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $skipped = (int) $counts->get(EmailHistoricalImportItem::STATUS_SKIPPED_OUT_OF_SCOPE, 0)
            + (int) $counts->get(EmailHistoricalImportItem::STATUS_SKIPPED_STALE, 0);

        EmailHistoricalImportRun::query()->whereKey($run->id)->update([
            'pending_count' => (int) $counts->get(EmailHistoricalImportItem::STATUS_PENDING, 0),
            'already_present_count' => (int) $counts->get(EmailHistoricalImportItem::STATUS_ALREADY_PRESENT, 0),
            'imported_count' => (int) $counts->get(EmailHistoricalImportItem::STATUS_IMPORTED, 0),
            'skipped_count' => $skipped,
            'failed_count' => (int) $counts->get(EmailHistoricalImportItem::STATUS_FAILED, 0),
            'updated_at' => now(),
        ]);
    }

    private function finishCompleted(EmailHistoricalImportRun $run): void
    {
        $this->refreshCounters($run);
        $run->refresh();
        $run->forceFill([
            'status' => $run->failed_count > 0
                ? EmailHistoricalImportRun::STATUS_PARTIAL
                : EmailHistoricalImportRun::STATUS_COMPLETED,
            'finished_at' => now(),
            'error_code' => $run->failed_count > 0 ? 'HISTORICAL_IMPORT_PARTIAL' : null,
            'error_message' => $run->failed_count > 0
                ? 'Some bounded historical items could not be imported.'
                : null,
        ])->save();
    }

    private function finishCancelled(EmailHistoricalImportRun $run, ?string $code = null): void
    {
        $this->refreshCounters($run);
        $run->forceFill([
            'status' => EmailHistoricalImportRun::STATUS_CANCELLED,
            'cancellation_requested_at' => $run->cancellation_requested_at ?? now(),
            'finished_at' => now(),
            'error_code' => $code,
            'error_message' => $code ? 'Mailbox maintenance authorization was revoked before completion.' : null,
        ])->save();
    }

    private function finishStale(EmailHistoricalImportRun $run, string $code): void
    {
        $this->refreshCounters($run);
        $run->forceFill([
            'status' => EmailHistoricalImportRun::STATUS_STALE,
            'finished_at' => now(),
            'error_code' => $code,
            'error_message' => 'The provider UID namespace or metadata snapshot changed. No wider scope was imported.',
        ])->save();
    }

    private function finishPolicyStale(EmailHistoricalImportRun $run): void
    {
        $this->refreshCounters($run);
        $run->forceFill([
            'status' => EmailHistoricalImportRun::STATUS_STALE,
            'finished_at' => now(),
            'error_code' => 'HISTORICAL_IMPORT_POLICY_CAP_CHANGED',
            'error_message' => 'The installation historical import cap changed after preview. Preview a narrower scope.',
        ])->save();
    }

    private function finishStorageFailed(EmailHistoricalImportRun $run): void
    {
        $this->refreshCounters($run);
        $run->forceFill([
            'status' => EmailHistoricalImportRun::STATUS_FAILED,
            'finished_at' => now(),
            'error_code' => EmailHistoricalImportStorageReadiness::FAILURE_CODE,
            'error_message' => 'Private raw-message and attachment storage became unavailable before a batch.',
        ])->save();
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $this->expectedProviderBindingVersion,
        ]);
    }
}

class HistoricalImportStaleException extends RuntimeException {}

class HistoricalImportStorageIncompleteException extends RuntimeException {}
