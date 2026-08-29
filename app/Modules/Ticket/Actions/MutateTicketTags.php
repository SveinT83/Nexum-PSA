<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ticket-owned audited Taxonomy tag delta boundary.
 */
final class MutateTicketTags
{
    public function __construct(
        private readonly TicketActionGuard $actionGuard,
        private readonly TicketMutationScopeGuard $scope,
        private readonly DispatchTicketRuleMutationEvent $dispatchRules,
    ) {}

    /**
     * @param  list<int|string>  $addTagIds
     * @param  list<int|string>  $removeTagIds
     * @param  array<string, mixed>  $context
     */
    public function handle(
        Ticket $ticket,
        array $addTagIds,
        array $removeTagIds,
        User $actor,
        array $context = [],
    ): TicketMutationResult {
        return $this->mutate($ticket, $addTagIds, $removeTagIds, null, $actor, $context, true);
    }

    /**
     * Add tags supplied by an already-authorized, transactional integration boundary.
     *
     * @param  list<int|string>  $tagIds
     * @param  array<string, mixed>  $context
     */
    public function addFromTrustedSource(
        Ticket $ticket,
        array $tagIds,
        array $context,
    ): TicketMutationResult {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Trusted Ticket Tag imports must remain inside their source transaction.');
        }

        $sourceChannel = $context['source_channel'] ?? null;
        if (! is_string($sourceChannel)
            || ! in_array($sourceChannel, ['email', 'integration', 'relationship'], true)
            || ! filled($context['source_action'] ?? null)
            || ! filled($context['delivery_identity'] ?? null)) {
            throw new \LogicException('Trusted Ticket Tag imports require an approved source and delivery identity.');
        }

        return $this->mutate($ticket, $tagIds, [], null, null, $context, false);
    }

    /**
     * @param  list<int|string>  $tagIds
     * @param  array<string, mixed>  $context
     */
    public function replace(
        Ticket $ticket,
        array $tagIds,
        User $actor,
        array $context = [],
    ): TicketMutationResult {
        return $this->mutate($ticket, [], [], $tagIds, $actor, $context, true);
    }

    /**
     * Initialize tags inside the wider Ticket creation transaction.
     *
     * @param  list<int|string>  $tagIds
     * @param  array<string, mixed>  $context
     */
    public function initialize(
        Ticket $ticket,
        array $tagIds,
        ?User $actor = null,
        array $context = [],
    ): TicketMutationResult {
        if (DB::connection()->transactionLevel() < 1) {
            throw new \LogicException('Ticket Tag initialization must remain inside the Ticket creation transaction.');
        }

        return $this->mutate($ticket, [], [], $tagIds, $actor, $context, false);
    }

    /**
     * @param  list<int|string>  $addTagIds
     * @param  list<int|string>  $removeTagIds
     * @param  list<int|string>|null  $replacementTagIds
     * @param  array<string, mixed>  $context
     */
    private function mutate(
        Ticket $ticket,
        array $addTagIds,
        array $removeTagIds,
        ?array $replacementTagIds,
        ?User $actor,
        array $context,
        bool $enforceActionGuard,
    ): TicketMutationResult {
        return DB::transaction(function () use (
            $ticket,
            $addTagIds,
            $removeTagIds,
            $replacementTagIds,
            $actor,
            $context,
            $enforceActionGuard,
        ): TicketMutationResult {
            $ticket = Ticket::query()->whereKey($ticket->getKey())->lockForUpdate()->firstOrFail();
            $this->scope->assert($ticket);

            if ($enforceActionGuard
                && $reason = $this->actionGuard->reason($ticket, TicketAction::UPDATE_FIELDS, $actor)) {
                throw ValidationException::withMessages(['tag_ids' => $reason]);
            }

            $add = $this->tagIds($addTagIds);
            $remove = $this->tagIds($removeTagIds);
            $replacement = $replacementTagIds === null ? null : $this->tagIds($replacementTagIds);
            if ($replacement === null && array_intersect($add, $remove) !== []) {
                throw ValidationException::withMessages([
                    'tag_ids' => 'The same Tag cannot be added and removed in one mutation.',
                ]);
            }

            $requested = $replacement ?? array_values(array_unique(array_merge($add, $remove)));
            $this->assertActiveTargets($requested);

            $pivotRows = DB::table('taggables')
                ->where('taggable_type', $ticket->getMorphClass())
                ->where('taggable_id', $ticket->id)
                ->orderBy('tag_id')
                ->get(['tag_id', 'module']);
            $before = $pivotRows->pluck('tag_id')->map(fn (mixed $id): int => (int) $id)->sort()->values()->all();
            $affectedForDomainCheck = $replacement === null
                ? array_values(array_intersect($before, $requested))
                : $before;

            foreach ($pivotRows->whereIn('tag_id', $affectedForDomainCheck) as $pivot) {
                if (strtolower((string) $pivot->module) !== 'ticket') {
                    throw ValidationException::withMessages([
                        'tag_ids' => 'A selected Tag is attached through a different domain boundary.',
                    ]);
                }
            }

            $desired = $replacement ?? array_values(array_unique(array_merge(
                array_diff($before, $remove),
                $add,
            )));
            sort($desired);
            $added = array_values(array_diff($desired, $before));
            $removed = array_values(array_diff($before, $desired));
            if ($added === [] && $removed === []) {
                return TicketMutationResult::noChange($ticket);
            }

            if ($replacement !== null) {
                $ticket->tags()->syncWithPivotValues($desired, ['module' => 'ticket']);
            } else {
                if ($removed !== []) {
                    $ticket->tags()->detach($removed);
                }
                if ($added !== []) {
                    $ticket->tags()->syncWithoutDetaching(
                        collect($added)->mapWithKeys(
                            fn (int $tagId): array => [$tagId => ['module' => 'ticket']],
                        )->all(),
                    );
                }
            }

            $ticket->forceFill(['updated_by' => $actor?->id])->touch();
            $history = TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'tags_changed',
                'message' => 'Ticket Tags updated.',
                'before' => ['tag_ids' => $before],
                'after' => [
                    'tag_ids' => $desired,
                    'added_tag_ids' => $added,
                    'removed_tag_ids' => $removed,
                ],
            ]);

            $sourceChannel = $this->sourceChannel($actor, $context);
            $sourceAction = (string) ($context['source_action'] ?? 'MutateTicketTags');
            $event = TicketRuleMutationEvent::make(
                ticketId: (int) $ticket->id,
                eventKey: TicketRuleTriggerRegistry::TAGS_CHANGED,
                changedFields: ['tag_ids'],
                before: ['tag_ids' => $before],
                after: ['tag_ids' => $desired],
                safeFacts: [
                    'tag_ids' => $desired,
                    'added_tag_ids' => $added,
                    'removed_tag_ids' => $removed,
                    'event_source_channel' => $sourceChannel,
                    'event_source_action' => $sourceAction,
                ],
                classification: [
                    'added_tag_ids' => $added,
                    'removed_tag_ids' => $removed,
                ],
                sourceChannel: $sourceChannel,
                sourceAction: $sourceAction,
                deliveryIdentity: (string) ($context['delivery_identity'] ?? 'ticket-event:'.$history->id),
                relatedRecordType: TicketEvent::class,
                relatedRecordId: (int) $history->id,
                correlationUuid: $context['correlation_uuid'] ?? null,
                causationUuid: $context['causation_uuid'] ?? null,
            );

            $suppressRuleDispatch = (bool) ($context['_suppress_ticket_rule_dispatch']
                ?? $context['suppress_rule_dispatch']
                ?? false);
            if (! $suppressRuleDispatch) {
                $this->dispatchRules->handle($ticket, $event, $actor);
            }

            return new TicketMutationResult($ticket->refresh(), $event);
        });
    }

    /** @param list<int|string> $values
     * @return list<int>
     */
    private function tagIds(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_string($value) && preg_match('/\A[1-9][0-9]*\z/', $value) === 1) {
                $id = (int) $value;
            } else {
                throw ValidationException::withMessages(['tag_ids' => 'Every Tag identifier must be a positive integer.']);
            }

            if ($id < 1) {
                throw ValidationException::withMessages(['tag_ids' => 'Every Tag identifier must be a positive integer.']);
            }
            $ids[] = $id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /** @param list<int> $tagIds */
    private function assertActiveTargets(array $tagIds): void
    {
        if ($tagIds === []) {
            return;
        }

        $count = Tag::query()->whereIn('id', $tagIds)->where('active', true)->count();
        if ($count !== count($tagIds)) {
            throw ValidationException::withMessages([
                'tag_ids' => 'Every selected Tag must exist and be active.',
            ]);
        }
    }

    /** @param array<string, mixed> $context */
    private function sourceChannel(?User $actor, array $context): string
    {
        return (string) ($context['source_channel']
            ?? ($actor?->isSystemActor() ? 'ticket_rule' : ($actor ? 'tech' : 'system')));
    }
}
