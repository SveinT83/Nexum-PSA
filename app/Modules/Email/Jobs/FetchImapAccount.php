<?php

namespace App\Modules\Email\Jobs;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailFolderProjector;
use App\Modules\Email\Services\ImapClient;
use App\Modules\Email\Support\EmailAccountProviderLock;
use App\Modules\Email\Support\EmailAccountProviderLockContext;
use App\Modules\Email\Support\EmailProviderPath;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

class FetchImapAccount implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120; // seconds

    public int $tries = 40;

    public int $maxExceptions = 10;

    /** @var array<int, int> */
    public array $backoff = [15, 30, 60];

    public ?string $queuedAt = null;

    private ?bool $messageUidValidityColumnAvailable = null;

    protected ?int $providerBindingVersion = null;

    public function __construct(
        public int $accountId,
        public int $batchSize = 20,
        public bool $syncStore = false
    ) {
        $account = EmailAccount::query()->find($this->accountId);
        $this->providerBindingVersion = $account
            ? app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
            : null;
        $this->queuedAt = now()->toIso8601String();
        $this->onQueue('email');
    }

    /**
     * Overlap releases count as queue attempts. A time boundary takes
     * precedence over a worker-level --tries=1 setting, so a fetch can wait
     * out the shared provider lock without retrying forever.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addMinutes(10);
    }

    public function middleware(): array
    {
        return [
            EmailAccountProviderLock::middleware($this->accountId, $this->timeout),
        ];
    }

    public function handle(): void
    {
        $account = EmailAccount::find($this->accountId);
        if (! $account || ! $account->is_active) {
            return; // skip inactive or missing accounts
        }

        // A poll queued under one provider binding must not silently read a
        // newly rebound mailbox. Older serialized jobs have no frozen value
        // and are superseded before opening a socket.
        if (! $this->providerBindingVersion || $this->providerBindingVersion < 1) {
            Log::notice('IMAP account polling was superseded because its provider binding snapshot is missing.', [
                'account_id' => $account->id,
                'reason' => 'provider_binding_snapshot_missing',
            ]);

            return;
        }

        if (app(EmailAccountProviderRuntimeResolver::class)->captureBindingVersion($account)
            !== $this->providerBindingVersion) {
            Log::notice('IMAP account polling was superseded after a provider binding change.', [
                'account_id' => $account->id,
                'reason' => 'provider_binding_stale',
            ]);

            return;
        }

        $client = $this->makeImapClient($account);
        try {
            $client->connect();
        } catch (Throwable $exception) {
            [$code, $message] = $this->safeImapFailure($exception, 'IMAP_CONNECT');
            $this->recordAccountError($account, $code, $message);
            Log::warning('IMAP account polling connection failed.', [
                'account_id' => $account->id,
                'code' => $code,
                'exception' => $exception::class,
            ]);

            throw new RuntimeException('IMAP account polling could not connect.');
        }

        try {
            $batchSize = max(1, $this->batchSize);
            $projector = app(EmailFolderProjector::class);

            if ($projector->available()) {
                $this->syncProviderFolders($account, $client, $projector, $batchSize);

                return;
            }

            $mailboxState = $client->mailboxState();
            $uidValidity = (int) $mailboxState['uid_validity'];
            $nextUid = (int) $mailboxState['next_uid'];

            if ($uidValidity <= 0 || $nextUid <= 0) {
                $account->forceFill([
                    'last_error_code' => 'IMAP_MAILBOX_STATE',
                    'last_error_message' => 'INBOX did not return a valid UIDVALIDITY/UIDNEXT state.',
                ])->save();
                Log::warning('IMAP mailbox state is incomplete; automatic ingest stopped.', [
                    'account' => $account->id,
                ]);

                return;
            }

            if ($account->imap_uid_validity === null || $account->imap_live_start_uid === null) {
                // First activation is a forward-only baseline. Messages that
                // already existed in the mailbox are never implicit work.
                $account->forceFill([
                    'imap_uid_validity' => $uidValidity,
                    'imap_live_start_uid' => max(0, $nextUid - 1),
                    'imap_live_cursor_initialized_at' => now(),
                    'last_successful_fetch_at' => now(),
                    'last_error_code' => null,
                    'last_error_message' => null,
                ])->save();

                return;
            }

            if ((int) $account->imap_uid_validity !== $uidValidity) {
                // UID reuse after a mailbox reset can route old mail as new.
                // Fail closed until an operator explicitly re-baselines it.
                $account->forceFill([
                    'last_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
                    'last_error_message' => 'INBOX UIDVALIDITY changed; automatic ingest requires an explicit re-baseline.',
                ])->save();
                Log::warning('IMAP UIDVALIDITY changed; automatic ingest stopped.', [
                    'account' => $account->id,
                    'expected_uid_validity' => (int) $account->imap_uid_validity,
                    'actual_uid_validity' => $uidValidity,
                ]);

                return;
            }

            $storedMessages = $account->messages()
                ->withTrashed()
                ->where('mailbox', 'INBOX');
            if ($this->messageUidValidityColumnAvailable()) {
                $storedMessages->where('imap_uid_validity', $uidValidity);
            }
            $highestStoredUid = (int) $storedMessages->max('imap_uid');
            $liveHighWaterUid = max((int) $account->imap_live_start_uid, $highestStoredUid);

            // Drain the oldest new UIDs first. This remains bounded without
            // losing a burst larger than one poll batch.
            $messages = $this->unstoredMessages(
                $account,
                $client->fetchAfterUid($liveHighWaterUid, $batchSize),
                uidValidity: $uidValidity,
            )
                ->filter(fn (array $payload): bool => (int) $payload['imap_uid'] > $liveHighWaterUid)
                ->take($batchSize)
                ->values();

            $account->forceFill([
                'last_successful_fetch_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            $settings = CommonSetting::query()
                ->where('type', 'emailhub')
                ->pluck('value', 'name')
                ->all();
            $limitMb = (int) ($settings['size_limit_mb'] ?? 25);

            foreach ($messages as $payload) {
                $oversize = isset($payload['size_bytes'])
                    && $payload['size_bytes'] > $limitMb * 1024 * 1024;

                if ($this->syncStore) {
                    $storePayload = array_merge($payload, [
                        'account_id' => $account->id,
                        'mailbox' => 'INBOX',
                        'uid_validity' => $uidValidity,
                        'is_oversize' => $oversize,
                        'allow_provider_mutation' => true,
                        'provider_binding_version' => $this->providerBindingVersion,
                    ]);
                    EmailAccountProviderLockContext::withinHeld(
                        (int) $account->id,
                        fn () => StoreInboundMessage::dispatchSync($storePayload),
                    );
                } else {
                    StoreInboundMessage::dispatch(array_merge($payload, [
                        'account_id' => $account->id,
                        'mailbox' => 'INBOX',
                        'uid_validity' => $uidValidity,
                        'is_oversize' => $oversize,
                        'allow_provider_mutation' => true,
                        'provider_binding_version' => $this->providerBindingVersion,
                    ]));
                }
            }
        } catch (Throwable $exception) {
            [$code, $message] = $this->safeImapFailure($exception, 'IMAP_FETCH_FAILED');
            $this->recordAccountError($account, $code, $message);
            Log::warning('IMAP account polling failed.', [
                'account_id' => $account->id,
                'code' => $code,
                'exception' => $exception::class,
                'failure_source' => $this->safeFailureSource($exception),
            ]);

            throw new RuntimeException('IMAP account polling could not complete.');
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable $exception) {
                Log::warning('IMAP account polling disconnect failed.', [
                    'account_id' => $account->id,
                    'exception' => $exception::class,
                ]);
            }
        }
    }

    public function failed(?Throwable $exception): void
    {
        $account = EmailAccount::find($this->accountId);
        if (! $account) {
            return;
        }

        $queuedAt = filled($this->queuedAt) ? Carbon::parse($this->queuedAt) : null;
        if ($queuedAt && $account->last_successful_fetch_at?->greaterThanOrEqualTo($queuedAt)) {
            return;
        }

        // Preserve the stronger fail-closed namespace evidence when a later
        // queue failure callback runs for the same fetch.
        if ($account->last_error_code !== 'IMAP_UIDVALIDITY_CHANGED') {
            $this->recordAccountError(
                $account,
                'IMAP_FETCH_RETRY_EXHAUSTED',
                'Mailbox polling could not start or complete within the retry window.',
            );
        }

        Log::warning('IMAP account polling exhausted its retry window.', [
            'account_id' => $account->id,
            'code' => 'IMAP_FETCH_RETRY_EXHAUSTED',
            'exception' => $exception ? $exception::class : null,
        ]);
    }

    protected function makeImapClient(EmailAccount $account): ImapClient
    {
        return new ImapClient($account, (int) $this->providerBindingVersion);
    }

    private function syncProviderFolders(
        EmailAccount $account,
        ImapClient $client,
        EmailFolderProjector $projector,
        int $batchSize,
    ): void {
        $providerFolders = collect($client->folders());
        if ($providerFolders->isEmpty()) {
            $providerFolders = collect([[
                'path' => 'INBOX',
                ...$client->mailboxState(),
            ]]);
        }

        // Some IMAP servers return folder discovery successfully but omit
        // STATUS metadata for a subset of selectable folders. Retry an exact
        // per-folder state query before projecting IMAP_FOLDER_STATE.
        $providerFolders = $providerFolders
            ->map(fn (array $folder): array => $this->resolveDiscoveredFolderState($account, $client, $folder));

        $providerFolders
            ->each(fn (array $folder): ?EmailFolder => $projector->upsertFolder($account, $folder));

        $this->mirrorLegacyInboxBaselineToFolder($account);

        $folders = $account->folders()
            ->where('sync_enabled', true)
            ->where('is_selectable', true)
            ->orderByRaw("case when role = 'inbox' then 0 else 1 end")
            ->orderBy('path')
            ->get();

        $settings = CommonSetting::query()
            ->where('type', 'emailhub')
            ->pluck('value', 'name')
            ->all();
        $limitMb = (int) ($settings['size_limit_mb'] ?? 25);
        $anyFolderFailed = false;

        foreach ($folders as $folder) {
            $folderState = $providerFolders->firstWhere('path', $folder->path);
            if (! $this->hasUsableFolderState($folderState)) {
                $folderState = $this->resolveExactFolderState($account, $client, $folder->path, $folder->isInbox(), $folderState);
            }
            $uidValidity = (int) ($folderState['uid_validity'] ?? 0);
            $nextUid = (int) ($folderState['next_uid'] ?? $folderState['uid_next'] ?? 0);

            if ($uidValidity <= 0 || $nextUid <= 0) {
                $anyFolderFailed = true;
                $this->markFolderError($folder, 'IMAP_FOLDER_STATE', 'Folder did not return a valid UIDVALIDITY/UIDNEXT state.');

                continue;
            }

            if ($this->folderNeedsInitialBaseline($folder, $account)) {
                $this->baselineFolder($folder, $uidValidity, $nextUid);
                $this->mirrorInboxBaselineToAccount($account, $folder, $uidValidity, $nextUid);

                continue;
            }

            if ((int) $folder->uid_validity !== $uidValidity) {
                $anyFolderFailed = true;
                $this->markFolderError($folder, 'IMAP_UIDVALIDITY_CHANGED', 'Folder UIDVALIDITY changed; automatic sync requires explicit re-baseline.');

                if ($folder->isInbox()) {
                    $account->forceFill([
                        'last_error_code' => 'IMAP_UIDVALIDITY_CHANGED',
                        'last_error_message' => 'INBOX UIDVALIDITY changed; automatic ingest requires an explicit re-baseline.',
                    ])->save();
                }

                continue;
            }

            $highWaterUid = $this->folderHighWaterUid($account, $folder);
            $fetchedMessages = $folder->isInbox()
                ? $client->fetchAfterUid($highWaterUid, $batchSize)
                : $client->fetchAfterUidInFolder($folder->path, $highWaterUid, $batchSize);

            $messages = $this->unstoredMessages(
                $account,
                $fetchedMessages,
                $folder,
                $uidValidity,
            )
                ->filter(fn (array $payload): bool => (int) $payload['imap_uid'] > $highWaterUid)
                ->take($batchSize)
                ->values();

            foreach ($messages as $payload) {
                $oversize = isset($payload['size_bytes'])
                    && $payload['size_bytes'] > $limitMb * 1024 * 1024;

                $payload = array_merge($payload, [
                    'account_id' => $account->id,
                    'mailbox' => $folder->path,
                    'uid_validity' => $uidValidity,
                    'email_folder_id' => $folder->id,
                    'is_oversize' => $oversize,
                    'run_inbound_rules' => $folder->isInbox() && $account->allowsTicketIngress(),
                    'allow_provider_mutation' => true,
                    'provider_binding_version' => $this->providerBindingVersion,
                ]);

                if ($this->syncStore) {
                    EmailAccountProviderLockContext::withinHeld(
                        (int) $account->id,
                        fn () => StoreInboundMessage::dispatchSync($payload),
                    );
                } else {
                    StoreInboundMessage::dispatch($payload);
                }
            }

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
        }

        if (! $anyFolderFailed) {
            $account->forceFill([
                'last_successful_fetch_at' => now(),
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();
        }
    }

    private function unstoredMessages(
        EmailAccount $account,
        array $messages,
        ?EmailFolder $folder = null,
        ?int $uidValidity = null,
    ): Collection {
        $candidates = collect($messages)
            ->filter(fn (array $payload): bool => (int) ($payload['imap_uid'] ?? 0) > 0)
            ->unique(fn (array $payload): int => (int) $payload['imap_uid'])
            ->values();

        if ($candidates->isEmpty()) {
            return collect();
        }

        // Include soft-deleted rows because the database uniqueness boundary
        // still reserves their account/mailbox/UID identity.
        $mailbox = $folder?->path ?? 'INBOX';
        $storedQuery = $account->messages()
            ->withTrashed()
            ->where('mailbox', $mailbox)
            ->whereIn('imap_uid', $candidates->pluck('imap_uid'));
        $uidValidity ??= $folder?->uid_validity;
        if ($uidValidity !== null && $this->messageUidValidityColumnAvailable()) {
            $storedQuery->where('imap_uid_validity', max(0, $uidValidity));
        }
        $stored = $storedQuery
            ->pluck('imap_uid')
            ->map(fn ($uid): int => (int) $uid)
            ->flip();

        return $candidates
            ->reject(fn (array $payload): bool => $stored->has((int) $payload['imap_uid']))
            ->values();
    }

    private function folderNeedsInitialBaseline(EmailFolder $folder, EmailAccount $account): bool
    {
        if ($folder->isInbox() && $account->imap_uid_validity !== null && $account->imap_live_start_uid !== null) {
            return false;
        }

        return $folder->live_start_uid === null
            || $folder->last_synced_at === null
            || $folder->sync_status === EmailFolder::SYNC_SHADOW;
    }

    private function baselineFolder(EmailFolder $folder, int $uidValidity, int $nextUid): void
    {
        $folder->forceFill([
            'uid_validity' => $uidValidity,
            'uid_next' => $nextUid,
            'live_start_uid' => max(0, $nextUid - 1),
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => now(),
            'sync_error_code' => null,
            'sync_error_message' => null,
        ])->save();
    }

    private function mirrorInboxBaselineToAccount(EmailAccount $account, EmailFolder $folder, int $uidValidity, int $nextUid): void
    {
        if (! $folder->isInbox() || ($account->imap_uid_validity !== null && $account->imap_live_start_uid !== null)) {
            return;
        }

        $account->forceFill([
            'imap_uid_validity' => $uidValidity,
            'imap_live_start_uid' => max(0, $nextUid - 1),
            'imap_live_cursor_initialized_at' => now(),
            'last_successful_fetch_at' => now(),
            'last_error_code' => null,
            'last_error_message' => null,
        ])->save();
    }

    private function mirrorLegacyInboxBaselineToFolder(EmailAccount $account): void
    {
        if ($account->imap_uid_validity === null || $account->imap_live_start_uid === null) {
            return;
        }

        $folder = $account->folders()
            ->where('role', EmailFolder::ROLE_INBOX)
            ->first();

        if (! $folder || $folder->live_start_uid !== null) {
            return;
        }

        $folder->forceFill([
            'uid_validity' => (int) $account->imap_uid_validity,
            'live_start_uid' => (int) $account->imap_live_start_uid,
            'sync_status' => EmailFolder::SYNC_BASELINED,
            'last_synced_at' => $account->imap_live_cursor_initialized_at ?? $account->last_successful_fetch_at ?? now(),
        ])->save();
    }

    private function folderHighWaterUid(EmailAccount $account, EmailFolder $folder): int
    {
        $messageQuery = $account->messages()
            ->withTrashed()
            ->where('mailbox', $folder->path);
        if ($this->messageUidValidityColumnAvailable()) {
            $messageQuery->where('imap_uid_validity', (int) $folder->uid_validity);
        }
        $messageHighWater = (int) $messageQuery->max('imap_uid');
        $placementHighWater = (int) $folder->placements()
            ->where('imap_uid_validity', (int) $folder->uid_validity)
            ->max('imap_uid');

        $baseline = (int) $folder->live_start_uid;
        if ($folder->isInbox()) {
            $baseline = max($baseline, (int) $account->imap_live_start_uid);
        }

        return max($baseline, $messageHighWater, $placementHighWater);
    }

    private function markFolderError(EmailFolder $folder, string $code, string $message): void
    {
        $folder->forceFill([
            'sync_status' => EmailFolder::SYNC_ERROR,
            'sync_error_code' => $code,
            'sync_error_message' => $message,
        ])->save();
    }

    /** @param array<string, mixed> $folder */
    private function resolveDiscoveredFolderState(
        EmailAccount $account,
        ImapClient $client,
        array $folder,
    ): array {
        if (($folder['is_selectable'] ?? true) === false || $this->hasUsableFolderState($folder)) {
            return $folder;
        }

        $path = EmailProviderPath::normalize((string) ($folder['path'] ?? ''));

        return $this->resolveExactFolderState(
            $account,
            $client,
            $path,
            EmailFolder::inferRole($path, $folder['special_use'] ?? null, $folder['delimiter'] ?? null) === EmailFolder::ROLE_INBOX,
            $folder,
        );
    }

    /**
     * @param  array<string, mixed>|null  $discovered
     * @return array<string, mixed>
     */
    private function resolveExactFolderState(
        EmailAccount $account,
        ImapClient $client,
        string $path,
        bool $isInbox,
        ?array $discovered = null,
    ): array {
        try {
            $state = $isInbox ? $client->mailboxState() : $client->folderState($path);
        } catch (Throwable $exception) {
            Log::warning('IMAP folder state fallback failed.', [
                'account_id' => $account->id,
                'code' => 'IMAP_FOLDER_STATE',
                'exception' => $exception::class,
            ]);

            return $discovered ?? [];
        }

        $resolved = array_merge($discovered ?? [], $state, [
            'uid_next' => $state['next_uid'] ?? $state['uid_next'] ?? ($discovered['uid_next'] ?? null),
        ]);

        if ($this->hasUsableFolderState($resolved)) {
            $resolved['sync_status'] = EmailFolder::SYNC_SYNCED;
            $resolved['sync_error_code'] = null;
            $resolved['sync_error_message'] = null;
        }

        return $resolved;
    }

    /** @param array<string, mixed>|null $state */
    private function hasUsableFolderState(?array $state): bool
    {
        return (int) ($state['uid_validity'] ?? 0) > 0
            && (int) ($state['next_uid'] ?? $state['uid_next'] ?? 0) > 0;
    }

    /** @return array{0: string, 1: string} */
    private function safeImapFailure(Throwable $exception, string $fallbackCode): array
    {
        $providerMessage = mb_strtolower($exception->getMessage());

        if (str_contains($providerMessage, 'auth') || str_contains($providerMessage, 'credential')) {
            return ['IMAP_AUTH', 'IMAP authentication failed. Check the configured account credentials.'];
        }

        if (str_contains($providerMessage, 'tls') || str_contains($providerMessage, 'ssl') || str_contains($providerMessage, 'certificate')) {
            return ['IMAP_TLS', 'IMAP TLS negotiation failed. Check the account connection settings.'];
        }

        if (str_contains($providerMessage, 'timeout') || str_contains($providerMessage, 'timed out')) {
            return ['IMAP_CONNECT', 'IMAP connection timed out. Check provider availability and connection settings.'];
        }

        return match ($fallbackCode) {
            'IMAP_CONNECT' => ['IMAP_CONNECT', 'IMAP connection failed. Check provider availability and connection settings.'],
            default => ['IMAP_FETCH_FAILED', 'Mailbox polling failed before the provider state could be synchronized.'],
        };
    }

    private function recordAccountError(EmailAccount $account, string $code, string $message): void
    {
        $account->forceFill([
            'last_error_code' => $code,
            'last_error_message' => $message,
        ])->save();
    }

    /**
     * Keep operational diagnostics useful without logging provider messages,
     * mailbox paths, credentials, or arbitrary exception text.
     */
    private function safeFailureSource(Throwable $exception): string
    {
        $projectRoot = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $file = $exception->getFile();

        if (! str_starts_with($file, $projectRoot)) {
            return 'external';
        }

        $relative = substr($file, strlen($projectRoot));
        if (! str_starts_with($relative, 'app/Modules/Email/')) {
            return 'application';
        }

        return $relative.':'.max(1, $exception->getLine());
    }

    private function messageUidValidityColumnAvailable(): bool
    {
        return $this->messageUidValidityColumnAvailable ??= Schema::hasColumn(
            'email_messages',
            'imap_uid_validity',
        );
    }
}
