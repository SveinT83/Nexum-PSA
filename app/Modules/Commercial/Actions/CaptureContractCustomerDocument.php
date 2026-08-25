<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\Commercial\Support\ContractLegacyDocumentReadiness;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\System\Support\CompanyProfileSettings;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Capture the customer-facing commercial/legal document exactly once.
 */
final class CaptureContractCustomerDocument
{
    public function __construct(
        private readonly ContractCustomerDocument $documents,
        private readonly ContractDocumentReadiness $readiness,
        private readonly ContractLegacyDocumentReadiness $legacyReadiness,
        private readonly ContractTermSnapshotReadiness $termReadiness,
        private readonly CompanyProfileSettings $companyProfile,
    ) {}

    public function handle(
        Contracts $contract,
        ?string $statusOverride = null,
        bool $replace = false,
    ): array {
        return DB::transaction(function () use ($contract, $statusOverride, $replace): array {
            $locked = Contracts::query()
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if ($replace && ! $locked->isEditable()) {
                throw new LogicException('Customer document snapshots can only be replaced while the contract is editable.');
            }

            $existing = $replace ? null : $this->documents->storedSnapshot($locked);

            if ($existing !== null) {
                // Every non-null database value is immutable evidence. The
                // typed reader above rejects empty, scalar and unknown schemas.
                $contract->setAttribute('customer_document_snapshot', $existing);

                return $existing;
            }

            $profile = $this->companyProfile->get();
            $targetStatus = $statusOverride ?? (string) $locked->approval_status;

            if (in_array($targetStatus, ['sent_quote', 'sent_contract', 'approved', 'won'], true)) {
                if ($locked->isEditable() && ! $locked->isReady()) {
                    throw new DomainException(
                        'Kundedokumentet kan ikke fryses før kontrakten har tjenester, vilkår og en gyldig avtaleperiode.'
                    );
                }

                $this->readiness->assertReadyForCapture($locked, $profile);
                $this->termReadiness->assertCurrent($locked);
                $this->legacyReadiness->assertSafeProjection($locked);
            }

            $snapshot = $this->documents->build(
                $locked,
                $profile,
                $statusOverride,
            );
            $this->documents->assertSupportedSnapshot($snapshot);

            $locked->forceFill([
                'customer_document_snapshot' => $snapshot,
            ])->saveQuietly();
            $contract->setAttribute('customer_document_snapshot', $snapshot);

            return $snapshot;
        });
    }

    public function replace(Contracts $contract, ?string $statusOverride = null): array
    {
        return $this->handle($contract, $statusOverride, true);
    }
}
