<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Jobs\ImportHistoricalEmailMessages;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailHistoricalImportRun;
use App\Modules\Email\Services\EmailHistoricalImportPolicy;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailHistoricalImportSnapshotVerifier;
use App\Modules\Email\Services\EmailHistoricalImportStorageReadiness;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;
use App\Modules\Email\Services\EmailMailboxMaintenanceFingerprint;
use App\Modules\Email\Services\EmailMailboxMaintenanceLock;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class StartEmailHistoricalImport
{
    protected int $expectedProviderBindingVersion = 1;

    public function __construct(
        private readonly EmailMailboxMaintenanceAuthorization $authorization,
        private readonly EmailMailboxMaintenanceFingerprint $fingerprints,
        private readonly EmailMailboxMaintenanceLock $locks,
        private readonly EmailHistoricalImportSnapshotVerifier $snapshotVerifier,
        private readonly EmailHistoricalImportPolicy $policy,
        private readonly EmailHistoricalImportStorageReadiness $storageReadiness,
    ) {}

    public function handle(
        EmailAccount $account,
        EmailHistoricalImportRun $run,
        User $actor,
        string $previewFingerprint,
    ): EmailHistoricalImportRun {
        $this->assertNestedScope($account, $run, $actor);

        if ($run->status !== EmailHistoricalImportRun::STATUS_PREVIEWED) {
            return $this->returnIdempotentOrFail($run, $previewFingerprint);
        }

        $this->assertCurrentExecutionPolicy($run);
        $this->assertStorageReady($run);

        $lock = $this->locks->acquire((int) $account->id);
        $this->expectedProviderBindingVersion = (int) $run->provider_binding_version;
        if ($this->expectedProviderBindingVersion < 1) {
            $lock->release();
            $this->markProviderBindingStale($run);

            throw ValidationException::withMessages([
                'mailbox' => 'The mailbox provider binding snapshot is missing. Preview again.',
            ]);
        }
        if (app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
            !== $this->expectedProviderBindingVersion) {
            $lock->release();
            $this->markProviderBindingStale($run);

            throw ValidationException::withMessages([
                'mailbox' => 'The mailbox provider binding changed after preview. Preview again.',
            ]);
        }
        $client = $this->makeImapClient($account);

        try {
            $client->connect();
            $providerIsCurrent = $this->snapshotVerifier->verify($run->fresh(), $client);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'mailbox' => 'The preview could not be verified against the provider. Preview again.',
            ]);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
            $lock->release();
        }

        if (! $providerIsCurrent) {
            EmailHistoricalImportRun::query()
                ->whereKey($run->id)
                ->where('status', EmailHistoricalImportRun::STATUS_PREVIEWED)
                ->update([
                    'status' => EmailHistoricalImportRun::STATUS_STALE,
                    'finished_at' => now(),
                    'error_code' => 'HISTORICAL_IMPORT_SNAPSHOT_CHANGED',
                    'error_message' => 'The metadata-only provider snapshot changed before confirmation.',
                    'updated_at' => now(),
                ]);

            throw ValidationException::withMessages([
                'mailbox' => 'The provider mailbox changed after preview. Preview again.',
            ]);
        }

        $dispatch = false;
        $queued = DB::transaction(function () use ($account, $run, $actor, $previewFingerprint, &$dispatch): EmailHistoricalImportRun {
            $locked = EmailHistoricalImportRun::query()->lockForUpdate()->findOrFail($run->id);
            $currentActor = User::query()->whereKey($actor->id)->first();
            $currentAccount = EmailAccount::query()->lockForUpdate()->find($account->id);
            if (! $currentActor || ! $currentAccount
                || (int) $locked->account_id !== (int) $currentAccount->id
                || (int) $locked->requested_by !== (int) $currentActor->id) {
                throw new AuthorizationException('Mailbox maintenance record not found.');
            }
            $this->authorization->authorize($currentActor, $currentAccount);

            if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($currentAccount)
                !== $this->expectedProviderBindingVersion) {
                $locked->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_STALE,
                    'finished_at' => now(),
                    'error_code' => 'HISTORICAL_IMPORT_PROVIDER_BINDING_STALE',
                    'error_message' => 'The mailbox provider binding changed after preview.',
                ])->save();

                return $locked->refresh();
            }

            if ($locked->status !== EmailHistoricalImportRun::STATUS_PREVIEWED) {
                return $this->returnIdempotentOrFail($locked, $previewFingerprint);
            }

            if ($locked->previewExpired()) {
                $locked->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_STALE,
                    'finished_at' => now(),
                    'error_code' => 'HISTORICAL_IMPORT_PREVIEW_EXPIRED',
                    'error_message' => 'The historical import preview expired before confirmation.',
                ])->save();

                return $locked->refresh();
            }

            if (! hash_equals((string) $locked->preview_fingerprint, $previewFingerprint)
                || ! hash_equals((string) $locked->preview_fingerprint, $this->storedFingerprint($locked))) {
                $locked->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_STALE,
                    'finished_at' => now(),
                    'error_code' => 'HISTORICAL_IMPORT_FINGERPRINT_CHANGED',
                    'error_message' => 'The confirmed preview fingerprint did not match durable evidence.',
                ])->save();

                return $locked->refresh();
            }

            if (! $this->policy->permits($locked)) {
                $locked->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_STALE,
                    'finished_at' => now(),
                    'error_code' => 'HISTORICAL_IMPORT_POLICY_CAP_CHANGED',
                    'error_message' => 'The installation historical import cap changed after preview.',
                ])->save();

                return $locked->refresh();
            }

            if (! $this->storageReadiness->check()['safe']) {
                $locked->forceFill([
                    'status' => EmailHistoricalImportRun::STATUS_FAILED,
                    'finished_at' => now(),
                    'error_code' => EmailHistoricalImportStorageReadiness::FAILURE_CODE,
                    'error_message' => 'Private raw-message and attachment storage must be writable before import.',
                ])->save();

                return $locked->refresh();
            }

            $locked->forceFill([
                'status' => EmailHistoricalImportRun::STATUS_QUEUED,
                'queued_at' => now(),
                'error_code' => null,
                'error_message' => null,
            ])->save();
            $dispatch = true;

            return $locked->refresh();
        });

        if ($queued->status === EmailHistoricalImportRun::STATUS_STALE) {
            throw ValidationException::withMessages([
                'preview' => 'The preview is no longer executable. Preview again under the current policy.',
            ]);
        }
        if ($queued->status === EmailHistoricalImportRun::STATUS_FAILED
            && $queued->error_code === EmailHistoricalImportStorageReadiness::FAILURE_CODE) {
            throw ValidationException::withMessages([
                'storage' => 'Private raw-message and attachment storage is not writable.',
            ]);
        }

        if ($dispatch) {
            ImportHistoricalEmailMessages::dispatch((int) $queued->id)->onQueue('email');
        }

        return $queued;
    }

    private function assertNestedScope(EmailAccount $account, EmailHistoricalImportRun $run, User $actor): void
    {
        $this->authorization->authorize($actor, $account);

        if ((int) $run->account_id !== (int) $account->id
            || (int) $run->requested_by !== (int) $actor->id) {
            throw new AuthorizationException('Mailbox maintenance record not found.');
        }
    }

    private function returnIdempotentOrFail(EmailHistoricalImportRun $run, string $fingerprint): EmailHistoricalImportRun
    {
        if (hash_equals((string) $run->preview_fingerprint, $fingerprint)
            && in_array($run->status, [
                EmailHistoricalImportRun::STATUS_QUEUED,
                EmailHistoricalImportRun::STATUS_RUNNING,
                EmailHistoricalImportRun::STATUS_CANCELLING,
                EmailHistoricalImportRun::STATUS_COMPLETED,
                EmailHistoricalImportRun::STATUS_PARTIAL,
                EmailHistoricalImportRun::STATUS_CANCELLED,
            ], true)) {
            return $run;
        }

        throw ValidationException::withMessages(['preview' => 'This preview can no longer be started.']);
    }

    private function assertCurrentExecutionPolicy(EmailHistoricalImportRun $run): void
    {
        if ($this->policy->permits($run)) {
            return;
        }

        EmailHistoricalImportRun::query()
            ->whereKey($run->id)
            ->where('status', EmailHistoricalImportRun::STATUS_PREVIEWED)
            ->update([
                'status' => EmailHistoricalImportRun::STATUS_STALE,
                'finished_at' => now(),
                'error_code' => 'HISTORICAL_IMPORT_POLICY_CAP_CHANGED',
                'error_message' => 'The installation historical import cap changed after preview.',
                'updated_at' => now(),
            ]);

        throw ValidationException::withMessages([
            'cap' => 'The preview exceeds the current historical import cap. Preview a narrower scope.',
        ]);
    }

    private function assertStorageReady(EmailHistoricalImportRun $run): void
    {
        if ($this->storageReadiness->check()['safe']) {
            return;
        }

        EmailHistoricalImportRun::query()
            ->whereKey($run->id)
            ->where('status', EmailHistoricalImportRun::STATUS_PREVIEWED)
            ->update([
                'status' => EmailHistoricalImportRun::STATUS_FAILED,
                'finished_at' => now(),
                'error_code' => EmailHistoricalImportStorageReadiness::FAILURE_CODE,
                'error_message' => 'Private raw-message and attachment storage must be writable before import.',
                'updated_at' => now(),
            ]);

        throw ValidationException::withMessages([
            'storage' => 'Private raw-message and attachment storage is not writable.',
        ]);
    }

    private function storedFingerprint(EmailHistoricalImportRun $run): string
    {
        $items = $run->items()
            ->orderBy('id')
            ->get()
            ->map(fn ($item): array => [
                'folder_id' => (int) $item->email_folder_id,
                'uid_namespace_id' => (int) $item->uid_namespace_id,
                'uid_validity' => (int) $item->uid_validity,
                'imap_uid' => (int) $item->imap_uid,
                'status' => $item->status,
            ])->all();

        return $this->fingerprints->make([
            'account_id' => (int) $run->account_id,
            'provider_binding_version' => (int) $run->provider_binding_version,
            'scope' => [
                'folder_ids' => collect($run->folder_scope_json)->pluck('folder_id')->map(fn ($id): int => (int) $id)->all(),
                'date_from' => $run->date_from->toDateString(),
                'date_to' => $run->date_to->toDateString(),
                'uid_from' => (int) $run->uid_from,
                'uid_to' => $run->uid_to === null ? null : (int) $run->uid_to,
                'requested_cap' => (int) $run->requested_cap,
                'effective_cap' => (int) $run->effective_cap,
            ],
            'provider_snapshot' => $run->provider_snapshot_json,
            'items' => $items,
        ]);
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $this->expectedProviderBindingVersion,
        ]);
    }

    private function markProviderBindingStale(EmailHistoricalImportRun $run): void
    {
        EmailHistoricalImportRun::query()
            ->whereKey($run->id)
            ->where('status', EmailHistoricalImportRun::STATUS_PREVIEWED)
            ->update([
                'status' => EmailHistoricalImportRun::STATUS_STALE,
                'finished_at' => now(),
                'error_code' => 'HISTORICAL_IMPORT_PROVIDER_BINDING_STALE',
                'error_message' => 'The mailbox provider binding changed after preview.',
                'updated_at' => now(),
            ]);
    }
}
