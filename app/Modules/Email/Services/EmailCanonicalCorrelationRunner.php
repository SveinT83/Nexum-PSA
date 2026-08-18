<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailCanonicalCorrelationCandidate;
use App\Modules\Email\Models\EmailCanonicalCorrelationRun;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmailCanonicalCorrelationRunner
{
    private const GROUPS_PER_BATCH = 10;

    public function __construct(
        private readonly ResolveMailboxAccessDecision $accessDecisions,
        private readonly EmailCanonicalCorrelationEvidence $evidence,
        private readonly EmailCanonicalCorrelationScope $scope,
    ) {}

    /**
     * Process one bounded local-only batch. Returns true when another batch is required.
     */
    public function processBatch(int $runId): bool
    {
        $run = EmailCanonicalCorrelationRun::query()->find($runId);
        if (! $run || in_array($run->status, [
            EmailCanonicalCorrelationRun::STATUS_COMPLETED,
            EmailCanonicalCorrelationRun::STATUS_CANCELLED,
        ], true)) {
            return false;
        }

        try {
            $this->authorize($run);
            $this->claim($run);

            if ($run->status !== EmailCanonicalCorrelationRun::STATUS_RUNNING) {
                return false;
            }

            if ($run->initial_scope_verified_at === null) {
                $this->assertScopeUnchanged($run);
                EmailCanonicalCorrelationRun::query()
                    ->whereKey($run->id)
                    ->where('status', EmailCanonicalCorrelationRun::STATUS_RUNNING)
                    ->whereNull('initial_scope_verified_at')
                    ->update([
                        'initial_scope_verified_at' => now(),
                        'updated_at' => now(),
                    ]);
                $run->refresh();
            }

            $groups = $this->nextGroups($run);
            if ($groups->isEmpty()) {
                if (in_array($run->discovery_phase, ['message_id', 'checksum'], true)) {
                    $this->advancePhase($run);

                    return true;
                }

                $this->assertScopeUnchanged($run);
                $this->complete($run);

                return false;
            }

            if ($run->groups_processed >= $run->group_cap) {
                $this->fail($run, 'group_cap_reached', 'The bounded correlation group cap was reached before all groups were inspected.');

                return false;
            }

            foreach ($groups as $group) {
                $run->refresh();
                if ($run->status !== EmailCanonicalCorrelationRun::STATUS_RUNNING) {
                    return false;
                }
                $this->authorize($run);

                if ($run->groups_processed >= $run->group_cap) {
                    $this->fail($run, 'group_cap_reached', 'The bounded correlation group cap was reached before all groups were inspected.');

                    return false;
                }

                $messageIds = collect($group->message_ids)->map(fn (mixed $id): int => (int) $id)->values();
                if ($messageIds->count() < 2) {
                    $this->advanceGroup($run, (int) $group->first_message_id, 0);

                    continue;
                }

                if ((int) $group->group_size > $run->per_group_cap
                    || $messageIds->count() > $run->per_group_cap) {
                    $this->recordOversizedGroup($run, $this->evidenceEntries($run, $messageIds->take(2)), (int) $group->group_size, (string) $group->correlation_value);
                    $this->advanceGroup($run, (int) $group->first_message_id, 0);

                    continue;
                }

                $newPairCount = $this->unrecordedPairCount($run, $messageIds);
                if ((int) $run->pairs_processed + $newPairCount > (int) $run->pair_cap) {
                    $this->fail($run, 'pair_cap_reached', 'The bounded correlation pair cap is too small for the next complete candidate group.');

                    return false;
                }

                $pairCount = $this->compareGroup(
                    $run,
                    $this->evidenceEntries($run, $messageIds),
                    (string) $group->correlation_value,
                );
                $this->advanceGroup($run, (int) $group->first_message_id, $pairCount);
            }

            $this->syncCounters($run);

            return true;
        } catch (EmailCanonicalCorrelationEvidenceBudgetExceeded $exception) {
            $this->fail(
                $run,
                'evidence_read_cap_reached',
                'The bounded local evidence-read cap was reached. Narrow the message-ID scope.',
            );

            return false;
        } catch (Throwable $exception) {
            Log::warning('Canonical correlation batch failed.', [
                'run_id' => $run->id,
                'exception' => $exception::class,
                'code' => is_int($exception->getCode()) ? $exception->getCode() : 0,
            ]);
            $this->fail(
                $run,
                'correlation_batch_failed',
                'The local correlation batch failed before completion.',
            );

            return false;
        }
    }

    private function authorize(EmailCanonicalCorrelationRun $run): void
    {
        $actor = User::query()->find($run->requested_by);
        if (! $actor?->isActive() || $actor->isSystemActor() || ! $actor->can('email.mailbox_sync_manage')) {
            throw new \RuntimeException('Correlation requester authorization changed.');
        }

        $accountIds = $this->accountIds($run);
        $accounts = EmailAccount::query()
            ->whereIn('id', $accountIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        if ($accounts->count() !== count($accountIds)) {
            throw new \RuntimeException('Correlation account scope changed.');
        }

        foreach ($accountIds as $accountId) {
            if (! $this->accessDecisions->resolve($actor, $accounts->get($accountId), MailboxAccess::VIEW)->allowed) {
                throw new \RuntimeException('Correlation mailbox authorization changed.');
            }
        }
    }

    private function claim(EmailCanonicalCorrelationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);
            if (! $locked || $locked->status === EmailCanonicalCorrelationRun::STATUS_CANCELLED) {
                return;
            }

            if ($locked->status === EmailCanonicalCorrelationRun::STATUS_FAILED) {
                throw new \RuntimeException('A failed run requires an explicit resume action.');
            }

            if ($locked->status === EmailCanonicalCorrelationRun::STATUS_QUEUED) {
                $locked->forceFill([
                    'status' => EmailCanonicalCorrelationRun::STATUS_RUNNING,
                    'started_at' => $locked->started_at ?? now(),
                ])->save();
            }
        }, 3);

        $run->refresh();
    }

    /** @return Collection<int, object> */
    private function nextGroups(EmailCanonicalCorrelationRun $run): Collection
    {
        if ($run->discovery_phase === 'relationship') {
            return $this->relationshipGroups($run);
        }

        return $this->baseMessageQuery($run)
            ->select(['id', 'message_id', 'checksum_sha1'])
            ->orderBy('id')
            ->get()
            ->map(fn (EmailMessage $message): array => [
                'id' => (int) $message->id,
                'key' => $this->evidence->discoveryKey($message, $run->discovery_phase),
            ])
            ->filter(fn (array $message): bool => $message['key'] !== null)
            ->groupBy('key')
            ->map(function (Collection $messages, string $correlationValue): object {
                $ids = $messages->pluck('id')->sort()->values();

                return (object) [
                    'first_message_id' => (int) $ids->first(),
                    'correlation_value' => $correlationValue,
                    'group_size' => $ids->count(),
                    'message_ids' => $ids->all(),
                ];
            })
            ->filter(fn (object $group): bool => $group->group_size > 1
                && $group->first_message_id > (int) $run->cursor_message_id)
            ->sortBy('first_message_id')
            ->take(self::GROUPS_PER_BATCH)
            ->values();
    }

    /** @return Collection<int, object> */
    private function relationshipGroups(EmailCanonicalCorrelationRun $run): Collection
    {
        $messages = $this->baseMessageQuery($run)
            ->select(['id', 'ticket_id'])
            ->with(['placements' => fn (HasMany $placements): HasMany => $placements
                ->select(['id', 'email_message_id', 'email_conversation_id'])
                ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
                ->whereNull('provider_missing_at')])
            ->orderBy('id')
            ->get();
        $memberships = collect();

        foreach ($messages as $message) {
            if ($message->ticket_id !== null) {
                $memberships->push([
                    'id' => (int) $message->id,
                    'key' => hash('sha256', 'ticket:'.(int) $message->ticket_id),
                ]);
            }

            foreach ($message->placements->pluck('email_conversation_id')->filter()->unique() as $conversationId) {
                $memberships->push([
                    'id' => (int) $message->id,
                    'key' => hash('sha256', 'conversation:'.(int) $conversationId),
                ]);
            }
        }

        return $memberships
            ->groupBy('key')
            ->map(function (Collection $group, string $correlationValue): object {
                $ids = $group->pluck('id')->unique()->sort()->values();

                return (object) [
                    'first_message_id' => (int) $ids->first(),
                    'correlation_value' => $correlationValue,
                    'group_size' => $ids->count(),
                    'message_ids' => $ids->all(),
                ];
            })
            ->filter(fn (object $group): bool => $group->group_size > 1
                && $group->first_message_id > (int) $run->cursor_message_id)
            ->sortBy('first_message_id')
            ->take(self::GROUPS_PER_BATCH)
            ->values();
    }

    /**
     * Load full evidence in small chunks so the hard per-group count also bounds peak body and
     * attachment memory. Returned entries retain only the model identity plus one-way evidence.
     *
     * @param  Collection<int, int>  $messageIds
     * @return Collection<int, array{message:EmailMessage,evidence:array<string,mixed>}>
     */
    private function evidenceEntries(EmailCanonicalCorrelationRun $run, Collection $messageIds): Collection
    {
        $messages = collect();

        foreach ($messageIds->chunk(5) as $chunk) {
            EmailMessage::query()
                ->whereIn('id', $chunk->all())
                ->with(['account:id,address', 'attachments'])
                ->orderBy('id')
                ->get()
                ->each(fn (EmailMessage $message) => $messages->push($message));
        }

        if ($messages->count() !== $messageIds->count()) {
            throw new \RuntimeException('Frozen canonical correlation evidence is no longer available.');
        }

        $this->reserveEvidenceBytes(
            $run,
            $messages->sum(fn (EmailMessage $message): int => $this->scope->estimateMessageEvidenceBytes($message)),
        );
        $entries = $messages->map(fn (EmailMessage $message): array => [
            'message' => $message,
            'evidence' => $this->evidence->forMessage($message),
        ]);

        return $entries->sortBy(fn (array $entry): int => (int) $entry['message']->id)->values();
    }

    /** @param  Collection<int, array{message:EmailMessage,evidence:array<string,mixed>}>  $entries */
    private function compareGroup(
        EmailCanonicalCorrelationRun $run,
        Collection $entries,
        string $correlationValue,
    ): int {
        $processed = 0;
        $values = $entries->values();

        for ($leftIndex = 0; $leftIndex < $values->count() - 1; $leftIndex++) {
            for ($rightIndex = $leftIndex + 1; $rightIndex < $values->count(); $rightIndex++) {
                if ((int) $run->pairs_processed + $processed >= (int) $run->pair_cap) {
                    return $processed;
                }

                $leftEntry = $values->get($leftIndex);
                $rightEntry = $values->get($rightIndex);
                /** @var EmailMessage $left */
                $left = $leftEntry['message'];
                /** @var EmailMessage $right */
                $right = $rightEntry['message'];
                $comparison = $this->evidence->compare($leftEntry['evidence'], $rightEntry['evidence']);

                if ($this->recordCandidate(
                    run: $run,
                    left: $left,
                    right: $right,
                    correlationValue: $correlationValue,
                    values: $comparison,
                    groupSize: $values->count(),
                )) {
                    $processed++;
                }
            }
        }

        return $processed;
    }

    /** @param  Collection<int, array{message:EmailMessage,evidence:array<string,mixed>}>  $entries */
    private function recordOversizedGroup(
        EmailCanonicalCorrelationRun $run,
        Collection $entries,
        int $groupSize,
        string $correlationValue,
    ): void {
        $leftEntry = $entries->get(0);
        $rightEntry = $entries->get(1);
        if (! is_array($leftEntry) || ! is_array($rightEntry)) {
            return;
        }

        /** @var EmailMessage $left */
        $left = $leftEntry['message'];
        /** @var EmailMessage $right */
        $right = $rightEntry['message'];
        $leftEvidence = $leftEntry['evidence'];
        $rightEvidence = $rightEntry['evidence'];
        $reasonCodes = ['group_exceeds_per_group_cap'];
        $leftHash = (string) $leftEvidence['evidence_hash'];
        $rightHash = (string) $rightEvidence['evidence_hash'];

        $this->recordCandidate(
            run: $run,
            left: $left,
            right: $right,
            correlationValue: $correlationValue,
            values: [
                'candidate_class' => EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED,
                'reason_codes' => $reasonCodes,
                'left_evidence_hash' => $leftHash,
                'right_evidence_hash' => $rightHash,
                'pair_fingerprint' => hash('sha256', json_encode([
                    'algorithm' => EmailCanonicalCorrelationEvidence::ALGORITHM_VERSION,
                    'class' => EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED,
                    'group_size' => $groupSize,
                    'left' => $leftHash,
                    'right' => $rightHash,
                ], JSON_THROW_ON_ERROR)),
            ],
            groupSize: $groupSize,
        );
    }

    /** @param  array{candidate_class:string,reason_codes:list<string>,left_evidence_hash:string,right_evidence_hash:string,pair_fingerprint:string}  $values */
    private function recordCandidate(
        EmailCanonicalCorrelationRun $run,
        EmailMessage $left,
        EmailMessage $right,
        string $correlationValue,
        array $values,
        int $groupSize,
    ): bool {
        if ($left->id > $right->id) {
            [$left, $right] = [$right, $left];
            [$values['left_evidence_hash'], $values['right_evidence_hash']] = [
                $values['right_evidence_hash'],
                $values['left_evidence_hash'],
            ];
        }

        return DB::transaction(function () use (
            $correlationValue,
            $groupSize,
            $left,
            $right,
            $run,
            $values,
        ): bool {
            $lockedRun = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);
            if (! $lockedRun || $lockedRun->status !== EmailCanonicalCorrelationRun::STATUS_RUNNING) {
                return false;
            }

            $attributes = [
                'email_canonical_correlation_run_id' => $run->id,
                'left_email_message_id' => $left->id,
                'right_email_message_id' => $right->id,
            ];
            $existing = EmailCanonicalCorrelationCandidate::query()->where($attributes)->first();
            if ($existing) {
                if ($existing->candidate_class === EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED
                    || $values['candidate_class'] === EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED) {
                    if (! hash_equals((string) $existing->left_evidence_hash, (string) $values['left_evidence_hash'])
                        || ! hash_equals((string) $existing->right_evidence_hash, (string) $values['right_evidence_hash'])) {
                        throw new \RuntimeException('Frozen correlation evidence changed during the run.');
                    }

                    $overlapReason = $existing->candidate_class !== $values['candidate_class']
                        ? ['overlapping_discovery_requires_narrower_scope']
                        : [];
                    $reasonCodes = collect([
                        ...($existing->reason_codes_json ?? []),
                        ...$values['reason_codes'],
                        ...$overlapReason,
                    ])->unique()->sort()->values()->all();
                    $mergedGroupSize = max((int) $existing->group_size, $groupSize);
                    $existing->forceFill([
                        'candidate_class' => EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED,
                        'reason_codes_json' => $reasonCodes,
                        'correlation_key_hash' => hash('sha256', 'oversized-pair:'.$left->id.':'.$right->id),
                        'pair_fingerprint' => hash('sha256', json_encode([
                            'algorithm' => EmailCanonicalCorrelationEvidence::ALGORITHM_VERSION,
                            'candidate_class' => EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED,
                            'reason_codes' => $reasonCodes,
                            'group_size' => $mergedGroupSize,
                            'left' => $values['left_evidence_hash'],
                            'right' => $values['right_evidence_hash'],
                        ], JSON_THROW_ON_ERROR)),
                        'group_size' => min(65535, max(2, $mergedGroupSize)),
                    ])->save();

                    return false;
                }

                if (! hash_equals($existing->pair_fingerprint, $values['pair_fingerprint'])) {
                    throw new \RuntimeException('Frozen correlation evidence changed during the run.');
                }

                return false;
            }

            EmailCanonicalCorrelationCandidate::query()->create([
                ...$attributes,
                'left_email_account_id' => $left->account_id,
                'right_email_account_id' => $right->account_id,
                'candidate_class' => $values['candidate_class'],
                'reason_codes_json' => $values['reason_codes'],
                'correlation_key_hash' => hash('sha256', $run->discovery_phase.':'.$correlationValue),
                'left_evidence_hash' => $values['left_evidence_hash'],
                'right_evidence_hash' => $values['right_evidence_hash'],
                'pair_fingerprint' => $values['pair_fingerprint'],
                'group_size' => min(65535, max(2, $groupSize)),
                'review_state' => EmailCanonicalCorrelationCandidate::REVIEW_UNREVIEWED,
            ]);

            return true;
        }, 3);
    }

    private function advanceGroup(EmailCanonicalCorrelationRun $run, int $cursor, int $pairCount): void
    {
        DB::transaction(function () use ($run, $cursor, $pairCount): void {
            $locked = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);
            if (! $locked || $locked->status !== EmailCanonicalCorrelationRun::STATUS_RUNNING) {
                return;
            }

            $locked->forceFill([
                'cursor_message_id' => max((int) $locked->cursor_message_id, $cursor),
                'groups_processed' => (int) $locked->groups_processed + 1,
                'pairs_processed' => (int) $locked->pairs_processed + $pairCount,
            ])->save();
        }, 3);

        $run->refresh();
        $this->syncCounters($run);
    }

    private function advancePhase(EmailCanonicalCorrelationRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);
            if (! $locked || $locked->status !== EmailCanonicalCorrelationRun::STATUS_RUNNING) {
                return;
            }

            $nextPhase = match ($locked->discovery_phase) {
                'message_id' => 'checksum',
                'checksum' => 'relationship',
                default => null,
            };
            if ($nextPhase === null) {
                return;
            }

            $locked->forceFill([
                'discovery_phase' => $nextPhase,
                'cursor_message_id' => 0,
            ])->save();
        }, 3);

        $run->refresh();
    }

    /** @param Collection<int, int> $messageIds */
    private function unrecordedPairCount(EmailCanonicalCorrelationRun $run, Collection $messageIds): int
    {
        $ids = $messageIds->sort()->values();
        $existing = EmailCanonicalCorrelationCandidate::query()
            ->where('email_canonical_correlation_run_id', $run->id)
            ->whereIn('left_email_message_id', $ids->all())
            ->whereIn('right_email_message_id', $ids->all())
            ->get(['left_email_message_id', 'right_email_message_id'])
            ->mapWithKeys(fn (EmailCanonicalCorrelationCandidate $candidate): array => [
                $candidate->left_email_message_id.':'.$candidate->right_email_message_id => true,
            ]);
        $count = 0;

        for ($left = 0; $left < $ids->count() - 1; $left++) {
            for ($right = $left + 1; $right < $ids->count(); $right++) {
                if (! $existing->has($ids->get($left).':'.$ids->get($right))) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function syncCounters(EmailCanonicalCorrelationRun $run): void
    {
        $counts = EmailCanonicalCorrelationCandidate::query()
            ->where('email_canonical_correlation_run_id', $run->id)
            ->selectRaw('candidate_class, COUNT(*) AS aggregate')
            ->groupBy('candidate_class')
            ->pluck('aggregate', 'candidate_class')
            ->map(fn (mixed $count): int => (int) $count);

        $candidateCount = $counts->sum();
        EmailCanonicalCorrelationRun::query()
            ->whereKey($run->id)
            ->where('status', EmailCanonicalCorrelationRun::STATUS_RUNNING)
            ->update([
                'candidate_count' => $candidateCount,
                'strong_count' => $counts->get(EmailCanonicalCorrelationCandidate::CLASS_STRONG, 0),
                'possible_count' => $counts->get(EmailCanonicalCorrelationCandidate::CLASS_POSSIBLE, 0),
                'ambiguous_count' => $counts->get(EmailCanonicalCorrelationCandidate::CLASS_AMBIGUOUS, 0)
                    + $counts->get(EmailCanonicalCorrelationCandidate::CLASS_OVERSIZED, 0),
                'different_count' => $counts->get(EmailCanonicalCorrelationCandidate::CLASS_DIFFERENT, 0),
                'updated_at' => now(),
            ]);

        $run->refresh();
    }

    private function complete(EmailCanonicalCorrelationRun $run): void
    {
        $this->syncCounters($run);
        EmailCanonicalCorrelationRun::query()
            ->whereKey($run->id)
            ->where('status', EmailCanonicalCorrelationRun::STATUS_RUNNING)
            ->update([
                'status' => EmailCanonicalCorrelationRun::STATUS_COMPLETED,
                'finished_at' => now(),
                'error_code' => null,
                'error_message' => null,
                'updated_at' => now(),
            ]);

        $run->refresh();
    }

    private function fail(EmailCanonicalCorrelationRun $run, string $code, string $message): void
    {
        if (! $run->exists) {
            return;
        }

        EmailCanonicalCorrelationRun::query()
            ->whereKey($run->id)
            ->whereIn('status', [
                EmailCanonicalCorrelationRun::STATUS_QUEUED,
                EmailCanonicalCorrelationRun::STATUS_RUNNING,
            ])
            ->update([
                'status' => EmailCanonicalCorrelationRun::STATUS_FAILED,
                'error_code' => $code,
                'error_message' => $message,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

        $run->refresh();
    }

    private function assertScopeUnchanged(EmailCanonicalCorrelationRun $run): void
    {
        $run->refresh();
        $accountIds = $this->accountIds($run);
        $remainingBytes = (int) $run->evidence_run_byte_cap - (int) $run->evidence_bytes_processed;
        if ($remainingBytes < 0) {
            throw new EmailCanonicalCorrelationEvidenceBudgetExceeded;
        }
        $snapshot = $this->scope->snapshot(
            $accountIds,
            (int) $run->frozen_min_message_id,
            (int) $run->frozen_max_message_id,
            min($remainingBytes, (int) $run->evidence_snapshot_byte_cap),
        );
        if ($snapshot['exceeded']) {
            throw new EmailCanonicalCorrelationEvidenceBudgetExceeded;
        }
        $this->reserveEvidenceBytes($run, $snapshot['evidence_bytes']);
        $fingerprint = $this->scope->fingerprint(
            $accountIds,
            (int) $run->frozen_min_message_id,
            (int) $run->frozen_max_message_id,
            [
                'message_cap' => (int) $run->message_cap,
                'group_cap' => (int) $run->group_cap,
                'pair_cap' => (int) $run->pair_cap,
                'per_group_cap' => (int) $run->per_group_cap,
                'evidence_snapshot_byte_cap' => (int) $run->evidence_snapshot_byte_cap,
                'evidence_run_byte_cap' => (int) $run->evidence_run_byte_cap,
            ],
            $snapshot['message_digest'],
        );

        if ($snapshot['count'] !== (int) $run->scoped_message_count
            || ! hash_equals((string) $run->scope_fingerprint, $fingerprint)) {
            throw new \RuntimeException('Frozen canonical correlation scope changed.');
        }
    }

    private function reserveEvidenceBytes(EmailCanonicalCorrelationRun $run, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        DB::transaction(function () use ($bytes, $run): void {
            $locked = EmailCanonicalCorrelationRun::query()->lockForUpdate()->find($run->id);
            if (! $locked || $locked->status !== EmailCanonicalCorrelationRun::STATUS_RUNNING) {
                throw new \RuntimeException('The correlation run is no longer active.');
            }

            $next = (int) $locked->evidence_bytes_processed + $bytes;
            if ($next > (int) $locked->evidence_run_byte_cap) {
                throw new EmailCanonicalCorrelationEvidenceBudgetExceeded;
            }

            $locked->forceFill(['evidence_bytes_processed' => $next])->save();
        }, 3);

        $run->refresh();
    }

    private function baseMessageQuery(EmailCanonicalCorrelationRun $run): \Illuminate\Database\Eloquent\Builder
    {
        return EmailMessage::query()
            ->whereIn('account_id', $this->accountIds($run))
            ->whereBetween('id', [
                (int) $run->frozen_min_message_id,
                (int) $run->frozen_max_message_id,
            ]);
    }

    /** @return list<int> */
    private function accountIds(EmailCanonicalCorrelationRun $run): array
    {
        return collect($run->account_scope_json)
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }
}
