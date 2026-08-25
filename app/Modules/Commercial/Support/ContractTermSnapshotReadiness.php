<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Models\Contracts\Contracts;
use DomainException;

/**
 * Keep generated legal text aligned with the catalogue versions named in the
 * immutable customer document without overwriting manually reviewed wording.
 */
final class ContractTermSnapshotReadiness
{
    private const METADATA_KEY = 'customer_document_terms';

    private const METADATA_VERSION = 2;

    public function __construct(private readonly BuildContractTermSnapshots $builder) {}

    public function isCurrent(Contracts $contract): bool
    {
        $currentSource = $this->builder->sourceFingerprint($contract);
        $currentSnapshot = $this->builder->snapshotFingerprint($contract);
        $metadata = is_array($contract->approval_metadata) ? $contract->approval_metadata : [];
        $review = $metadata[self::METADATA_KEY] ?? null;
        $hasSnapshotText = collect(BuildContractTermSnapshots::SNAPSHOT_FIELDS)
            ->contains(fn (string $field): bool => filled($contract->{$field}));

        if ($currentSource === null && ! $hasSnapshotText) {
            return true;
        }

        $hasCompleteReview = is_array($review)
            && ($review['metadata_version'] ?? null) === self::METADATA_VERSION
            && array_key_exists('source_fingerprint', $review)
            && is_string($review['snapshot_fingerprint'] ?? null)
            && is_array($review['source_snapshot_checksums'] ?? null);

        if ($hasCompleteReview) {
            $reviewedSource = $review['source_fingerprint'];
            $sourceMatches = $currentSource === null
                ? $reviewedSource === null
                : is_string($reviewedSource) && hash_equals($currentSource, $reviewedSource);

            return $sourceMatches
                && hash_equals($currentSnapshot, $review['snapshot_fingerprint']);
        }

        // A pre-metadata sent document is recoverable without rewriting text
        // only when its source-backed fields still equal today's generator.
        return in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true)
            && filled($contract->terms_snapshot)
            && $this->builder->contractSnapshotsMatchCurrentSources($contract);
    }

    public function markReviewed(Contracts $contract, ?int $userId = null): void
    {
        $metadata = is_array($contract->approval_metadata) ? $contract->approval_metadata : [];
        $metadata[self::METADATA_KEY] = [
            'metadata_version' => self::METADATA_VERSION,
            'source_fingerprint' => $this->builder->sourceFingerprint($contract),
            'snapshot_fingerprint' => $this->builder->snapshotFingerprint($contract),
            'source_snapshot_checksums' => $this->builder->sourceSnapshotChecksums($contract),
            'reviewed_at' => now()->toIso8601String(),
            'reviewed_by_user_id' => $userId,
        ];

        $contract->forceFill(['approval_metadata' => $metadata])->save();
    }

    public function failureMessage(): string
    {
        return 'Vilkårene samsvarer ikke med tjenestene og versjonene som skal vedlegges. Oppdater eller lagre vilkårene etter gjennomgang før utsending.';
    }

    public function assertCurrent(Contracts $contract): void
    {
        if (! $this->isCurrent($contract)) {
            throw new DomainException($this->failureMessage());
        }
    }
}
