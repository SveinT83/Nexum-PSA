<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCursorRebaselineRun;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailCursorRebaselineBlockers;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailMailboxMaintenanceAuthorization;
use App\Modules\Email\Services\EmailMailboxMaintenanceFingerprint;
use App\Modules\Email\Services\EmailMailboxMaintenanceLock;
use App\Modules\Email\Services\ImapClient;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PreviewEmailCursorRebaseline
{
    protected int $expectedProviderBindingVersion = 1;

    public function __construct(
        private readonly EmailMailboxMaintenanceAuthorization $authorization,
        private readonly EmailMailboxMaintenanceFingerprint $fingerprints,
        private readonly EmailMailboxMaintenanceLock $locks,
        private readonly EmailCursorRebaselineBlockers $blockers,
    ) {}

    public function handle(
        EmailAccount $account,
        EmailFolder $folder,
        User $actor,
        string $reason,
    ): EmailCursorRebaselineRun {
        $this->authorization->authorizeFolder($actor, $account, $folder);
        $reason = $this->normalizeReason($reason);

        try {
            $lock = $this->locks->acquire((int) $account->id);
        } catch (ValidationException) {
            return $this->storeAudit(
                $account,
                $folder,
                $actor,
                $reason,
                EmailCursorRebaselineRun::STATUS_BLOCKED,
                null,
                ['ACTIVE_PROVIDER_LOCK'],
            );
        }

        $this->expectedProviderBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
            ->captureBindingVersion($account);
        $client = $this->makeImapClient($account);
        try {
            $client->connect();
            $start = $this->providerState($client->folderState($folder->path));
            $end = $this->providerState($client->folderState($folder->path));

            if ($start !== $end) {
                return $this->storeAudit(
                    $account,
                    $folder,
                    $actor,
                    $reason,
                    EmailCursorRebaselineRun::STATUS_BLOCKED,
                    $start,
                    ['UNSTABLE_PROVIDER_STATE'],
                );
            }

            $blockers = $this->blockers->forFolder($folder);
            if ($start['uid_validity'] <= 0 || $start['next_uid'] <= 0) {
                $blockers[] = 'INVALID_PROVIDER_STATE';
            }

            $oldValidity = $this->oldUidValidity($folder);
            $hasDocumentedRecovery = $this->hasDocumentedRecoveryCondition($folder);
            if ($start['uid_validity'] > 0
                && $oldValidity > 0
                && $start['uid_validity'] === $oldValidity
                && ! $hasDocumentedRecovery) {
                $blockers[] = 'UNCHANGED_UIDVALIDITY_WITHOUT_FAILURE';
            }

            $blockers = array_values(array_unique($blockers));
            sort($blockers);

            return $this->storeAudit(
                $account,
                $folder,
                $actor,
                $reason,
                $blockers === []
                    ? EmailCursorRebaselineRun::STATUS_PREVIEWED
                    : EmailCursorRebaselineRun::STATUS_BLOCKED,
                $start,
                $blockers,
            );
        } catch (Throwable) {
            return $this->storeAudit(
                $account,
                $folder,
                $actor,
                $reason,
                EmailCursorRebaselineRun::STATUS_FAILED,
                null,
                ['PROVIDER_STATE_UNAVAILABLE'],
            );
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
            $lock->release();
        }
    }

    private function storeAudit(
        EmailAccount $account,
        EmailFolder $folder,
        User $actor,
        string $reason,
        string $status,
        ?array $providerState,
        array $blockers,
    ): EmailCursorRebaselineRun {
        return DB::transaction(function () use (
            $account,
            $folder,
            $actor,
            $reason,
            $status,
            $providerState,
            $blockers,
        ): EmailCursorRebaselineRun {
            $currentActor = User::query()->whereKey($actor->id)->first();
            $currentAccount = EmailAccount::query()->lockForUpdate()->find($account->id);
            $currentFolder = EmailFolder::query()
                ->whereKey($folder->id)
                ->where('account_id', $account->id)
                ->lockForUpdate()
                ->first();

            if (! $currentActor || ! $currentAccount || ! $currentFolder) {
                throw new AuthorizationException('Mailbox maintenance is unavailable.');
            }
            $this->authorization->authorizeFolder($currentActor, $currentAccount, $currentFolder);

            $currentBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
                ->bindingVersion($currentAccount);
            $providerBindingVersion = $providerState === null
                ? $currentBindingVersion
                : $this->expectedProviderBindingVersion;
            if ($providerState !== null && $currentBindingVersion !== $providerBindingVersion) {
                $status = EmailCursorRebaselineRun::STATUS_FAILED;
                $blockers = array_values(array_unique([...$blockers, 'PROVIDER_BINDING_CHANGED']));
                sort($blockers);
                $providerState = null;
            }

            $oldNamespace = $currentFolder->active_uid_namespace_id
                ? EmailFolderUidNamespace::query()->find($currentFolder->active_uid_namespace_id)
                : null;
            $oldValidity = (int) ($oldNamespace?->uid_validity ?? $currentFolder->uid_validity ?? 0);
            $oldPlacementCount = $oldNamespace
                ? EmailMailboxPlacement::query()
                    ->where('account_id', $currentAccount->id)
                    ->where('email_folder_id', $currentFolder->id)
                    ->where('uid_namespace_id', $oldNamespace->id)
                    ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->count()
                : 0;
            $snapshot = [
                'folder_id' => (int) $currentFolder->id,
                'folder_path' => (string) $currentFolder->path,
                'folder_updated_at' => $currentFolder->updated_at?->toJSON(),
                'old_uid_namespace_id' => $oldNamespace?->id,
                'old_uid_namespace_generation' => $oldNamespace?->generation,
                'old_uid_validity' => $oldValidity,
                'old_live_start_uid' => $currentFolder->live_start_uid,
                'old_placement_count' => $oldPlacementCount,
                'provider' => $providerState,
            ];
            $fingerprint = $this->fingerprints->make([
                'account_id' => (int) $currentAccount->id,
                'provider_binding_version' => $providerBindingVersion,
                'folder_id' => (int) $currentFolder->id,
                'reason' => $reason,
                'snapshot' => $snapshot,
                'blockers' => $blockers,
            ]);

            $attributes = [
                'account_id' => $currentAccount->id,
                'email_folder_id' => $currentFolder->id,
                'requested_by' => $currentActor->id,
                'reason' => $reason,
                'status' => $status,
                'idempotency_key' => hash('sha256', 'cursor-rebaseline-preview:'.Str::uuid()),
                'preview_fingerprint' => $fingerprint,
                'old_uid_namespace_id' => $oldNamespace?->id,
                'old_uid_validity' => $oldValidity ?: null,
                'observed_uid_validity' => $providerState['uid_validity'] ?? null,
                'observed_uid_next' => $providerState['next_uid'] ?? null,
                'old_live_start_uid' => $currentFolder->live_start_uid,
                'new_live_start_uid' => isset($providerState['next_uid'])
                    ? max(0, (int) $providerState['next_uid'] - 1)
                    : null,
                'old_placement_count' => $oldPlacementCount,
                'provider_snapshot_json' => $snapshot,
                'blocker_codes_json' => $blockers,
                'preview_expires_at' => now()->addMinutes(EmailCursorRebaselineRun::PREVIEW_TTL_MINUTES),
                'finished_at' => in_array($status, [
                    EmailCursorRebaselineRun::STATUS_BLOCKED,
                    EmailCursorRebaselineRun::STATUS_FAILED,
                ], true) ? now() : null,
                'error_code' => $status === EmailCursorRebaselineRun::STATUS_FAILED
                    ? 'CURSOR_REBASELINE_PROVIDER_READ_FAILED'
                    : ($status === EmailCursorRebaselineRun::STATUS_BLOCKED ? 'CURSOR_REBASELINE_BLOCKED' : null),
                'error_message' => $status === EmailCursorRebaselineRun::STATUS_FAILED
                    ? 'The provider folder state could not be read safely.'
                    : null,
            ];
            if (Schema::hasColumn('email_cursor_rebaseline_runs', 'provider_binding_version')) {
                $attributes['provider_binding_version'] = $providerBindingVersion;
            }

            return EmailCursorRebaselineRun::query()->create($attributes);
        });
    }

    private function normalizeReason(string $reason): string
    {
        $reason = trim(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $reason) ?? '');
        if (mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
            throw ValidationException::withMessages([
                'reason' => 'Enter a recovery reason between 10 and 1000 characters.',
            ]);
        }

        return $reason;
    }

    private function oldUidValidity(EmailFolder $folder): int
    {
        if ($folder->active_uid_namespace_id) {
            return (int) EmailFolderUidNamespace::query()
                ->whereKey($folder->active_uid_namespace_id)
                ->value('uid_validity');
        }

        return (int) $folder->uid_validity;
    }

    private function hasDocumentedRecoveryCondition(EmailFolder $folder): bool
    {
        $code = mb_strtoupper(trim((string) $folder->sync_error_code));

        return $folder->sync_status === EmailFolder::SYNC_ERROR
            && ($code !== '')
            && (str_contains($code, 'UIDVALIDITY')
                || in_array($code, [
                    'IMAP_FOLDER_STATE',
                    'IMAP_MAILBOX_STATE',
                    'IMAP_UID_NAMESPACE_MISSING',
                ], true));
    }

    /** @return array{uid_validity: int, next_uid: int, highest_modseq: int|null, exists_count: int|null} */
    private function providerState(array $state): array
    {
        return [
            'uid_validity' => (int) ($state['uid_validity'] ?? 0),
            'next_uid' => (int) ($state['next_uid'] ?? 0),
            'highest_modseq' => isset($state['highest_modseq']) ? (int) $state['highest_modseq'] : null,
            'exists_count' => isset($state['exists_count']) ? (int) $state['exists_count'] : null,
        ];
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $this->expectedProviderBindingVersion,
        ]);
    }
}
