<?php

namespace App\Modules\Email\Contracts;

use App\Modules\Email\DTOs\EmailProviderReconciliationPeekedMessage;
use App\Modules\Email\DTOs\EmailProviderReconciliationStoredMessage;

/**
 * Local persistence seam for one exact reconciliation import.
 *
 * The eventual StoreInboundMessage adapter must always pass
 * allow_provider_mutation=false. The dedicated contract intentionally offers
 * no option to relax that invariant.
 */
interface EmailProviderReconciliationMessageStore
{
    public function store(
        int $runId,
        int $itemId,
        int $claimAttempt,
        int $accountId,
        int $folderId,
        int $uidNamespaceId,
        int $uidValidity,
        int $uid,
        EmailProviderReconciliationPeekedMessage $peeked,
        bool $runInboundRules,
    ): EmailProviderReconciliationStoredMessage;
}
