<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\System\Support\CompanyProfileSettings;
use DomainException;

/**
 * Verify the legal party identity required before a customer document is frozen.
 */
final class ContractDocumentReadiness
{
    public function __construct(private readonly CompanyProfileSettings $companyProfile) {}

    /** @return array<string, string> */
    public function missingLegalIdentity(Contracts $contract, ?array $profile = null): array
    {
        $contract->loadMissing('client');
        $profile ??= $this->companyProfile->get();
        $missing = [];

        if (blank($profile['legal_name'] ?? null)) {
            $missing['supplier_legal_name'] = 'leverandørens juridiske navn';
        }

        if (blank($profile['organization_number'] ?? null)) {
            $missing['supplier_organization_number'] = 'leverandørens organisasjonsnummer';
        }

        if (blank($contract->client?->name)) {
            $missing['customer_legal_name'] = 'kundens juridiske navn';
        }

        if (blank($contract->client?->org_no)) {
            $missing['customer_organization_number'] = 'kundens organisasjonsnummer';
        }

        return $missing;
    }

    public function failureMessage(Contracts $contract, ?array $profile = null): string
    {
        $missing = array_values($this->missingLegalIdentity($contract, $profile));

        return $missing === []
            ? ''
            : 'Kundedokumentet kan ikke sendes eller godkjennes før følgende er registrert: '
                .implode(', ', $missing).'.';
    }

    public function assertReadyForCapture(Contracts $contract, ?array $profile = null): void
    {
        $message = $this->failureMessage($contract, $profile);

        if ($message !== '') {
            throw new DomainException($message);
        }
    }
}
