<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalParityAttestation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class EmailCanonicalParityAttestationValidator
{
    public const VALID_MINUTES = 15;

    public function __construct(private readonly EmailCanonicalParityScope $scope) {}

    public function latestUsable(int $accountId, bool $requireStrict): EmailCanonicalParityAttestation
    {
        $query = EmailCanonicalParityAttestation::query()
            ->where('email_account_id', $accountId)
            ->where('status', EmailCanonicalParityAttestation::STATUS_COMPLETED);
        if ($requireStrict) {
            $query->where('strict_evidence', true);
        }

        $attestation = $query->latest('id')->first();
        if (! $attestation) {
            throw ValidationException::withMessages([
                'mode' => 'Complete a current paginated whole-account parity attestation first.',
            ]);
        }

        return $this->assertUsable($attestation, $accountId, $requireStrict);
    }

    public function assertUsable(
        EmailCanonicalParityAttestation $attestation,
        int $accountId,
        bool $requireStrict,
        ?string $expectedFingerprint = null,
    ): EmailCanonicalParityAttestation {
        $attestation = EmailCanonicalParityAttestation::query()->find($attestation->id);
        $summary = $this->scope->summary($accountId);
        $fingerprint = $attestation ? $this->fingerprint($attestation) : null;
        $usable = $attestation
            && (int) $attestation->email_account_id === $accountId
            && $attestation->status === EmailCanonicalParityAttestation::STATUS_COMPLETED
            && $attestation->algorithm_version === EmailCanonicalCutoverEvidence::ALGORITHM_VERSION
            && (! $requireStrict || $attestation->strict_evidence)
            && $attestation->completed_at?->gte(now()->subMinutes(self::VALID_MINUTES))
            && (int) $attestation->frozen_active_placement_count === $summary['active_count']
            && (int) $attestation->frozen_max_placement_id === $summary['max_placement_id']
            && hash_equals((string) $attestation->scope_state_hash, $summary['state_hash'])
            && (int) $attestation->verified_placement_count === (int) $attestation->frozen_active_placement_count
            && $attestation->items()->count() === (int) $attestation->verified_placement_count
            && $this->databaseParityMatches($attestation)
            && hash_equals((string) $attestation->attestation_fingerprint, (string) $fingerprint)
            && ($expectedFingerprint === null
                || hash_equals($expectedFingerprint, (string) $attestation->attestation_fingerprint));

        if (! $usable) {
            throw ValidationException::withMessages([
                'mode' => 'The whole-account parity attestation is missing, stale, or changed.',
            ]);
        }

        return $attestation;
    }

    public function fingerprint(EmailCanonicalParityAttestation $attestation): string
    {
        return hash('sha256', json_encode([
            'algorithm' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
            'attestation_id' => (int) $attestation->id,
            'account_id' => (int) $attestation->email_account_id,
            'strict_evidence' => (bool) $attestation->strict_evidence,
            'active_count' => (int) $attestation->frozen_active_placement_count,
            'max_placement_id' => (int) $attestation->frozen_max_placement_id,
            'verified_count' => (int) $attestation->verified_placement_count,
            'total_evidence_bytes' => (int) $attestation->total_evidence_bytes,
            'scope_state_hash' => $attestation->scope_state_hash,
            'rolling_evidence_hash' => $attestation->rolling_evidence_hash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * Recheck every frozen placement/mapping/projection with one indexed database anti-join. This
     * materializes no mailbox page and leaves actual private-file rehashing to the recent paginated
     * attestation plus the raw/attachment read boundary.
     */
    private function databaseParityMatches(EmailCanonicalParityAttestation $attestation): bool
    {
        return ! DB::table('email_canonical_parity_attestation_items as items')
            ->leftJoin(
                'email_mailbox_placements as placements',
                'placements.id',
                '=',
                'items.email_mailbox_placement_id',
            )
            ->leftJoin(
                'email_canonical_message_sources as mappings',
                'mappings.source_email_message_id',
                '=',
                'items.source_email_message_id',
            )
            ->leftJoin(
                'email_canonical_messages as canonicals',
                'canonicals.id',
                '=',
                'items.canonical_email_message_id',
            )
            ->where('items.email_canonical_parity_attestation_id', $attestation->id)
            ->where(function ($query) use ($attestation): void {
                $query->whereNull('placements.id')
                    ->orWhere('placements.account_id', '!=', $attestation->email_account_id)
                    ->orWhere('placements.local_state', '!=', EmailMailboxPlacement::LOCAL_ACTIVE)
                    ->orWhereNotNull('placements.provider_missing_at')
                    ->orWhereColumn('placements.email_message_id', '!=', 'items.source_email_message_id')
                    ->orWhereNull('placements.canonical_email_message_id')
                    ->orWhereColumn('placements.canonical_email_message_id', '!=', 'items.canonical_email_message_id')
                    ->orWhereNull('mappings.id')
                    ->orWhereColumn('mappings.canonical_email_message_id', '!=', 'items.canonical_email_message_id')
                    ->orWhereColumn('mappings.source_state_hash', '!=', 'items.source_state_hash')
                    ->orWhereNull('canonicals.id')
                    ->orWhere('canonicals.status', '!=', EmailCanonicalMessage::STATUS_ACTIVE)
                    ->orWhereColumn('canonicals.root_projection_hash', '!=', 'items.canonical_projection_hash');
                if ($attestation->strict_evidence) {
                    $query->orWhere('mappings.evidence_complete', false)
                        ->orWhere('canonicals.evidence_complete', false)
                        ->orWhereNull('items.strict_evidence_hash')
                        ->orWhereColumn('mappings.strict_evidence_hash', '!=', 'items.strict_evidence_hash')
                        ->orWhereColumn('canonicals.strict_evidence_hash', '!=', 'items.strict_evidence_hash');
                }
            })
            ->exists();
    }
}
