<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperationAttempt;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailProviderMessageMissingException;
use App\Modules\Email\Services\EmailProviderReadException;
use App\Modules\Email\Services\EmailProviderRemoteOperationObserver;
use App\Modules\Email\Services\EmailProviderUidNamespaceStaleException;
use App\Modules\Email\Services\EmailRemoteOperationAttemptRecorder;
use App\Modules\Email\Services\EmailRemoteOperationEvidenceSanitizer;
use App\Modules\Email\Services\EmailRemoteOperationReconciler;
use App\Modules\Email\Services\EmailRemoteOperationResultSnapshot;
use App\Modules\Email\Services\EmailRemoteOperationUndoGuard;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailProviderPath;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RunEmailRemoteOperation
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailRemoteOperationAttemptRecorder $attemptRecorder,
        private readonly EmailRemoteOperationEvidenceSanitizer $evidenceSanitizer,
        private readonly EmailRemoteOperationReconciler $reconciler,
        private readonly EmailRemoteOperationResultSnapshot $resultSnapshot,
        private readonly EmailRemoteOperationUndoGuard $undoGuard,
        private readonly EmailProviderRemoteOperationObserver $remoteOperationObserver,
    ) {}

    public function handle(
        EmailRemoteOperation $operation,
        string $trigger = 'initial',
        ?User $triggeredBy = null,
    ): EmailRemoteOperation {
        $capturedScope = EmailRemoteOperation::query()
            ->whereKey($operation->id)
            ->first(['account_id', 'email_mailbox_placement_id']);
        if (! $capturedScope) {
            return $operation;
        }

        $capturedAccountId = (int) $capturedScope->account_id;
        $capturedPlacementId = $capturedScope->email_mailbox_placement_id === null
            ? null
            : (int) $capturedScope->email_mailbox_placement_id;
        $callerPlacementId = $operation->email_mailbox_placement_id === null
            ? null
            : (int) $operation->email_mailbox_placement_id;
        $callerIdentityMatches = (int) $operation->account_id === $capturedAccountId
            && $callerPlacementId === $capturedPlacementId;

        $providerLock = EmailAccountProviderLock::acquire($capturedAccountId, 360);
        if (! $providerLock) {
            // Recording may already have created a durable pending operation.
            // Leave it pending so retry can acquire the shared provider lease;
            // never race an import, poll, or cursor re-baseline.
            return EmailRemoteOperation::query()->find($operation->id) ?: $operation;
        }

        try {
            return $this->handleWithProviderLock(
                $operation,
                $trigger,
                $triggeredBy,
                $capturedAccountId,
                $capturedPlacementId,
                $callerIdentityMatches,
            );
        } finally {
            $providerLock->release();
        }
    }

    private function handleWithProviderLock(
        EmailRemoteOperation $operation,
        string $trigger,
        ?User $triggeredBy,
        int $capturedAccountId,
        ?int $capturedPlacementId,
        bool $callerIdentityMatches,
    ): EmailRemoteOperation {
        $claimed = DB::transaction(function () use (
            $operation,
            $capturedAccountId,
            $capturedPlacementId,
            $callerIdentityMatches,
        ): ?EmailRemoteOperation {
            /** @var EmailMailboxPlacement|null $lockedPlacement */
            $lockedPlacement = $capturedPlacementId === null
                ? null
                : EmailMailboxPlacement::query()->lockForUpdate()->find($capturedPlacementId);

            /** @var EmailRemoteOperation|null $locked */
            $locked = EmailRemoteOperation::query()->lockForUpdate()->find($operation->id);

            if (! $locked || in_array($locked->status, [
                EmailRemoteOperation::STATUS_SUCCEEDED,
                EmailRemoteOperation::STATUS_CANCELLED,
                EmailRemoteOperation::STATUS_SUPERSEDED,
            ], true)) {
                return null;
            }

            if ($locked->status === EmailRemoteOperation::STATUS_RUNNING) {
                return null;
            }

            $lockedPlacementId = $locked->email_mailbox_placement_id === null
                ? null
                : (int) $locked->email_mailbox_placement_id;
            if (! $callerIdentityMatches
                || (int) $locked->account_id !== $capturedAccountId
                || $lockedPlacementId !== $capturedPlacementId
                || ($capturedPlacementId !== null && (! $lockedPlacement
                    || (int) $lockedPlacement->account_id !== (int) $locked->account_id))) {
                $this->supersede(
                    $locked,
                    'REMOTE_OPERATION_RELATION_STALE',
                    'The mailbox placement relationship changed before this operation was claimed.',
                    EmailRemoteOperation::FAILURE_STALE,
                );

                return null;
            }

            $locked->load(['account', 'requester', 'folder']);
            if ($lockedPlacement) {
                $lockedPlacement->load('message');
            }
            $locked->setRelation('placement', $lockedPlacement);

            if ($blocked = $this->preflightBlocker(
                $locked,
                $locked->failure_classification === EmailRemoteOperation::FAILURE_AMBIGUOUS,
            )) {
                $this->supersede($locked, $blocked['code'], $blocked['message'], $blocked['classification']);

                return null;
            }

            if ($locked->failure_classification !== EmailRemoteOperation::FAILURE_AMBIGUOUS
                && $locked->hasReachedAttemptLimit()) {
                $this->fail(
                    $locked,
                    'REMOTE_OPERATION_MAX_ATTEMPTS',
                    'The provider operation reached its maximum attempt count.',
                    EmailRemoteOperation::FAILURE_PERMANENT,
                    false,
                );

                return null;
            }

            $locked->forceFill([
                'status' => EmailRemoteOperation::STATUS_RUNNING,
                'started_at' => now(),
                'failed_at' => null,
                'next_attempt_at' => null,
                'error_code' => null,
                'error_message' => null,
                'status_reason_code' => 'REMOTE_OPERATION_CLAIMED',
                'status_reason_message' => 'The operation is claimed for execution.',
            ])->save();

            return $locked->refresh(['account', 'requester', 'folder', 'placement.message']);
        });

        if (! $claimed) {
            return EmailRemoteOperation::query()->find($operation->id) ?: $operation;
        }

        $operation = $claimed;
        $placement = $operation->placement;
        $account = $operation->account;
        $folder = $operation->folder;
        $expectedBindingVersion = Schema::hasColumn('email_remote_operations', 'provider_binding_version')
            ? (int) $operation->provider_binding_version
            : null;
        $client = app()->makeWith(ImapClient::class, [
            'account' => $account,
            'expectedProviderBindingVersion' => $expectedBindingVersion,
        ]);
        $attempt = null;
        $mutationStarted = false;
        $connected = false;
        $reconciling = false;
        $undoVerification = null;

        try {
            if ($operation->failure_classification === EmailRemoteOperation::FAILURE_AMBIGUOUS) {
                $reconciling = true;
                $attempt = $this->attemptRecorder->start(
                    $operation,
                    EmailRemoteOperationAttempt::KIND_RECONCILIATION,
                    $trigger,
                    $triggeredBy,
                );

                $client->connect();
                $connected = true;
                $reconciliation = $this->reconciler->reconcile($operation, $client);

                $this->attemptRecorder->finish(
                    $attempt,
                    $reconciliation['result'],
                    $reconciliation['result'] === EmailRemoteOperationReconciler::UNRESOLVED
                        ? EmailRemoteOperation::FAILURE_AMBIGUOUS
                        : null,
                    $reconciliation['reason_code'],
                    $reconciliation['reason_message'],
                    $reconciliation['response'],
                );
                $attempt = null;

                if ($reconciliation['result'] === EmailRemoteOperationReconciler::APPLIED) {
                    return $this->succeed(
                        $operation,
                        $reconciliation['response'] + ['reconciled' => true],
                        $reconciliation['reason_code'],
                        $reconciliation['reason_message'],
                        true,
                    );
                }

                if ($reconciliation['result'] === EmailRemoteOperationReconciler::UNRESOLVED) {
                    return $this->fail(
                        $operation,
                        $reconciliation['reason_code'],
                        $reconciliation['reason_message'],
                        EmailRemoteOperation::FAILURE_AMBIGUOUS,
                        false,
                    );
                }

                $reconciling = false;

                $operation->refresh()->load(['account', 'requester', 'folder', 'placement.message']);
                if ($operation->hasReachedAttemptLimit()) {
                    return $this->fail(
                        $operation,
                        'REMOTE_OPERATION_MAX_ATTEMPTS',
                        'Provider reconciliation proved the change was not applied, but the operation reached its maximum provider-attempt count.',
                        EmailRemoteOperation::FAILURE_PERMANENT,
                        false,
                    );
                }

                if ($blocked = $this->preflightBlocker($operation)) {
                    return $this->supersede(
                        $operation,
                        $blocked['code'],
                        $blocked['message'],
                        $blocked['classification'],
                    );
                }
            }

            $attempt = $this->attemptRecorder->start(
                $operation,
                EmailRemoteOperationAttempt::KIND_PREFLIGHT,
                $trigger,
                $triggeredBy,
            );

            $operation->forceFill([
                'last_attempt_at' => now(),
            ])->save();

            if (! $connected) {
                $client->connect();
                $connected = true;
            }

            if ($this->undoGuard->isInverseOperation($operation)) {
                $undoVerification = $this->undoGuard->verifyProvider($operation, $client);
                if (! $undoVerification['verified']) {
                    $this->attemptRecorder->finish(
                        $attempt,
                        'blocked',
                        $undoVerification['classification'],
                        $undoVerification['code'],
                        $undoVerification['message'],
                        ['undo_verification' => $undoVerification['evidence']],
                    );
                    $attempt = null;

                    return $this->supersede(
                        $operation,
                        $undoVerification['code'],
                        $undoVerification['message'],
                        $undoVerification['classification'] ?: EmailRemoteOperation::FAILURE_STALE,
                    );
                }

                $operation->forceFill(['undo_verified_at' => now()])->save();
            }

            $attempt = $this->attemptRecorder->markMutationStarted($attempt);
            $operation->forceFill([
                'attempts' => ((int) $operation->attempts) + 1,
            ])->save();
            $mutationStarted = true;

            $response = match ($operation->operation_type) {
                PerformEmailRemoteOperation::MARK_SEEN => $this->applySeen($client, $operation, $placement, true),
                PerformEmailRemoteOperation::MARK_UNSEEN => $this->applySeen($client, $operation, $placement, false),
                PerformEmailRemoteOperation::FLAG => $this->applyFlagged($client, $operation, $placement, true),
                PerformEmailRemoteOperation::UNFLAG => $this->applyFlagged($client, $operation, $placement, false),
                PerformEmailRemoteOperation::ARCHIVE,
                PerformEmailRemoteOperation::TRASH,
                PerformEmailRemoteOperation::MOVE => $this->applyMove($client, $operation, $placement),
                ManageProviderEmailFolder::RENAME_FOLDER => $this->applyFolderRename($client, $operation, $folder),
                ManageProviderEmailFolder::MOVE_FOLDER => $this->applyFolderRename($client, $operation, $folder),
                ManageProviderEmailFolder::DELETE_FOLDER => $this->applyFolderDelete($client, $operation, $folder),
                default => throw new RuntimeException('Unsupported email remote operation type.'),
            };

            if ($undoVerification) {
                $response['undo_verification'] = [
                    'verified' => true,
                    ...$undoVerification['evidence'],
                ];
            }

            $this->attemptRecorder->finish(
                $attempt,
                'succeeded',
                null,
                'REMOTE_OPERATION_ACKNOWLEDGED',
                'The provider acknowledged the mailbox operation.',
                $response,
            );
            $attempt = null;

            return $this->succeed(
                $operation,
                $response,
                'REMOTE_OPERATION_ACKNOWLEDGED',
                'The provider acknowledged the mailbox operation.',
            );
        } catch (EmailProviderUidNamespaceStaleException $exception) {
            $code = 'REMOTE_OPERATION_UIDVALIDITY_STALE';
            $message = 'The provider UID namespace changed before the mailbox operation.';
            $this->resetMutationAccounting($operation, $mutationStarted);

            if ($attempt) {
                $this->attemptRecorder->finish(
                    $attempt,
                    'blocked',
                    EmailRemoteOperation::FAILURE_STALE,
                    $code,
                    $message,
                    $operation->provider_response_json ?? [],
                    $exception,
                    EmailRemoteOperationAttempt::KIND_PREFLIGHT,
                );
                $attempt = null;
            }

            return $this->fail(
                $operation,
                $code,
                $message,
                EmailRemoteOperation::FAILURE_STALE,
                false,
            );
        } catch (EmailProviderMessageMissingException $exception) {
            $code = 'REMOTE_OPERATION_SOURCE_MISSING';
            $message = 'The source message is no longer present in the provider folder.';
            $this->resetMutationAccounting($operation, $mutationStarted);

            if ($attempt) {
                $this->attemptRecorder->finish(
                    $attempt,
                    'blocked',
                    EmailRemoteOperation::FAILURE_STALE,
                    $code,
                    $message,
                    $operation->provider_response_json ?? [],
                    $exception,
                    EmailRemoteOperationAttempt::KIND_PREFLIGHT,
                );
                $attempt = null;
            }

            return $this->fail(
                $operation,
                $code,
                $message,
                EmailRemoteOperation::FAILURE_STALE,
                false,
            );
        } catch (Throwable $exception) {
            $providerReadFailed = $exception instanceof EmailProviderReadException;

            if ($providerReadFailed) {
                $this->resetMutationAccounting($operation, $mutationStarted);
            }

            $classification = $reconciling
                ? EmailRemoteOperation::FAILURE_AMBIGUOUS
                : $this->classifyException($exception, $mutationStarted);
            $code = $reconciling
                ? 'REMOTE_RECONCILIATION_FAILED'
                : ($mutationStarted ? 'REMOTE_OPERATION_AMBIGUOUS' : 'REMOTE_OPERATION_TRANSIENT');
            if ($classification === EmailRemoteOperation::FAILURE_PERMANENT) {
                $code = 'REMOTE_OPERATION_PERMANENT';
            }
            $message = $this->safeProviderFailureMessage(
                $classification,
                $mutationStarted,
                $reconciling,
                $providerReadFailed,
            );

            if ($attempt) {
                $this->attemptRecorder->finish(
                    $attempt,
                    'failed',
                    $classification,
                    $code,
                    $message,
                    $operation->provider_response_json ?? [],
                    $exception,
                    ! $mutationStarted && ! $reconciling
                        ? EmailRemoteOperationAttempt::KIND_PREFLIGHT
                        : null,
                );
            }

            // Provider read failures stay manually recoverable without an
            // automatic loop. Connection/preflight failures retain bounded
            // backoff using their own audit count, not the mutation budget.
            $preflightAttemptCount = ! $mutationStarted && ! $reconciling && ! $providerReadFailed
                ? $operation->attemptRecords()
                    ->where('attempt_kind', EmailRemoteOperationAttempt::KIND_PREFLIGHT)
                    ->count()
                : null;

            return $this->fail(
                $operation,
                $code,
                $message,
                $classification,
                ! $reconciling && ! $providerReadFailed,
                $preflightAttemptCount,
            );
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // Disconnect is cleanup only and cannot change an operation's
                // already-recorded provider outcome or expose library detail.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function applySeen(
        ImapClient $client,
        EmailRemoteOperation $operation,
        EmailMailboxPlacement $placement,
        bool $seen,
    ): array {
        $client->assertUidNamespace(
            $this->sourceFolderPath($placement),
            (int) $operation->expected_uid_validity,
            (int) $placement->uid_namespace_id,
        );
        $ok = $client->setSeenByUid($this->imapUid($placement), $seen, $this->sourceFolderPath($placement));

        if (! $ok) {
            throw new RuntimeException('The provider did not acknowledge the Seen flag change.');
        }

        $response = [
            'ok' => true,
            'provider_seen' => $seen,
        ];
        $this->persistProviderResponse($operation, $response);

        $placement->forceFill([
            'provider_seen' => $seen,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => ((int) $placement->sync_version) + 1,
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        app(EmailConversationProjector::class)->refreshForPlacement($placement->refresh());

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyFlagged(
        ImapClient $client,
        EmailRemoteOperation $operation,
        EmailMailboxPlacement $placement,
        bool $flagged,
    ): array {
        $client->assertUidNamespace(
            $this->sourceFolderPath($placement),
            (int) $operation->expected_uid_validity,
            (int) $placement->uid_namespace_id,
        );
        $ok = $client->setFlaggedByUid($this->imapUid($placement), $flagged, $this->sourceFolderPath($placement));

        if (! $ok) {
            throw new RuntimeException('The provider did not acknowledge the Flagged flag change.');
        }

        $response = [
            'ok' => true,
            'provider_flagged' => $flagged,
        ];
        $this->persistProviderResponse($operation, $response);

        $placement->forceFill([
            'provider_flagged' => $flagged,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => ((int) $placement->sync_version) + 1,
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function applyMove(
        ImapClient $client,
        EmailRemoteOperation $operation,
        EmailMailboxPlacement $placement,
    ): array {
        $targetFolder = $this->targetFolderFromOperation($operation);

        if (! $targetFolder) {
            throw new RuntimeException('The target folder is no longer available.');
        }
        $targetFolderPath = $this->providerPath(
            $operation->getAttribute('target_folder_path'),
            $targetFolder->getAttribute('path'),
        );

        $client->assertUidNamespace(
            $this->sourceFolderPath($placement),
            (int) $operation->expected_uid_validity,
            (int) $placement->uid_namespace_id,
        );

        $response = $client->moveByUid(
            $this->imapUid($placement),
            $this->sourceFolderPath($placement),
            $targetFolderPath,
        );

        if (! Arr::get($response, 'ok')) {
            throw new RuntimeException('The provider did not acknowledge the folder move.');
        }

        $this->persistProviderResponse($operation, $response);

        $targetUid = (int) ($response['target_imap_uid'] ?? 0);
        $targetUidValidity = (int) ($response['target_uid_validity'] ?? 0);
        $authoritative = ($response['target_uid_authoritative'] ?? false) === true;
        $targetNamespace = $authoritative
            ? EmailFolderUidNamespace::query()
                ->whereKey($targetFolder->active_uid_namespace_id)
                ->where('account_id', $operation->account_id)
                ->where('email_folder_id', $targetFolder->id)
                ->where('uid_validity', $targetUidValidity)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first()
            : null;

        if ($targetUid < 1 || $targetUidValidity < 1 || ! $targetNamespace) {
            // The provider may already have moved the message. Retain the
            // acknowledgement, leave the source visible/error-marked, and let
            // bounded reconciliation resolve it without replaying the write.
            throw new RuntimeException('The provider move target identity could not be confirmed.');
        }

        $targetPlacement = $this->projectMovedPlacement(
            $placement,
            $targetFolder,
            $targetNamespace,
            $response,
        );

        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => ((int) $placement->sync_version) + 1,
            'provider_missing_at' => now(),
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        app(EmailConversationProjector::class)->refreshForPlacement($targetPlacement ?: $placement);

        return array_merge($response, [
            'source_hidden' => true,
            'target_folder_id' => $targetFolder->id,
            'target_placement_id' => $targetPlacement?->id,
        ]);
    }

    private function projectMovedPlacement(
        EmailMailboxPlacement $sourcePlacement,
        EmailFolder $targetFolder,
        EmailFolderUidNamespace $targetNamespace,
        array $response,
    ): EmailMailboxPlacement {
        $targetUid = (int) ($response['target_imap_uid'] ?? 0);
        $targetUidValidity = (int) ($response['target_uid_validity'] ?? 0);

        $attributes = [
            'email_message_id' => $sourcePlacement->email_message_id,
            'provider' => $sourcePlacement->provider,
            'folder_path' => $targetFolder->path,
            'remote_message_id' => $sourcePlacement->remote_message_id,
            'provider_seen' => $sourcePlacement->provider_seen,
            'provider_answered' => $sourcePlacement->provider_answered,
            'provider_flagged' => $sourcePlacement->provider_flagged,
            'provider_deleted' => false,
            'provider_draft' => $sourcePlacement->provider_draft,
            'flags_json' => $sourcePlacement->flags_json,
            'labels_json' => $sourcePlacement->labels_json,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'last_reconciled_at' => now(),
            'provider_missing_at' => null,
            'sync_error_code' => null,
            'sync_error_message' => null,
        ];

        if (Schema::hasColumn('email_mailbox_placements', 'email_conversation_id')) {
            $attributes['email_conversation_id'] = $sourcePlacement->email_conversation_id;
        }

        $targetPlacement = EmailMailboxPlacement::query()->updateOrCreate(
            [
                'account_id' => $sourcePlacement->account_id,
                'email_folder_id' => $targetFolder->id,
                'uid_namespace_id' => $targetNamespace->id,
                'imap_uid_validity' => $targetUidValidity,
                'imap_uid' => $targetUid,
            ],
            $attributes,
        );

        app(EmailConversationProjector::class)->assignPlacement($targetPlacement);

        return $targetPlacement->refresh();
    }

    private function targetFolderFromOperation(EmailRemoteOperation $operation): ?EmailFolder
    {
        $targetPath = $operation->getAttribute('target_folder_path');
        if (! is_string($targetPath) || $targetPath === '') {
            return null;
        }

        return EmailFolder::query()
            ->where('account_id', $operation->account_id)
            ->where('path', EmailProviderPath::normalize($targetPath))
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function applyFolderRename(
        ImapClient $client,
        EmailRemoteOperation $operation,
        EmailFolder $folder,
    ): array {
        $sourceFolderPath = $this->providerPath(
            $operation->getAttribute('source_folder_path'),
            $folder->getAttribute('path'),
        );
        $targetFolderPath = $this->providerPath(
            $operation->getAttribute('target_folder_path'),
        );

        $response = $client->renameFolder($sourceFolderPath, $targetFolderPath);

        if (! Arr::get($response, 'ok')) {
            throw new RuntimeException('The provider did not acknowledge the folder rename.');
        }

        $this->persistProviderResponse($operation, $response);

        $delimiter = $folder->delimiter ?: (str_contains($targetFolderPath, '/') ? '/' : null);
        $folder->forceFill([
            'path' => $targetFolderPath,
            'name' => $response['name'] ?? basename(str_replace('\\', '/', $targetFolderPath)) ?: $targetFolderPath,
            'delimiter' => $delimiter,
            'parent_path' => $this->parentPath($targetFolderPath, $delimiter),
            'remote_id' => $response['remote_id'] ?? $targetFolderPath,
            'special_use' => null,
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => (int) ($response['uid_validity'] ?? $folder->uid_validity),
            'uid_next' => $response['uid_next'] ?? $folder->uid_next,
            'highest_modseq' => $response['highest_modseq'] ?? $folder->highest_modseq,
            'exists_count' => $response['exists_count'] ?? $folder->exists_count,
            'unseen_count' => $response['unseen_count'] ?? $folder->unseen_count,
            'sync_status' => $response['sync_status'] ?? EmailFolder::SYNC_SYNCED,
            'last_discovered_at' => now(),
            'last_synced_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        EmailMailboxPlacement::query()
            ->where('email_folder_id', $folder->id)
            ->update([
                'folder_path' => $targetFolderPath,
                'imap_uid_validity' => (int) $folder->uid_validity,
                'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
                'sync_version' => DB::raw('sync_version + 1'),
                'last_reconciled_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
            ]);

        $this->updateRuleTargetPaths($folder, $sourceFolderPath, $targetFolderPath);

        return array_merge($response, [
            'source_folder_path' => $sourceFolderPath,
            'target_folder_path' => $targetFolderPath,
            'folder_id' => $folder->id,
            'placements_reprojected' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function applyFolderDelete(
        ImapClient $client,
        EmailRemoteOperation $operation,
        EmailFolder $folder,
    ): array {
        $sourceFolderPath = $this->providerPath(
            $operation->getAttribute('source_folder_path'),
            $folder->getAttribute('path'),
        );

        if ($folder->placements()
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->exists()) {
            throw new RuntimeException('The folder still has active local mailbox placements.');
        }

        $response = $client->deleteFolder($sourceFolderPath);

        if (! Arr::get($response, 'ok')) {
            throw new RuntimeException('The provider did not acknowledge the folder delete.');
        }

        $this->persistProviderResponse($operation, $response);

        $folder->forceFill([
            'is_selectable' => false,
            'sync_enabled' => false,
            'exists_count' => 0,
            'unseen_count' => 0,
            'sync_status' => EmailFolder::SYNC_SYNCED,
            'last_synced_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();

        return array_merge($response, [
            'source_folder_path' => $sourceFolderPath,
            'folder_id' => $folder->id,
            'folder_hidden_locally' => true,
        ]);
    }

    private function imapUid(EmailMailboxPlacement $placement): int
    {
        $uid = (int) $placement->imap_uid;

        if ($uid <= 0) {
            throw new RuntimeException('The selected mailbox placement does not have a provider UID.');
        }

        return $uid;
    }

    private function sourceFolderPath(EmailMailboxPlacement $placement): string
    {
        return $this->providerPath(
            $placement->getAttribute('folder_path'),
            $placement->folder?->getAttribute('path'),
        );
    }

    private function providerPath(mixed $path, mixed $fallback = null): string
    {
        $candidate = is_string($path) && $path !== '' ? $path : $fallback;

        return EmailProviderPath::normalize(is_string($candidate) ? $candidate : '');
    }

    private function fail(
        EmailRemoteOperation $operation,
        string $code,
        string $message,
        string $classification,
        bool $allowRetry,
        ?int $preflightAttemptCount = null,
    ): EmailRemoteOperation {
        $operation->refresh();
        $mutationBudgetReached = $operation->hasReachedAttemptLimit();
        $preflightBudgetReached = $preflightAttemptCount !== null
            && $preflightAttemptCount >= max(1, (int) ($operation->max_attempts ?: EmailRemoteOperation::DEFAULT_MAX_ATTEMPTS));
        $retryBudgetReached = $mutationBudgetReached || $preflightBudgetReached;
        $maxReached = $retryBudgetReached
            && $classification !== EmailRemoteOperation::FAILURE_AMBIGUOUS;
        $retryable = $allowRetry
            && (! $retryBudgetReached || $classification === EmailRemoteOperation::FAILURE_AMBIGUOUS)
            && in_array($classification, [
                EmailRemoteOperation::FAILURE_TRANSIENT,
                EmailRemoteOperation::FAILURE_AMBIGUOUS,
            ], true);
        $retryAttemptCount = $preflightAttemptCount ?? $operation->providerAttemptCount();
        $nextAttemptAt = $retryable ? now()->addSeconds($this->backoffSeconds($retryAttemptCount)) : null;
        $safeMessage = $this->evidenceSanitizer->message($message);

        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_FAILED,
            'failed_at' => now(),
            'next_attempt_at' => $nextAttemptAt,
            'error_code' => $code,
            'error_message' => $safeMessage,
            'failure_classification' => $classification,
            'status_reason_code' => $maxReached ? 'REMOTE_OPERATION_MAX_ATTEMPTS' : $code,
            'status_reason_message' => $maxReached
                ? 'The provider operation reached its maximum attempt count and requires review.'
                : $safeMessage,
            'reconciliation_required_at' => $classification === EmailRemoteOperation::FAILURE_AMBIGUOUS
                ? ($operation->reconciliation_required_at ?: now())
                : null,
        ])->save();

        if ($operation->placement) {
            $operation->placement->forceFill([
                'sync_status' => EmailMailboxPlacement::SYNC_ERROR,
                'sync_error_code' => $code,
                'sync_error_message' => $safeMessage,
            ])->save();
        }

        if ($operation->folder && in_array($operation->operation_type, [
            ManageProviderEmailFolder::RENAME_FOLDER,
            ManageProviderEmailFolder::MOVE_FOLDER,
            ManageProviderEmailFolder::DELETE_FOLDER,
        ], true)) {
            $operation->folder->forceFill([
                'sync_status' => EmailFolder::SYNC_ERROR,
                'sync_error_code' => $code,
                'sync_error_message' => $safeMessage,
            ])->save();

            // The recovery metadata update above is owned by this operation,
            // so it becomes the new expected local folder evidence. A later
            // independent folder change will still fail the next preflight.
            $operation->forceFill([
                'expected_folder_updated_at' => $operation->folder->fresh()->updated_at,
            ])->save();
        }

        return $operation->refresh();
    }

    /**
     * @return array{code: string, message: string, classification: string}|null
     */
    private function preflightBlocker(
        EmailRemoteOperation $operation,
        bool $allowStaleEvidenceForReconciliation = false,
    ): ?array {
        $account = $operation->account;
        if (! $account) {
            return $this->blocker('REMOTE_OPERATION_CONTEXT', 'The mailbox account no longer exists.', EmailRemoteOperation::FAILURE_PERMANENT);
        }

        if (! $account->is_active) {
            return $this->blocker('REMOTE_OPERATION_ACCOUNT_INACTIVE', 'The mailbox account is no longer active.', EmailRemoteOperation::FAILURE_AUTHORIZATION);
        }

        if (Schema::hasColumn('email_remote_operations', 'provider_binding_version')
            && (int) $operation->provider_binding_version !== app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account)) {
            return $this->blocker(
                'REMOTE_OPERATION_PROVIDER_BINDING_STALE',
                'The mailbox provider binding changed after this operation was requested.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        if (! $operation->requester
            || ! $this->mailboxAccess->canAccessAccount($operation->requester, $account, MailboxAccess::ORGANIZE)) {
            return $this->blocker('REMOTE_OPERATION_AUTH_REVOKED', 'The original requester no longer has mailbox Organize access.', EmailRemoteOperation::FAILURE_AUTHORIZATION);
        }

        if (! in_array($operation->operation_type, array_merge(
            PerformEmailRemoteOperation::allowedOperations(),
            [
                ManageProviderEmailFolder::RENAME_FOLDER,
                ManageProviderEmailFolder::MOVE_FOLDER,
                ManageProviderEmailFolder::DELETE_FOLDER,
            ],
        ), true)) {
            return $this->blocker('REMOTE_OPERATION_UNSUPPORTED', 'This provider operation type is no longer supported.', EmailRemoteOperation::FAILURE_PERMANENT);
        }

        if ($this->isFolderOperation($operation)) {
            $folder = $operation->folder;
            if (! $folder || (int) $folder->account_id !== (int) $operation->account_id) {
                return $this->blocker('REMOTE_OPERATION_FOLDER_MISSING', 'The provider folder context no longer exists.', EmailRemoteOperation::FAILURE_STALE);
            }
            if (blank($operation->source_folder_path)
                || (in_array($operation->operation_type, [
                    ManageProviderEmailFolder::RENAME_FOLDER,
                    ManageProviderEmailFolder::MOVE_FOLDER,
                ], true) && blank($operation->target_folder_path))) {
                return $this->blocker('REMOTE_OPERATION_FOLDER_EVIDENCE_MISSING', 'Required provider folder path evidence is missing.', EmailRemoteOperation::FAILURE_STALE);
            }

            if ($folder->role !== EmailFolder::ROLE_CUSTOM || filled($folder->special_use) || ! $folder->is_selectable || ! $folder->sync_enabled) {
                return $this->blocker('REMOTE_OPERATION_FOLDER_POLICY_CHANGED', 'The provider folder is no longer eligible for this mutation.', EmailRemoteOperation::FAILURE_STALE);
            }

            if ((string) $folder->path !== (string) $operation->source_folder_path) {
                if ($allowStaleEvidenceForReconciliation) {
                    return null;
                }

                return $this->blocker('REMOTE_OPERATION_FOLDER_STALE', 'The provider folder path changed after this operation was requested.', EmailRemoteOperation::FAILURE_STALE);
            }

            if ($operation->expected_folder_updated_at
                && $folder->updated_at
                && $folder->updated_at->format('Y-m-d H:i:s') !== $operation->expected_folder_updated_at->format('Y-m-d H:i:s')) {
                if ($allowStaleEvidenceForReconciliation) {
                    return null;
                }

                return $this->blocker('REMOTE_OPERATION_FOLDER_VERSION_STALE', 'The provider folder changed after this operation was requested.', EmailRemoteOperation::FAILURE_STALE);
            }

            if (in_array($operation->operation_type, [
                ManageProviderEmailFolder::RENAME_FOLDER,
                ManageProviderEmailFolder::MOVE_FOLDER,
            ], true) && EmailFolder::query()
                ->where('account_id', $operation->account_id)
                ->where('path', $operation->target_folder_path)
                ->whereKeyNot($folder->id)
                ->where('sync_enabled', true)
                ->exists()) {
                return $this->blocker('REMOTE_OPERATION_FOLDER_TARGET_STALE', 'The target folder path is no longer available.', EmailRemoteOperation::FAILURE_STALE);
            }

            if ($operation->operation_type === ManageProviderEmailFolder::DELETE_FOLDER
                && ($folder->placements()->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)->exists()
                    || $this->folderReferencedByRule((int) $folder->id))) {
                return $this->blocker('REMOTE_OPERATION_FOLDER_DELETE_STALE', 'The folder gained mail or a rule reference after deletion was requested.', EmailRemoteOperation::FAILURE_STALE);
            }

            return null;
        }

        $placement = $operation->placement;
        if (! $placement || (int) $placement->account_id !== (int) $operation->account_id) {
            return $this->blocker('REMOTE_OPERATION_PLACEMENT_MISSING', 'The mailbox placement no longer exists.', EmailRemoteOperation::FAILURE_STALE);
        }

        if ($this->remoteOperationObserver->hasPriorUnresolvedForPlacement(
            (int) $operation->email_mailbox_placement_id,
            (int) $operation->id,
        )) {
            return $this->blocker(
                'REMOTE_OPERATION_PLACEMENT_CONFLICT',
                'An earlier provider mailbox operation still owns this placement.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        if ($placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE) {
            if ($allowStaleEvidenceForReconciliation) {
                return null;
            }

            return $this->blocker('REMOTE_OPERATION_PLACEMENT_INACTIVE', 'The mailbox placement is no longer active.', EmailRemoteOperation::FAILURE_STALE);
        }

        if ($placement->provider_missing_at !== null) {
            if ($allowStaleEvidenceForReconciliation) {
                // Ambiguous recovery may inspect provider evidence, but the
                // second ordinary preflight below still prevents a mutation.
                return null;
            }

            return $this->blocker(
                'REMOTE_OPERATION_PROVIDER_MISSING',
                'The mailbox placement is no longer present at the provider.',
                EmailRemoteOperation::FAILURE_STALE,
            );
        }

        if ($operation->expected_placement_sync_version === null
            || $operation->expected_provider_uid === null
            || $operation->expected_uid_validity === null
            || (int) $operation->expected_provider_uid <= 0
            || blank($operation->source_folder_path)) {
            return $this->blocker('REMOTE_OPERATION_EVIDENCE_MISSING', 'Required placement identity evidence is missing.', EmailRemoteOperation::FAILURE_STALE);
        }

        if ((int) $placement->sync_version !== (int) $operation->expected_placement_sync_version
            || (int) $placement->imap_uid !== (int) $operation->expected_provider_uid
            || (int) $placement->imap_uid_validity !== (int) $operation->expected_uid_validity
            || (string) $placement->folder_path !== (string) $operation->source_folder_path) {
            if ($allowStaleEvidenceForReconciliation) {
                return null;
            }

            return $this->blocker('REMOTE_OPERATION_PLACEMENT_STALE', 'The placement version, UID, UIDVALIDITY, or folder changed after this operation was requested.', EmailRemoteOperation::FAILURE_STALE);
        }

        if (in_array($operation->operation_type, [
            PerformEmailRemoteOperation::ARCHIVE,
            PerformEmailRemoteOperation::TRASH,
            PerformEmailRemoteOperation::MOVE,
        ], true)) {
            $target = EmailFolder::query()
                ->where('account_id', $operation->account_id)
                ->where('path', $operation->target_folder_path)
                ->where('is_selectable', true)
                ->where('sync_enabled', true)
                ->first();

            if (! $target || (int) $target->id === (int) $placement->email_folder_id) {
                return $this->blocker('REMOTE_OPERATION_TARGET_STALE', 'The requested provider target folder is no longer available.', EmailRemoteOperation::FAILURE_STALE);
            }
        }

        if (! $allowStaleEvidenceForReconciliation
            && ($undoBlocker = $this->undoGuard->localBlocker($operation))) {
            return $undoBlocker;
        }

        return null;
    }

    private function supersede(
        EmailRemoteOperation $operation,
        string $code,
        string $message,
        string $classification,
    ): EmailRemoteOperation {
        $safeMessage = $this->evidenceSanitizer->message($message);

        $operation->forceFill([
            'status' => EmailRemoteOperation::STATUS_SUPERSEDED,
            'next_attempt_at' => null,
            'failed_at' => now(),
            'error_code' => $code,
            'error_message' => $safeMessage,
            'failure_classification' => $classification,
            'status_reason_code' => $code,
            'status_reason_message' => $safeMessage,
        ])->save();

        return $operation->refresh();
    }

    /** @param array<string, mixed> $response */
    private function succeed(
        EmailRemoteOperation $operation,
        array $response,
        string $code,
        string $message,
        bool $reconciled = false,
    ): EmailRemoteOperation {
        $values = [
            'status' => EmailRemoteOperation::STATUS_SUCCEEDED,
            'provider_response_json' => $this->evidenceSanitizer->sanitize($response),
            'acknowledged_at' => now(),
            'failed_at' => null,
            'next_attempt_at' => null,
            'error_code' => null,
            'error_message' => null,
            'failure_classification' => null,
            'status_reason_code' => $code,
            'status_reason_message' => $message,
            'reconciled_at' => $reconciled ? now() : $operation->reconciled_at,
            'reconciliation_required_at' => null,
        ];

        if (Schema::hasColumn('email_remote_operations', 'result_snapshot_json')
            && $operation->result_snapshot_json === null) {
            $values['result_snapshot_json'] = $this->resultSnapshot->capture($operation, $response, $reconciled);
            $values['result_snapshot_captured_at'] = now();
        }

        $operation->forceFill($values)->save();

        return $operation->refresh();
    }

    /** @param array<string, mixed> $response */
    private function persistProviderResponse(EmailRemoteOperation $operation, array $response): void
    {
        // Save provider acknowledgement before local projection changes. If a
        // later local write fails, reconciliation retains the evidence needed
        // to prove the remote result without replaying the mutation.
        $values = [
            'provider_response_json' => $this->evidenceSanitizer->sanitize($response),
        ];
        if (Schema::hasColumn('email_remote_operations', 'acknowledged_target_uid')
            && ($response['target_uid_authoritative'] ?? false) === true
            && (int) ($response['target_uid_validity'] ?? 0) > 0
            && (int) ($response['target_imap_uid'] ?? 0) > 0) {
            $values['acknowledged_target_uid_validity'] = (int) $response['target_uid_validity'];
            $values['acknowledged_target_uid'] = (int) $response['target_imap_uid'];
        }

        $operation->forceFill($values)->save();
    }

    private function backoffSeconds(int $attempts): int
    {
        return min(3600, 60 * (2 ** max(0, min(6, $attempts - 1))));
    }

    private function isFolderOperation(EmailRemoteOperation $operation): bool
    {
        return in_array($operation->operation_type, [
            ManageProviderEmailFolder::RENAME_FOLDER,
            ManageProviderEmailFolder::MOVE_FOLDER,
            ManageProviderEmailFolder::DELETE_FOLDER,
        ], true);
    }

    /** @return array{code: string, message: string, classification: string} */
    private function blocker(string $code, string $message, string $classification): array
    {
        return compact('code', 'message', 'classification');
    }

    private function classifyException(Throwable $exception, bool $mutationStarted): string
    {
        if ($mutationStarted) {
            return EmailRemoteOperation::FAILURE_AMBIGUOUS;
        }

        return preg_match(
            '/authentication|login failed|invalid credentials|certificate|unsupported|malformed|permission denied/i',
            $exception->getMessage(),
        )
            ? EmailRemoteOperation::FAILURE_PERMANENT
            : EmailRemoteOperation::FAILURE_TRANSIENT;
    }

    private function resetMutationAccounting(EmailRemoteOperation $operation, bool &$mutationStarted): void
    {
        if (! $mutationStarted) {
            return;
        }

        $operation->refresh();
        $operation->forceFill([
            'attempts' => max(0, ((int) $operation->attempts) - 1),
        ])->save();
        $mutationStarted = false;
    }

    private function safeProviderFailureMessage(
        string $classification,
        bool $mutationStarted,
        bool $reconciling,
        bool $providerReadFailed,
    ): string {
        if ($reconciling) {
            return 'The provider state could not be read for reconciliation.';
        }

        if ($providerReadFailed) {
            return 'The provider message could not be read before the mailbox operation.';
        }

        if ($mutationStarted || $classification === EmailRemoteOperation::FAILURE_AMBIGUOUS) {
            return 'The provider operation outcome could not be confirmed.';
        }

        if ($classification === EmailRemoteOperation::FAILURE_PERMANENT) {
            return 'The provider rejected the mailbox connection or operation configuration.';
        }

        return 'The provider mailbox was unavailable before the operation could start.';
    }

    private function folderReferencedByRule(int $folderId): bool
    {
        return EmailRule::query()
            ->get(['actions_json'])
            ->contains(fn (EmailRule $rule): bool => $this->actionTreeReferencesFolder($rule->actions_json ?? [], $folderId));
    }

    /** @param array<int|string, mixed> $actions */
    private function actionTreeReferencesFolder(array $actions, int $folderId): bool
    {
        if ((int) ($actions['target_folder_id'] ?? 0) === $folderId) {
            return true;
        }

        foreach ($actions as $value) {
            if (is_array($value) && $this->actionTreeReferencesFolder($value, $folderId)) {
                return true;
            }
        }

        return false;
    }

    private function parentPath(string $path, ?string $delimiter): ?string
    {
        $delimiter = $delimiter ?: (str_contains($path, '/') ? '/' : null);
        if (! $delimiter || ! str_contains($path, $delimiter)) {
            return null;
        }

        return Str::beforeLast($path, $delimiter);
    }

    private function updateRuleTargetPaths(EmailFolder $folder, string $sourceFolderPath, string $targetFolderPath): void
    {
        EmailRule::query()
            ->get()
            ->each(function (EmailRule $rule) use ($folder, $sourceFolderPath, $targetFolderPath): void {
                $actions = $rule->actions_json ?? [];
                $updated = $this->rewriteActionFolderPath($actions, (int) $folder->id, $sourceFolderPath, $targetFolderPath);

                if ($updated !== $actions) {
                    $rule->forceFill(['actions_json' => $updated])->save();
                }
            });
    }

    /**
     * @param  array<int, mixed>|array<string, mixed>  $actions
     * @return array<int, mixed>|array<string, mixed>
     */
    private function rewriteActionFolderPath(array $actions, int $folderId, string $sourceFolderPath, string $targetFolderPath): array
    {
        foreach ($actions as $key => $value) {
            if (is_array($value)) {
                $actions[$key] = $this->rewriteActionFolderPath($value, $folderId, $sourceFolderPath, $targetFolderPath);

                continue;
            }

            if ($key === 'value'
                && ($actions['target_folder_id'] ?? null)
                && (int) $actions['target_folder_id'] === $folderId
                && (string) $value === $sourceFolderPath) {
                $actions[$key] = $targetFolderPath;
            }
        }

        return $actions;
    }
}
