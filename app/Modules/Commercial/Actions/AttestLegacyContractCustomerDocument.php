<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\System\Support\CompanyProfileSettings;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Freeze a named, manually verified reconstruction for pre-snapshot contracts.
 *
 * This is deliberately separate from ordinary capture: current live data is
 * never treated as historical evidence without an explicit human attestation.
 */
final class AttestLegacyContractCustomerDocument
{
    private const LEGACY_STATUSES = [
        'sent_quote',
        'sent_contract',
        'approved',
        'won',
    ];

    public function __construct(
        private readonly ContractCustomerDocument $documents,
        private readonly ContractDocumentReadiness $readiness,
        private readonly CompanyProfileSettings $companyProfile,
    ) {}

    public function handle(
        Contracts $contract,
        User $attestedBy,
        string $note,
        bool $confirmed,
        string $expectedFingerprint,
        string $documentType,
    ): array {
        return DB::transaction(function () use ($contract, $attestedBy, $note, $confirmed, $expectedFingerprint, $documentType): array {
            $locked = Contracts::query()
                ->with('client')
                ->lockForUpdate()
                ->findOrFail($contract->getKey());

            if (! in_array((string) $locked->approval_status, self::LEGACY_STATUSES, true)) {
                throw new DomainException(
                    'Bare historiske sendte eller godkjente kontrakter kan attesteres.'
                );
            }

            $requiredDocumentType = match ((string) $locked->approval_status) {
                'sent_quote' => 'Tilbud',
                'sent_contract' => 'Avtale',
                default => null,
            };
            if (! in_array($documentType, ['Tilbud', 'Avtale'], true)
                || ($requiredDocumentType !== null && $documentType !== $requiredDocumentType)) {
                throw new DomainException(
                    'Historisk dokumenttype samsvarer ikke med kontraktens sendestatus.'
                );
            }

            if ($this->documents->hasStoredSnapshot($locked)) {
                throw new DomainException(
                    'Kundedokumentet har allerede et uforanderlig snapshot og kan ikke erstattes.'
                );
            }

            if (! $confirmed || ! $attestedBy->getKey()) {
                throw new DomainException(
                    'En navngitt tekniker må bekrefte den manuelle kontrollen.'
                );
            }

            $note = $this->documents->plainText($note);
            if (mb_strlen($note, 'UTF-8') < 20) {
                throw new DomainException(
                    'Attestasjonen må beskrive hvilket originalt underlag som er kontrollert.'
                );
            }

            if (! $locked->hasValidContractPeriod()) {
                throw new DomainException(
                    'Rekonstruksjonen kan ikke attesteres før avtaleperioden er gyldig.'
                );
            }

            $profile = $this->companyProfile->get();
            $this->readiness->assertReadyForCapture($locked, $profile);

            $snapshot = $this->documents->previewForLegacyAttestation(
                $locked,
                $profile,
                $documentType,
            );
            $fingerprint = $this->documents->fingerprint($snapshot);

            if (! hash_equals($fingerprint, strtolower($expectedFingerprint))) {
                throw new DomainException(
                    'Rekonstruksjonsgrunnlaget er endret siden det ble vist. Last siden på nytt, kontroller hele dokumentet igjen og attester deretter.'
                );
            }

            $metadata = is_array($locked->approval_metadata) ? $locked->approval_metadata : [];
            $metadata['customer_document_legacy_attestation'] = [
                'metadata_version' => 1,
                'source' => 'manual_tech_reconstruction',
                'status_at_attestation' => (string) $locked->approval_status,
                'original_document_type' => $documentType,
                'attested_at' => now()->toIso8601String(),
                'attested_by_user_id' => (int) $attestedBy->getKey(),
                'attested_by_name' => (string) $attestedBy->name,
                'note' => $note,
                'snapshot_sha256' => $fingerprint,
            ];

            $locked->forceFill([
                'customer_document_snapshot' => $snapshot,
                'approval_metadata' => $metadata,
            ])->saveQuietly();

            $contract->setAttribute('customer_document_snapshot', $snapshot);
            $contract->setAttribute('approval_metadata', $metadata);

            return $snapshot;
        });
    }
}
