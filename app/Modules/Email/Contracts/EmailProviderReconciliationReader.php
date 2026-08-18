<?php

namespace App\Modules\Email\Contracts;

use App\Modules\Email\DTOs\EmailProviderReconciliationBindingSnapshot;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderDescriptor;
use App\Modules\Email\DTOs\EmailProviderReconciliationFolderState;
use App\Modules\Email\DTOs\EmailProviderReconciliationMetadataPage;
use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;

/**
 * The provider-to-Nexum boundary deliberately exposes reads only.
 *
 * Implementations may discover folders, inspect stable folder tuples, fetch
 * bounded metadata pages, and retrieve one exact message with BODY.PEEK. The
 * interface has no send, APPEND, STORE, MOVE, COPY, EXPUNGE, or folder-write
 * operation, so reconciliation jobs cannot accidentally become a mutation
 * retry path.
 */
interface EmailProviderReconciliationReader
{
    public function binding(
        int $accountId,
        int $expectedBindingVersion,
    ): EmailProviderReconciliationBindingSnapshot;

    /**
     * @return array<int, EmailProviderReconciliationFolderDescriptor>
     */
    public function discoverFolders(
        int $accountId,
        int $expectedBindingVersion,
        int $timeCapSeconds,
    ): array;

    public function folderState(
        int $accountId,
        int $expectedBindingVersion,
        #[\SensitiveParameter]
        string $folderPath,
        int $timeCapSeconds,
    ): EmailProviderReconciliationFolderState;

    /**
     * Read at most $limit existing UIDs after $afterUid and no later than the
     * frozen $throughUid. Every successful page identifies the bounded numeric
     * UID window the provider completely searched in completeThroughUid; that
     * boundary must be no more than Policy::HARD_UID_WINDOW_SPAN beyond
     * $afterUid. Empty nonterminal windows advance the durable cursor without
     * claiming whole-inventory completeness. A complete inventory ends only
     * with an explicit empty terminal page whose completeThroughUid equals the
     * frozen $throughUid. Timeouts, partial responses, and provider errors must
     * throw a sanitized read exception and must never be represented as a
     * completed window or terminal emptiness.
     */
    public function metadataPage(
        int $accountId,
        int $expectedBindingVersion,
        #[\SensitiveParameter]
        string $folderPath,
        int $uidValidity,
        int $afterUid,
        int $throughUid,
        int $limit,
        int $timeCapSeconds,
    ): EmailProviderReconciliationMetadataPage;

    /**
     * Return one exact detached provider message, or null when the UID no
     * longer exists. The non-serializable envelope must stay in this worker.
     */
    public function messageByUidPeek(
        int $accountId,
        int $expectedBindingVersion,
        #[\SensitiveParameter]
        string $folderPath,
        int $uidValidity,
        int $uid,
        int $timeCapSeconds,
    ): ?EmailProviderReconciliationPeekedMessage;
}
