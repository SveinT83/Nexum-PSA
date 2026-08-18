<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailCanonicalParityAttestation;
use App\Modules\Email\Services\EmailCanonicalCutoverAuthorization;
use App\Modules\Email\Services\EmailCanonicalCutoverEvidence;
use App\Modules\Email\Services\EmailCanonicalParityAttestationValidator;
use App\Modules\Email\Services\EmailCanonicalParityScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StartEmailCanonicalParityAttestation
{
    public function __construct(
        private readonly EmailCanonicalCutoverAuthorization $authorization,
        private readonly EmailCanonicalParityScope $scope,
        private readonly EmailCanonicalParityAttestationValidator $validator,
    ) {}

    public function handle(
        User $actor,
        int $accountId,
        bool $strictEvidence = true,
    ): EmailCanonicalParityAttestation {
        $authorized = $this->authorization->authorize($actor, [$accountId]);
        $actor = $authorized['actor'];

        return Cache::lock('email-canonical-parity-account:'.$accountId, 30)->block(5, function () use (
            $accountId,
            $actor,
            $strictEvidence,
        ): EmailCanonicalParityAttestation {
            $summary = $this->scope->summary($accountId);
            $existing = EmailCanonicalParityAttestation::query()
                ->where('email_account_id', $accountId)
                ->where('strict_evidence', $strictEvidence)
                ->where('scope_state_hash', $summary['state_hash'])
                ->whereIn('status', [
                    EmailCanonicalParityAttestation::STATUS_PENDING,
                    EmailCanonicalParityAttestation::STATUS_RUNNING,
                    EmailCanonicalParityAttestation::STATUS_COMPLETED,
                ])
                ->latest('id')
                ->first();
            if ($existing) {
                if ($existing->status !== EmailCanonicalParityAttestation::STATUS_COMPLETED) {
                    return $existing;
                }

                try {
                    return $this->validator->assertUsable(
                        $existing,
                        $accountId,
                        $strictEvidence,
                    );
                } catch (ValidationException) {
                    // A completed but stale/corrupt durable record stays available for audit. Start
                    // a new frozen run instead of trapping the operator until its age window ends.
                }
            }

            return DB::transaction(function () use (
                $accountId,
                $actor,
                $strictEvidence,
                $summary,
            ): EmailCanonicalParityAttestation {
                $attestation = EmailCanonicalParityAttestation::query()->create([
                    'email_account_id' => $accountId,
                    'requested_by' => $actor->id,
                    'completed_by' => null,
                    'algorithm_version' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
                    'status' => EmailCanonicalParityAttestation::STATUS_PENDING,
                    'strict_evidence' => $strictEvidence,
                    'frozen_max_placement_id' => $summary['max_placement_id'],
                    'frozen_active_placement_count' => $summary['active_count'],
                    'next_placement_id' => 0,
                    'verified_placement_count' => 0,
                    'total_evidence_bytes' => 0,
                    'scope_state_hash' => $summary['state_hash'],
                    'rolling_evidence_hash' => hash('sha256', 'canonical-parity-empty-v1'),
                    'attestation_fingerprint' => null,
                    'error_code' => null,
                    'started_at' => now(),
                    'completed_at' => null,
                ]);

                if ($summary['active_count'] === 0) {
                    $attestation->forceFill([
                        'status' => EmailCanonicalParityAttestation::STATUS_COMPLETED,
                        'completed_by' => $actor->id,
                        'completed_at' => now(),
                    ])->save();
                    $attestation->forceFill([
                        'attestation_fingerprint' => $this->validator->fingerprint($attestation),
                    ])->save();
                }

                return $attestation->refresh();
            });
        });
    }
}
