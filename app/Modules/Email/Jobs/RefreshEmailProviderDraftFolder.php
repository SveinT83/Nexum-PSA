<?php

namespace App\Modules\Email\Jobs;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailProviderDraftSyncService;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class RefreshEmailProviderDraftFolder implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const DEFAULT_BATCH_SIZE = 20;

    public const MAX_BATCH_SIZE = 50;

    public int $timeout = 120;

    public int $uniqueFor = 300;

    public function __construct(
        public int $draftId,
        public int $accountId,
        public int $folderId,
        public int $batchSize = self::DEFAULT_BATCH_SIZE,
        public ?int $providerBindingVersion = null,
    ) {
        // New jobs may recover the immutable draft reservation when an older
        // in-process caller omits the explicit argument. Legacy serialized
        // jobs skip this constructor and remain null, so they fail closed.
        if ($this->providerBindingVersion === null) {
            $draft = EmailComposerDraft::query()->find($this->draftId);
            $draftVersion = Schema::hasColumn('email_composer_drafts', 'provider_binding_version')
                ? (int) ($draft?->provider_binding_version ?? 0)
                : 0;
            $this->providerBindingVersion = $draftVersion > 0 ? $draftVersion : null;
        }
    }

    public function uniqueId(): string
    {
        return 'email-provider-draft-refresh:'.$this->accountId.':'.$this->folderId.':'.$this->draftId.':'.($this->providerBindingVersion ?? 'missing');
    }

    /** @return array<int, WithoutOverlapping> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('email-fetch-account:'.$this->accountId))
                ->shared()
                ->releaseAfter(5)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(EmailProviderDraftSyncService $providerDrafts): void
    {
        $draft = EmailComposerDraft::query()
            ->whereKey($this->draftId)
            ->where('email_account_id', $this->accountId)
            ->first();
        $account = EmailAccount::query()
            ->whereKey($this->accountId)
            ->where('is_active', true)
            ->first();
        $folder = EmailFolder::query()
            ->whereKey($this->folderId)
            ->where('account_id', $this->accountId)
            ->where('is_selectable', true)
            ->where('sync_enabled', true)
            ->first();

        if ($folder && ! $providerDrafts->isExactDraftsFolder($folder)) {
            $folder = null;
        } elseif ($folder && $folder->role !== EmailFolder::ROLE_DRAFTS) {
            $folder->forceFill(['role' => EmailFolder::ROLE_DRAFTS])->save();
        }

        if (! $draft || ! $account || ! $folder || ! $this->shouldRefresh($draft, $folder)) {
            return;
        }

        if (! $this->providerBindingVersion || $this->providerBindingVersion < 1) {
            $this->markFailed(
                $draft,
                $folder,
                'PROVIDER_DRAFT_BINDING_SNAPSHOT_MISSING',
                'The saved draft refresh has no immutable mailbox provider binding snapshot.',
            );

            return;
        }

        if ((Schema::hasColumn('email_composer_drafts', 'provider_binding_version')
                && (int) $draft->provider_binding_version !== $this->providerBindingVersion)
            || app(EmailAccountProviderRuntimeResolver::class)->bindingVersion($account) !== $this->providerBindingVersion) {
            $this->markFailed(
                $draft,
                $folder,
                'PROVIDER_DRAFT_BINDING_STALE',
                'The mailbox provider binding changed before the saved draft could be confirmed.',
            );

            return;
        }

        // A targeted refresh must never turn an uninitialized folder into a
        // historical import. The normal account poll owns the first baseline.
        if ($folder->live_start_uid === null || $folder->last_synced_at === null || (int) $folder->uid_validity <= 0) {
            return;
        }

        $client = $this->makeImapClient($account);

        try {
            $client->connect();
            $folderState = $client->folderState($folder->path);
            $uidValidity = (int) ($folderState['uid_validity'] ?? 0);
            $nextUid = (int) ($folderState['next_uid'] ?? 0);

            if ($uidValidity <= 0 || $nextUid <= 0) {
                $this->markFailed(
                    $draft,
                    $folder,
                    'PROVIDER_DRAFT_FOLDER_STATE_INVALID',
                    'The provider Drafts folder did not return a valid identity state.',
                );

                return;
            }

            if ($uidValidity !== (int) $folder->uid_validity
                || ((int) $draft->provider_draft_uid_validity > 0
                    && $uidValidity !== (int) $draft->provider_draft_uid_validity)) {
                $this->markFailed(
                    $draft,
                    $folder,
                    'PROVIDER_DRAFT_UIDVALIDITY_CHANGED',
                    'The provider Drafts folder identity changed before the saved draft could be confirmed.',
                );

                return;
            }

            $highWaterUid = $this->folderHighWaterUid($account, $folder);
            $batchSize = min(self::MAX_BATCH_SIZE, max(1, $this->batchSize));
            $expectedMessageId = $providerDrafts->normalizeMessageId($draft->provider_draft_message_id);

            if ($expectedMessageId === '') {
                $this->markFailed(
                    $draft,
                    $folder,
                    'PROVIDER_DRAFT_MESSAGE_ID_INVALID',
                    'The saved provider draft is missing a usable message identity.',
                );

                return;
            }

            $candidate = collect($client->fetchAfterUidInFolder($folder->path, $highWaterUid, $batchSize))
                ->filter(fn (array $payload): bool => (int) ($payload['imap_uid'] ?? 0) > $highWaterUid)
                ->take($batchSize)
                ->first(fn (array $payload): bool => $providerDrafts->normalizeMessageId(
                    isset($payload['message_id']) ? (string) $payload['message_id'] : null,
                ) === $expectedMessageId);

            if (! is_array($candidate)) {
                $this->markPending($draft);

                return;
            }

            $limitMb = max(1, (int) (CommonSetting::query()
                ->where('type', 'emailhub')
                ->where('name', 'size_limit_mb')
                ->value('value') ?? 25));
            $oversize = isset($candidate['size_bytes'])
                && (int) $candidate['size_bytes'] > $limitMb * 1024 * 1024;

            $storePayload = array_merge($candidate, [
                'account_id' => $account->id,
                'mailbox' => $folder->path,
                'uid_validity' => $uidValidity,
                'email_folder_id' => $folder->id,
                'is_oversize' => $oversize,
                'run_inbound_rules' => false,
                'allow_provider_mutation' => false,
                'provider_binding_version' => $this->providerBindingVersion,
            ]);
            EmailAccountProviderLockContext::withinHeld(
                (int) $account->id,
                fn () => StoreInboundMessage::dispatchSync($storePayload),
            );

            $folder->forceFill([
                'uid_validity' => $uidValidity,
                'uid_next' => $nextUid,
                'highest_modseq' => $folderState['highest_modseq'] ?? $folder->highest_modseq,
                'exists_count' => $folderState['exists_count'] ?? $folder->exists_count,
                'unseen_count' => $folderState['unseen_count'] ?? $folder->unseen_count,
                'sync_status' => EmailFolder::SYNC_SYNCED,
                'last_synced_at' => now(),
                'sync_error_code' => null,
                'sync_error_message' => null,
            ])->save();
        } catch (Throwable $exception) {
            $this->markFailed(
                $draft,
                $folder,
                'PROVIDER_DRAFT_REFRESH_FAILED',
                'The saved provider draft could not be confirmed by the targeted folder refresh.',
            );
            Log::warning('Targeted provider Drafts refresh failed.', [
                'account_id' => $this->accountId,
                'folder_id' => $this->folderId,
                'draft_id' => $this->draftId,
                'exception' => $exception::class,
            ]);

            throw new RuntimeException('Targeted provider Drafts refresh failed.');
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable $exception) {
                Log::notice('Targeted provider Drafts disconnect failed.', [
                    'account_id' => $this->accountId,
                    'folder_id' => $this->folderId,
                    'draft_id' => $this->draftId,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return new ImapClient($account, (int) $this->providerBindingVersion);
    }

    private function shouldRefresh(EmailComposerDraft $draft, EmailFolder $folder): bool
    {
        return $draft->status === EmailComposerDraft::STATUS_ACTIVE
            && $draft->provider_draft_status !== EmailComposerDraft::PROVIDER_DRAFT_DELETED
            && trim((string) $draft->provider_draft_message_id) !== ''
            && (string) $draft->provider_draft_folder_path === (string) $folder->path;
    }

    private function folderHighWaterUid(EmailAccount $account, EmailFolder $folder): int
    {
        $messageHighWater = (int) $account->messages()
            ->withTrashed()
            ->where('mailbox', $folder->path)
            ->where('imap_uid_validity', (int) $folder->uid_validity)
            ->max('imap_uid');
        $placementHighWater = (int) EmailMailboxPlacement::query()
            ->where('account_id', $account->id)
            ->where('email_folder_id', $folder->id)
            ->where('imap_uid_validity', (int) $folder->uid_validity)
            ->when(
                $folder->active_uid_namespace_id,
                fn ($query) => $query->where('uid_namespace_id', $folder->active_uid_namespace_id),
            )
            ->max('imap_uid');

        return max((int) $folder->live_start_uid, $messageHighWater, $placementHighWater);
    }

    private function markPending(EmailComposerDraft $draft): void
    {
        DB::transaction(function () use ($draft): void {
            $current = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->first();

            // A miss only means the targeted cursor did not find new evidence.
            // It must not overwrite a stronger normal-sync reconciliation.
            if (! $current || ! in_array($current->provider_draft_status, [
                null,
                EmailComposerDraft::PROVIDER_DRAFT_LOCAL_ONLY,
                EmailComposerDraft::PROVIDER_DRAFT_PENDING,
            ], true)) {
                return;
            }

            $current->forceFill([
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_PENDING,
                'provider_draft_error_code' => null,
                'provider_draft_error_message' => null,
            ])->save();
        });
    }

    private function markFailed(
        EmailComposerDraft $draft,
        EmailFolder $folder,
        string $code,
        string $message,
    ): void {
        DB::transaction(function () use ($draft, $code, $message): void {
            $current = EmailComposerDraft::query()
                ->whereKey($draft->id)
                ->lockForUpdate()
                ->first();

            if (! $current
                || $current->provider_draft_status === EmailComposerDraft::PROVIDER_DRAFT_DELETED
                || in_array($current->provider_draft_status, [
                    EmailComposerDraft::PROVIDER_DRAFT_APPEND_RESERVED,
                    EmailComposerDraft::PROVIDER_DRAFT_APPEND_STARTED,
                ], true)
                || $current->hasUnresolvedProviderAppend()) {
                return;
            }

            $current->forceFill([
                'provider_draft_status' => EmailComposerDraft::PROVIDER_DRAFT_ERROR,
                'provider_draft_error_code' => $code,
                'provider_draft_error_message' => $message,
            ])->save();
        });

        $folder->forceFill([
            'sync_status' => EmailFolder::SYNC_ERROR,
            'sync_error_code' => $code,
            'sync_error_message' => $message,
        ])->save();
    }
}
