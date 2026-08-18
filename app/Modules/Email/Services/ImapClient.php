<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Support\EmailProviderPath;
use App\Modules\Integration\Services\EmailProviderTransportFactory;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Connection\Protocols\Response;
use Webklex\PHPIMAP\Exceptions\MessageHeaderFetchingException;
use Webklex\PHPIMAP\Header;
use Webklex\PHPIMAP\IMAP;

class ImapClient
{
    public const HISTORICAL_UID_CHUNK_SIZE = 1000;

    public const HISTORICAL_UID_MAX_SCAN_SPAN = 50000;

    public const HISTORICAL_UID_MAX_RESULTS = 501;

    protected Client $client;

    public function __construct(
        #[\SensitiveParameter] protected EmailAccount $account,
        protected ?int $expectedProviderBindingVersion = null,
    ) {}

    public function connect(): void
    {
        $runtime = app(EmailAccountProviderRuntimeResolver::class)->resolve(
            $this->account,
            $this->expectedProviderBindingVersion,
        );
        $this->client = app(EmailProviderTransportFactory::class)->makeImap($runtime);
        $this->client->connect();
    }

    /**
     * Fetch up to $limit unseen messages from INBOX and return lightweight payloads.
     * NOTE: Does not delete/move messages; caller decides after persistence.
     */
    public function fetchUnseen(int $limit = 20, int $page = 1): array
    {
        return $this->payloadsFromMessages(
            $this->inbox()->messages()->unseen()->limit($limit, max(1, $page))->get(),
        );
    }

    /**
     * Fetch recent INBOX messages regardless of Seen state. This protects
     * monitored mailboxes where a user or provider marks mail read before the
     * next Nexum poll; storage dedupe still prevents duplicate processing.
     */
    public function fetchRecent(int $limit = 20): array
    {
        $query = $this->inbox()->messages()->all();

        if (method_exists($query, 'setFetchOrderDesc')) {
            $query->setFetchOrderDesc();
        }

        return $this->payloadsFromMessages($query->limit($limit)->get());
    }

    /**
     * Return the immutable UID namespace and the next server UID for INBOX.
     * Automatic polling uses this state to avoid treating historical unread
     * messages as a backlog when an established mailbox is activated.
     *
     * @return array{uid_validity: int, next_uid: int}
     */
    public function mailboxState(): array
    {
        return $this->folderState('INBOX');
    }

    public function folderState(string $folderPath): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $status = $this->folderByPath($folderPath)->status();

        return [
            'uid_validity' => (int) ($status['uidvalidity'] ?? 0),
            'next_uid' => (int) ($status['uidnext'] ?? 0),
            'exists_count' => isset($status['messages']) ? (int) $status['messages'] : null,
            'unseen_count' => isset($status['unseen']) ? (int) $status['unseen'] : null,
            'highest_modseq' => isset($status['highestmodseq']) ? (int) $status['highestmodseq'] : null,
        ];
    }

    /**
     * Discover provider folders and their current UID namespace without
     * mutating remote state.
     *
     * @return array<int, array<string, mixed>>
     */
    public function folders(): array
    {
        $folders = [];

        if (! isset($this->client)) {
            $state = $this->safeMailboxState('INBOX');

            return [[
                'path' => 'INBOX',
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => $state['uid_validity'],
                'uid_next' => $state['next_uid'],
                'exists_count' => $state['exists_count'] ?? null,
                'unseen_count' => $state['unseen_count'] ?? null,
                'highest_modseq' => $state['highest_modseq'] ?? null,
                'sync_status' => $state['uid_validity'] > 0 ? 'synced' : 'error',
                'sync_error_code' => $state['uid_validity'] > 0 ? null : 'IMAP_FOLDER_STATE',
                'sync_error_message' => $state['uid_validity'] > 0 ? null : 'INBOX did not return a valid UIDVALIDITY state.',
            ]];
        }

        try {
            $providerFolders = method_exists($this->client, 'getFolders')
                ? $this->client->getFolders(false)
                : [];
        } catch (\Throwable) {
            $providerFolders = [];
        }

        foreach ($this->flattenFolders($providerFolders) as $folder) {
            $path = $this->folderPath($folder);
            if ($path === null || $path === '') {
                continue;
            }

            $attributes = $this->folderAttributes($folder);
            $specialUse = $this->specialUseFromAttributes($attributes);
            $state = $this->safeMailboxState($path);
            $delimiter = $this->folderDelimiter($folder);

            $folders[$path] = [
                'path' => $path,
                'name' => $this->folderName($folder) ?? basename(str_replace('\\', '/', $path)) ?: $path,
                'delimiter' => $delimiter,
                'parent_path' => $this->parentPath($path, $delimiter),
                'remote_id' => $path,
                'special_use' => $specialUse,
                'role' => EmailFolder::inferRole($path, $specialUse, $delimiter),
                'is_selectable' => ! $this->hasFolderAttribute($attributes, 'Noselect'),
                'sync_enabled' => ! $this->hasFolderAttribute($attributes, 'Noselect'),
                'uid_validity' => $state['uid_validity'],
                'uid_next' => $state['next_uid'],
                'exists_count' => $state['exists_count'] ?? null,
                'unseen_count' => $state['unseen_count'] ?? null,
                'highest_modseq' => $state['highest_modseq'] ?? null,
                'sync_status' => $state['uid_validity'] > 0 ? 'synced' : 'error',
                'sync_error_code' => $state['uid_validity'] > 0 ? null : 'IMAP_FOLDER_STATE',
                'sync_error_message' => $state['uid_validity'] > 0 ? null : 'Folder did not return a valid UIDVALIDITY state.',
            ];
        }

        if (! isset($folders['INBOX'])) {
            $state = $this->safeMailboxState('INBOX');
            $folders['INBOX'] = [
                'path' => 'INBOX',
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => $state['uid_validity'],
                'uid_next' => $state['next_uid'],
                'exists_count' => $state['exists_count'] ?? null,
                'unseen_count' => $state['unseen_count'] ?? null,
                'highest_modseq' => $state['highest_modseq'] ?? null,
                'sync_status' => $state['uid_validity'] > 0 ? 'synced' : 'error',
                'sync_error_code' => $state['uid_validity'] > 0 ? null : 'IMAP_FOLDER_STATE',
                'sync_error_message' => $state['uid_validity'] > 0 ? null : 'INBOX did not return a valid UIDVALIDITY state.',
            ];
        }

        return array_values($folders);
    }

    /**
     * Fetch the oldest bounded batch strictly after a known UID. Seen state
     * is irrelevant: UID order is the durable live-feed boundary.
     */
    public function fetchAfterUid(int $uid, int $limit = 20): array
    {
        return $this->fetchAfterUidInFolder('INBOX', $uid, $limit);
    }

    public function fetchAfterUidInFolder(string $folderPath, int $uid, int $limit = 20): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $query = $this->folderByPath($folderPath)
            ->messages()
            ->whereUid((max(0, $uid) + 1).':*');

        if (method_exists($query, 'setFetchOrderAsc')) {
            $query->setFetchOrderAsc();
        }

        return $this->payloadsFromMessages(
            $query->limit(max(1, $limit))->get(),
            $folderPath,
        );
    }

    /**
     * Fetch a single message by IMAP UID from the selected provider folder.
     */
    public function fetchByUid(int $uid, string $folderPath = 'INBOX')
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        if ($uid <= 0) {
            return null;
        }

        try {
            if (! $this->messageExistsByUid($uid, $folderPath)) {
                return null;
            }

            // Recovery and remote operations must never turn a single-message
            // lookup into a whole-folder fetch or change the provider Seen
            // flag. The UID search can return at most one message, and PEEK is
            // forced here instead of relying on mutable package configuration.
            return $this->folderByPath($folderPath)
                ->query()
                ->whereUid($uid)
                ->setSequence(IMAP::ST_UID)
                ->leaveUnread()
                ->limit(1)
                ->get()
                ->first();
        } catch (MessageHeaderFetchingException $exception) {
            // The message may disappear between SEARCH and FETCH. Confirm
            // absence without requesting headers; real header failures for a
            // still-present UID become a stable pre-mutation read failure.
            try {
                $stillExists = $this->messageExistsByUid($uid, $folderPath);
            } catch (\Throwable $confirmationException) {
                throw new EmailProviderReadException(
                    'The provider message could not be read before the mailbox operation.',
                    0,
                    $confirmationException,
                );
            }

            if (! $stillExists) {
                return null;
            }

            throw new EmailProviderReadException(
                'The provider message could not be read before the mailbox operation.',
                0,
                $exception,
            );
        } catch (EmailProviderReadException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new EmailProviderReadException(
                'The provider message could not be read before the mailbox operation.',
                0,
                $exception,
            );
        }

        return null;
    }

    /**
     * Check exact provider UID presence without fetching headers, body, flags,
     * or any message content. This is the safe identity preflight for mailbox
     * mutations and reconciliation.
     */
    public function messageExistsByUid(int $uid, string $folderPath = 'INBOX'): bool
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        if ($uid <= 0) {
            return false;
        }

        $query = $this->folderByPath($folderPath)
            ->query()
            ->whereUid($uid)
            ->setSequence(IMAP::ST_UID);
        $matches = $query->search();

        return $matches->contains(
            fn (mixed $candidate): bool => (int) $candidate === $uid,
        );
    }

    /**
     * Enumerate only provider UIDs for a bounded historical window. SEARCH
     * returns operational identity metadata and does not fetch headers, body,
     * flags, filenames, or addresses. Seen/unseen is deliberately irrelevant.
     *
     * @return array<int, int>
     */
    public function searchHistoricalUidsInFolder(
        string $folderPath,
        CarbonInterface $dateFrom,
        CarbonInterface $dateTo,
        int $uidFrom,
        int $uidTo,
        int $limit,
    ): array {
        $folderPath = EmailProviderPath::normalize($folderPath);
        if ($uidFrom <= 0 || $uidTo < $uidFrom || $limit <= 0) {
            return [];
        }

        $scanSpan = $uidTo - $uidFrom + 1;
        if ($scanSpan > self::HISTORICAL_UID_MAX_SCAN_SPAN) {
            throw new \InvalidArgumentException('Historical UID scan scope exceeds the bounded provider range.');
        }

        $resultLimit = min($limit, self::HISTORICAL_UID_MAX_RESULTS);
        $result = collect();

        // Standard IMAP SEARCH has no portable server-side LIMIT. Numeric UID
        // chunks bound each provider response and the total scanned range;
        // stop as soon as the caller's cap-plus-one sentinel is collected.
        for ($chunkFrom = $uidFrom; $chunkFrom <= $uidTo; $chunkFrom += self::HISTORICAL_UID_CHUNK_SIZE) {
            $chunkTo = min($uidTo, $chunkFrom + self::HISTORICAL_UID_CHUNK_SIZE - 1);
            $matches = $this->folderByPath($folderPath)
                ->query()
                ->whereUid($chunkFrom.':'.$chunkTo)
                ->whereSince($dateFrom->startOfDay())
                // IMAP BEFORE is exclusive, while the maintenance request is
                // an inclusive UTC date window.
                ->whereBefore($dateTo->addDay()->startOfDay())
                ->setSequence(IMAP::ST_UID)
                ->search();

            $result = $result->merge($matches
                ->map(fn (mixed $uid): int => (int) $uid)
                ->filter(fn (int $uid): bool => $uid >= $chunkFrom && $uid <= $chunkTo));

            if ($result->unique()->count() >= $resultLimit) {
                break;
            }
        }

        return $result
            ->unique()
            ->sort()
            ->take($resultLimit)
            ->values()
            ->all();
    }

    /**
     * Fetch one exact UID with PEEK and normalize it for the existing inbound
     * storage pipeline. This method never widens to a folder batch.
     *
     * @return array<string, mixed>|null
     */
    public function payloadByUid(int $uid, string $folderPath = 'INBOX'): ?array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $message = $this->fetchByUid($uid, $folderPath);

        if (! $message) {
            return null;
        }

        return $this->payloadsFromMessages([$message], $folderPath)[0] ?? null;
    }

    /**
     * Read the minimum provider evidence needed to reconcile an uncertain
     * mailbox mutation. This intentionally returns no subject, addresses,
     * body, MIME, or attachment data.
     *
     * @return array{exists: bool, imap_uid: int, folder_path: string, provider_seen?: bool, provider_flagged?: bool}
     */
    public function messageStateByUid(int $uid, string $folderPath = 'INBOX'): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $message = $this->fetchByUid($uid, $folderPath);

        if (! $message) {
            return [
                'exists' => false,
                'imap_uid' => $uid,
                'folder_path' => $folderPath,
            ];
        }

        $flags = $this->messageFlags($message);

        return [
            'exists' => true,
            'imap_uid' => $uid,
            'folder_path' => $folderPath,
            'provider_seen' => $this->hasMessageFlag($flags, 'Seen'),
            'provider_flagged' => $this->hasMessageFlag($flags, 'Flagged'),
        ];
    }

    /**
     * Check provider folder presence from a complete provider inventory.
     *
     * Provider errors intentionally bubble to the reconciliation boundary.
     * Treating an unavailable inventory as a missing folder could falsely
     * confirm a delete or rename and corrupt the local projection.
     */
    public function folderExists(string $folderPath): bool
    {
        $expectedPath = EmailProviderPath::normalize($folderPath);

        foreach ($this->flattenFolders($this->providerFolderInventory()) as $folder) {
            $providerPath = $this->folderPath($folder);
            if ($providerPath === $expectedPath) {
                return true;
            }
        }

        return false;
    }

    /**
     * Delete a message by IMAP UID from the selected provider folder.
     */
    public function deleteByUid(int $uid, string $folderPath = 'INBOX'): bool
    {
        $message = $this->fetchByUid($uid, $folderPath);
        if ($message) {
            return (bool) $message->delete();
        }

        return false;
    }

    /**
     * Update the provider Seen flag for one placement without touching
     * Nexum's per-user unread state.
     */
    public function setSeenByUid(int $uid, bool $seen, string $folderPath = 'INBOX'): bool
    {
        return $this->setFlagByUid($uid, 'Seen', $seen, $folderPath);
    }

    /**
     * Update the provider Flagged flag for one placement.
     */
    public function setFlaggedByUid(int $uid, bool $flagged, string $folderPath = 'INBOX'): bool
    {
        return $this->setFlagByUid($uid, 'Flagged', $flagged, $folderPath);
    }

    /**
     * Move one message and retain only an RFC 4315 COPYUID acknowledgement as
     * target identity evidence. Webklex's Message::move() guesses UIDNEXT
     * before the write, which can alias an unrelated concurrent delivery.
     *
     * @return array{ok: bool, target_folder_path: string, target_imap_uid: int|null, target_uid_validity: int|null, target_uid_authoritative: bool}
     */
    public function moveByUid(int $uid, string $sourceFolderPath, string $targetFolderPath): array
    {
        $sourceFolderPath = EmailProviderPath::normalize($sourceFolderPath);
        $targetFolderPath = EmailProviderPath::normalize($targetFolderPath);
        $this->assertCurrentSourceUidNamespace($sourceFolderPath);

        if ($uid < 1 || ! $this->messageExistsByUid($uid, $sourceFolderPath)) {
            throw new EmailProviderMessageMissingException(
                'The source message is no longer present in the provider folder.',
            );
        }

        $response = $this->performUidMove($uid, $sourceFolderPath, $targetFolderPath);
        $response->validate();
        $target = $this->authoritativeCopyUid($response, $uid);

        return [
            'ok' => true,
            'target_folder_path' => $targetFolderPath,
            'target_imap_uid' => $target['uid'] ?? null,
            'target_uid_validity' => $target['uid_validity'] ?? null,
            'target_uid_authoritative' => $target !== null,
        ];
    }

    /**
     * Prove that a queued operation's frozen UID namespace is still the exact
     * active local and provider namespace before any UID-scoped write.
     */
    public function assertUidNamespace(
        #[\SensitiveParameter] string $sourceFolderPath,
        int $expectedUidValidity,
        ?int $expectedNamespaceId = null,
    ): void {
        $this->assertCurrentSourceUidNamespace(
            $sourceFolderPath,
            $expectedUidValidity,
            $expectedNamespaceId,
        );
    }

    protected function performUidMove(
        int $uid,
        #[\SensitiveParameter] string $sourceFolderPath,
        #[\SensitiveParameter] string $targetFolderPath,
    ): Response {
        // Re-select the exact source immediately before the UID mutation. No
        // target mailbox state or UIDNEXT guess participates in identity.
        $this->client->openFolder($sourceFolderPath);

        return $this->client->getConnection()->moveMessage(
            $targetFolderPath,
            $uid,
            null,
            IMAP::ST_UID,
        );
    }

    /** @return array{uid_validity:int,uid:int}|null */
    private function authoritativeCopyUid(
        #[\SensitiveParameter] Response $response,
        int $expectedSourceUid,
    ): ?array {
        $match = null;
        $taggedOk = '/^TAG'.preg_quote((string) $response->Noun(), '/').'\s+OK(?:\s|$)/i';
        foreach ($response->getResponse() as $line) {
            if (! is_string($line)) {
                continue;
            }

            if (stripos($line, 'COPYUID') === false) {
                continue;
            }

            if (preg_match($taggedOk, $line) !== 1
                || preg_match_all(
                    '/\[COPYUID\s+([0-9]+)\s+([0-9:,]+)\s+([0-9:,]+)\]/i',
                    $line,
                    $lineMatches,
                    PREG_SET_ORDER,
                ) !== 1
                || $match !== null) {
                return null;
            }

            $match = $lineMatches[0];
        }

        if ($match === null) {
            return null;
        }

        $uidValidity = $this->positiveProviderInteger($match[1] ?? null);
        $sourceUid = $this->singleUidSetValue($match[2] ?? null);
        $targetUid = $this->singleUidSetValue($match[3] ?? null);
        if ($uidValidity === null
            || $sourceUid !== $expectedSourceUid
            || $targetUid === null) {
            return null;
        }

        return [
            'uid_validity' => $uidValidity,
            'uid' => $targetUid,
        ];
    }

    private function singleUidSetValue(mixed $value): ?int
    {
        if (! is_string($value)
            || preg_match('/^([0-9]+)(?::\1)?$/', $value, $matches) !== 1) {
            return null;
        }

        return $this->positiveProviderInteger($matches[1]);
    }

    private function positiveProviderInteger(mixed $value): ?int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer > 0 ? $integer : null;
    }

    private function assertCurrentSourceUidNamespace(
        #[\SensitiveParameter] string $sourceFolderPath,
        ?int $expectedUidValidity = null,
        ?int $expectedNamespaceId = null,
    ): void {
        $sourceFolderPath = EmailProviderPath::normalize($sourceFolderPath);
        $folder = EmailFolder::query()
            ->where('account_id', $this->account->id)
            ->where('path', $sourceFolderPath)
            ->first();
        $namespace = $folder?->active_uid_namespace_id
            ? EmailFolderUidNamespace::query()
                ->whereKey($folder->active_uid_namespace_id)
                ->where('account_id', $this->account->id)
                ->where('email_folder_id', $folder->id)
                ->where('status', EmailFolderUidNamespace::STATUS_ACTIVE)
                ->first()
            : null;
        $providerState = $this->folderState($sourceFolderPath);

        if (! $folder
            || ! $namespace
            || (int) $namespace->uid_validity < 1
            || ($expectedNamespaceId !== null
                && ($expectedNamespaceId < 1 || (int) $namespace->id !== $expectedNamespaceId))
            || ($expectedUidValidity !== null
                && ($expectedUidValidity < 1 || (int) $namespace->uid_validity !== $expectedUidValidity))
            || (int) ($providerState['uid_validity'] ?? 0) !== (int) $namespace->uid_validity) {
            throw new EmailProviderUidNamespaceStaleException;
        }
    }

    /**
     * Append a complete RFC 822 draft message to a provider Drafts folder.
     * UIDNEXT is best-effort evidence; normal Drafts folder sync remains the
     * authoritative confirmation for providers that do not expose APPENDUID.
     *
     * @return array{ok: bool, folder_path: string, imap_uid_validity: int|null, imap_uid: int|null, response: array<int, string>}
     */
    public function appendDraft(string $folderPath, string $message): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $state = $this->safeMailboxState($folderPath);
        $folder = $this->folderByPath($folderPath);
        $response = $folder->appendMessage($message, ['\\Draft']);
        $ok = collect($response)->contains(
            fn (mixed $line): bool => str_starts_with(mb_strtoupper(trim((string) $line)), 'OK'),
        );

        return [
            'ok' => $ok,
            'folder_path' => $folderPath,
            'imap_uid_validity' => ((int) $state['uid_validity']) > 0 ? (int) $state['uid_validity'] : null,
            'imap_uid' => ((int) $state['next_uid']) > 0 ? (int) $state['next_uid'] : null,
            'response' => array_values(array_map(fn (mixed $line): string => (string) $line, $response)),
        ];
    }

    /**
     * Append a complete RFC 822 message to the provider Sent folder.
     * Normal Sent-folder sync remains the authoritative confirmation.
     *
     * @return array{ok: bool, folder_path: string, imap_uid_validity: int|null, imap_uid: int|null, response: array<int, string>}
     */
    public function appendSent(string $folderPath, string $message): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $state = $this->safeMailboxState($folderPath);
        $folder = $this->folderByPath($folderPath);
        $response = $folder->appendMessage($message, ['\\Seen']);
        $ok = collect($response)->contains(
            fn (mixed $line): bool => str_starts_with(mb_strtoupper(trim((string) $line)), 'OK'),
        );

        return [
            'ok' => $ok,
            'folder_path' => $folderPath,
            'imap_uid_validity' => ((int) $state['uid_validity']) > 0 ? (int) $state['uid_validity'] : null,
            'imap_uid' => ((int) $state['next_uid']) > 0 ? (int) $state['next_uid'] : null,
            'response' => array_values(array_map(fn (mixed $line): string => (string) $line, $response)),
        ];
    }

    /**
     * Create one provider folder and return its discovered state.
     *
     * @return array<string, mixed>
     */
    public function createFolder(string $folderPath): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $folder = $this->client->createFolder($folderPath, false);
        $path = $this->folderPath($folder) ?: $folderPath;
        $state = $this->safeMailboxState($path);

        return [
            'path' => $path,
            'name' => $this->folderName($folder) ?? basename(str_replace('\\', '/', $path)) ?: $path,
            'delimiter' => $this->folderDelimiter($folder),
            'parent_path' => $this->parentPath($path, $this->folderDelimiter($folder)),
            'remote_id' => $path,
            'special_use' => null,
            'role' => EmailFolder::inferRole($path, null, $this->folderDelimiter($folder)),
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $state['uid_validity'],
            'uid_next' => $state['next_uid'],
            'exists_count' => $state['exists_count'] ?? null,
            'unseen_count' => $state['unseen_count'] ?? null,
            'highest_modseq' => $state['highest_modseq'] ?? null,
            'sync_status' => $state['uid_validity'] > 0 ? EmailFolder::SYNC_SYNCED : EmailFolder::SYNC_SHADOW,
            'last_synced_at' => now(),
        ];
    }

    /**
     * Rename one provider folder and return the new discovered state.
     *
     * @return array<string, mixed>
     */
    public function renameFolder(string $sourceFolderPath, string $targetFolderPath): array
    {
        $sourceFolderPath = EmailProviderPath::normalize($sourceFolderPath);
        $targetFolderPath = EmailProviderPath::normalize($targetFolderPath);
        $folder = $this->folderByPath($sourceFolderPath);
        $response = method_exists($folder, 'rename')
            ? $folder->rename($targetFolderPath, false)
            : $folder->move($targetFolderPath, false);

        $state = $this->safeMailboxState($targetFolderPath);

        return [
            'ok' => $this->responseOk($response),
            'source_folder_path' => $sourceFolderPath,
            'target_folder_path' => $targetFolderPath,
            'path' => $targetFolderPath,
            'name' => basename(str_replace('\\', '/', $targetFolderPath)) ?: $targetFolderPath,
            'delimiter' => str_contains($targetFolderPath, '/') ? '/' : null,
            'parent_path' => $this->parentPath($targetFolderPath, str_contains($targetFolderPath, '/') ? '/' : null),
            'remote_id' => $targetFolderPath,
            'special_use' => null,
            'role' => EmailFolder::ROLE_CUSTOM,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => $state['uid_validity'],
            'uid_next' => $state['next_uid'],
            'exists_count' => $state['exists_count'] ?? null,
            'unseen_count' => $state['unseen_count'] ?? null,
            'highest_modseq' => $state['highest_modseq'] ?? null,
            'sync_status' => $state['uid_validity'] > 0 ? EmailFolder::SYNC_SYNCED : EmailFolder::SYNC_SHADOW,
            'last_synced_at' => now(),
            'response' => array_values(array_map(fn (mixed $line): string => (string) $line, $response)),
        ];
    }

    /**
     * Delete one provider folder. The caller must ensure the folder is safe
     * and empty before invoking IMAP DELETE.
     *
     * @return array<string, mixed>
     */
    public function deleteFolder(string $folderPath): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $folder = $this->folderByPath($folderPath);
        $response = method_exists($folder, 'delete')
            ? $folder->delete(false)
            : $this->client->deleteFolder($folderPath, false);

        return [
            'ok' => $this->responseOk($response),
            'source_folder_path' => $folderPath,
            'folder_path' => $folderPath,
            'response' => array_values(array_map(fn (mixed $line): string => (string) $line, $response)),
        ];
    }

    public function disconnect(): void
    {
        if (isset($this->client)) {
            try {
                $this->client->disconnect();
            } catch (\Throwable $e) { /* swallow */
            }
        }
    }

    private function setFlagByUid(int $uid, string $flag, bool $enabled, string $folderPath): bool
    {
        $this->assertCurrentSourceUidNamespace($folderPath);
        $message = $this->fetchByUid($uid, $folderPath);

        if (! $message) {
            throw new EmailProviderMessageMissingException(
                'The source message is no longer present in the provider folder.',
            );
        }

        return $enabled
            ? (bool) $message->setFlag($flag)
            : (bool) $message->unsetFlag($flag);
    }

    private function responseOk(array $response): bool
    {
        return collect($response)->contains(
            fn (mixed $line): bool => str_starts_with(mb_strtoupper(trim((string) $line)), 'OK'),
        );
    }

    /**
     * Normalize an address list (various Webklex attribute types) into
     * an array of ['name' => string|null, 'email' => string|null].
     */
    private function normalizeAddressList($attr): array
    {
        if ($attr === null) {
            return [];
        }

        // Convert attribute/collection to array when possible
        if (is_object($attr)) {
            if (method_exists($attr, 'toArray')) {
                $attr = $attr->toArray();
            } elseif ($attr instanceof \Traversable) {
                $attr = iterator_to_array($attr);
            }
        }

        if (! is_array($attr)) {
            return [];
        }

        $out = [];
        foreach ($attr as $a) {
            $name = null;
            $email = null;
            if (is_array($a)) {
                $name = $a['personal'] ?? $a['name'] ?? null;
                $email = $a['mail'] ?? $a['email'] ?? ($a['address'] ?? null);
                if (! $email && isset($a['mailbox'], $a['host'])) {
                    $email = $a['mailbox'].'@'.$a['host'];
                }
            } elseif (is_object($a)) {
                // Try common property names and methods
                $name = $a->personal ?? $a->name ?? (method_exists($a, 'getName') ? $a->getName() : null);
                $email = $a->mail ?? $a->email ?? (method_exists($a, 'getAddress') ? $a->getAddress() : null);
                if (! $email) {
                    $mailbox = $a->mailbox ?? (method_exists($a, 'getMailbox') ? $a->getMailbox() : null);
                    $host = $a->host ?? (method_exists($a, 'getHost') ? $a->getHost() : null);
                    if ($mailbox && $host) {
                        $email = $mailbox.'@'.$host;
                    }
                }
            }
            $out[] = ['name' => $name, 'email' => $email];
        }

        return $out;
    }

    private function inbox()
    {
        return $this->folderByPath('INBOX');
    }

    private function folderByPath(string $path)
    {
        $path = EmailProviderPath::normalize($path);

        return method_exists($this->client, 'getFolderByPath')
            ? $this->client->getFolderByPath($path)
            : $this->client->getFolder($path);
    }

    private function payloadsFromMessages(iterable $messages, string $folderPath = 'INBOX'): array
    {
        $folderPath = EmailProviderPath::normalize($folderPath);
        $result = [];

        foreach ($messages as $msg) {
            $fromList = $this->normalizeAddressList($msg->getFrom());
            $from = $fromList[0] ?? null;
            $references = $this->normalizeScalarList($msg->getReferences());
            $flags = $this->messageFlags($msg);

            $result[] = [
                'mailbox' => $folderPath,
                'imap_uid' => (int) $msg->getUid(),
                'message_id' => $this->normalizeString($msg->getMessageId()),
                'subject' => $this->normalizeString($msg->getSubject()),
                'from_name' => $from['name'] ?? null,
                'from_email' => $from['email'] ?? null,
                'to' => $this->normalizeAddressList($msg->getTo()),
                'cc' => $this->normalizeAddressList($msg->getCc()),
                'in_reply_to' => $this->normalizeString($msg->getInReplyTo()),
                'references' => implode(' ', $references),
                'headers' => $this->normalizeHeaders($msg->getHeader()),
                'received_at' => $this->normalizeDate($msg->getDate()),
                'size_bytes' => $msg->getSize() ?? null,
                'flags' => $flags,
                'provider_seen' => $this->hasMessageFlag($flags, 'Seen'),
                'provider_answered' => $this->hasMessageFlag($flags, 'Answered'),
                'provider_flagged' => $this->hasMessageFlag($flags, 'Flagged'),
                'provider_deleted' => $this->hasMessageFlag($flags, 'Deleted'),
                'provider_draft' => $this->hasMessageFlag($flags, 'Draft'),
            ];
        }

        return $result;
    }

    private function safeMailboxState(string $path): array
    {
        try {
            return $path === 'INBOX'
                ? $this->mailboxState()
                : $this->folderState($path);
        } catch (\Throwable) {
            return [
                'uid_validity' => 0,
                'next_uid' => 0,
                'exists_count' => null,
                'unseen_count' => null,
                'highest_modseq' => null,
            ];
        }
    }

    /**
     * @return iterable<int, object>
     */
    protected function providerFolderInventory(): iterable
    {
        return $this->client->getFolders(false);
    }

    private function flattenFolders(iterable $folders): array
    {
        $flattened = [];

        foreach ($folders as $folder) {
            $flattened[] = $folder;

            $children = null;
            if (is_object($folder)) {
                $children = method_exists($folder, 'children') ? $folder->children() : ($folder->children ?? null);
            }

            if ($children instanceof \Traversable || is_array($children)) {
                array_push($flattened, ...$this->flattenFolders($children));
            }
        }

        return $flattened;
    }

    private function folderPath(object $folder): ?string
    {
        foreach (['getPath', 'path', 'getFullName', 'full_name', 'getName', 'name'] as $accessor) {
            $value = $this->objectValue($folder, $accessor);
            if ($value !== null && $value !== '') {
                return EmailProviderPath::normalize((string) $value);
            }
        }

        return null;
    }

    private function folderName(object $folder): ?string
    {
        return $this->objectValue($folder, 'getName') ?? $this->objectValue($folder, 'name');
    }

    private function folderDelimiter(object $folder): ?string
    {
        return $this->objectValue($folder, 'getDelimiter') ?? $this->objectValue($folder, 'delimiter');
    }

    private function folderAttributes(object $folder): array
    {
        if (method_exists($folder, 'getAttributes')) {
            $attributes = $folder->getAttributes();
        } else {
            $attributes = property_exists($folder, 'attributes') ? $folder->attributes : [];
        }

        if ($attributes instanceof \Traversable) {
            $attributes = iterator_to_array($attributes);
        }

        return is_array($attributes) ? array_values($attributes) : [];
    }

    private function specialUseFromAttributes(array $attributes): ?string
    {
        $supported = ['Inbox', 'Sent', 'Drafts', 'Trash', 'Deleted', 'Archive', 'Junk', 'Spam'];

        foreach ($supported as $candidate) {
            if ($this->hasFolderAttribute($attributes, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function hasFolderAttribute(array $attributes, string $expected): bool
    {
        $expected = mb_strtolower(ltrim($expected, '\\'));

        return collect($attributes)->contains(
            fn ($attribute): bool => mb_strtolower(ltrim((string) $attribute, '\\')) === $expected,
        );
    }

    private function parentPath(string $path, ?string $delimiter): ?string
    {
        $path = EmailProviderPath::normalize($path);
        $delimiter = $delimiter ?: (str_contains($path, '/') ? '/' : null);
        if (! $delimiter || ! str_contains($path, $delimiter)) {
            return null;
        }

        return EmailProviderPath::normalize(Str::beforeLast($path, $delimiter));
    }

    private function messageFlags(object $message): array
    {
        $flags = method_exists($message, 'getFlags')
            ? $message->getFlags()
            : ($message->flags ?? []);

        if ($flags instanceof \Traversable) {
            $flags = iterator_to_array($flags);
        } elseif (is_object($flags) && method_exists($flags, 'toArray')) {
            $flags = $flags->toArray();
        }

        return is_array($flags)
            ? array_values(array_map(fn ($flag): string => ltrim((string) $flag, '\\'), $flags))
            : [];
    }

    private function hasMessageFlag(array $flags, string $expected): bool
    {
        $expected = mb_strtolower(ltrim($expected, '\\'));

        return collect($flags)->contains(
            fn (string $flag): bool => mb_strtolower(ltrim($flag, '\\')) === $expected,
        );
    }

    private function objectValue(object $object, string $name): ?string
    {
        if (method_exists($object, $name)) {
            $value = $object->{$name}();

            return is_scalar($value) ? (string) $value : null;
        }

        if (property_exists($object, $name)) {
            $value = $object->{$name};

            return is_scalar($value) ? (string) $value : null;
        }

        return null;
    }

    /**
     * Parse the original header block instead of Webklex's derived attributes.
     * Repeated Received and Authentication-Results fields must retain their
     * top-to-bottom boundaries because the trusted-hop verifier depends on it.
     */
    private function normalizeHeaders(?Header $header): array
    {
        $raw = $header?->raw ?? '';
        if ($raw === '') {
            return [];
        }

        $headers = [];
        $currentName = null;
        $currentIndex = null;

        foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
            if ($line === '') {
                break;
            }

            if (preg_match('/^[ \t]+(.*)$/', $line, $continuation)) {
                if ($currentName !== null && $currentIndex !== null) {
                    $value = trim($continuation[1]);
                    if ($value !== '') {
                        $headers[$currentName][$currentIndex] .= ' '.$value;
                    }
                }

                continue;
            }

            if (! preg_match('/^([^:\s]+):[ \t]*(.*)$/', $line, $match)) {
                $currentName = null;
                $currentIndex = null;

                continue;
            }

            $currentName = mb_strtolower($match[1]);
            $headers[$currentName] ??= [];
            $headers[$currentName][] = trim($match[2]);
            $currentIndex = array_key_last($headers[$currentName]);
        }

        return $headers;
    }

    /**
     * Normalize scalar list attributes (e.g., References) to a simple array of strings.
     */
    private function normalizeScalarList($attr): array
    {
        if ($attr === null) {
            return [];
        }
        if (is_string($attr)) {
            return [$attr];
        }
        if (is_object($attr)) {
            if (method_exists($attr, 'toArray')) {
                $attr = $attr->toArray();
            } elseif ($attr instanceof \Traversable) {
                $attr = iterator_to_array($attr);
            }
        }

        return is_array($attr) ? $attr : [];
    }

    /**
     * Normalize a date attribute to 'Y-m-d H:i:s' string.
     */
    private function normalizeDate($attr): string
    {
        try {
            if ($attr instanceof \DateTimeInterface) {
                return $attr->format('Y-m-d H:i:s');
            }
            if (is_object($attr)) {
                if (method_exists($attr, 'toDateTime')) {
                    $dt = $attr->toDateTime();
                    if ($dt instanceof \DateTimeInterface) {
                        return $dt->format('Y-m-d H:i:s');
                    }
                }
                if (method_exists($attr, 'toCarbon')) {
                    $c = $attr->toCarbon();
                    if ($c instanceof \DateTimeInterface) {
                        return $c->format('Y-m-d H:i:s');
                    }
                }
                if (method_exists($attr, '__toString')) {
                    $s = (string) $attr;
                    $dt = new \DateTimeImmutable($s);

                    return $dt->format('Y-m-d H:i:s');
                }
            }
            if (is_string($attr)) {
                $dt = new \DateTimeImmutable($attr);

                return $dt->format('Y-m-d H:i:s');
            }
        } catch (\Throwable $e) {
            // fall through
        }

        return now()->toDateTimeString();
    }

    /**
     * Normalize a scalar/attribute to string.
     */
    private function normalizeString($attr): ?string
    {
        if ($attr === null) {
            return null;
        }
        if (is_string($attr)) {
            return $attr === '' ? null : $attr;
        }
        if (is_object($attr)) {
            if (method_exists($attr, 'toString')) {
                $s = $attr->toString();

                return $s === '' ? null : (string) $s;
            }
            if (method_exists($attr, '__toString')) {
                $s = (string) $attr;

                return $s === '' ? null : $s;
            }
            if (method_exists($attr, 'getValue')) {
                $v = $attr->getValue();

                return $v === '' ? null : (string) $v;
            }
        }
        if (is_scalar($attr)) {
            $s = (string) $attr;

            return $s === '' ? null : $s;
        }

        return null;
    }
}
