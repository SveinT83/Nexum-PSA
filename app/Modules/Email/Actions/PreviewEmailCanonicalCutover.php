<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
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
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates immutable bounded plans. No source, mapping, placement, mode, provider, or private file is
 * changed until ApplyEmailCanonicalCutover reauthorizes and reproduces the exact scope fingerprint.
 */
final class PreviewEmailCanonicalCutover
{
    public const DEFAULT_ITEM_CAP = 100;

    public const MAX_ITEM_CAP = 500;

    public const MAX_COMPONENT_SIZE = 32;

    public const MAX_EVIDENCE_BYTES = 256 * 1024 * 1024;

    public function __construct(
        private readonly EmailCanonicalCutoverAuthorization $authorization,
        private readonly EmailCanonicalCutoverEvidence $evidence,
        private readonly EmailCanonicalCorrelationEvidence $shadowEvidence,
        private readonly EmailCanonicalParityAttestationValidator $parityAttestations,
    ) {}

    /** @param list<int> $accountIds */
    public function backfill(
        User $actor,
        array $accountIds,
        ?int $minimumMessageId = null,
        ?int $maximumMessageId = null,
        int $itemCap = self::DEFAULT_ITEM_CAP,
    ): EmailCanonicalCutoverRun {
        $itemCap = $this->itemCap($itemCap);
        $accountIds = $this->accountIds($accountIds);
        $authorized = $this->authorization->authorize($actor, $accountIds);
        $actor = $authorized['actor'];

        [$minimumMessageId, $maximumMessageId] = $this->messageWindow(
            $accountIds,
            $minimumMessageId,
            $maximumMessageId,
        );
        $messages = $this->scopedMessages(
            $accountIds,
            $minimumMessageId,
            $maximumMessageId,
            $itemCap,
        );
        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $messages->modelKeys())
            ->get()
            ->keyBy('source_email_message_id');

        $items = [];
        $evidenceBytes = 0;
        foreach ($messages as $message) {
            $snapshot = $this->evidence->forMessage($message);
            $evidenceBytes += (int) $snapshot['evidence_bytes'];
            $this->assertEvidenceBudget($evidenceBytes);
            $mapping = $mappings->get($message->id);
            $pointers = $this->placementPointers($message);
            $pointersMatch = $mapping
                && collect($pointers)->every(
                    fn (mixed $canonicalId): bool => (int) $canonicalId === (int) $mapping->canonical_email_message_id,
                );

            if ($mapping && $pointersMatch) {
                continue;
            }

            $items[] = $this->sourceItem(
                message: $message,
                snapshot: $snapshot,
                mapping: $mapping,
                kind: $mapping
                    ? EmailCanonicalCutoverItem::KIND_POINTER_REPAIR
                    : EmailCanonicalCutoverItem::KIND_SELF_MAP,
                componentKey: $this->hash(['self', (int) $message->id]),
                proposedRootId: $mapping
                    ? (int) EmailCanonicalMessage::query()
                        ->whereKey($mapping->canonical_email_message_id)
                        ->value('root_source_email_message_id')
                    : (int) $message->id,
                candidateIds: [],
                pointers: $pointers,
            );
        }

        return $this->persist(
            actor: $actor,
            operation: EmailCanonicalCutoverRun::OPERATION_BACKFILL,
            accountIds: $accountIds,
            minimumMessageId: $minimumMessageId,
            maximumMessageId: $maximumMessageId,
            itemCap: $itemCap,
            sourceCorrelationRunId: null,
            requestedMode: null,
            items: $items,
        );
    }

    /** @param list<int> $candidateIds */
    public function merge(
        User $actor,
        EmailCanonicalCorrelationRun $correlationRun,
        array $candidateIds,
    ): EmailCanonicalCutoverRun {
        $candidateIds = collect($candidateIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($candidateIds === [] || count($candidateIds) > $this->maximumCliqueEdges()) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'Choose a bounded non-empty set of correlation candidates.',
            ]);
        }

        $correlationRun = EmailCanonicalCorrelationRun::query()->find($correlationRun->id);
        if (! $correlationRun || $correlationRun->status !== EmailCanonicalCorrelationRun::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'correlation_run' => 'A completed correlation run is required.',
            ]);
        }

        $candidates = EmailCanonicalCorrelationCandidate::query()
            ->where('email_canonical_correlation_run_id', $correlationRun->id)
            ->whereKey($candidateIds)
            ->orderBy('id')
            ->get();
        if ($candidates->count() !== count($candidateIds)) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'The complete candidate set is no longer available.',
            ]);
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
                throw ValidationException::withMessages([
                    'candidate_ids' => 'Every edge must be strong, confirmed, and inspected at the exact reviewed evidence.',
                ]);
            }
        }

        $sourceIds = $candidates
            ->flatMap(fn (EmailCanonicalCorrelationCandidate $candidate): array => [
                (int) $candidate->left_email_message_id,
                (int) $candidate->right_email_message_id,
            ])
            ->unique()
            ->sort()
            ->values()
            ->all();
        if (count($sourceIds) > self::MAX_COMPONENT_SIZE) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'The candidate component exceeds the cutover component cap.',
            ]);
        }

        $accountIds = $candidates
            ->flatMap(fn (EmailCanonicalCorrelationCandidate $candidate): array => [
                (int) $candidate->left_email_account_id,
                (int) $candidate->right_email_account_id,
            ])
            ->unique()
            ->sort()
            ->values()
            ->all();
        $authorized = $this->authorization->authorize($actor, $accountIds);
        $actor = $authorized['actor'];

        $messages = EmailMessage::query()
            ->whereKey($sourceIds)
            ->with(['account:id,address', 'attachments', 'placements:id,email_message_id,canonical_email_message_id'])
            ->orderBy('id')
            ->get()
            ->keyBy('id');
        if ($messages->count() !== count($sourceIds)) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'The complete source-message set is no longer available.',
            ]);
        }

        $this->assertCandidateAccounts($candidates, $messages);
        $components = $this->completeComponents($candidates);
        $this->assertClosedCandidateSet($correlationRun, $candidateIds, $sourceIds);
        $this->assertNoRetainedSeparation($sourceIds);
        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $sourceIds)
            ->get()
            ->keyBy('source_email_message_id');
        $this->assertCompleteExistingComponents($mappings, $sourceIds);

        $snapshots = [];
        $evidenceBytes = 0;
        foreach ($messages as $message) {
            $snapshot = $this->evidence->forMessage($message);
            $evidenceBytes += (int) $snapshot['evidence_bytes'];
            $this->assertEvidenceBudget($evidenceBytes);
            $snapshots[(int) $message->id] = $snapshot;
        }
        $this->assertCurrentShadowEvidence($candidates, $messages);

        $items = [];
        foreach ($components as $componentSourceIds) {
            $rootId = min($componentSourceIds);
            $rootSnapshot = $snapshots[$rootId];
            foreach ($componentSourceIds as $sourceId) {
                if (! $this->evidence->exactlyEquivalent($rootSnapshot, $snapshots[$sourceId])) {
                    throw ValidationException::withMessages([
                        'candidate_ids' => 'The current complete local fields and files are not exactly equivalent.',
                    ]);
                }
            }

            $componentCandidateIds = $candidates
                ->filter(fn (EmailCanonicalCorrelationCandidate $candidate): bool => in_array(
                    (int) $candidate->left_email_message_id,
                    $componentSourceIds,
                    true,
                ) && in_array(
                    (int) $candidate->right_email_message_id,
                    $componentSourceIds,
                    true,
                ))
                ->modelKeys();
            $componentKey = $this->hash([
                'sources' => $componentSourceIds,
                'candidates' => $componentCandidateIds,
                'strict_evidence_hash' => $rootSnapshot['strict_evidence_hash'],
            ]);
            foreach ($componentSourceIds as $sourceId) {
                $message = $messages->get($sourceId);
                $items[] = $this->sourceItem(
                    message: $message,
                    snapshot: $snapshots[$sourceId],
                    mapping: $mappings->get($sourceId),
                    kind: EmailCanonicalCutoverItem::KIND_COMPONENT_MEMBER,
                    componentKey: $componentKey,
                    proposedRootId: $rootId,
                    candidateIds: $componentCandidateIds,
                    pointers: $this->placementPointers($message),
                );
            }
        }

        return $this->persist(
            actor: $actor,
            operation: EmailCanonicalCutoverRun::OPERATION_MERGE,
            accountIds: $accountIds,
            minimumMessageId: min($sourceIds),
            maximumMessageId: max($sourceIds),
            itemCap: count($sourceIds),
            sourceCorrelationRunId: (int) $correlationRun->id,
            requestedMode: null,
            items: $items,
        );
    }

    /** @param list<int> $accountIds */
    public function audit(
        User $actor,
        array $accountIds,
        ?int $minimumMessageId = null,
        ?int $maximumMessageId = null,
        int $itemCap = self::DEFAULT_ITEM_CAP,
    ): EmailCanonicalCutoverRun {
        $itemCap = $this->itemCap($itemCap);
        $accountIds = $this->accountIds($accountIds);
        $authorized = $this->authorization->authorize($actor, $accountIds);
        $actor = $authorized['actor'];
        [$minimumMessageId, $maximumMessageId] = $this->messageWindow(
            $accountIds,
            $minimumMessageId,
            $maximumMessageId,
        );

        $seedMappings = EmailCanonicalMessageSource::query()
            ->whereHas('sourceMessage', function ($messages) use ($accountIds, $minimumMessageId, $maximumMessageId): void {
                $messages->whereIn('account_id', $accountIds)
                    ->whereBetween('id', [$minimumMessageId, $maximumMessageId]);
            })
            ->limit($itemCap + 1)
            ->get();
        if ($seedMappings->count() > $itemCap) {
            throw ValidationException::withMessages([
                'item_cap' => 'The audit scope exceeds the bounded item cap.',
            ]);
        }

        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('canonical_email_message_id', $seedMappings->pluck('canonical_email_message_id'))
            ->get();
        if ($mappings->count() > $itemCap) {
            throw ValidationException::withMessages([
                'item_cap' => 'A complete canonical component exceeds the bounded audit scope.',
            ]);
        }

        $messages = EmailMessage::query()
            ->whereKey($mappings->pluck('source_email_message_id'))
            ->with(['account:id,address', 'attachments', 'placements:id,email_message_id,canonical_email_message_id'])
            ->get()
            ->keyBy('id');
        $expandedAccountIds = $messages->pluck('account_id')->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();
        if ($expandedAccountIds !== []) {
            $authorized = $this->authorization->authorize($actor, $expandedAccountIds);
            $actor = $authorized['actor'];
            $accountIds = $expandedAccountIds;
        }

        $canonicals = EmailCanonicalMessage::query()
            ->whereKey($mappings->pluck('canonical_email_message_id'))
            ->with('attachments')
            ->get()
            ->keyBy('id');
        $mappingBySource = $mappings->keyBy('source_email_message_id');
        $snapshots = [];
        $evidenceBytes = 0;
        foreach ($messages as $message) {
            $snapshot = $this->evidence->forMessage($message);
            $snapshots[(int) $message->id] = $snapshot;
            $evidenceBytes += (int) $snapshot['evidence_bytes'];
            $this->assertEvidenceBudget($evidenceBytes);
        }

        $items = [];
        foreach ($mappings->groupBy('canonical_email_message_id') as $canonicalId => $componentMappings) {
            $canonical = $canonicals->get($canonicalId);
            if (! $canonical) {
                throw ValidationException::withMessages([
                    'scope' => 'A canonical projection disappeared during audit preview.',
                ]);
            }

            $componentSources = $componentMappings
                ->pluck('source_email_message_id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();
            $rootSnapshot = $snapshots[(int) $canonical->root_source_email_message_id] ?? null;
            $drifted = $canonical->status !== EmailCanonicalMessage::STATUS_ACTIVE
                || ! $rootSnapshot
                || ! hash_equals((string) $canonical->strict_evidence_hash, (string) ($rootSnapshot['strict_evidence_hash'] ?? ''))
                || ! hash_equals((string) $canonical->root_projection_hash, (string) ($rootSnapshot['root_projection_hash'] ?? ''))
                || ! hash_equals((string) $canonical->root_projection_hash, $this->evidence->storedProjectionHash($canonical));

            foreach ($componentMappings as $mapping) {
                $snapshot = $snapshots[(int) $mapping->source_email_message_id] ?? null;
                $drifted = $drifted
                    || ! $snapshot
                    || ! hash_equals((string) $mapping->strict_evidence_hash, (string) ($snapshot['strict_evidence_hash'] ?? ''))
                    || ! hash_equals((string) $canonical->strict_evidence_hash, (string) ($snapshot['strict_evidence_hash'] ?? ''));
            }

            if ($drifted) {
                $componentKey = $this->hash(['dissolve', (int) $canonicalId, $componentSources]);
                foreach ($componentSources as $sourceId) {
                    $message = $messages->get($sourceId);
                    $items[] = $this->sourceItem(
                        message: $message,
                        snapshot: $snapshots[$sourceId],
                        mapping: $mappingBySource->get($sourceId),
                        kind: EmailCanonicalCutoverItem::KIND_DISSOLVE_MEMBER,
                        componentKey: $componentKey,
                        proposedRootId: $sourceId,
                        candidateIds: [],
                        pointers: $this->placementPointers($message),
                    );
                }

                continue;
            }

            foreach ($componentMappings as $mapping) {
                $message = $messages->get($mapping->source_email_message_id);
                $pointers = $this->placementPointers($message);
                if (collect($pointers)->every(
                    fn (mixed $pointer): bool => (int) $pointer === (int) $canonicalId,
                )) {
                    continue;
                }

                $items[] = $this->sourceItem(
                    message: $message,
                    snapshot: $snapshots[(int) $message->id],
                    mapping: $mapping,
                    kind: EmailCanonicalCutoverItem::KIND_POINTER_REPAIR,
                    componentKey: $this->hash(['pointer', (int) $canonicalId, (int) $message->id]),
                    proposedRootId: (int) $canonical->root_source_email_message_id,
                    candidateIds: [],
                    pointers: $pointers,
                );
            }
        }

        return $this->persist(
            actor: $actor,
            operation: EmailCanonicalCutoverRun::OPERATION_AUDIT,
            accountIds: $accountIds,
            minimumMessageId: $minimumMessageId,
            maximumMessageId: $maximumMessageId,
            itemCap: $itemCap,
            sourceCorrelationRunId: null,
            requestedMode: null,
            items: $items,
        );
    }

    /** @param list<int> $accountIds */
    public function mode(User $actor, array $accountIds, string $mode): EmailCanonicalCutoverRun
    {
        if (! in_array($mode, EmailCanonicalReadMode::MODES, true)) {
            throw ValidationException::withMessages(['mode' => 'Choose a supported canonical read mode.']);
        }

        $accountIds = $this->accountIds($accountIds);
        $authorized = $this->authorization->authorize($actor, $accountIds);
        $actor = $authorized['actor'];
        $accountAttestations = [];
        if ($mode !== EmailCanonicalReadMode::MODE_LEGACY) {
            foreach ($accountIds as $accountId) {
                $placementCount = $this->activePlacementCount($accountId);
                if ($placementCount <= self::MAX_ITEM_CAP) {
                    $this->assertAccountProjectionParity(
                        $accountId,
                        requireEvidenceParity: $mode === EmailCanonicalReadMode::MODE_CANONICAL,
                    );
                } else {
                    $accountAttestations[$accountId] = $this->parityAttestations->latestUsable(
                        $accountId,
                        requireStrict: $mode === EmailCanonicalReadMode::MODE_CANONICAL,
                    );
                }
            }
        }

        $currentModes = EmailCanonicalReadMode::query()
            ->whereIn('email_account_id', $accountIds)
            ->get()
            ->keyBy('email_account_id');
        $items = [];
        foreach ($accountIds as $accountId) {
            $current = $currentModes->get($accountId);
            $previous = $current?->mode ?? EmailCanonicalReadMode::MODE_LEGACY;
            if ($previous === $mode) {
                continue;
            }

            /** @var EmailCanonicalParityAttestation|null $attestation */
            $attestation = $accountAttestations[$accountId] ?? null;
            $items[] = [
                'item_key' => 'account:'.$accountId,
                'item_kind' => EmailCanonicalCutoverItem::KIND_MODE_CHANGE,
                'component_key' => null,
                'email_account_id' => $accountId,
                'source_email_message_id' => null,
                'proposed_root_source_message_id' => null,
                'previous_canonical_email_message_id' => null,
                'applied_canonical_email_message_id' => null,
                'previous_mapping_kind' => null,
                'previous_evidence_hash' => null,
                'previous_source_state_hash' => null,
                'previous_evidence_complete' => null,
                'previous_mapped_by' => null,
                'previous_mapped_at' => null,
                'previous_canonical_state_hash' => null,
                'strict_evidence_hash' => null,
                'source_state_hash' => null,
                'evidence_complete' => true,
                'correlation_candidate_ids_json' => null,
                'previous_placement_pointers_json' => null,
                'previous_read_mode' => $previous,
                'previous_read_mode_row_exists' => $current !== null,
                'previous_read_mode_updated_by' => $current?->updated_by,
                'previous_read_mode_lock_version' => $current?->lock_version,
                'proposed_read_mode' => $mode,
                'parity_attestation_id' => $attestation?->id,
                'parity_attestation_fingerprint' => $attestation?->attestation_fingerprint,
                'status' => EmailCanonicalCutoverItem::STATUS_PREVIEWED,
                'error_code' => null,
            ];
        }

        return $this->persist(
            actor: $actor,
            operation: EmailCanonicalCutoverRun::OPERATION_MODE,
            accountIds: $accountIds,
            minimumMessageId: null,
            maximumMessageId: null,
            itemCap: count($accountIds),
            sourceCorrelationRunId: null,
            requestedMode: $mode,
            items: $items,
        );
    }

    /** @return EloquentCollection<int,EmailMessage> */
    private function scopedMessages(
        array $accountIds,
        int $minimumMessageId,
        int $maximumMessageId,
        int $itemCap,
    ): EloquentCollection {
        $messages = EmailMessage::query()
            ->whereIn('account_id', $accountIds)
            ->whereBetween('id', [$minimumMessageId, $maximumMessageId])
            ->with(['account:id,address', 'attachments', 'placements:id,email_message_id,canonical_email_message_id'])
            ->orderBy('id')
            ->limit($itemCap + 1)
            ->get();
        if ($messages->count() > $itemCap) {
            throw ValidationException::withMessages([
                'item_cap' => 'The message scope exceeds the bounded item cap.',
            ]);
        }

        return $messages;
    }

    /** @return list<list<int>> */
    private function completeComponents(Collection $candidates): array
    {
        $adjacency = [];
        foreach ($candidates as $candidate) {
            $left = (int) $candidate->left_email_message_id;
            $right = (int) $candidate->right_email_message_id;
            $adjacency[$left][$right] = true;
            $adjacency[$right][$left] = true;
        }

        $components = [];
        $seen = [];
        foreach (array_keys($adjacency) as $start) {
            if (isset($seen[$start])) {
                continue;
            }

            $queue = [$start];
            $component = [];
            while ($queue !== []) {
                $current = array_pop($queue);
                if (isset($seen[$current])) {
                    continue;
                }
                $seen[$current] = true;
                $component[] = (int) $current;
                foreach (array_keys($adjacency[$current] ?? []) as $neighbor) {
                    if (! isset($seen[$neighbor])) {
                        $queue[] = (int) $neighbor;
                    }
                }
            }
            sort($component);
            $requiredEdges = count($component) * (count($component) - 1) / 2;
            $actualEdges = $candidates->filter(fn ($candidate): bool => in_array(
                (int) $candidate->left_email_message_id,
                $component,
                true,
            ) && in_array(
                (int) $candidate->right_email_message_id,
                $component,
                true,
            ))->count();
            if ($requiredEdges !== $actualEdges) {
                throw ValidationException::withMessages([
                    'candidate_ids' => 'Every selected connected component must be a complete reviewed clique.',
                ]);
            }
            $components[] = $component;
        }

        return $components;
    }

    private function assertClosedCandidateSet(
        EmailCanonicalCorrelationRun $run,
        array $candidateIds,
        array $sourceIds,
    ): void {
        $touching = EmailCanonicalCorrelationCandidate::query()
            ->where('email_canonical_correlation_run_id', $run->id)
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
        if ($touching !== $candidateIds) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'Select the complete confirmed component; partial component cutover is forbidden.',
            ]);
        }
    }

    private function assertNoRetainedSeparation(array $sourceIds): void
    {
        if (EmailCanonicalCorrelationCandidate::query()
            ->where('review_state', EmailCanonicalCorrelationCandidate::REVIEW_KEEP_SEPARATE)
            ->whereIn('left_email_message_id', $sourceIds)
            ->whereIn('right_email_message_id', $sourceIds)
            ->exists()) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'A retained reviewed decision requires at least one pair to stay separate.',
            ]);
        }
    }

    private function assertCompleteExistingComponents(Collection $mappings, array $sourceIds): void
    {
        $canonicalIds = $mappings->pluck('canonical_email_message_id')->unique()->all();
        $outsideCount = EmailCanonicalMessageSource::query()
            ->whereIn('canonical_email_message_id', $canonicalIds)
            ->whereNotIn('source_email_message_id', $sourceIds)
            ->count();
        if ($outsideCount > 0) {
            throw ValidationException::withMessages([
                'candidate_ids' => 'The selected messages do not include a complete existing canonical component.',
            ]);
        }
    }

    private function assertCurrentShadowEvidence(Collection $candidates, Collection $messages): void
    {
        foreach ($candidates as $candidate) {
            $left = $messages->get($candidate->left_email_message_id);
            $right = $messages->get($candidate->right_email_message_id);
            $comparison = $this->shadowEvidence->compare(
                $this->shadowEvidence->forMessage($left),
                $this->shadowEvidence->forMessage($right),
            );
            if ($comparison['candidate_class'] !== EmailCanonicalCorrelationCandidate::CLASS_STRONG
                || ! hash_equals((string) $candidate->left_evidence_hash, $comparison['left_evidence_hash'])
                || ! hash_equals((string) $candidate->right_evidence_hash, $comparison['right_evidence_hash'])
                || ! hash_equals((string) $candidate->pair_fingerprint, $comparison['pair_fingerprint'])) {
                throw ValidationException::withMessages([
                    'candidate_ids' => 'The exact reviewed shadow evidence changed after inspection.',
                ]);
            }
        }
    }

    private function assertCandidateAccounts(Collection $candidates, Collection $messages): void
    {
        foreach ($candidates as $candidate) {
            $left = $messages->get($candidate->left_email_message_id);
            $right = $messages->get($candidate->right_email_message_id);
            if (! $left || ! $right
                || (int) $left->account_id !== (int) $candidate->left_email_account_id
                || (int) $right->account_id !== (int) $candidate->right_email_account_id) {
                throw ValidationException::withMessages([
                    'candidate_ids' => 'A reviewed source no longer belongs to its recorded account.',
                ]);
            }
        }
    }

    private function assertAccountProjectionParity(int $accountId, bool $requireEvidenceParity): void
    {
        $placementCount = $this->activePlacementCount($accountId);
        if ($placementCount > self::MAX_ITEM_CAP) {
            throw ValidationException::withMessages([
                'mode' => 'The account exceeds the bounded mode-verification cap.',
            ]);
        }

        $placements = EmailMailboxPlacement::query()
            ->where('account_id', $accountId)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->with(['message.account:id,address', 'message.attachments'])
            ->get();
        $mappings = EmailCanonicalMessageSource::query()
            ->whereIn('source_email_message_id', $placements->pluck('email_message_id'))
            ->get()
            ->keyBy('source_email_message_id');
        $canonicals = EmailCanonicalMessage::query()
            ->whereKey($mappings->pluck('canonical_email_message_id'))
            ->with('attachments')
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
                throw ValidationException::withMessages([
                    'mode' => 'Every active placement must have exact canonical pointer parity first.',
                ]);
            }

            if (! $requireEvidenceParity) {
                continue;
            }

            $snapshot = $this->evidence->forMessage($placement->message);
            $bytes += (int) $snapshot['evidence_bytes'];
            $this->assertEvidenceBudget($bytes);
            if (! hash_equals((string) $mapping->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                || ! $snapshot['complete']
                || ! $mapping->evidence_complete
                || ! $canonical->evidence_complete
                || ! hash_equals((string) $mapping->source_state_hash, (string) $snapshot['source_state_hash'])
                || ! hash_equals((string) $canonical->strict_evidence_hash, (string) $snapshot['strict_evidence_hash'])
                || ! hash_equals((string) $canonical->root_projection_hash, $this->evidence->storedProjectionHash($canonical))) {
                throw ValidationException::withMessages([
                    'mode' => 'Canonical evidence parity must pass before canonical reads are enabled.',
                ]);
            }
        }
    }

    private function activePlacementCount(int $accountId): int
    {
        return EmailMailboxPlacement::query()
            ->where('account_id', $accountId)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->count();
    }

    /** @param array<string,mixed> $snapshot
     * @param  array<int,int|null>  $pointers
     * @param  list<int>  $candidateIds
     * @return array<string,mixed>
     */
    private function sourceItem(
        EmailMessage $message,
        array $snapshot,
        ?EmailCanonicalMessageSource $mapping,
        string $kind,
        string $componentKey,
        int $proposedRootId,
        array $candidateIds,
        array $pointers,
    ): array {
        return [
            'item_key' => 'source:'.$message->id,
            'item_kind' => $kind,
            'component_key' => $componentKey,
            'email_account_id' => (int) $message->account_id,
            'source_email_message_id' => (int) $message->id,
            'proposed_root_source_message_id' => $proposedRootId,
            'previous_canonical_email_message_id' => $mapping?->canonical_email_message_id,
            'applied_canonical_email_message_id' => null,
            'previous_mapping_kind' => $mapping?->mapping_kind,
            'previous_evidence_hash' => $mapping?->strict_evidence_hash,
            'previous_source_state_hash' => $mapping?->source_state_hash,
            'previous_evidence_complete' => $mapping?->evidence_complete,
            'previous_mapped_by' => $mapping?->mapped_by,
            'previous_mapped_at' => $mapping?->mapped_at,
            'previous_canonical_state_hash' => $this->canonicalStateHash($mapping),
            'strict_evidence_hash' => $snapshot['strict_evidence_hash'],
            'source_state_hash' => $snapshot['source_state_hash'],
            'evidence_complete' => $snapshot['complete'],
            'correlation_candidate_ids_json' => $candidateIds === [] ? null : array_values($candidateIds),
            'previous_placement_pointers_json' => $pointers,
            'previous_read_mode' => null,
            'previous_read_mode_row_exists' => null,
            'previous_read_mode_updated_by' => null,
            'previous_read_mode_lock_version' => null,
            'proposed_read_mode' => null,
            'parity_attestation_id' => null,
            'parity_attestation_fingerprint' => null,
            'status' => EmailCanonicalCutoverItem::STATUS_PREVIEWED,
            'error_code' => null,
        ];
    }

    /** @return array<int,int|null> */
    private function placementPointers(EmailMessage $message): array
    {
        return $message->placements
            ->sortBy('id')
            ->mapWithKeys(fn ($placement): array => [
                (int) $placement->id => $placement->canonical_email_message_id === null
                    ? null
                    : (int) $placement->canonical_email_message_id,
            ])
            ->all();
    }

    /** @param list<array<string,mixed>> $items */
    private function persist(
        User $actor,
        string $operation,
        array $accountIds,
        ?int $minimumMessageId,
        ?int $maximumMessageId,
        int $itemCap,
        ?int $sourceCorrelationRunId,
        ?string $requestedMode,
        array $items,
    ): EmailCanonicalCutoverRun {
        usort($items, fn (array $left, array $right): int => $left['item_key'] <=> $right['item_key']);
        $scopeFingerprint = $this->hash([
            'algorithm' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
            'operation' => $operation,
            'accounts' => $accountIds,
            'minimum_message_id' => $minimumMessageId,
            'maximum_message_id' => $maximumMessageId,
            'correlation_run_id' => $sourceCorrelationRunId,
            'requested_mode' => $requestedMode,
            'items' => $items,
        ]);
        $idempotencyKey = $this->hash([
            'actor_id' => (int) $actor->id,
            'scope_fingerprint' => $scopeFingerprint,
        ]);

        return DB::transaction(function () use (
            $accountIds,
            $actor,
            $idempotencyKey,
            $itemCap,
            $items,
            $maximumMessageId,
            $minimumMessageId,
            $operation,
            $requestedMode,
            $scopeFingerprint,
            $sourceCorrelationRunId,
        ): EmailCanonicalCutoverRun {
            $existing = EmailCanonicalCutoverRun::query()
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $existing;
            }

            $run = EmailCanonicalCutoverRun::query()->create([
                'requested_by' => $actor->id,
                'source_correlation_run_id' => $sourceCorrelationRunId,
                'operation' => $operation,
                'status' => EmailCanonicalCutoverRun::STATUS_PREVIEWED,
                'algorithm_version' => EmailCanonicalCutoverEvidence::ALGORITHM_VERSION,
                'account_scope_json' => $accountIds,
                'frozen_min_message_id' => $minimumMessageId,
                'frozen_max_message_id' => $maximumMessageId,
                'item_cap' => max(1, $itemCap),
                'item_count' => count($items),
                'scope_fingerprint' => $scopeFingerprint,
                'idempotency_key' => $idempotencyKey,
                'requested_mode' => $requestedMode,
                'previewed_at' => now(),
            ]);

            if ($items !== []) {
                $now = now();
                $run->items()->createMany(array_map(
                    fn (array $item): array => $item + [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    $items,
                ));
            }

            return $run->refresh();
        }, 3);
    }

    /** @return array{0:int,1:int} */
    private function messageWindow(array $accountIds, ?int $minimum, ?int $maximum): array
    {
        $minimum ??= 1;
        $maximum ??= (int) (EmailMessage::query()->whereIn('account_id', $accountIds)->max('id') ?? 0);
        if ($minimum < 1 || $maximum < $minimum) {
            throw ValidationException::withMessages([
                'message_window' => 'Choose a valid inclusive source-message ID window.',
            ]);
        }

        return [$minimum, $maximum];
    }

    /** @return list<int> */
    private function accountIds(array $accountIds): array
    {
        $accountIds = collect($accountIds)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
        if ($accountIds === []) {
            throw ValidationException::withMessages(['account_ids' => 'Choose at least one account.']);
        }

        return $accountIds;
    }

    private function itemCap(int $itemCap): int
    {
        if ($itemCap < 1 || $itemCap > self::MAX_ITEM_CAP) {
            throw ValidationException::withMessages([
                'item_cap' => 'The canonical cutover item cap must be between 1 and '.self::MAX_ITEM_CAP.'.',
            ]);
        }

        return $itemCap;
    }

    private function assertEvidenceBudget(int $bytes): void
    {
        if ($bytes > self::MAX_EVIDENCE_BYTES) {
            throw ValidationException::withMessages([
                'scope' => 'The local-file evidence budget was exceeded; narrow the message scope.',
            ]);
        }
    }

    private function maximumCliqueEdges(): int
    {
        return (int) (self::MAX_COMPONENT_SIZE * (self::MAX_COMPONENT_SIZE - 1) / 2);
    }

    private function canonicalStateHash(?EmailCanonicalMessageSource $mapping): ?string
    {
        if (! $mapping) {
            return null;
        }

        $canonical = EmailCanonicalMessage::query()
            ->with('attachments')
            ->find($mapping->canonical_email_message_id);
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
