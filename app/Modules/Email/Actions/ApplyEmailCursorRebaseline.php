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
use Illuminate\Validation\ValidationException;
use Throwable;

class ApplyEmailCursorRebaseline
{
    protected int $expectedProviderBindingVersion = 1;

    public function __construct(
        private readonly EmailMailboxMaintenanceAuthorization $authorization,
        private readonly EmailMailboxMaintenanceFingerprint $fingerprints,
        private readonly EmailMailboxMaintenanceLock $locks,
        private readonly EmailCursorRebaselineBlockers $blockers,
    ) {}

    /** @param array<string, mixed> $confirmation */
    public function handle(
        EmailAccount $account,
        EmailFolder $folder,
        EmailCursorRebaselineRun $run,
        User $actor,
        string $previewFingerprint,
        array $confirmation,
    ): EmailCursorRebaselineRun {
        $this->assertNestedScope($account, $folder, $run, $actor);

        if ($run->status === EmailCursorRebaselineRun::STATUS_COMPLETED
            && hash_equals((string) $run->preview_fingerprint, $previewFingerprint)) {
            return $run;
        }

        if ($run->status !== EmailCursorRebaselineRun::STATUS_PREVIEWED) {
            throw ValidationException::withMessages(['rebaseline' => 'This cursor re-baseline preview cannot be applied.']);
        }

        $this->validateConfirmation($run, $confirmation);
        if ($run->previewExpired()) {
            $this->markStale($run, 'CURSOR_REBASELINE_PREVIEW_EXPIRED');
            throw ValidationException::withMessages(['rebaseline' => 'This preview expired. Preview again.']);
        }
        if (! hash_equals((string) $run->preview_fingerprint, $previewFingerprint)
            || ! hash_equals((string) $run->preview_fingerprint, $this->storedFingerprint($run))) {
            $this->markStale($run, 'CURSOR_REBASELINE_FINGERPRINT_CHANGED');
            throw ValidationException::withMessages(['rebaseline' => 'The preview evidence changed. Preview again.']);
        }

        $lock = $this->locks->acquire((int) $account->id);
        $this->expectedProviderBindingVersion = (int) $run->provider_binding_version;
        if ($this->expectedProviderBindingVersion < 1) {
            $this->markStale($run, 'CURSOR_REBASELINE_PROVIDER_BINDING_MISSING');
            $lock->release();

            throw ValidationException::withMessages([
                'mailbox' => 'The mailbox provider binding snapshot is missing. Preview again.',
            ]);
        }
        if (app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
            !== $this->expectedProviderBindingVersion) {
            $this->markStale($run, 'CURSOR_REBASELINE_PROVIDER_BINDING_STALE');
            $lock->release();

            throw ValidationException::withMessages([
                'mailbox' => 'The mailbox provider binding changed after preview. Preview again.',
            ]);
        }
        $client = $this->makeImapClient($account);
        try {
            $client->connect();
            $start = $this->providerState($client->folderState($folder->path));
            $end = $this->providerState($client->folderState($folder->path));
        } catch (Throwable) {
            $lock->release();
            throw ValidationException::withMessages([
                'mailbox' => 'The provider folder state could not be verified. Preview again.',
            ]);
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
            }
        }

        $expectedProvider = (array) data_get($run->provider_snapshot_json, 'provider', []);
        if ($start !== $end || $start !== $expectedProvider) {
            $this->markStale($run, 'CURSOR_REBASELINE_PROVIDER_STATE_CHANGED');
            $lock->release();
            throw ValidationException::withMessages([
                'mailbox' => 'The provider folder changed after preview. Preview again.',
            ]);
        }

        $blockers = $this->blockers->forFolder($folder->fresh());
        if ($blockers !== []) {
            $run->forceFill([
                'status' => EmailCursorRebaselineRun::STATUS_BLOCKED,
                'blocker_codes_json' => $blockers,
                'finished_at' => now(),
                'error_code' => 'CURSOR_REBASELINE_BLOCKED',
            ])->save();
            $lock->release();

            throw ValidationException::withMessages([
                'rebaseline' => 'Unresolved mailbox operations must finish before re-baseline.',
            ]);
        }

        try {
            $result = DB::transaction(function () use (
                $account,
                $folder,
                $run,
                $actor,
                $previewFingerprint,
                $start,
            ): EmailCursorRebaselineRun {
                $lockedRun = EmailCursorRebaselineRun::query()->lockForUpdate()->findOrFail($run->id);
                if ($lockedRun->status === EmailCursorRebaselineRun::STATUS_COMPLETED
                    && hash_equals((string) $lockedRun->preview_fingerprint, $previewFingerprint)) {
                    return $lockedRun;
                }

                $currentActor = User::query()->whereKey($actor->id)->first();
                $currentAccount = EmailAccount::query()->lockForUpdate()->find($account->id);
                $currentFolder = EmailFolder::query()
                    ->whereKey($folder->id)
                    ->where('account_id', $account->id)
                    ->lockForUpdate()
                    ->first();

                if (! $currentActor || ! $currentAccount || ! $currentFolder) {
                    throw new AuthorizationException('Mailbox maintenance record not found.');
                }
                $this->authorization->authorizeFolder($currentActor, $currentAccount, $currentFolder);

                if (app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($currentAccount)
                    !== $this->expectedProviderBindingVersion) {
                    $lockedRun->forceFill([
                        'status' => EmailCursorRebaselineRun::STATUS_STALE,
                        'finished_at' => now(),
                        'error_code' => 'CURSOR_REBASELINE_PROVIDER_BINDING_STALE',
                        'error_message' => 'The mailbox provider binding changed after preview.',
                    ])->save();

                    return $lockedRun->refresh();
                }

                if ($lockedRun->status !== EmailCursorRebaselineRun::STATUS_PREVIEWED
                    || $lockedRun->previewExpired()
                    || ! hash_equals((string) $lockedRun->preview_fingerprint, $previewFingerprint)
                    || ! $this->localSnapshotMatches($lockedRun, $currentFolder)) {
                    $lockedRun->forceFill([
                        'status' => EmailCursorRebaselineRun::STATUS_STALE,
                        'finished_at' => now(),
                        'error_code' => 'CURSOR_REBASELINE_LOCAL_SNAPSHOT_CHANGED',
                        'error_message' => 'The durable folder or placement baseline changed after preview.',
                    ])->save();

                    return $lockedRun->refresh();
                }

                $oldNamespace = $lockedRun->old_uid_namespace_id
                    ? EmailFolderUidNamespace::query()->lockForUpdate()->find($lockedRun->old_uid_namespace_id)
                    : null;
                if ($oldNamespace
                    && ($oldNamespace->status !== EmailFolderUidNamespace::STATUS_ACTIVE
                        || (int) $currentFolder->active_uid_namespace_id !== (int) $oldNamespace->id)) {
                    $lockedRun->forceFill([
                        'status' => EmailCursorRebaselineRun::STATUS_STALE,
                        'finished_at' => now(),
                        'error_code' => 'CURSOR_REBASELINE_UID_NAMESPACE_CHANGED',
                        'error_message' => 'The UID namespace was already changed by another operation.',
                    ])->save();

                    return $lockedRun->refresh();
                }

                $observedValidity = (int) $start['uid_validity'];
                $observedNextUid = (int) $start['next_uid'];
                $newLiveStartUid = max(0, $observedNextUid - 1);
                $sameProvenNamespace = $oldNamespace
                    && (int) $oldNamespace->uid_validity === $observedValidity;

                if ($sameProvenNamespace && ! $this->hasDocumentedRecoveryCondition($currentFolder)) {
                    $lockedRun->forceFill([
                        'status' => EmailCursorRebaselineRun::STATUS_BLOCKED,
                        'blocker_codes_json' => ['UNCHANGED_UIDVALIDITY_WITHOUT_FAILURE'],
                        'finished_at' => now(),
                        'error_code' => 'CURSOR_REBASELINE_BLOCKED',
                    ])->save();

                    return $lockedRun->refresh();
                }

                $retiredPlacementCount = 0;
                if ($sameProvenNamespace) {
                    // Documented cursor/state recovery inside the same immutable
                    // UID namespace resets only the live high-water. Creating a
                    // second generation for an unchanged UIDVALIDITY would split
                    // one provider identity and violate placement provenance.
                    $newNamespace = $oldNamespace;
                } else {
                    $conflictingNamespace = EmailFolderUidNamespace::query()
                        ->where('email_folder_id', $currentFolder->id)
                        ->where('uid_validity', $observedValidity)
                        ->when($oldNamespace, fn ($query) => $query->whereKeyNot($oldNamespace->id))
                        ->lockForUpdate()
                        ->first();
                    if ($conflictingNamespace) {
                        $lockedRun->forceFill([
                            'status' => EmailCursorRebaselineRun::STATUS_BLOCKED,
                            'blocker_codes_json' => ['UID_NAMESPACE_ALREADY_RECORDED'],
                            'finished_at' => now(),
                            'error_code' => 'CURSOR_REBASELINE_BLOCKED',
                        ])->save();

                        return $lockedRun->refresh();
                    }

                    if ($oldNamespace) {
                        $oldNamespace->forceFill([
                            'status' => EmailFolderUidNamespace::STATUS_SUPERSEDED,
                            'superseded_at' => now(),
                        ])->save();
                    }

                    $newNamespace = EmailFolderUidNamespace::query()->create([
                        'account_id' => $currentAccount->id,
                        'email_folder_id' => $currentFolder->id,
                        'generation' => max(1, (int) EmailFolderUidNamespace::query()
                            ->where('email_folder_id', $currentFolder->id)
                            ->max('generation') + 1),
                        'uid_validity' => $observedValidity,
                        'uid_next_at_establishment' => $observedNextUid,
                        'live_start_uid' => $newLiveStartUid,
                        'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
                        'provenance_code' => 'explicit_cursor_rebaseline',
                        'established_by' => $currentActor->id,
                        'established_at' => now(),
                    ]);

                    if ($oldNamespace) {
                        $retiredPlacementCount = EmailMailboxPlacement::query()
                            ->where('account_id', $currentAccount->id)
                            ->where('email_folder_id', $currentFolder->id)
                            ->where('uid_namespace_id', $oldNamespace->id)
                            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                            ->update([
                                'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
                                'sync_status' => EmailMailboxPlacement::SYNC_SHADOW,
                                'sync_version' => DB::raw('sync_version + 1'),
                                'sync_error_code' => 'IMAP_UID_NAMESPACE_SUPERSEDED',
                                'sync_error_message' => 'Provider mutation is disabled because this placement belongs to a superseded UID namespace.',
                                'updated_at' => now(),
                            ]);
                    }
                }

                $clearFolderError = $this->isMatchingRecoveryError($currentFolder->sync_error_code);
                $currentFolder->forceFill([
                    'active_uid_namespace_id' => $newNamespace->id,
                    'uid_validity' => $observedValidity,
                    'uid_next' => $observedNextUid,
                    'live_start_uid' => $newLiveStartUid,
                    'sync_status' => $clearFolderError || $currentFolder->sync_status !== EmailFolder::SYNC_ERROR
                        ? EmailFolder::SYNC_BASELINED
                        : $currentFolder->sync_status,
                    'last_synced_at' => now(),
                    'sync_error_code' => $clearFolderError ? null : $currentFolder->sync_error_code,
                    'sync_error_message' => $clearFolderError ? null : $currentFolder->sync_error_message,
                ])->save();

                if ($currentFolder->isInbox()) {
                    $clearAccountError = $this->isMatchingRecoveryError($currentAccount->last_error_code);
                    $currentAccount->forceFill([
                        'imap_uid_validity' => $observedValidity,
                        'imap_live_start_uid' => $newLiveStartUid,
                        'imap_live_cursor_initialized_at' => now(),
                        'last_error_code' => $clearAccountError ? null : $currentAccount->last_error_code,
                        'last_error_message' => $clearAccountError ? null : $currentAccount->last_error_message,
                    ])->save();
                }

                $lockedRun->forceFill([
                    'status' => EmailCursorRebaselineRun::STATUS_COMPLETED,
                    'new_uid_namespace_id' => $newNamespace->id,
                    'new_live_start_uid' => $newLiveStartUid,
                    'retired_placement_count' => $retiredPlacementCount,
                    'applied_at' => now(),
                    'finished_at' => now(),
                    'error_code' => null,
                    'error_message' => null,
                ])->save();

                return $lockedRun->refresh();
            });

            if ($result->status === EmailCursorRebaselineRun::STATUS_STALE) {
                throw ValidationException::withMessages([
                    'rebaseline' => 'The durable folder baseline changed after preview. Preview again.',
                ]);
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    private function assertNestedScope(
        EmailAccount $account,
        EmailFolder $folder,
        EmailCursorRebaselineRun $run,
        User $actor,
    ): void {
        $this->authorization->authorizeFolder($actor, $account, $folder);
        if ((int) $run->account_id !== (int) $account->id
            || (int) $run->email_folder_id !== (int) $folder->id
            || (int) $run->requested_by !== (int) $actor->id) {
            throw new AuthorizationException('Mailbox maintenance record not found.');
        }
    }

    /** @param array<string, mixed> $confirmation */
    private function validateConfirmation(EmailCursorRebaselineRun $run, array $confirmation): void
    {
        foreach ([
            'old_uid_validity' => $run->old_uid_validity,
            'observed_uid_validity' => $run->observed_uid_validity,
            'observed_uid_next' => $run->observed_uid_next,
        ] as $key => $expected) {
            if (! array_key_exists($key, $confirmation)
                || ! is_numeric($confirmation[$key])
                || (int) $confirmation[$key] !== (int) $expected) {
                throw ValidationException::withMessages([
                    $key => 'Confirm the exact values shown in the cursor re-baseline preview.',
                ]);
            }
        }
    }

    private function storedFingerprint(EmailCursorRebaselineRun $run): string
    {
        return $this->fingerprints->make([
            'account_id' => (int) $run->account_id,
            'provider_binding_version' => (int) $run->provider_binding_version,
            'folder_id' => (int) $run->email_folder_id,
            'reason' => (string) $run->reason,
            'snapshot' => $run->provider_snapshot_json,
            'blockers' => $run->blocker_codes_json ?? [],
        ]);
    }

    private function localSnapshotMatches(EmailCursorRebaselineRun $run, EmailFolder $folder): bool
    {
        $snapshot = (array) $run->provider_snapshot_json;
        $namespaceId = (int) ($snapshot['old_uid_namespace_id'] ?? 0);
        $expectedPlacementCount = (int) ($snapshot['old_placement_count'] ?? -1);
        $currentPlacementCount = $namespaceId > 0
            ? EmailMailboxPlacement::query()
                ->where('account_id', $folder->account_id)
                ->where('email_folder_id', $folder->id)
                ->where('uid_namespace_id', $namespaceId)
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->lockForUpdate()
                ->get()
                ->count()
            : 0;

        return (int) ($snapshot['folder_id'] ?? 0) === (int) $folder->id
            && (string) ($snapshot['folder_path'] ?? '') === (string) $folder->path
            && (string) ($snapshot['folder_updated_at'] ?? '') === (string) $folder->updated_at?->toJSON()
            && (int) ($snapshot['old_uid_namespace_id'] ?? 0) === (int) $folder->active_uid_namespace_id
            && (int) ($snapshot['old_uid_validity'] ?? 0) === (int) $run->old_uid_validity
            && (int) ($snapshot['old_live_start_uid'] ?? 0) === (int) $folder->live_start_uid
            && $expectedPlacementCount === (int) $run->old_placement_count
            && $currentPlacementCount === $expectedPlacementCount;
    }

    private function hasDocumentedRecoveryCondition(EmailFolder $folder): bool
    {
        return $folder->sync_status === EmailFolder::SYNC_ERROR
            && $this->isMatchingRecoveryError($folder->sync_error_code);
    }

    private function isMatchingRecoveryError(?string $errorCode): bool
    {
        $code = mb_strtoupper(trim((string) $errorCode));

        return $code !== ''
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

    private function markStale(EmailCursorRebaselineRun $run, string $code): void
    {
        EmailCursorRebaselineRun::query()
            ->whereKey($run->id)
            ->where('status', EmailCursorRebaselineRun::STATUS_PREVIEWED)
            ->update([
                'status' => EmailCursorRebaselineRun::STATUS_STALE,
                'finished_at' => now(),
                'error_code' => $code,
                'error_message' => 'The cursor re-baseline preview no longer matches durable provider evidence.',
                'updated_at' => now(),
            ]);
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $this->expectedProviderBindingVersion,
        ]);
    }
}
