<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailProviderInventoryFolder;
use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use Illuminate\Support\Collection;
use Throwable;

class EmailProviderInventoryScanner
{
    protected int $expectedProviderBindingVersion = 0;

    public const DEFAULT_MAX_FOLDERS = 50;

    public const DEFAULT_MAX_MESSAGES_PER_FOLDER = 2000;

    public const DEFAULT_BATCH_SIZE = 100;

    public function __construct(
        private readonly EmailProviderMessageIdentity $identity,
    ) {}

    /**
     * Read a bounded, stable UID inventory without retaining provider payloads.
     * The returned message evidence lives only for the current reconciliation run.
     *
     * @return array<string, mixed>
     */
    public function scan(
        EmailAccount $account,
        int $maxFolders = self::DEFAULT_MAX_FOLDERS,
        int $maxMessagesPerFolder = self::DEFAULT_MAX_MESSAGES_PER_FOLDER,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
        int $providerBindingVersion = 0,
    ): array {
        $maxFolders = max(1, $maxFolders);
        $maxMessagesPerFolder = max(1, $maxMessagesPerFolder);
        $batchSize = max(1, min($maxMessagesPerFolder, $batchSize));
        $startedAt = now();
        if ($providerBindingVersion < 1) {
            throw new EmailProviderSecurityException('provider_binding_snapshot_missing');
        }
        $this->expectedProviderBindingVersion = $providerBindingVersion;
        $client = $this->makeClient($account);

        try {
            $client->connect();
        } catch (Throwable) {
            return $this->failedSnapshot(
                $account,
                $startedAt,
                $maxFolders,
                $maxMessagesPerFolder,
                'provider_connect_failed',
            );
        }

        try {
            try {
                $remoteFolders = collect($client->folders())
                    ->filter(fn (array $folder): bool => (bool) ($folder['is_selectable'] ?? true))
                    ->values();
            } catch (Throwable) {
                return $this->failedSnapshot(
                    $account,
                    $startedAt,
                    $maxFolders,
                    $maxMessagesPerFolder,
                    'provider_folder_discovery_failed',
                );
            }

            if ($remoteFolders->isEmpty()) {
                return $this->failedSnapshot(
                    $account,
                    $startedAt,
                    $maxFolders,
                    $maxMessagesPerFolder,
                    'provider_folder_inventory_empty',
                    'incomplete',
                );
            }

            if ($remoteFolders->count() > $maxFolders) {
                return [
                    'account_id' => (int) $account->id,
                    'provider' => 'imap',
                    'status' => 'incomplete',
                    'failure_code' => 'folder_limit_exceeded',
                    'max_folders' => $maxFolders,
                    'max_messages_per_folder' => $maxMessagesPerFolder,
                    'started_at' => $startedAt,
                    'finished_at' => now(),
                    'scope_fingerprint' => null,
                    'folders' => [],
                    'reported_folder_count' => $remoteFolders->count(),
                ];
            }

            $duplicates = $remoteFolders
                ->groupBy(fn (array $folder): string => (string) ($folder['path'] ?? ''))
                ->filter(fn (Collection $folders, string $path): bool => $path === '' || $folders->count() > 1);

            if ($duplicates->isNotEmpty()) {
                return $this->failedSnapshot(
                    $account,
                    $startedAt,
                    $maxFolders,
                    $maxMessagesPerFolder,
                    'duplicate_or_missing_provider_folder_path',
                    'incomplete',
                );
            }

            $localFolders = EmailFolder::query()
                ->where('account_id', $account->id)
                ->get()
                ->keyBy('path');
            $folderSnapshots = [];

            foreach ($remoteFolders as $remoteFolder) {
                $path = (string) $remoteFolder['path'];
                /** @var EmailFolder|null $localFolder */
                $localFolder = $localFolders->get($path);

                if (! $localFolder) {
                    $folderSnapshots[] = $this->unavailableFolderSnapshot(
                        $account,
                        $path,
                        'unprojected_provider_folder',
                        null,
                    );

                    continue;
                }

                $folderSnapshots[] = $this->scanFolder(
                    $account,
                    $localFolder,
                    $client,
                    $maxMessagesPerFolder,
                    $batchSize,
                );
            }

            $remotePaths = $remoteFolders->pluck('path')->map(fn (mixed $path): string => (string) $path);
            $localFoldersWithActivePlacements = EmailFolder::query()
                ->where('account_id', $account->id)
                ->whereHas('placements', fn ($placements) => $placements->where('local_state', 'active'))
                ->get();

            foreach ($localFoldersWithActivePlacements as $localFolder) {
                if ($remotePaths->contains($localFolder->path)) {
                    continue;
                }

                $folderSnapshots[] = $this->unavailableFolderSnapshot(
                    $account,
                    $localFolder->path,
                    'provider_folder_missing_or_renamed',
                    $localFolder,
                );
            }

            $complete = collect($folderSnapshots)->every(
                fn (array $folder): bool => $folder['status'] === EmailProviderInventoryFolder::STATUS_COMPLETE,
            );
            $scopeFacts = collect($folderSnapshots)
                ->sortBy('folder_path')
                ->map(fn (array $folder): string => implode('|', [
                    $folder['folder_path'],
                    $folder['status'],
                    (string) ($folder['observed_uid_validity'] ?? ''),
                    (string) ($folder['end_uid_next'] ?? ''),
                    (string) ($folder['end_exists_count'] ?? ''),
                    (string) ($folder['inventory_fingerprint'] ?? ''),
                ]))
                ->values()
                ->all();

            return [
                'account_id' => (int) $account->id,
                'provider' => 'imap',
                'status' => $complete ? 'complete' : 'incomplete',
                'failure_code' => $complete ? null : 'folder_inventory_incomplete',
                'max_folders' => $maxFolders,
                'max_messages_per_folder' => $maxMessagesPerFolder,
                'started_at' => $startedAt,
                'finished_at' => now(),
                'scope_fingerprint' => hash('sha256', implode("\n", $scopeFacts)),
                'folders' => $folderSnapshots,
                'reported_folder_count' => $remoteFolders->count(),
            ];
        } finally {
            try {
                $client->disconnect();
            } catch (Throwable) {
                // The inventory result already contains the provider evidence needed
                // for the run. A disconnect failure must not invent mailbox state.
            }
        }
    }

    protected function makeClient(EmailAccount $account): ImapClient
    {
        return new ImapClient($account, $this->expectedProviderBindingVersion);
    }

    /**
     * @return array<string, mixed>
     */
    private function scanFolder(
        EmailAccount $account,
        EmailFolder $folder,
        ImapClient $client,
        int $maxMessagesPerFolder,
        int $batchSize,
    ): array {
        $startedAt = now();
        $expectedUidValidity = (int) $folder->uid_validity;

        if ($expectedUidValidity <= 0) {
            return $this->unavailableFolderSnapshot(
                $account,
                $folder->path,
                'uidvalidity_not_baselined',
                $folder,
                $startedAt,
            );
        }

        try {
            $start = $client->folderState($folder->path);
        } catch (Throwable) {
            return $this->unavailableFolderSnapshot(
                $account,
                $folder->path,
                'provider_folder_state_failed',
                $folder,
                $startedAt,
                EmailProviderInventoryFolder::STATUS_FAILED,
            );
        }

        $observedUidValidity = (int) ($start['uid_validity'] ?? 0);
        $startUidNext = (int) ($start['next_uid'] ?? 0);
        $startExists = isset($start['exists_count']) ? (int) $start['exists_count'] : null;

        if ($observedUidValidity <= 0 || $startUidNext <= 0 || $startExists === null) {
            return $this->stateFailureSnapshot(
                $account,
                $folder,
                $startedAt,
                'provider_folder_state_incomplete',
                $observedUidValidity,
                $startUidNext,
                $startExists,
            );
        }

        if ($expectedUidValidity !== $observedUidValidity) {
            return $this->stateFailureSnapshot(
                $account,
                $folder,
                $startedAt,
                'uidvalidity_changed',
                $observedUidValidity,
                $startUidNext,
                $startExists,
            );
        }

        if ($startExists > $maxMessagesPerFolder) {
            return $this->stateFailureSnapshot(
                $account,
                $folder,
                $startedAt,
                'message_limit_exceeded',
                $observedUidValidity,
                $startUidNext,
                $startExists,
            );
        }

        $messages = [];
        $cursor = 0;
        $scanReason = null;

        try {
            while (count($messages) < $startExists) {
                $batch = $client->fetchAfterUidInFolder($folder->path, $cursor, $batchSize);

                if ($batch === []) {
                    break;
                }

                usort($batch, fn (array $left, array $right): int => ((int) ($left['imap_uid'] ?? 0)) <=> ((int) ($right['imap_uid'] ?? 0)));
                $previousCursor = $cursor;

                foreach ($batch as $payload) {
                    $uid = (int) ($payload['imap_uid'] ?? 0);

                    if ($uid <= $cursor || $uid >= $startUidNext || isset($messages[$uid])) {
                        $scanReason = 'non_stable_or_duplicate_uid_page';
                        break 2;
                    }

                    $messages[$uid] = [
                        'imap_uid' => $uid,
                        'uid_validity' => $observedUidValidity,
                        'identity_fingerprint' => $this->identity->forProviderPayload($payload),
                        'provider_seen' => (bool) ($payload['provider_seen'] ?? false),
                        'provider_flagged' => (bool) ($payload['provider_flagged'] ?? false),
                        'provider_deleted' => (bool) ($payload['provider_deleted'] ?? false),
                        'provider_draft' => (bool) ($payload['provider_draft'] ?? false),
                    ];
                    $cursor = $uid;
                }

                if ($cursor <= $previousCursor) {
                    $scanReason = 'non_advancing_uid_page';
                    break;
                }

                if (count($batch) < $batchSize) {
                    break;
                }
            }
        } catch (Throwable) {
            $scanReason = 'provider_folder_scan_failed';
        }

        try {
            $end = $client->folderState($folder->path);
        } catch (Throwable) {
            $end = [];
            $scanReason ??= 'provider_folder_end_state_failed';
        }

        $endUidValidity = (int) ($end['uid_validity'] ?? 0);
        $endUidNext = (int) ($end['next_uid'] ?? 0);
        $endExists = isset($end['exists_count']) ? (int) $end['exists_count'] : null;

        if ($scanReason === null
            && ($endUidValidity !== $observedUidValidity
                || $endUidNext !== $startUidNext
                || $endExists !== $startExists)) {
            $scanReason = 'folder_changed_during_inventory';
        }

        if ($scanReason === null && count($messages) !== $startExists) {
            $scanReason = 'uid_inventory_count_mismatch';
        }

        ksort($messages);
        $fingerprint = hash('sha256', implode(',', array_keys($messages)));

        return [
            'account_id' => (int) $account->id,
            'email_folder_id' => (int) $folder->id,
            'folder_path' => $folder->path,
            'status' => $scanReason === null
                ? EmailProviderInventoryFolder::STATUS_COMPLETE
                : EmailProviderInventoryFolder::STATUS_INCOMPLETE,
            'reason_code' => $scanReason,
            'expected_uid_validity' => $expectedUidValidity,
            'observed_uid_validity' => $observedUidValidity,
            'start_uid_next' => $startUidNext,
            'end_uid_next' => $endUidNext ?: null,
            'start_exists_count' => $startExists,
            'end_exists_count' => $endExists,
            'scanned_message_count' => count($messages),
            'inventory_fingerprint' => $fingerprint,
            'messages' => array_values($messages),
            'started_at' => $startedAt,
            'finished_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stateFailureSnapshot(
        EmailAccount $account,
        EmailFolder $folder,
        mixed $startedAt,
        string $reason,
        int $observedUidValidity,
        int $startUidNext,
        ?int $startExists,
    ): array {
        return [
            'account_id' => (int) $account->id,
            'email_folder_id' => (int) $folder->id,
            'folder_path' => $folder->path,
            'status' => EmailProviderInventoryFolder::STATUS_INCOMPLETE,
            'reason_code' => $reason,
            'expected_uid_validity' => (int) $folder->uid_validity,
            'observed_uid_validity' => $observedUidValidity ?: null,
            'start_uid_next' => $startUidNext ?: null,
            'end_uid_next' => null,
            'start_exists_count' => $startExists,
            'end_exists_count' => null,
            'scanned_message_count' => 0,
            'inventory_fingerprint' => null,
            'messages' => [],
            'started_at' => $startedAt,
            'finished_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailableFolderSnapshot(
        EmailAccount $account,
        string $path,
        string $reason,
        ?EmailFolder $folder,
        mixed $startedAt = null,
        string $status = EmailProviderInventoryFolder::STATUS_INCOMPLETE,
    ): array {
        return [
            'account_id' => (int) $account->id,
            'email_folder_id' => $folder?->id,
            'folder_path' => $path,
            'status' => $status,
            'reason_code' => $reason,
            'expected_uid_validity' => $folder?->uid_validity,
            'observed_uid_validity' => null,
            'start_uid_next' => null,
            'end_uid_next' => null,
            'start_exists_count' => null,
            'end_exists_count' => null,
            'scanned_message_count' => 0,
            'inventory_fingerprint' => null,
            'messages' => [],
            'started_at' => $startedAt ?? now(),
            'finished_at' => now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function failedSnapshot(
        EmailAccount $account,
        mixed $startedAt,
        int $maxFolders,
        int $maxMessagesPerFolder,
        string $failureCode,
        string $status = 'failed',
    ): array {
        return [
            'account_id' => (int) $account->id,
            'provider' => 'imap',
            'status' => $status,
            'failure_code' => $failureCode,
            'max_folders' => $maxFolders,
            'max_messages_per_folder' => $maxMessagesPerFolder,
            'started_at' => $startedAt,
            'finished_at' => now(),
            'scope_fingerprint' => null,
            'folders' => [],
            'reported_folder_count' => 0,
        ];
    }
}
