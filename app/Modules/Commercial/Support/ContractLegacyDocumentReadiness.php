<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\Contracts;
use DomainException;

/**
 * Refuse to present mutable live economics as historical accepted evidence.
 */
final class ContractLegacyDocumentReadiness
{
    public function assertSafeProjection(Contracts $contract): void
    {
        $status = (string) $contract->approval_status;

        // Legacy sent/accepted rows have no immutable party snapshot. Current
        // Client/Company Profile values cannot prove who the parties were at
        // send time, even when line timestamps still look unchanged. Require
        // an explicitly attested reconstruction instead of freezing live data.
        if (in_array($status, ['sent_quote', 'sent_contract', 'approved', 'won'], true)) {
            throw new DomainException($this->failureMessage());
        }
    }

    public function failureMessage(): string
    {
        return 'Det historiske kundedokumentet mangler et uforanderlig parts- og økonomisnapshot. Sendt eller godkjent grunnlag må rekonstrueres, attesteres og verifiseres manuelt før dokumentet kan brukes.';
    }
}
