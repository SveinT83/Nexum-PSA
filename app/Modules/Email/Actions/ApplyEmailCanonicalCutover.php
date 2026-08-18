<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAttachment;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailCanonicalCorrelationInspection;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailCanonicalCutoverItem;
use App\Modules\Email\Models\EmailCanonicalCutoverRun;
use App\Modules\Email\Models\EmailCanonicalMessage;
use App\Modules\Email\Models\EmailCanonicalMessageSource;
use App\Modules\Email\Models\EmailCanonicalParityAttestation;
use App\Modules\Email\Models\EmailCanonicalReadMode;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\EmailCanonicalCorrelationEvidence;
use App\Modules\Email\Services\EmailCanonicalCutoverAuthorization;
use App\Modules\Email\Services\EmailCanonicalCutoverEvidence;
use App\Modules\Email\Services\EmailCanonicalParityAttestationValidator;
use App\Modules\Email\Services\EmailCanonicalProjectionWriter;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

final class ApplyEmailCanonicalCutover
{
    public function __construct(
        private readonly EmailCanonicalCutoverAuthorization $authorization,
        private readonly EmailCanonicalCutoverEvidence $evidence,
        private readonly EmailCanonicalCorrelationEvidence $shadowEvidence,
        private readonly EmailCanonicalProjectionWriter $writer,
        private readonly EmailCanonicalParityAttestationValidator $parityAttestations,
    ) {}

    public function handle(EmailCanonicalCutoverRun $run, User $actor): EmailCanonicalCutoverRun
    {
        try {
            // Hash and budget all materialized fields/files before opening the component-locking
            // transaction. The locked pass below remains authoritative, but an oversized scope
            // cannot hold account/message rows while discovering that it was never eligible.
            $this->preflight($run, $actor);

            return DB::transaction(function () use ($run, $actor): EmailCanonicalCutoverRun {
                $run = EmailCanonicalCutoverRun::query()->lockForUpdate()->find($run->id);
                if (! $run) {
                    throw ValidationException::withMessages(['run' => 'The cutover preview is unavailable.']);
                }
                if ($run->status === EmailCanonicalCutoverRun::STATUS_APPLIED) {
                    return $run;
                }
                if ($run->status !== EmailCanonicalCutoverRun::STATUS_PREVIEWED) {
                    throw ValidationException::withMessages(['run' => 'Only a previewed cutover can be applied.']);
                }

                $accountIds = collect($run->account_scope_json)
                    ->map(fn ($id): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();
                $authorized = $this->authorization->authorize($actor, $accountIds, true);
                $actor = $authorized['actor'];
                $items = $run->items()->orderBy('item_key')->lockForUpdate()->get();
                if ($items->count() !== (int) $run->item_count
                    || $items->count() > (int) $run->item_cap) {
                    throw ValidationException::withMessages(['run' => 'The durable preview item set changed.']);
                }

                $run->forceFill([
                    'status' => EmailCanonicalCutoverRun::STATUS_APPLYING,
                    'error_code' => null,
                    'error_class' => null,
                ])->save();

                if ($run->operation === EmailCanonicalCutoverRun::OPERATION_MODE) {
                    $this->applyModes($items, $actor);
                } else {
                    $this->applySources($run, $items, $actor);
                }

                $run->forceFill([
                    'status' => EmailCanonicalCutoverRun::STATUS_APPLIED,
                    'applied_by' => $actor->id,
                    'applied_count' => $items->count(),
                    'applied_at' => now(),
                ])->save();

                return $run->refresh();
            }, 3);
        } catch (Throwable $exception) {
            EmailCanonicalCutoverRun::query()
                ->whereKey($run->id)
                ->where('status', EmailCanonicalCutoverRun::STATUS_PREVIEWED)
                ->update([
                    'status' => EmailCanonicalCutoverRun::STATUS_FAILED,
                    'error_code' => 'apply_verification_failed',
                    'error_class' => $exception::class,
                    'updated_at' => now(),
                ]);

            throw $exception;
        }
    }

    private function preflight(EmailCanonicalCutoverRun $run, User $actor): void
    {
        $run = EmailCanonicalCutoverRun::query()->find($run->id);
        if (! $run || $run->status === EmailCanonicalCutoverRun::STATUS_APPLIED) {
            return;
        }
        if ($run->status !== EmailCanonicalCutoverRun::STATUS_PREVIEWED) {
            throw ValidationException::withMessages(['run' => 'Only a previewed cutover can be applied.']);
        }

        $accountIds = collect($run->account_scope_json)
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        $this->authorization->authorize($actor, $accountIds);
        $items = $run->items()->orderBy('item_key')->get();
        if ($run->operation === EmailCanonicalCutoverRun::OPERATION_MODE) {
            foreach ($items as $item) {
                if ($item->proposed_read_mode !== EmailCanonicalReadMode::MODE_LEGACY) {
                    $this->assertAccountModeParity($item);
                }
            }

            return;
        }

        $messages = EmailMessage::query()
            ->whereKey($items->pluck('source_email_message_id')->filter())
            ->with(['account:id,address', 'attachments'])
            ->get()
            ->keyBy('id');
        $bytes = 0;
        foreach ($items as $item) {
            $message = $messages->get($item->source_email_message_id);
            if (! $message) {
                throw ValidationException::withMessages(['run' => 'A previewed source message disappeared.']);
            }
            $snapshot = $this->evidence->forMessage($message);
            $bytes += (int) $snapshot['evidence_bytes'];
            if ($bytes > PreviewEmailCanonicalCutover::MAX_EVIDENCE_BYTES
                || ! hash_equals((string) $item->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                || ! hash_equals((string) $item->source_state_hash, (string) $snapshot['source_state_hash'])) {
                throw ValidationException::withMessages(['run' => 'Source evidence changed or exceeds the apply budget.']);
            }
        }
    }

    private function applySources(
        EmailCanonicalCutoverRun $run,
        Collection $items,
        User $actor,
    ): void {
        $sourceIds = $items->pluck('source_email_message_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $messages = EmailMessage::query()
            ->whereKey($sourceIds)
            ->with(['account:id,address', 'attachments', 'placements:id,email_message_id,canonical_email_message_id'])
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        if ($messages->count() !== count($sourceIds)) {
            throw ValidationException::withMessages(['run' => 'A previewed source message disappeared.']);
        }

        EmailAttachment::query()->whereIn('message_id', $sourceIds)->orderBy('id')->lockForUpdate()->get(['id']);
        EmailMailboxPlacement::query()
            ->whereIn('email_message_id', $sourceIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);
        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $sourceIds)
            ->orderBy('source_email_message_id')
            ->lockForUpdate()
            ->get()
            ->keyBy('source_email_message_id');
        EmailCanonicalMessage::query()
            ->whereKey($mappings->pluck('canonical_email_message_id'))
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id']);

        $snapshots = [];
        $evidenceBytes = 0;
        foreach ($items as $item) {
            $message = $messages->get($item->source_email_message_id);
            if (! $message || (int) $message->account_id !== (int) $item->email_account_id) {
                throw ValidationException::withMessages(['run' => 'A previewed source changed account.']);
            }
            $snapshot = $this->evidence->forMessage($message);
            $evidenceBytes += (int) $snapshot['evidence_bytes'];
            if ($evidenceBytes > PreviewEmailCanonicalCutover::MAX_EVIDENCE_BYTES
                || ! hash_equals((string) $item->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                || ! hash_equals((string) $item->source_state_hash, (string) $snapshot['source_state_hash'])
                || (bool) $item->evidence_complete !== (bool) $snapshot['complete']) {
                throw ValidationException::withMessages(['run' => 'Source evidence changed after preview.']);
            }

            $mapping = $mappings->get($message->id);
            $this->assertPreviousMapping($item, $mapping);
            $this->assertPreviousPointers($item, $message);
            if (! hash_equals(
                (string) ($item->previous_canonical_state_hash ?? ''),
                (string) ($this->canonicalStateHash($mapping) ?? ''),
            )) {
                throw ValidationException::withMessages(['run' => 'A canonical component changed after preview.']);
            }
            $snapshots[(int) $message->id] = $snapshot;
        }

        if ($run->operation === EmailCanonicalCutoverRun::OPERATION_MERGE) {
            $this->verifyMerge($run, $items, $messages, $snapshots);
        }

        $oldCanonicalIds = $mappings->pluck('canonical_email_message_id')->map(fn ($id): int => (int) $id)->all();
        $newCanonicalIds = [];
        if ($run->operation === EmailCanonicalCutoverRun::OPERATION_MERGE) {
            foreach ($items->groupBy('component_key') as $componentItems) {
                $rootId = (int) $componentItems->first()->proposed_root_source_message_id;
                $root = $messages->get($rootId);
                $canonical = $this->writer->createProjection($root, $snapshots[$rootId]);
                $newCanonicalIds[] = (int) $canonical->id;

                foreach ($componentItems as $item) {
                    $source = $messages->get($item->source_email_message_id);
                    $this->writer->mapSource(
                        $source,
                        $canonical,
                        $snapshots[(int) $source->id],
                        EmailCanonicalMessageSource::KIND_CONFIRMED_COMPONENT,
                        $actor,
                    );
                    $this->markItemApplied($item, $canonical->id);
                }
            }
        } elseif ($run->operation === EmailCanonicalCutoverRun::OPERATION_AUDIT) {
            foreach ($items->groupBy('component_key') as $componentItems) {
                if ($componentItems->first()->item_kind === EmailCanonicalCutoverItem::KIND_DISSOLVE_MEMBER) {
                    foreach ($componentItems as $item) {
                        $source = $messages->get($item->source_email_message_id);
                        $canonical = $this->writer->createProjection($source, $snapshots[(int) $source->id]);
                        $newCanonicalIds[] = (int) $canonical->id;
                        $this->writer->mapSource(
                            $source,
                            $canonical,
                            $snapshots[(int) $source->id],
                            EmailCanonicalMessageSource::KIND_DRIFT_DISSOLUTION,
                            $actor,
                        );
                        $this->markItemApplied($item, $canonical->id);
                    }

                    continue;
                }

                foreach ($componentItems as $item) {
                    $mapping = $mappings->get($item->source_email_message_id);
                    if (! $mapping) {
                        throw ValidationException::withMessages(['run' => 'Pointer repair lost its source mapping.']);
                    }
                    EmailMailboxPlacement::query()
                        ->where('email_message_id', $item->source_email_message_id)
                        ->update(['canonical_email_message_id' => $mapping->canonical_email_message_id]);
                    $this->markItemApplied($item, $mapping->canonical_email_message_id);
                    $newCanonicalIds[] = (int) $mapping->canonical_email_message_id;
                }
            }
        } else {
            foreach ($items as $item) {
                $source = $messages->get($item->source_email_message_id);
                $mapping = $mappings->get($item->source_email_message_id);
                if ($item->item_kind === EmailCanonicalCutoverItem::KIND_SELF_MAP) {
                    $canonical = $this->writer->createProjection($source, $snapshots[(int) $source->id]);
                    $newCanonicalIds[] = (int) $canonical->id;
                    $this->writer->mapSource(
                        $source,
                        $canonical,
                        $snapshots[(int) $source->id],
                        EmailCanonicalMessageSource::KIND_SELF,
                        $actor,
                    );
                    $this->markItemApplied($item, $canonical->id);

                    continue;
                }

                if (! $mapping) {
                    throw ValidationException::withMessages(['run' => 'Pointer repair lost its source mapping.']);
                }
                EmailMailboxPlacement::query()
                    ->where('email_message_id', $source->id)
                    ->update(['canonical_email_message_id' => $mapping->canonical_email_message_id]);
                $this->markItemApplied($item, $mapping->canonical_email_message_id);
                $newCanonicalIds[] = (int) $mapping->canonical_email_message_id;
            }
        }

        $this->writer->refreshComponentCounts([...$oldCanonicalIds, ...$newCanonicalIds]);
    }

    private function verifyMerge(
        EmailCanonicalCutoverRun $run,
        Collection $items,
        Collection $messages,
        array $snapshots,
    ): void {
        $correlationRun = EmailCanonicalCorrelationRun::query()
            ->lockForUpdate()
            ->find($run->source_correlation_run_id);
        if (! $correlationRun || $correlationRun->status !== EmailCanonicalCorrelationRun::STATUS_COMPLETED) {
            throw ValidationException::withMessages(['run' => 'The source correlation run is no longer completed.']);
        }

        $candidateIds = $items->flatMap(fn ($item): array => $item->correlation_candidate_ids_json ?? [])
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
        $candidates = EmailCanonicalCorrelationCandidate::query()
            ->where('email_canonical_correlation_run_id', $correlationRun->id)
            ->whereKey($candidateIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($candidates->count() !== count($candidateIds)) {
            throw ValidationException::withMessages(['run' => 'The reviewed clique is no longer complete.']);
        }

        $sourceIds = $items->pluck('source_email_message_id')->map(fn ($id): int => (int) $id)->all();
        $touchingIds = EmailCanonicalCorrelationCandidate::query()
            ->where('email_canonical_correlation_run_id', $correlationRun->id)
            ->where('candidate_class', EmailCanonicalCorrelationCandidate::CLASS_STRONG)
            ->where('review_state', EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED)
            ->where(function ($query) use ($sourceIds): void {
                $query->whereIn('left_email_message_id', $sourceIds)
                    ->orWhereIn('right_email_message_id', $sourceIds);
            })
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
        if ($touchingIds !== $candidateIds
            || EmailCanonicalCorrelationCandidate::query()
                ->where('review_state', EmailCanonicalCorrelationCandidate::REVIEW_KEEP_SEPARATE)
                ->whereIn('left_email_message_id', $sourceIds)
                ->whereIn('right_email_message_id', $sourceIds)
                ->exists()) {
            throw ValidationException::withMessages(['run' => 'The reviewed component boundary changed.']);
        }

        foreach ($items->groupBy('component_key') as $componentItems) {
            $componentSources = $componentItems->pluck('source_email_message_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->all();
            $componentCandidateCount = $candidates->filter(fn ($candidate): bool => in_array(
                (int) $candidate->left_email_message_id,
                $componentSources,
                true,
            ) && in_array(
                (int) $candidate->right_email_message_id,
                $componentSources,
                true,
            ))->count();
            $required = count($componentSources) * (count($componentSources) - 1) / 2;
            if ($componentCandidateCount !== $required) {
                throw ValidationException::withMessages(['run' => 'The reviewed component is not a complete clique.']);
            }
            $rootSnapshot = $snapshots[(int) $componentItems->first()->proposed_root_source_message_id];
            foreach ($componentSources as $sourceId) {
                if (! $this->evidence->exactlyEquivalent($rootSnapshot, $snapshots[$sourceId])) {
                    throw ValidationException::withMessages(['run' => 'Exact current local evidence diverged.']);
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate->candidate_class !== EmailCanonicalCorrelationCandidate::CLASS_STRONG
                || $candidate->review_state !== EmailCanonicalCorrelationCandidate::REVIEW_CONFIRMED
                || ! $candidate->reviewed_by
                || ! EmailCanonicalCorrelationInspection::query()
                    ->where('email_canonical_correlation_candidate_id', $candidate->id)
                    ->where('inspected_by', $candidate->reviewed_by)
                    ->where('left_evidence_hash', $candidate->left_evidence_hash)
                    ->where('right_evidence_hash', $candidate->right_evidence_hash)
                    ->exists()) {
                throw ValidationException::withMessages(['run' => 'Reviewed correlation evidence is no longer eligible.']);
            }

            $left = $messages->get($candidate->left_email_message_id);
            $right = $messages->get($candidate->right_email_message_id);
            if (! $left || ! $right
                || (int) $left->account_id !== (int) $candidate->left_email_account_id
                || (int) $right->account_id !== (int) $candidate->right_email_account_id) {
                throw ValidationException::withMessages(['run' => 'A reviewed source changed its account boundary.']);
            }
            $comparison = $this->shadowEvidence->compare(
                $this->shadowEvidence->forMessage($left),
                $this->shadowEvidence->forMessage($right),
            );
            if ($comparison['candidate_class'] !== EmailCanonicalCorrelationCandidate::CLASS_STRONG
                || ! hash_equals($candidate->left_evidence_hash, $comparison['left_evidence_hash'])
                || ! hash_equals($candidate->right_evidence_hash, $comparison['right_evidence_hash'])
                || ! hash_equals($candidate->pair_fingerprint, $comparison['pair_fingerprint'])) {
                throw ValidationException::withMessages(['run' => 'The reviewed shadow evidence changed.']);
            }
        }

        $mappedCanonicalIds = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $sourceIds)
            ->pluck('canonical_email_message_id');
        if (EmailCanonicalMessageSource::query()
            ->whereIn('canonical_email_message_id', $mappedCanonicalIds)
            ->whereNotIn('source_email_message_id', $sourceIds)
            ->exists()) {
            throw ValidationException::withMessages(['run' => 'An existing canonical component gained another source.']);
        }
    }

    private function applyModes(Collection $items, User $actor): void
    {
        foreach ($items as $item) {
            $mode = EmailCanonicalReadMode::query()
                ->where('email_account_id', $item->email_account_id)
                ->lockForUpdate()
                ->first();
            $current = $mode?->mode ?? EmailCanonicalReadMode::MODE_LEGACY;
            if ($current !== $item->previous_read_mode) {
                throw ValidationException::withMessages(['mode' => 'An account read mode changed after preview.']);
            }
            if ($item->proposed_read_mode !== EmailCanonicalReadMode::MODE_LEGACY) {
                $this->assertAccountModeParity($item);
            }

            $mode = EmailCanonicalReadMode::query()->updateOrCreate([
                'email_account_id' => $item->email_account_id,
            ], [
                'mode' => $item->proposed_read_mode,
                'updated_by' => $actor->id,
                'lock_version' => (int) ($mode?->lock_version ?? 0) + 1,
            ]);
            $item->forceFill([
                'status' => EmailCanonicalCutoverItem::STATUS_APPLIED,
                'applied_at' => now(),
            ])->save();
        }
    }

    private function assertAccountModeParity(EmailCanonicalCutoverItem $item): void
    {
        $accountId = (int) $item->email_account_id;
        $strict = $item->proposed_read_mode === EmailCanonicalReadMode::MODE_CANONICAL;
        if ($item->parity_attestation_id) {
            $attestation = EmailCanonicalParityAttestation::query()->find($item->parity_attestation_id);
            if (! $attestation) {
                throw ValidationException::withMessages(['mode' => 'The bound parity attestation disappeared.']);
            }
            $this->parityAttestations->assertUsable(
                $attestation,
                $accountId,
                $strict,
                (string) $item->parity_attestation_fingerprint,
            );

            return;
        }

        $placements = EmailMailboxPlacement::query()
            ->where('account_id', $accountId)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->with(['message.account:id,address', 'message.attachments'])
            ->limit(PreviewEmailCanonicalCutover::MAX_ITEM_CAP + 1)
            ->lockForUpdate()
            ->get();
        if ($placements->count() > PreviewEmailCanonicalCutover::MAX_ITEM_CAP) {
            throw ValidationException::withMessages(['mode' => 'The account exceeds the bounded parity cap.']);
        }

        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $placements->pluck('email_message_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('source_email_message_id');
        $canonicals = EmailCanonicalMessage::query()
            ->whereKey($mappings->pluck('canonical_email_message_id'))
            ->with('attachments')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $bytes = 0;
        foreach ($placements as $placement) {
            $mapping = $mappings->get($placement->email_message_id);
            $canonical = $mapping ? $canonicals->get($mapping->canonical_email_message_id) : null;
            if (! $mapping
                || ! $canonical
                || $canonical->status !== EmailCanonicalMessage::STATUS_ACTIVE
                || (int) $placement->canonical_email_message_id !== (int) $canonical->id) {
                throw ValidationException::withMessages(['mode' => 'Placement pointer parity changed.']);
            }
            if (! $strict) {
                continue;
            }
            $snapshot = $this->evidence->forMessage($placement->message);
            $bytes += (int) $snapshot['evidence_bytes'];
            if ($bytes > PreviewEmailCanonicalCutover::MAX_EVIDENCE_BYTES
                || ! $snapshot['complete']
                || ! $mapping->evidence_complete
                || ! $canonical->evidence_complete
                || ! hash_equals($mapping->strict_evidence_hash, $snapshot['strict_evidence_hash'])
                || ! hash_equals($mapping->source_state_hash, $snapshot['source_state_hash'])
                || ! hash_equals($canonical->strict_evidence_hash, $snapshot['strict_evidence_hash'])
                || ! hash_equals($canonical->root_projection_hash, $this->evidence->storedProjectionHash($canonical))) {
                throw ValidationException::withMessages(['mode' => 'Canonical evidence parity changed.']);
            }
        }
    }

    private function assertPreviousMapping(
        EmailCanonicalCutoverItem $item,
        ?EmailCanonicalMessageSource $mapping,
    ): void {
        if ($item->previous_canonical_email_message_id === null) {
            if ($mapping !== null) {
                throw ValidationException::withMessages(['run' => 'A source mapping appeared after preview.']);
            }

            return;
        }

        if (! $mapping
            || (int) $mapping->canonical_email_message_id !== (int) $item->previous_canonical_email_message_id
            || $mapping->mapping_kind !== $item->previous_mapping_kind
            || ! hash_equals((string) $mapping->strict_evidence_hash, (string) $item->previous_evidence_hash)
            || ! hash_equals((string) $mapping->source_state_hash, (string) $item->previous_source_state_hash)
            || (bool) $mapping->evidence_complete !== (bool) $item->previous_evidence_complete
            || (int) ($mapping->mapped_by ?? 0) !== (int) ($item->previous_mapped_by ?? 0)
            || $mapping->mapped_at?->format('Y-m-d H:i:s.u') !== $item->previous_mapped_at?->format('Y-m-d H:i:s.u')) {
            throw ValidationException::withMessages(['run' => 'A source mapping changed after preview.']);
        }
    }

    private function assertPreviousPointers(EmailCanonicalCutoverItem $item, EmailMessage $message): void
    {
        $current = $message->placements
            ->sortBy('id')
            ->mapWithKeys(fn ($placement): array => [
                (string) $placement->id => $placement->canonical_email_message_id === null
                    ? null
                    : (int) $placement->canonical_email_message_id,
            ])
            ->all();
        $previous = collect($item->previous_placement_pointers_json ?? [])
            ->mapWithKeys(fn ($value, $key): array => [(string) $key => $value === null ? null : (int) $value])
            ->all();
        if ($current !== $previous) {
            throw ValidationException::withMessages(['run' => 'Mailbox placement pointers changed after preview.']);
        }
    }

    private function markItemApplied(EmailCanonicalCutoverItem $item, int $canonicalId): void
    {
        $item->forceFill([
            'applied_canonical_email_message_id' => $canonicalId,
            'status' => EmailCanonicalCutoverItem::STATUS_APPLIED,
            'applied_at' => now(),
        ])->save();
    }

    private function canonicalStateHash(?EmailCanonicalMessageSource $mapping): ?string
    {
        if (! $mapping) {
            return null;
        }
        $canonical = EmailCanonicalMessage::query()->with('attachments')->find($mapping->canonical_email_message_id);
        if (! $canonical) {
            return $this->hash(['missing_canonical_id' => (int) $mapping->canonical_email_message_id]);
        }

        return $this->hash([
            'id' => (int) $canonical->id,
            'status' => $canonical->status,
            'root_source_email_message_id' => (int) $canonical->root_source_email_message_id,
            'strict_evidence_hash' => $canonical->strict_evidence_hash,
            'root_projection_hash' => $canonical->root_projection_hash,
            'stored_projection_hash' => $this->evidence->storedProjectionHash($canonical),
            'source_ids' => EmailCanonicalMessageSource::query()
                ->where('canonical_email_message_id', $canonical->id)
                ->orderBy('source_email_message_id')
                ->pluck('source_email_message_id')
                ->map(fn ($id): int => (int) $id)
                ->all(),
        ]);
    }

    /** @param array<string,mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
