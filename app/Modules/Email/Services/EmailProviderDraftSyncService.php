<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Jobs\RefreshEmailProviderDraftFolder;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Support\EmailAccountProviderLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\DraftEmail;
use Throwable;

class EmailProviderDraftSyncService
{
    private const APPEND_RESERVATION_STALE_AFTER_SECONDS = 300;

    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
    ) {}

    public function sync(EmailComposerDraft $draft, User $actor): EmailComposerDraft
    {
        $draft->loadMissing(['account', 'message', 'attachments']);
        $account = $draft->account;

        if (! $account) {
            return $this->markError($draft, 'PROVIDER_DRAFT_ACCOUNT_MISSING', 'The draft is missing its mailbox account.');
        }

        if (! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::SEND)) {
            throw new AuthorizationException('You need mailbox Send access before syncing this draft.');
        }

        $reservation = $this->reserveProviderAppend($draft, $account);
        $draft = $reservation['draft'];
        $folder = $this->draftsFolder($account);

        if (! $folder) {
            return $reservation['acquired']
                ? $this->markAppendError(
                    $draft,
                    'PROVIDER_DRAFT_FOLDER_MISSING',
                    'No selectable provider Drafts folder has been discovered for this mailbox.',
                )
                : $draft;
        }

        if (! $reservation['acquired']) {
            if ($reservation['refresh']) {
                $this->dispatchTargetedRefresh($draft, $account, $folder);
            }

            return $draft;
        }

        $draft->loadMissing(['account', 'message', 'attachments']);
        $messageId = $this->normalizeMessageId($draft->provider_draft_message_id);
        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 180);
        if (! $providerLock) {
            return $this->markAppendError(
                $draft,
                EmailComposerDraft::PROVIDER_DRAFT_APPEND_PREWRITE_FAILED,
                'Another provider mailbox operation is active. Saving again may retry safely.',
            );
        }
        $client = null;
        $appendAttempted = false;
        $previousDeleteOk = null;
        $response = null;

        try {
            $rawMessage = $this->rawDraftMessage($draft, $account, $messageId);
            $client = app()->makeWith(ImapClient::class, [
                'account' => $account,
                'expectedProviderBindingVersion' => $this->expectedBindingVersion($draft, $account),
            ]);
            $client->connect();
            $previousDeleteOk = $this->deletePreviousDraftCopy($client, $draft, $folder);

            if (! $this->beginProviderAppend($draft, $folder)) {
                return $draft->refresh();
            }

            $appendAttempted = true;
            $response = $client->appendDraft($folder->path, $rawMessage);

            if (! ($response['ok'] ?? false)) {
                return $this->markAppendError(
                    $draft,
                    'PROVIDER_DRAFT_APPEND_REJECTED',
                    'The provider rejected the Drafts append before it could be confirmed.',
                );
            }
        } catch (Throwable $exception) {
            if ($appendAttempted) {
                $draft = $this->markAppendError(
                    $draft,
                    EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
                    'The provider Drafts append outcome could not be confirmed. Nexum will reconcile it before another append is allowed.',
                );
                $this->dispatchTargetedRefresh($draft, $account, $folder);
                Log::warning('Provider Drafts append outcome is unresolved.', [
                    'account_id' => $account->id,
                    'folder_id' => $folder->id,
                    'draft_id' => $draft->id,
                    'exception' => $exception::class,
                ]);

                return $draft;
            }

            Log::warning('Provider Drafts append failed before the append attempt.', [
                'account_id' => $account->id,
                'folder_id' => $folder->id,
                'draft_id' => $draft->id,
                'exception' => $exception::class,
            ]);

            return $this->markAppendError(
                $draft,
                EmailComposerDraft::PROVIDER_DRAFT_APPEND_PREWRITE_FAILED,
                'The provider Drafts connection failed before an append was attempted. Saving again may retry safely.',
            );
        } finally {
            $this->disconnectQuietly($client, $account, $draft);
            $providerLock->release();
        }

        if (! is_array($response)) {
            return $this->markAppendError(
                $draft,
                EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
                'The provider Drafts append outcome could not be confirmed. Nexum will reconcile it before another append is allowed.',
            );
        }

        $uidValidity = $response['imap_uid_validity'] ?? null;
        $uid = $response['imap_uid'] ?? null;
        $status = $uidValidity && $uid
            ? EmailComposerDraft::PROVIDER_DRAFT_SYNCED
            : EmailComposerDraft::PROVIDER_DRAFT_PENDING;

        EmailComposerDraft::query()
            ->whereKey($draft->id)
            ->where('provider_draft_status', EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED)
            ->where('provider_draft_error_code', $draft->provider_draft_error_code)
            ->update([
                'provider_draft_status' => $status,
                'provider_draft_folder_path' => $folder->path,
                'provider_draft_uid_validity' => $uidValidity,
                'provider_draft_uid' => $uid,
                'provider_draft_message_id' => '<'.$messageId.'>',
                'provider_draft_normalized_message_id' => $messageId,
                'provider_draft_synced_at' => now(),
                'provider_draft_deleted_at' => null,
                'provider_draft_error_code' => null,
                'provider_draft_error_message' => null,
            ]);

        $draft = $draft->refresh();

        if ($previousDeleteOk === false && in_array($draft->provider_draft_status, [
            EmailComposerDraft::PROVIDER_DRAFT_PENDING,
            EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
        ], true)) {
            $draft->forceFill([
                'provider_draft_error_code' => 'PROVIDER_DRAFT_PREVIOUS_COPY_MISSING',
                'provider_draft_error_message' => 'The previous provider draft copy was not found before replacement.',
            ])->save();
        } else {
            $this->refreshProviderDraftPlacementProjection($draft->fresh(['placement.message']), $response);
        }

        $this->dispatchTargetedRefresh($draft, $account, $folder);

        return $draft->refresh();
    }

    public function delete(EmailComposerDraft $draft): EmailComposerDraft
    {
        $draft->loadMissing('account');

        if ($draft->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_DELETED) {
            return $draft;
        }

        if (! $this->hasProviderUid($draft)) {
            if ($draft->hasUnresolvedProviderAppend()) {
                // No safe UID exists for a provider write whose outcome is
                // unknown. Keep the issue visible instead of claiming cleanup.
                return $draft->refresh();
            }

            if (in_array($draft->provider_draft_status, [
                EmailComposerDraft::PROVIDER_DRAFT_PENDING,
                EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
            ], true)) {
                return $this->markError(
                    $draft,
                    'PROVIDER_DRAFT_UID_MISSING',
                    'The provider draft copy has not been reconciled to a safe IMAP UID yet.',
                );
            }

            return $this->markDeleted($draft);
        }

        $account = $draft->account;

        if (! $account) {
            return $this->markError($draft, 'PROVIDER_DRAFT_ACCOUNT_MISSING', 'The draft is missing its mailbox account.');
        }

        $providerLock = EmailAccountProviderLock::acquire((int) $account->id, 180);
        if (! $providerLock) {
            return $this->markError(
                $draft,
                'PROVIDER_DRAFT_ACCOUNT_BUSY',
                'Another provider mailbox operation is active. Try provider draft cleanup again later.',
            );
        }

        $client = null;

        try {
            $client = app()->makeWith(ImapClient::class, [
                'account' => $account,
                'expectedProviderBindingVersion' => $this->expectedBindingVersion($draft, $account),
            ]);
            $client->connect();
            $this->ensureCurrentUidValidity($client, $draft);
            $deleted = $client->deleteByUid((int) $draft->provider_draft_uid, (string) $draft->provider_draft_folder_path);

            if (! $deleted) {
                return $this->markError($draft, 'PROVIDER_DRAFT_DELETE_REJECTED', 'The provider draft copy was not found or could not be deleted.');
            }
        } catch (Throwable $exception) {
            Log::warning('Provider Drafts cleanup failed.', [
                'account_id' => $account->id,
                'draft_id' => $draft->id,
                'exception' => $exception::class,
            ]);

            return $this->markError(
                $draft,
                'PROVIDER_DRAFT_DELETE_FAILED',
                'The provider draft copy could not be removed. Nexum kept the cleanup issue for review.',
            );
        } finally {
            $this->disconnectQuietly($client, $account, $draft);
            $providerLock->release();
        }

        $draft = $this->markDeleted($draft);
        $this->hideProviderDraftPlacement($draft->fresh(['placement']));

        return $draft;
    }

    public function reconcilePlacement(EmailMailboxPlacement $placement): ?EmailComposerDraft
    {
        $placement->loadMissing(['account', 'folder', 'message']);

        if (! $placement->account
            || $placement->folder?->role !== EmailFolder::ROLE_DRAFTS
            || ! $placement->message) {
            return null;
        }

        $bindingVersion = app(EmailAccountProviderRuntimeResolver::class)
            ->captureBindingVersion($placement->account);

        return $this->reconcilePlacementForBinding($placement, $bindingVersion);
    }

    /**
     * Reconcile an already accepted provider observation using local rows only.
     * Every provider identity component is rechecked so an import cannot match
     * a Draft reservation through Message-ID alone or across a stale binding.
     */
    public function reconcileObservedPlacementLocally(
        EmailMailboxPlacement $placement,
        int $accountId,
        int $folderId,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
        int $providerBindingVersion,
    ): ?EmailComposerDraft {
        $placement = EmailMailboxPlacement::query()
            ->with(['account', 'folder', 'message'])
            ->whereKey($placement->id)
            ->where('account_id', $accountId)
            ->where('email_folder_id', $folderId)
            ->where('uid_namespace_id', $uidNamespaceId)
            ->where('imap_uid_validity', $uidValidity)
            ->where('imap_uid', $uid)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->first();

        if (! $placement
            || $providerBindingVersion < 1
            || (int) $placement->account?->provider_binding_version !== $providerBindingVersion
            || $placement->folder?->role !== EmailFolder::ROLE_DRAFTS
            || (int) $placement->folder?->active_uid_namespace_id !== $uidNamespaceId
            || ! $placement->message
            || (int) $placement->message->account_id !== $accountId
            || (string) $placement->message->mailbox !== (string) $placement->folder_path
            || (int) $placement->message->imap_uid_validity !== $uidValidity
            || (int) $placement->message->imap_uid !== $uid) {
            throw new EmailProviderReconciliationReadException(
                'reconciliation_draft_projection_scope_mismatch',
            );
        }

        return $this->reconcilePlacementForBinding($placement, $providerBindingVersion);
    }

    private function reconcilePlacementForBinding(
        EmailMailboxPlacement $placement,
        int $providerBindingVersion,
    ): ?EmailComposerDraft {
        if ($providerBindingVersion < 1) {
            return null;
        }

        $normalizedMessageId = $this->normalizeMessageId($placement->message->message_id);

        if ($normalizedMessageId === '') {
            return null;
        }

        return DB::transaction(function () use (
            $placement,
            $normalizedMessageId,
            $providerBindingVersion,
        ): ?EmailComposerDraft {
            $draft = EmailComposerDraft::query()
                ->where('email_account_id', $placement->account_id)
                ->when(
                    Schema::hasColumn('email_composer_drafts', 'provider_binding_version'),
                    fn ($query) => $query->where(
                        'provider_binding_version',
                        $providerBindingVersion,
                    ),
                )
                ->where('status', EmailComposerDraft::STATUS_ACTIVE)
                ->where('provider_draft_normalized_message_id', $normalizedMessageId)
                ->latest('last_saved_at')
                ->lockForUpdate()
                ->first();

            // While only reserved, the matching provider message can still be
            // the old copy that this replacement is about to remove.
            if (! $draft || $draft->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED) {
                return null;
            }

            $draft->forceFill([
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_SYNCED,
                'provider_draft_folder_path' => $placement->folder_path,
                'provider_draft_uid_validity' => $placement->imap_uid_validity,
                'provider_draft_uid' => $placement->imap_uid,
                'provider_draft_synced_at' => now(),
                'provider_draft_deleted_at' => null,
                'provider_draft_error_code' => null,
                'provider_draft_error_message' => null,
            ])->save();

            return $draft->refresh();
        });
    }

    public function normalizeMessageId(?string $value): string
    {
        $value = preg_replace('/[\r\n\t ]+/', ' ', (string) $value);
        $value = trim((string) $value);
        $value = trim($value, '<> ');

        return mb_strtolower($value);
    }

    private function draftsFolder(EmailAccount $account): ?EmailFolder
    {
        $folder = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->get()
            ->filter(fn (EmailFolder $candidate): bool => $this->isExactDraftsFolder($candidate))
            ->sort(fn (EmailFolder $first, EmailFolder $second): int => $this->draftsFolderRank($first)
                <=> $this->draftsFolderRank($second))
            ->first();

        if ($folder && $folder->role !== EmailFolder::ROLE_DRAFTS) {
            $folder->forceFill(['role' => EmailFolder::ROLE_DRAFTS])->save();
        }

        return $folder;
    }

    public function isExactDraftsFolder(EmailFolder $folder): bool
    {
        return EmailFolder::inferRole(
            (string) $folder->path,
            $folder->special_use,
            $folder->delimiter,
        ) === EmailFolder::ROLE_DRAFTS;
    }

    /** @return array{int, int, string, int} */
    private function draftsFolderRank(EmailFolder $folder): array
    {
        $explicitSpecialUse = filled($folder->special_use)
            && EmailFolder::inferRole('', $folder->special_use, $folder->delimiter) === EmailFolder::ROLE_DRAFTS;
        $delimiter = filled($folder->delimiter) ? (string) $folder->delimiter : null;
        $depth = $delimiter
            ? substr_count((string) $folder->path, $delimiter)
            : preg_match_all('/[.\\\\\/]/u', (string) $folder->path);

        return [
            $explicitSpecialUse ? 0 : 1,
            max(0, (int) $depth),
            mb_strtolower(trim((string) $folder->path)),
            (int) $folder->id,
        ];
    }

    /**
     * Claim one durable APPEND attempt before any provider connection. A
     * pending or unresolved attempt is deliberately not claimable again.
     *
     * @return array{draft: EmailComposerDraft, acquired: bool, refresh: bool}
     */
    private function reserveProviderAppend(EmailComposerDraft $draft, EmailAccount $account): array
    {
        return DB::transaction(function () use ($draft, $account): array {
            $current = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($current->status !== EmailComposerDraft::STATUS_ACTIVE) {
                return ['draft' => $current, 'acquired' => false, 'refresh' => false];
            }

            $currentBindingVersion = app(EmailAccountProviderRuntimeResolver::class)
                ->captureBindingVersion($account);
            if (Schema::hasColumn('email_composer_drafts', 'provider_binding_version')
                && (int) $current->provider_binding_version !== $currentBindingVersion) {
                $current->forceFill([
                    'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
                    'provider_draft_error_code' => 'PROVIDER_DRAFT_BINDING_STALE',
                    'provider_draft_error_message' => 'The mailbox provider binding changed after this draft reserved provider work.',
                ])->save();

                return ['draft' => $current->refresh(), 'acquired' => false, 'refresh' => false];
            }

            if ($current->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED) {
                $current->forceFill([
                    'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
                    'provider_draft_error_code' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_OUTCOME_UNRESOLVED,
                    'provider_draft_error_message' => 'The provider Drafts append outcome could not be confirmed. Nexum will reconcile it before another append is allowed.',
                ])->save();

                return ['draft' => $current->refresh(), 'acquired' => false, 'refresh' => true];
            }

            if ($current->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED) {
                if ($this->appendReservationIsFresh($current->provider_draft_error_code)) {
                    return ['draft' => $current, 'acquired' => false, 'refresh' => false];
                }

                $current->forceFill([
                    'provider_draft_error_code' => $this->newAppendReservationToken(),
                    'provider_draft_error_message' => null,
                ])->save();

                return ['draft' => $current->refresh(), 'acquired' => true, 'refresh' => false];
            }

            if ($current->hasProtectedProviderAppendState()) {
                return [
                    'draft' => $current,
                    'acquired' => false,
                    'refresh' => $current->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_PENDING
                        || $current->hasUnresolvedProviderAppend(),
                ];
            }

            $messageId = $this->normalizeMessageId($current->provider_draft_message_id);
            if ($messageId === '') {
                $messageId = $this->newMessageId($account);
            }

            $current->forceFill([
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED,
                'provider_draft_message_id' => '<'.$messageId.'>',
                'provider_draft_normalized_message_id' => $messageId,
                'provider_draft_deleted_at' => null,
                'provider_draft_error_code' => $this->newAppendReservationToken(),
                'provider_draft_error_message' => null,
            ])->save();

            return ['draft' => $current->refresh(), 'acquired' => true, 'refresh' => false];
        });
    }

    /**
     * Transition the reservation immediately before APPEND. Clearing stale UID
     * evidence prevents cleanup from targeting an older provider copy when the
     * new APPEND outcome later becomes ambiguous.
     */
    private function beginProviderAppend(EmailComposerDraft $draft, EmailFolder $folder): bool
    {
        return EmailComposerDraft::query()
            ->whereKey($draft->id)
            ->where('provider_draft_status', EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED)
            ->where('provider_draft_error_code', $draft->provider_draft_error_code)
            ->update([
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
                'provider_draft_folder_path' => $folder->path,
                'provider_draft_uid_validity' => null,
                'provider_draft_uid' => null,
                'provider_draft_synced_at' => null,
                'provider_draft_error_message' => null,
            ]) === 1;
    }

    private function markAppendError(EmailComposerDraft $draft, string $code, string $message): EmailComposerDraft
    {
        return DB::transaction(function () use ($draft, $code, $message): EmailComposerDraft {
            $current = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (in_array($current->provider_draft_status, [
                EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED,
                EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
            ], true) && hash_equals(
                (string) $current->provider_draft_error_code,
                (string) $draft->provider_draft_error_code,
            )) {
                $current->forceFill([
                    'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
                    'provider_draft_error_code' => $code,
                    'provider_draft_error_message' => Str::limit($message, 1000, ''),
                ])->save();
            }

            return $current->refresh();
        });
    }

    private function dispatchTargetedRefresh(
        EmailComposerDraft $draft,
        EmailAccount $account,
        EmailFolder $folder,
    ): void {
        try {
            RefreshEmailProviderDraftFolder::dispatch(
                $draft->id,
                $account->id,
                $folder->id,
                RefreshEmailProviderDraftFolder::DEFAULT_BATCH_SIZE,
                $this->expectedBindingVersion($draft, $account),
            );
        } catch (Throwable $exception) {
            // Provider acceptance and the durable duplicate guard must survive
            // a queue outage; the normal account poll remains the fallback.
            Log::warning('Provider Drafts targeted refresh could not be queued.', [
                'account_id' => $account->id,
                'folder_id' => $folder->id,
                'draft_id' => $draft->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function disconnectQuietly(
        ?ImapClient $client,
        EmailAccount $account,
        EmailComposerDraft $draft,
    ): void {
        if (! $client) {
            return;
        }

        try {
            $client->disconnect();
        } catch (Throwable $exception) {
            Log::warning('Provider Drafts IMAP disconnect failed.', [
                'account_id' => $account->id,
                'draft_id' => $draft->id,
                'exception' => $exception::class,
            ]);
        }
    }

    private function expectedBindingVersion(EmailComposerDraft $draft, EmailAccount $account): int
    {
        $version = (int) $draft->provider_binding_version;

        if ($version < 1) {
            throw new \App\Modules\Integration\Exceptions\EmailProviderSecurityException(
                'provider_binding_snapshot_missing',
            );
        }

        return $version;
    }

    private function newAppendReservationToken(): string
    {
        return EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVATION
            .':'.now()->getTimestamp()
            .':'.bin2hex(random_bytes(8));
    }

    private function appendReservationIsFresh(?string $token): bool
    {
        $prefix = preg_quote(EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVATION, '/');

        if (! preg_match('/^'.$prefix.':(?<reserved_at>\d{10}):[a-f0-9]{16}$/', (string) $token, $matches)) {
            return false;
        }

        return (int) $matches['reserved_at'] > now()->subSeconds(self::APPEND_RESERVATION_STALE_AFTER_SECONDS)->getTimestamp();
    }

    private function deletePreviousDraftCopy(ImapClient $client, EmailComposerDraft $draft, EmailFolder $folder): ?bool
    {
        if (! $this->hasProviderUid($draft)
            || $draft->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_DELETED
            || (string) $draft->provider_draft_folder_path !== (string) $folder->path
            || (int) $draft->provider_draft_uid_validity !== (int) $folder->uid_validity) {
            return null;
        }

        return $client->deleteByUid((int) $draft->provider_draft_uid, $folder->path);
    }

    private function ensureCurrentUidValidity(ImapClient $client, EmailComposerDraft $draft): void
    {
        $state = $client->folderState((string) $draft->provider_draft_folder_path);
        $currentUidValidity = (int) ($state['uid_validity'] ?? 0);

        if ($currentUidValidity > 0 && (int) $draft->provider_draft_uid_validity > 0
            && $currentUidValidity !== (int) $draft->provider_draft_uid_validity) {
            throw new \RuntimeException('The provider Drafts folder UIDVALIDITY changed before cleanup.');
        }
    }

    private function hasProviderUid(EmailComposerDraft $draft): bool
    {
        return trim((string) $draft->provider_draft_folder_path) !== ''
            && (int) $draft->provider_draft_uid_validity > 0
            && (int) $draft->provider_draft_uid > 0;
    }

    private function hideProviderDraftPlacement(EmailComposerDraft $draft): void
    {
        $placement = $draft->placement;

        if (! $placement
            || ! in_array($draft->mode, [\App\Modules\Email\Actions\SendEmailComposerMessage::MODE_PROVIDER_DRAFT], true)
            || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE) {
            return;
        }

        $placement->forceFill([
            'local_state' => EmailMailboxPlacement::LOCAL_HIDDEN,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'provider_missing_at' => now(),
            'last_reconciled_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function refreshProviderDraftPlacementProjection(EmailComposerDraft $draft, array $response): void
    {
        $placement = $draft->placement;
        $message = $placement?->message;

        if (! $placement
            || ! $message
            || $draft->mode !== \App\Modules\Email\Actions\SendEmailComposerMessage::MODE_PROVIDER_DRAFT
            || $placement->local_state !== EmailMailboxPlacement::LOCAL_ACTIVE) {
            return;
        }

        $message->forceFill([
            'message_id' => $draft->provider_draft_message_id,
            'subject' => $draft->subject,
            'to_json' => $this->recipientStrings((string) $draft->to_recipients),
            'cc_json' => $this->recipientStrings((string) $draft->cc_recipients),
            'body_html_sanitized' => $draft->body_html,
            'body_text' => $draft->body_text,
            'attachments_count' => $draft->attachments()->count(),
        ])->save();

        $placementUpdates = [
            'provider_draft' => true,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => ((int) $placement->sync_version) + 1,
            'last_reconciled_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ];

        $uidValidity = (int) ($response['imap_uid_validity'] ?? 0);
        $uid = (int) ($response['imap_uid'] ?? 0);

        if ($uidValidity > 0 && $uid > 0 && ! $this->placementUidExists($placement, $uidValidity, $uid)) {
            $placementUpdates['imap_uid_validity'] = $uidValidity;
            $placementUpdates['imap_uid'] = $uid;
            $placementUpdates['folder_path'] = (string) ($response['folder_path'] ?? $placement->folder_path);
        }

        $placement->forceFill($placementUpdates)->save();
    }

    private function placementUidExists(EmailMailboxPlacement $placement, int $uidValidity, int $uid): bool
    {
        return EmailMailboxPlacement::query()
            ->where('account_id', $placement->account_id)
            ->where('email_folder_id', $placement->email_folder_id)
            ->where('imap_uid_validity', $uidValidity)
            ->where('imap_uid', $uid)
            ->whereKeyNot($placement->id)
            ->exists();
    }

    private function rawDraftMessage(EmailComposerDraft $draft, EmailAccount $account, string $messageId): string
    {
        $bodyHtml = (string) ($draft->body_html ?: '<p><br></p>');
        $bodyText = (string) ($draft->body_text ?: BodyNormalizer::toText($bodyHtml));

        $email = (new DraftEmail)
            ->from(new Address($account->address, $account->from_name ?: $account->address))
            ->subject((string) $draft->subject)
            ->text($bodyText)
            ->html($bodyHtml);

        $to = $this->addresses((string) $draft->to_recipients);
        if ($to !== []) {
            $email->to(...$to);
        }

        $cc = $this->addresses((string) $draft->cc_recipients);
        if ($cc !== []) {
            $email->cc(...$cc);
        }

        $headers = $email->getHeaders();
        $headers->addIdHeader('Message-ID', $messageId);
        $headers->addDateHeader('Date', now()->toDateTimeImmutable());
        $headers->addTextHeader('X-Nexum-Draft-ID', (string) $draft->id);
        $headers->addTextHeader('X-Nexum-Draft-Mode', (string) $draft->mode);

        if (in_array($draft->mode, [
            \App\Modules\Email\Actions\SendEmailComposerMessage::MODE_REPLY,
            \App\Modules\Email\Actions\SendEmailComposerMessage::MODE_REPLY_ALL,
        ], true) && $draft->message) {
            $inReplyTo = $this->cleanHeaderValue($draft->message->message_id);
            if ($inReplyTo !== '') {
                $headers->addTextHeader('In-Reply-To', $inReplyTo);
            }

            $references = $this->cleanHeaderValue($draft->message->references);
            if ($inReplyTo !== '' && ! str_contains($references, $inReplyTo)) {
                $references = trim($references.' '.$inReplyTo);
            }
            if ($references !== '') {
                $headers->addTextHeader('References', $references);
            }
        }

        foreach ($draft->attachments as $attachment) {
            $path = Storage::disk($attachment->disk ?: 'local')->path($attachment->path);

            if (is_file($path)) {
                $email->attachFromPath($path, $attachment->filename, $attachment->content_type);
            }
        }

        return $email->toString();
    }

    /**
     * @return array<int, Address>
     */
    private function addresses(string $value): array
    {
        return collect(preg_split('/[,;\n]+/', $value) ?: [])
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->map(function (string $recipient): ?Address {
                if (preg_match('/^(?<name>.*)<(?<email>[^>]+)>$/', $recipient, $matches)) {
                    $email = trim($matches['email']);
                    $name = trim(trim($matches['name']), '"\' ');
                } else {
                    $email = $recipient;
                    $name = '';
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }

                return new Address(mb_strtolower($email), $name);
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{email: string, name: string}>
     */
    private function recipientStrings(string $value): array
    {
        return collect(preg_split('/[,;\n]+/', $value) ?: [])
            ->map(fn (string $recipient): string => trim($recipient))
            ->filter()
            ->map(function (string $recipient): ?array {
                if (preg_match('/^(?<name>.*)<(?<email>[^>]+)>$/', $recipient, $matches)) {
                    $email = trim($matches['email']);
                    $name = trim(trim($matches['name']), '"\' ');
                } else {
                    $email = $recipient;
                    $name = '';
                }

                if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }

                return [
                    'email' => mb_strtolower($email),
                    'name' => $name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function markDeleted(EmailComposerDraft $draft): EmailComposerDraft
    {
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_DELETED,
            'provider_draft_deleted_at' => now(),
            'provider_draft_error_code' => null,
            'provider_draft_error_message' => null,
        ])->save();

        return $draft->refresh();
    }

    private function markError(EmailComposerDraft $draft, string $code, string $message): EmailComposerDraft
    {
        $draft->forceFill([
            'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
            'provider_draft_error_code' => $code,
            'provider_draft_error_message' => Str::limit($message, 1000, ''),
        ])->save();

        return $draft->refresh();
    }

    private function cleanHeaderValue(?string $value): string
    {
        return trim((string) preg_replace('/[\r\n]+/', ' ', (string) $value));
    }

    private function newMessageId(EmailAccount $account): string
    {
        $domain = trim((string) Str::of($account->address)->after('@'));
        $domain = preg_replace('/[^a-z0-9.-]/i', '', $domain) ?: parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = $domain ?: 'nexum-psa.local';

        return 'nexum-draft-'.bin2hex(random_bytes(16)).'@'.$domain;
    }
}
