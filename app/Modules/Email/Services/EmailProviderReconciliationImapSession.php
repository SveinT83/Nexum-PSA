<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\DTOs\EmailProviderReconciliationMessageMetadata;
use App\Modules\Email\DTOs\EmailProviderReconciliationMetadataPage;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use InvalidArgumentException;
use Traversable;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\EncodingAliases;
use Webklex\PHPIMAP\IMAP;
use Webklex\PHPIMAP\Message;

/**
 * One connected read-only IMAP session for a bounded reconciliation call.
 *
 * This class deliberately bypasses Webklex Query/Message provider fetching.
 * In the installed version, `leaveUnread()` compensates for RFC822.TEXT by
 * issuing UID STORE to remove Seen. Reconciliation instead sends explicit
 * metadata commands and BODY.PEEK literals, then builds a detached Message.
 */
final class EmailProviderReconciliationImapSession
{
    public function __construct(
        #[\SensitiveParameter] private readonly Client $client,
        private readonly EmailProviderReconciliationMessagePayload $payloads,
        private readonly int $bodyByteCap,
    ) {
        if ($bodyByteCap < 1 || $bodyByteCap > EmailProviderReconciliationPolicy::HARD_MESSAGE_BYTES) {
            throw new InvalidArgumentException('The reconciliation body byte cap is invalid.');
        }
    }

    /** @return array<int, EmailProviderReconciliationFolderDescriptor> */
    public function discoverFolders(): array
    {
        $inventory = $this->client->getConnection()->folders('', '*')->validatedData();
        if (! is_array($inventory)) {
            throw new EmailProviderReconciliationReadException('provider_folder_inventory_invalid');
        }
        if (count($inventory) > EmailProviderReconciliationPolicy::HARD_MAX_FOLDERS) {
            // Count every LIST result, including \Noselect and other folders
            // that would later be filtered from synchronization. Otherwise a
            // hostile provider can bypass the run cap with disabled entries.
            throw new EmailProviderReconciliationReadException(
                'provider_folder_inventory_cap_exceeded',
            );
        }

        $folders = [];
        $hasSelectableInbox = false;
        foreach ($inventory as $path => $facts) {
            if ((! is_string($path) && ! is_int($path)) || ! is_array($facts)) {
                throw new EmailProviderReconciliationReadException('provider_folder_inventory_invalid');
            }
            // PHP converts a numeric-string array key to an integer. Numeric
            // IMAP mailbox names remain valid paths, not list offsets.
            $path = (string) $path;
            if ($path === '') {
                throw new EmailProviderReconciliationReadException('provider_folder_inventory_invalid');
            }
            $delimiter = $facts['delimiter'] ?? null;
            $flags = $facts['flags'] ?? [];
            if (($delimiter !== null && ! is_string($delimiter)) || ! is_array($flags)) {
                throw new EmailProviderReconciliationReadException('provider_folder_inventory_invalid');
            }
            $flags = array_values(array_map(fn (mixed $flag): string => (string) $flag, $flags));
            $selectable = ! $this->hasSystemFlag($flags, 'Noselect');
            $hasSelectableInbox = $hasSelectableInbox
                || ($selectable && strcasecmp($path, 'INBOX') === 0);
            $decodedPath = $this->decodeFolderPath($path, $delimiter);
            $name = $delimiter && str_contains($decodedPath, $delimiter)
                ? substr($decodedPath, (int) strrpos($decodedPath, $delimiter) + strlen($delimiter))
                : $decodedPath;

            $folders[] = new EmailProviderReconciliationFolderDescriptor(
                path: $path,
                name: $name !== '' ? $name : $path,
                delimiter: $delimiter,
                parentPath: $this->parentPath($path, $delimiter),
                remoteId: $path,
                specialUse: $this->specialUse($flags),
                selectable: $selectable,
                syncEnabled: $selectable,
            );
        }

        // RFC 3501 requires INBOX to exist. Treat an empty/truncated LIST as a
        // failed discovery rather than durable evidence that every local
        // provider folder disappeared.
        if (! $hasSelectableInbox) {
            throw new EmailProviderReconciliationReadException('provider_folder_inventory_incomplete');
        }

        return $folders;
    }

    public function folderState(
        #[\SensitiveParameter] string $folderPath,
    ): EmailProviderReconciliationFolderState {
        $capabilitySupportsModseq = $this->supportsModseq();
        $arguments = ['MESSAGES', 'UIDNEXT', 'UIDVALIDITY'];
        if ($capabilitySupportsModseq) {
            $arguments[] = 'HIGHESTMODSEQ';
        }
        $state = $this->client->getConnection()
            ->folderStatus($folderPath, $arguments)
            ->validatedData();
        if (! is_array($state)) {
            throw new EmailProviderReconciliationReadException('provider_folder_state_invalid');
        }

        $supportsModseq = $this->folderSupportsModseq(
            $state,
            $capabilitySupportsModseq,
        );

        return $this->typedFolderState($state, $supportsModseq);
    }

    public function metadataPage(
        #[\SensitiveParameter] string $folderPath,
        int $uidValidity,
        int $afterUid,
        int $throughUid,
        int $limit,
    ): EmailProviderReconciliationMetadataPage {
        if ($uidValidity < 1
            || $afterUid < 0
            || $throughUid < $afterUid
            || $limit < 1
            || $limit > EmailProviderReconciliationPolicy::HARD_UID_BATCH_SIZE) {
            throw new EmailProviderReconciliationReadException('provider_metadata_scope_invalid');
        }

        $capabilitySupportsModseq = $this->supportsModseq();
        $selectedState = $this->examineFolder($folderPath);
        $this->assertUidValidity($selectedState, $uidValidity);
        $supportsModseq = $this->selectedFolderSupportsModseq(
            $folderPath,
            $selectedState,
            $uidValidity,
            $capabilitySupportsModseq,
        );
        if ($afterUid === $throughUid) {
            return new EmailProviderReconciliationMetadataPage(
                [],
                terminal: true,
                completeThroughUid: $throughUid,
            );
        }

        $windowFrom = $afterUid + 1;
        $windowTo = min(
            $throughUid,
            $afterUid + EmailProviderReconciliationPolicy::HARD_UID_WINDOW_SPAN,
        );
        $uids = $this->searchWindow($windowFrom, $windowTo);
        $selected = array_slice($uids, 0, $limit);
        $completeThroughUid = count($uids) > $limit
            ? (int) end($selected)
            : $windowTo;

        if ($selected === []) {
            $this->assertCurrentUidValidity($folderPath, $uidValidity);

            return new EmailProviderReconciliationMetadataPage(
                [],
                terminal: $completeThroughUid === $throughUid,
                completeThroughUid: $completeThroughUid,
            );
        }

        $items = ['UID', 'FLAGS'];
        if ($supportsModseq) {
            $items[] = 'MODSEQ';
        }
        $response = $this->client->getConnection()
            ->fetch($items, $selected, null, IMAP::ST_UID)
            ->validatedData();
        $this->assertCurrentUidValidity($folderPath, $uidValidity);
        $rows = $this->exactRows($response, $selected, 'provider_metadata_fetch_invalid');
        $messages = [];
        foreach ($selected as $uid) {
            $row = $rows[$uid];
            $flags = $this->flagsFromRow($row);
            $modseq = $supportsModseq
                ? $this->nonNegativeInteger($this->rowValue($row, 'MODSEQ'))
                : null;
            if ($supportsModseq && $modseq === null) {
                throw new EmailProviderReconciliationReadException('provider_metadata_modseq_missing');
            }

            $messages[] = new EmailProviderReconciliationMessageMetadata(
                uid: $uid,
                modseq: $modseq,
                seen: $this->hasSystemFlag($flags, 'Seen'),
                answered: $this->hasSystemFlag($flags, 'Answered'),
                flagged: $this->hasSystemFlag($flags, 'Flagged'),
                deleted: $this->hasSystemFlag($flags, 'Deleted'),
                draft: $this->hasSystemFlag($flags, 'Draft'),
                customFlags: $flags,
            );
        }

        return new EmailProviderReconciliationMetadataPage(
            $messages,
            terminal: false,
            completeThroughUid: $completeThroughUid,
        );
    }

    public function messageByUidPeek(
        int $accountId,
        int $bindingVersion,
        #[\SensitiveParameter] string $folderPath,
        int $uidValidity,
        int $uid,
    ): ?EmailProviderReconciliationPeekedMessage {
        if ($accountId < 1 || $bindingVersion < 1 || $uidValidity < 1 || $uid < 1) {
            throw new EmailProviderReconciliationReadException('provider_message_scope_invalid');
        }

        $selectedState = $this->examineFolder($folderPath);
        $this->assertUidValidity($selectedState, $uidValidity);
        $metadataResponse = $this->client->getConnection()
            ->fetch(['UID', 'FLAGS', 'RFC822.SIZE'], [$uid], null, IMAP::ST_UID)
            ->validatedData();
        if ($metadataResponse === [] || $metadataResponse === null) {
            $this->assertCurrentUidValidity($folderPath, $uidValidity);

            return null;
        }
        $metadataRow = $this->exactRows(
            $metadataResponse,
            [$uid],
            'provider_message_metadata_invalid',
        )[$uid];
        $flags = $this->flagsFromRow($metadataRow);
        $size = $this->nonNegativeInteger($this->rowValue($metadataRow, 'RFC822.SIZE'));
        if ($size === null) {
            throw new EmailProviderReconciliationReadException('provider_message_size_missing');
        }
        $oversize = $size > $this->bodyByteCap;

        // A normal-size message is fetched as one exact bounded raw literal.
        // Split HEADER/TEXT responses cannot prove octet parity because their
        // separator and newline normalization is provider-dependent. The
        // complete PEEK must match RFC822.SIZE exactly before parsing/storage.
        if (! $oversize) {
            $rawItem = $this->fullPeekItem($size);
            $rawRow = $this->peekRow($uid, $rawItem);
            $raw = $this->literal($rawRow, [
                'BODY[]<0>',
                'BODY.PEEK[]<0>',
                $rawItem,
                'BODY[]',
                'BODY.PEEK[]',
            ]);
            if ($raw === null) {
                throw new EmailProviderReconciliationReadException('provider_message_body_incomplete');
            }
            if (strlen($raw) !== $size) {
                throw new EmailProviderReconciliationReadException(
                    'provider_message_literal_length_mismatch',
                );
            }
            $this->assertRawHeaderBound($raw);
        } else {
            // Oversize messages deliberately project only bounded header and
            // provider metadata. Their body is never requested or persisted.
            $headerItem = $this->headerPeekItem();
            $headerRow = $this->peekRow($uid, $headerItem);
            $header = $this->literal($headerRow, [
                'BODY[HEADER]<0>',
                'BODY.PEEK[HEADER]<0>',
                $headerItem,
                'BODY[HEADER]',
                'BODY.PEEK[HEADER]',
            ]);
            if ($header === null || trim($header) === '') {
                throw new EmailProviderReconciliationReadException('provider_message_header_incomplete');
            }
            if (strlen($header) > EmailProviderReconciliationPolicy::HARD_HEADER_BYTES) {
                throw new EmailProviderReconciliationReadException(
                    'provider_message_header_byte_cap_exceeded',
                );
            }
            $raw = rtrim($header, "\r\n")."\r\n\r\n";
        }

        $this->assertCurrentUidValidity($folderPath, $uidValidity);
        try {
            $message = Message::fromString($raw, $this->client->getConfig());
            $message->setUid($uid)
                ->setFolderPath($folderPath)
                ->setSequence(IMAP::ST_UID);
            $message->size = $size;
        } catch (InvalidArgumentException) {
            throw new EmailProviderReconciliationReadException('provider_message_parse_failed');
        } catch (\Throwable) {
            throw new EmailProviderReconciliationReadException('provider_message_parse_failed');
        }

        try {
            $payload = $this->payloads->make(
                $message,
                $accountId,
                $bindingVersion,
                $folderPath,
                $uidValidity,
                $size,
                $oversize,
                $flags,
            );
        } catch (\Throwable) {
            // Sever the detached Message/header/address frames. Production
            // traces retain only this stable code even with trace args on.
            throw new EmailProviderReconciliationReadException(
                'provider_message_payload_invalid',
            );
        }

        return new EmailProviderReconciliationPeekedMessage($payload, $message);
    }

    /** @return array<int, int> */
    private function searchWindow(int $from, int $to): array
    {
        $response = $this->client->getConnection()
            ->search(['UID '.$from.':'.$to], IMAP::ST_UID)
            ->validatedData();
        if ($response instanceof Traversable) {
            $response = iterator_to_array($response);
        }
        if (! is_array($response)) {
            throw new EmailProviderReconciliationReadException('provider_uid_search_invalid');
        }

        $uids = [];
        foreach ($response as $candidate) {
            $uid = filter_var($candidate, FILTER_VALIDATE_INT);
            if ($uid === false || $uid < $from || $uid > $to || isset($uids[$uid])) {
                throw new EmailProviderReconciliationReadException('provider_uid_search_invalid');
            }
            $uids[$uid] = $uid;
        }
        ksort($uids, SORT_NUMERIC);

        return array_values($uids);
    }

    /** @param array<int, int> $expectedUids @return array<int, array<string, mixed>> */
    private function exactRows(
        #[\SensitiveParameter] mixed $response,
        array $expectedUids,
        string $errorCode,
    ): array {
        if ($response instanceof Traversable) {
            $response = iterator_to_array($response);
        }
        if (! is_array($response)) {
            throw new EmailProviderReconciliationReadException($errorCode);
        }

        $rows = [];
        if ($this->rowValue($response, 'UID') !== null) {
            $response = [$response];
        }
        foreach ($response as $row) {
            if (! is_array($row)) {
                throw new EmailProviderReconciliationReadException($errorCode);
            }
            $uid = $this->positiveInteger($this->rowValue($row, 'UID'));
            if ($uid === null || isset($rows[$uid])) {
                throw new EmailProviderReconciliationReadException($errorCode);
            }
            $rows[$uid] = $row;
        }

        $actual = array_keys($rows);
        sort($actual, SORT_NUMERIC);
        $expected = $expectedUids;
        sort($expected, SORT_NUMERIC);
        if ($actual !== $expected) {
            throw new EmailProviderReconciliationReadException($errorCode);
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function peekRow(int $uid, string $literal): array
    {
        $response = $this->client->getConnection()
            ->fetch(['UID', $literal], [$uid], null, IMAP::ST_UID)
            ->validatedData();

        return $this->exactRows(
            $response,
            [$uid],
            'provider_message_peek_incomplete',
        )[$uid];
    }

    private function headerPeekItem(): string
    {
        return sprintf(
            'BODY.PEEK[HEADER]<0.%d>',
            EmailProviderReconciliationPolicy::HARD_HEADER_BYTES + 1,
        );
    }

    private function fullPeekItem(int $reportedSize): string
    {
        return sprintf(
            'BODY.PEEK[]<0.%d>',
            $reportedSize + 1,
        );
    }

    private function assertRawHeaderBound(#[\SensitiveParameter] string $raw): void
    {
        $separator = null;
        foreach (["\r\n\r\n", "\n\n", "\r\r"] as $candidate) {
            $position = strpos($raw, $candidate);
            if ($position !== false && ($separator === null || $position < $separator)) {
                $separator = $position;
            }
        }

        if ($separator === null) {
            throw new EmailProviderReconciliationReadException('provider_message_header_incomplete');
        }
        if ($separator > EmailProviderReconciliationPolicy::HARD_HEADER_BYTES) {
            throw new EmailProviderReconciliationReadException(
                'provider_message_header_byte_cap_exceeded',
            );
        }
    }

    /** @param array<string, mixed> $row @return array<int, string> */
    private function flagsFromRow(#[\SensitiveParameter] array $row): array
    {
        $flags = $this->rowValue($row, 'FLAGS');
        if ($flags instanceof Traversable) {
            $flags = iterator_to_array($flags);
        }
        if (! is_array($flags)) {
            throw new EmailProviderReconciliationReadException('provider_message_flags_invalid');
        }

        try {
            // Reuse the durable evidence cap before any flag reaches Webklex's
            // detached parser or a local persistence payload.
            EmailProviderReconciliationMessageMetadata::normalizeCustomFlags($flags);
        } catch (\Throwable) {
            throw new EmailProviderReconciliationReadException('provider_message_flags_invalid');
        }

        $validated = [];
        $keys = [];
        foreach ($flags as $flag) {
            if (! is_string($flag)) {
                throw new EmailProviderReconciliationReadException('provider_message_flags_invalid');
            }
            $flag = trim($flag);
            // System flags and same-named custom keywords are distinct RFC
            // atoms. Only case is insignificant; the leading backslash is
            // part of the durable identity.
            $key = mb_strtolower($flag);
            if ($key === '' || isset($keys[$key])) {
                throw new EmailProviderReconciliationReadException('provider_message_flags_invalid');
            }
            $keys[$key] = true;
            $validated[] = $flag;
        }

        return $validated;
    }

    /** @param array<string, mixed> $row @param array<int, string> $keys */
    private function literal(#[\SensitiveParameter] array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->rowValue($row, $key);
            if (is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function rowValue(#[\SensitiveParameter] array $row, string $expected): mixed
    {
        foreach ($row as $key => $value) {
            if (is_string($key) && strcasecmp($key, $expected) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        $value = $this->scalarFromSingleValue($value);
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer > 0 ? $integer : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        $value = $this->scalarFromSingleValue($value);
        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return $integer !== false && $integer >= 0 ? $integer : null;
    }

    private function scalarFromSingleValue(mixed $value): mixed
    {
        return is_array($value) && count($value) === 1
            ? reset($value)
            : $value;
    }

    private function supportsModseq(): bool
    {
        $capabilities = $this->client->getConnection()->getCapabilities()->validatedData();
        if (! is_array($capabilities)) {
            throw new EmailProviderReconciliationReadException('provider_capabilities_invalid');
        }

        return collect($capabilities)->contains(
            fn (mixed $capability): bool => strcasecmp((string) $capability, 'CONDSTORE') === 0,
        );
    }

    /** @return array<string, mixed> */
    private function examineFolder(#[\SensitiveParameter] string $folderPath): array
    {
        $response = $this->client->getConnection()->examineFolder($folderPath);
        $state = $response->validatedData();
        if (! is_array($state)) {
            throw new EmailProviderReconciliationReadException('provider_folder_examine_invalid');
        }

        // Webklex does not project SELECT/EXAMINE response codes for
        // NOMODSEQ or HIGHESTMODSEQ into validatedData(). Preserve those
        // mailbox-local facts from the already bounded raw response so a
        // globally advertised CONDSTORE capability cannot be mistaken for
        // support in every mailbox.
        if ($this->responseContainsCode($response->getResponse(), 'NOMODSEQ')) {
            $state['nomodseq'] = true;
        }
        $highestModseq = $this->responseCodeInteger(
            $response->getResponse(),
            'HIGHESTMODSEQ',
        );
        if ($highestModseq !== null) {
            $state['highestmodseq'] = $highestModseq;
        }

        // Direct protocol FETCH operates on the EXAMINE-selected mailbox.
        // Updating only Webklex's local pointer keeps detached message context
        // aligned without its Client::openFolder() issuing mutable SELECT.
        $this->client->setActiveFolder($folderPath);

        return $state;
    }

    /** @param array<string, mixed> $selectedState */
    private function selectedFolderSupportsModseq(
        #[\SensitiveParameter] string $folderPath,
        array $selectedState,
        int $expectedUidValidity,
        bool $capabilitySupportsModseq,
    ): bool {
        $selectedSupport = $this->folderSupportsModseq(
            $selectedState,
            $capabilitySupportsModseq,
            allowMissingEvidence: true,
        );
        if ($selectedSupport !== null) {
            return $selectedSupport;
        }

        // Some servers omit the response code from EXAMINE but expose a
        // mailbox-local HIGHESTMODSEQ value through STATUS. Missing or
        // malformed evidence remains a hard failure, never an ordinary-IMAP
        // downgrade.
        $state = $this->client->getConnection()
            ->folderStatus($folderPath, ['UIDVALIDITY', 'HIGHESTMODSEQ'])
            ->validatedData();
        if (! is_array($state)) {
            throw new EmailProviderReconciliationReadException('provider_folder_state_invalid');
        }
        $this->assertUidValidity($state, $expectedUidValidity);

        return $this->folderSupportsModseq($state, true);
    }

    /**
     * Resolve mailbox-local MODSEQ support. A null return is allowed only
     * while EXAMINE evidence is being supplemented with a STATUS read.
     *
     * @param  array<string, mixed>  $state
     */
    private function folderSupportsModseq(
        array $state,
        bool $capabilitySupportsModseq,
        bool $allowMissingEvidence = false,
    ): ?bool {
        if (! $capabilitySupportsModseq) {
            return false;
        }

        $hasNoModseq = $this->rowHasKey($state, 'nomodseq');
        $hasHighestModseq = $this->rowHasKey($state, 'highestmodseq');
        if ($hasHighestModseq) {
            $highestModseq = $this->nonNegativeInteger(
                $this->rowValue($state, 'highestmodseq'),
            );
            if ($highestModseq === null) {
                throw new EmailProviderReconciliationReadException(
                    'provider_folder_state_incomplete',
                );
            }

            if ($hasNoModseq && $highestModseq > 0) {
                throw new EmailProviderReconciliationReadException(
                    'provider_folder_state_invalid',
                );
            }

            // RFC 7162 uses HIGHESTMODSEQ 0 as the explicit mailbox-local
            // no-persistent-modseq signal.
            return $highestModseq > 0;
        }

        if ($hasNoModseq) {
            return false;
        }

        if ($allowMissingEvidence) {
            return null;
        }

        throw new EmailProviderReconciliationReadException(
            'provider_folder_state_incomplete',
        );
    }

    /** @param array<string, mixed> $row */
    private function rowHasKey(array $row, string $expected): bool
    {
        foreach (array_keys($row) as $key) {
            if (is_string($key) && strcasecmp($key, $expected) === 0) {
                return true;
            }
        }

        return false;
    }

    /** @param array<int, mixed> $response */
    private function responseContainsCode(array $response, string $code): bool
    {
        $matched = false;
        array_walk_recursive(
            $response,
            static function (mixed $value) use ($code, &$matched): void {
                if (! $matched && is_scalar($value)) {
                    $matched = preg_match(
                        '/\[\s*'.preg_quote($code, '/').'(?:\s|\])/i',
                        (string) $value,
                    ) === 1;
                }
            },
        );

        return $matched;
    }

    /** @param array<int, mixed> $response */
    private function responseCodeInteger(array $response, string $code): ?int
    {
        $values = [];
        array_walk_recursive(
            $response,
            static function (mixed $value) use ($code, &$values): void {
                if (! is_scalar($value)) {
                    return;
                }
                if (preg_match(
                    '/\[\s*'.preg_quote($code, '/').'\s+([0-9]+)\s*\]/i',
                    (string) $value,
                    $matches,
                ) === 1) {
                    $integer = filter_var($matches[1], FILTER_VALIDATE_INT);
                    if ($integer !== false && $integer >= 0) {
                        $values[$integer] = $integer;
                    }
                }
            },
        );

        if (count($values) > 1) {
            throw new EmailProviderReconciliationReadException(
                'provider_folder_state_invalid',
            );
        }

        return $values === [] ? null : (int) reset($values);
    }

    private function assertCurrentUidValidity(
        #[\SensitiveParameter] string $folderPath,
        int $expected,
    ): void {
        $state = $this->client->getConnection()
            ->folderStatus($folderPath, ['UIDVALIDITY'])
            ->validatedData();
        if (! is_array($state)) {
            throw new EmailProviderReconciliationReadException('provider_uid_namespace_stale');
        }

        $this->assertUidValidity($state, $expected);
    }

    /** @param array<string, mixed> $state */
    private function typedFolderState(array $state, bool $supportsModseq): EmailProviderReconciliationFolderState
    {
        $uidValidity = $this->positiveInteger($this->rowValue($state, 'uidvalidity'));
        $uidNext = $this->positiveInteger($this->rowValue($state, 'uidnext'));
        $exists = $this->nonNegativeInteger($this->rowValue($state, 'messages'));
        $highestModseq = $supportsModseq
            ? $this->nonNegativeInteger($this->rowValue($state, 'highestmodseq'))
            : null;
        if ($uidValidity === null || $uidNext === null || $exists === null
            || ($supportsModseq && $highestModseq === null)) {
            throw new EmailProviderReconciliationReadException('provider_folder_state_incomplete');
        }

        return new EmailProviderReconciliationFolderState(
            $uidValidity,
            $uidNext,
            $exists,
            $supportsModseq,
            $highestModseq,
        );
    }

    /** @param array<string, mixed> $state */
    private function assertUidValidity(array $state, int $expected): void
    {
        $actual = $this->positiveInteger($this->rowValue($state, 'uidvalidity'));
        if ($actual === null || $actual !== $expected) {
            throw new EmailProviderReconciliationReadException('provider_uid_namespace_stale');
        }
    }

    /** @param array<int, string> $flags */
    private function hasSystemFlag(array $flags, string $expected): bool
    {
        $expected = mb_strtolower(ltrim($expected, '\\'));

        return collect($flags)->contains(
            static fn (string $flag): bool => str_starts_with(trim($flag), '\\')
                && mb_strtolower(ltrim(trim($flag), '\\')) === $expected,
        );
    }

    /** @param array<int, string> $flags */
    private function specialUse(array $flags): ?string
    {
        foreach (['Inbox', 'Sent', 'Drafts', 'Trash', 'Deleted', 'Archive', 'Junk', 'Spam'] as $candidate) {
            if ($this->hasSystemFlag($flags, $candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function decodeFolderPath(#[\SensitiveParameter] string $path, ?string $delimiter): string
    {
        try {
            $parts = $delimiter ? explode($delimiter, $path) : [$path];
            $decoded = array_map(
                fn (string $part): string => (string) EncodingAliases::convert($part, 'UTF7-IMAP'),
                $parts,
            );

            return implode($delimiter ?? '', $decoded);
        } catch (\Throwable) {
            return $path;
        }
    }

    private function parentPath(#[\SensitiveParameter] string $path, ?string $delimiter): ?string
    {
        if (! $delimiter || ! str_contains($path, $delimiter)) {
            return null;
        }

        return substr($path, 0, (int) strrpos($path, $delimiter));
    }
}
