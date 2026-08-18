<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailCanonicalParityAttestation;
use App\Modules\Email\Models\EmailCanonicalParityAttestationItem;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalCutoverAuthorization;
use App\Modules\Email\Services\EmailCanonicalCutoverEvidence;
use App\Modules\Email\Services\EmailCanonicalParityAttestationValidator;
use App\Modules\Email\Services\EmailCanonicalParityScope;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Hashes at most one bounded page of whole-account parity evidence per operator request. */
final class ProcessEmailCanonicalParityAttestation
{
    public const DEFAULT_BATCH_SIZE = 100;

    public const MAX_BATCH_SIZE = 100;

    public function __construct(
        private readonly EmailCanonicalCutoverAuthorization $authorization,
        private readonly EmailCanonicalCutoverEvidence $evidence,
        private readonly EmailCanonicalParityScope $scope,
        private readonly EmailCanonicalParityAttestationValidator $validator,
    ) {}

    public function handle(
        EmailCanonicalParityAttestation $attestation,
        User $actor,
        int $batchSize = self::DEFAULT_BATCH_SIZE,
    ): EmailCanonicalParityAttestation {
        if ($batchSize < 1 || $batchSize > self::MAX_BATCH_SIZE) {
            throw ValidationException::withMessages(['batch_size' => 'Choose a parity page from 1 to 100.']);
        }

        $current = EmailCanonicalParityAttestation::query()->findOrFail($attestation->id);
        $this->authorization->authorize($actor, [(int) $current->email_account_id]);

        $lock = Cache::lock('email-canonical-parity-attestation:'.$attestation->id, 300);
        if (! $lock->get()) {
            throw ValidationException::withMessages(['attestation' => 'This parity page is already being processed.']);
        }

        try {
            return $this->processLocked($attestation, $actor, $batchSize);
        } catch (AuthorizationException $exception) {
            // Losing current authority is not evidence that the frozen mailbox parity failed.
            throw $exception;
        } catch (Throwable $exception) {
            EmailCanonicalParityAttestation::query()
                ->whereKey($attestation->id)
                ->where('status', '!=', EmailCanonicalParityAttestation::STATUS_COMPLETED)
                ->update([
                    'status' => EmailCanonicalParityAttestation::STATUS_FAILED,
                    'error_code' => $exception instanceof ValidationException
                        ? 'parity_verification_failed'
                        : 'parity_processing_failed',
                    'updated_at' => now(),
                ]);

            throw $exception;
        } finally {
            $lock->release();
        }
    }

    private function processLocked(
        EmailCanonicalParityAttestation $attestation,
        User $actor,
        int $batchSize,
    ): EmailCanonicalParityAttestation {
        $attestation = EmailCanonicalParityAttestation::query()->findOrFail($attestation->id);
        $authorized = $this->authorization->authorize($actor, [(int) $attestation->email_account_id]);
        $actor = $authorized['actor'];
        if ($attestation->status === EmailCanonicalParityAttestation::STATUS_COMPLETED) {
            return $this->validator->assertUsable(
                $attestation,
                (int) $attestation->email_account_id,
                (bool) $attestation->strict_evidence,
            );
        }
        if ($attestation->algorithm_version !== EmailCanonicalCutoverEvidence::ALGORITHM_VERSION) {
            throw ValidationException::withMessages(['attestation' => 'The parity algorithm changed. Start again.']);
        }

        $this->assertFrozenScope($attestation);
        $attestation->forceFill([
            'status' => EmailCanonicalParityAttestation::STATUS_RUNNING,
            'error_code' => null,
        ])->save();

        $placements = EmailMailboxPlacement::query()
            ->where('account_id', $attestation->email_account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->where('id', '>', $attestation->next_placement_id)
            ->where('id', '<=', $attestation->frozen_max_placement_id)
            ->orderBy('id')
            ->limit($batchSize)
            ->get([
                'id',
                'email_message_id',
                'account_id',
                'canonical_email_message_id',
            ]);
        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $placements->pluck('email_message_id'))
            ->get()
            ->keyBy('source_email_message_id');

        $items = [];
        $batchEvidenceBytes = 0;
        foreach ($placements as $placement) {
            $mapping = $mappings->get($placement->email_message_id);
            $message = EmailMessage::query()
                ->with(['account:id,address', 'attachments'])
                ->find($placement->email_message_id);
            $canonical = $mapping
                ? EmailCanonicalMessage::query()
                    ->with('attachments')
                    ->find($mapping->canonical_email_message_id)
                : null;
            if (! $message
                || (int) $message->account_id !== (int) $attestation->email_account_id
                || ! $mapping
                || ! $canonical
                || $canonical->status !== EmailCanonicalMessage::STATUS_ACTIVE
                || (int) $placement->canonical_email_message_id !== (int) $canonical->id) {
                throw ValidationException::withMessages([
                    'attestation' => 'Every active placement must have exact canonical pointer parity.',
                ]);
            }

            $snapshot = null;
            if ($attestation->strict_evidence) {
                // Load and release one source/projection at a time. A 100-row page remains durable
                // and convenient without materializing 100 maximum-sized bodies/JSON blobs at once.
                $snapshot = $this->evidence->forMessage($message);
                $batchEvidenceBytes += (int) $snapshot['evidence_bytes'];
                if ($batchEvidenceBytes > PreviewEmailCanonicalCutover::MAX_EVIDENCE_BYTES
                    || ! $snapshot['complete']
                    || ! $mapping->evidence_complete
                    || ! $canonical->evidence_complete
                    || ! hash_equals((string) $mapping->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                    || ! hash_equals((string) $mapping->source_state_hash, (string) $snapshot['source_state_hash'])
                    || ! hash_equals((string) $canonical->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                    || ! hash_equals(
                        (string) $canonical->root_projection_hash,
                        $this->evidence->storedProjectionHash($canonical),
                    )) {
                    throw ValidationException::withMessages([
                        'attestation' => 'Canonical actual-file evidence parity failed in this bounded page.',
                    ]);
                }
            }

            $sourceStateHash = $snapshot['source_state_hash'] ?? (string) $mapping->source_state_hash;
            $strictEvidenceHash = $snapshot['strict_evidence_hash'] ?? null;
            $placementStateHash = $this->placementStateHash(
                $placement,
                $mapping,
                $canonical,
                $sourceStateHash,
                $strictEvidenceHash,
            );
            $items[] = [
                'email_canonical_parity_attestation_id' => $attestation->id,
                'email_mailbox_placement_id' => $placement->id,
                'source_email_message_id' => $placement->email_message_id,
                'canonical_email_message_id' => $canonical->id,
                'source_state_hash' => $sourceStateHash,
                'strict_evidence_hash' => $strictEvidenceHash,
                'canonical_projection_hash' => $canonical->root_projection_hash,
                'placement_state_hash' => $placementStateHash,
                'evidence_bytes' => (int) ($snapshot['evidence_bytes'] ?? 0),
                'created_at' => now(),
            ];
            unset($canonical, $message, $snapshot);
        }

        return DB::transaction(function () use (
            $attestation,
            $actor,
            $batchSize,
            $batchEvidenceBytes,
            $items,
            $placements,
        ): EmailCanonicalParityAttestation {
            $locked = EmailCanonicalParityAttestation::query()->lockForUpdate()->findOrFail($attestation->id);
            if ((int) $locked->next_placement_id !== (int) $attestation->next_placement_id) {
                throw ValidationException::withMessages(['attestation' => 'The parity cursor changed concurrently.']);
            }
            $this->assertFrozenScope($locked);

            $rollingHash = (string) $locked->rolling_evidence_hash;
            foreach ($items as $item) {
                EmailCanonicalParityAttestationItem::query()->create($item);
                $rollingHash = hash('sha256', $rollingHash.'|'.$item['placement_state_hash']);
            }

            $nextPlacementId = (int) ($placements->last()?->id ?? $locked->next_placement_id);
            $verifiedCount = (int) $locked->verified_placement_count + count($items);
            $locked->forceFill([
                'next_placement_id' => $nextPlacementId,
                'verified_placement_count' => $verifiedCount,
                'total_evidence_bytes' => (int) $locked->total_evidence_bytes + $batchEvidenceBytes,
                'rolling_evidence_hash' => $rollingHash,
                'status' => EmailCanonicalParityAttestation::STATUS_PENDING,
            ])->save();

            $hasMore = EmailMailboxPlacement::query()
                ->where('account_id', $locked->email_account_id)
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->whereNull('provider_missing_at')
                ->where('id', '>', $nextPlacementId)
                ->where('id', '<=', $locked->frozen_max_placement_id)
                ->exists();
            if (! $hasMore) {
                $this->assertFrozenScope($locked);
                if ($verifiedCount !== (int) $locked->frozen_active_placement_count
                    || $locked->items()->count() !== $verifiedCount) {
                    throw ValidationException::withMessages([
                        'attestation' => 'The durable parity item set is incomplete.',
                    ]);
                }
                $locked->forceFill([
                    'status' => EmailCanonicalParityAttestation::STATUS_COMPLETED,
                    'completed_by' => $actor->id,
                    'completed_at' => now(),
                ])->save();
                $locked->forceFill([
                    'attestation_fingerprint' => $this->validator->fingerprint($locked),
                ])->save();
                $this->validator->assertUsable(
                    $locked,
                    (int) $locked->email_account_id,
                    (bool) $locked->strict_evidence,
                    (string) $locked->attestation_fingerprint,
                );
            } elseif (count($items) < $batchSize) {
                throw ValidationException::withMessages([
                    'attestation' => 'The frozen parity cursor could not advance to the next page.',
                ]);
            }

            return $locked->refresh();
        });
    }

    private function assertFrozenScope(EmailCanonicalParityAttestation $attestation): void
    {
        $summary = $this->scope->summary((int) $attestation->email_account_id);
        if ((int) $attestation->frozen_active_placement_count !== $summary['active_count']
            || (int) $attestation->frozen_max_placement_id !== $summary['max_placement_id']
            || ! hash_equals((string) $attestation->scope_state_hash, $summary['state_hash'])) {
            throw ValidationException::withMessages([
                'attestation' => 'The active account scope changed. Start a fresh parity attestation.',
            ]);
        }
    }

    private function placementStateHash(
        EmailMailboxPlacement $placement,
        EmailCanonicalMessageSource $mapping,
        EmailCanonicalMessage $canonical,
        string $sourceStateHash,
        ?string $strictEvidenceHash,
    ): string {
        return hash('sha256', json_encode([
            'algorithm' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
            'placement_id' => (int) $placement->id,
            'source_message_id' => (int) $placement->email_message_id,
            'canonical_id' => (int) $canonical->id,
            'pointer_id' => (int) $placement->canonical_email_message_id,
            'mapping_kind' => $mapping->mapping_kind,
            'mapping_source_state_hash' => $mapping->source_state_hash,
            'mapping_strict_evidence_hash' => $mapping->strict_evidence_hash,
            'source_state_hash' => $sourceStateHash,
            'strict_evidence_hash' => $strictEvidenceHash,
            'canonical_strict_evidence_hash' => $canonical->strict_evidence_hash,
            'canonical_projection_hash' => $canonical->root_projection_hash,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
