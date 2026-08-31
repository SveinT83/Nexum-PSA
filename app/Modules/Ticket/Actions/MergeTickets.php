<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Models\EmailTicketCorrelationConflict;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketKeyAlias;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Support\TicketMergeSnapshot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class MergeTickets
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly LinkInboundEmailToTicket $linkInboundEmailToTicket,
    ) {}

    public function handle(Ticket $source, Ticket $target, ?User $actor = null, ?string $reason = null): Ticket
    {
        // Automated callers freeze the same database-hydrated representation
        // that the locked verifier will read. Web callers provide their own
        // previously rendered snapshot through handleMany().
        $source = $source->fresh() ?? $source;
        $target = $target->fresh() ?? $target;

        return $this->handleMany(
            collect([$source]),
            $target,
            $actor,
            $reason,
            [
                $source->id => TicketMergeSnapshot::fingerprint($source),
                $target->id => TicketMergeSnapshot::fingerprint($target),
            ],
        );
    }

    /**
     * Merge every source through one locked snapshot and one transaction. The
     * browser preview supplies the fingerprints; an automated caller may use
     * handle(), which freezes its snapshot immediately before locking.
     *
     * @param  Collection<int, Ticket>  $sources
     * @param  array<int|string, string>  $expectedSnapshots
     */
    public function handleMany(
        Collection $sources,
        Ticket $target,
        ?User $actor,
        ?string $reason,
        array $expectedSnapshots,
    ): Ticket {
        $ticketIds = $sources->pluck('id')->push($target->id)->map(fn ($id): int => (int) $id)->unique()->sort()->values();

        if ($ticketIds->count() < 2 || $sources->contains(fn (Ticket $source): bool => $source->is($target))) {
            throw new InvalidArgumentException('A ticket cannot be merged into itself.');
        }

        return DB::transaction(function () use ($ticketIds, $target, $actor, $reason, $expectedSnapshots): Ticket {
            $locked = Ticket::query()
                ->withTrashed()
                ->whereIn('id', $ticketIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($locked->count() !== $ticketIds->count()) {
                throw new InvalidArgumentException('One or more selected tickets no longer exist.');
            }

            foreach ($locked as $ticket) {
                $expected = (string) ($expectedSnapshots[$ticket->id] ?? '');

                if ($expected === '' || ! hash_equals($expected, TicketMergeSnapshot::fingerprint($ticket))) {
                    throw new InvalidArgumentException('The merge preview is stale. Reopen it and review the current Tickets before merging.');
                }
            }

            $lockedTarget = $locked->get($target->id);
            if (! $lockedTarget || $lockedTarget->trashed() || $lockedTarget->merged_into_ticket_id) {
                throw new InvalidArgumentException('The target ticket is not available for merging.');
            }

            if ($actor && (! $actor->isActive() || ! $actor->can('ticket.update'))) {
                throw new InvalidArgumentException('Current Ticket update permission is required for merging.');
            }

            $sources = $locked->except($lockedTarget->id)->values();
            foreach ($sources as $source) {
                if ($source->trashed() || $source->merged_into_ticket_id) {
                    throw new InvalidArgumentException('A source ticket is no longer available for merging.');
                }

                $this->authorizeEmailLinkChanges($source, $lockedTarget, $actor);
            }

            foreach ($sources as $source) {
                $this->mergeOne($source, $lockedTarget, $actor, $reason);
            }

            return $lockedTarget->fresh();
        });
    }

    private function authorizeEmailLinkChanges(Ticket $source, Ticket $target, ?User $actor): void
    {
        $links = EmailTicketConversationLink::query()
            ->whereIn('ticket_id', [$source->id, $target->id])
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->with('message')
            ->lockForUpdate()
            ->get();

        if ($links->isEmpty()) {
            return;
        }

        if (! $actor) {
            throw new InvalidArgumentException('A current user is required to merge Tickets with Mail conversation links.');
        }

        foreach ($links as $link) {
            if (! $link->message || ! $this->mailboxAccess->canOrganizeMessage($actor, $link->message)) {
                throw new InvalidArgumentException('Mailbox Organize access is required for every Mail conversation affected by this merge.');
            }
        }
    }

    private function mergeOne(Ticket $source, Ticket $target, ?User $actor, ?string $reason): void
    {
        $source->loadMissing('tags');
        $metadata = $source->metadata ?? [];
        $metadata['merged'] = [
            'into_ticket_id' => $target->id,
            'into_ticket_key' => $target->ticket_key,
            'by_user_id' => $actor?->id,
            'at' => now()->toIso8601String(),
            'reason' => $reason,
        ];

        $source->forceFill([
            'merged_into_ticket_id' => $target->id,
            'merged_by' => $actor?->id,
            'merged_at' => now(),
            'metadata' => $metadata,
            'updated_by' => $actor?->id,
        ])->save();

        TicketMessage::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
        TicketAttachment::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
        TicketTimeEntry::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
        TicketCostEntry::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
        EmailMessage::where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
        $this->mergeEmailConversationLinks($source, $target);
        $this->recordRetiredTicketKey($source, $target, $actor);
        $this->canonicalizeCorrelationConflicts($source, $target, $actor);
        Task::where('owner_type', $source->getMorphClass())
            ->where('owner_id', $source->id)
            ->update(['owner_id' => $target->id]);

        DB::table('ticket_time_entry_allocations')
            ->where('ticket_id', $source->id)
            ->update(['ticket_id' => $target->id]);

        $source->tags->each(function ($tag) use ($target): void {
            if (! $target->tags()->where('tags.id', $tag->id)->exists()) {
                $target->tags()->attach($tag->id, ['module' => 'ticket']);
            }
        });

        TicketMessage::create([
            'ticket_id' => $target->id,
            'author_id' => $actor?->id,
            'author_type' => 'user',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'subject' => 'Ticket merged',
            'body' => trim('Ticket '.$source->ticket_key.' was merged into this ticket.'.($reason ? "\n\nReason: ".$reason : '')),
            'metadata' => [
                'merged_ticket_id' => $source->id,
                'merged_ticket_key' => $source->ticket_key,
            ],
        ]);

        TicketEvent::create([
            'ticket_id' => $target->id,
            'actor_id' => $actor?->id,
            'type' => 'merged_ticket',
            'message' => 'Ticket '.$source->ticket_key.' merged into '.$target->ticket_key.'.',
            'after' => [
                'source_ticket_id' => $source->id,
                'source_ticket_key' => $source->ticket_key,
                'reason' => $reason,
            ],
        ]);

        $target->forceFill([
            'is_unread' => $target->is_unread || $source->is_unread,
            'updated_by' => $actor?->id,
        ])->touch();

        $source->delete();
    }

    private function mergeEmailConversationLinks(Ticket $source, Ticket $target): void
    {
        $sourceLinks = EmailTicketConversationLink::query()
            ->where('ticket_id', $source->id)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($sourceLinks as $sourceLink) {
            $targetLink = EmailTicketConversationLink::query()
                ->where('ticket_id', $target->id)
                ->where('email_message_id', $sourceLink->email_message_id)
                ->where('status', $sourceLink->status)
                ->lockForUpdate()
                ->first();

            if (! $targetLink) {
                $sourceLink->forceFill(['ticket_id' => $target->id])->save();
                continue;
            }

            $metadata = $targetLink->metadata ?? [];
            $metadata['merged_link_ids'] = collect($metadata['merged_link_ids'] ?? [])
                ->push($sourceLink->id)
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values()
                ->all();
            $targetLink->forceFill([
                'relationship_role' => in_array(EmailTicketConversationLink::ROLE_PRIMARY, [
                    $targetLink->relationship_role,
                    $sourceLink->relationship_role,
                ], true) ? EmailTicketConversationLink::ROLE_PRIMARY : EmailTicketConversationLink::ROLE_SECONDARY,
                // Internal is the privacy-preserving winner when audiences conflict.
                'audience' => in_array(EmailTicketConversationLink::AUDIENCE_INTERNAL, [
                    $targetLink->audience,
                    $sourceLink->audience,
                ], true) ? EmailTicketConversationLink::AUDIENCE_INTERNAL : EmailTicketConversationLink::AUDIENCE_CUSTOMER,
                'metadata' => $metadata,
                'linked_at' => collect([$targetLink->linked_at, $sourceLink->linked_at])->filter()->min(),
            ])->save();
            $sourceLink->delete();
        }

        EmailTicketConversationLink::query()
            ->where('ticket_id', $target->id)
            ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
            ->get()
            ->groupBy(fn (EmailTicketConversationLink $link): string => $link->email_conversation_id
                ? 'id:'.$link->email_conversation_id
                : 'key:'.$link->account_id.':'.$link->conversation_key)
            ->each(function (Collection $links): void {
                $primaryLinks = $links->where('relationship_role', EmailTicketConversationLink::ROLE_PRIMARY)->sortBy('id');
                if ($primaryLinks->count() <= 1) {
                    return;
                }

                $primaryLinks->slice(1)->each(fn (EmailTicketConversationLink $link) => $link->forceFill([
                    'relationship_role' => EmailTicketConversationLink::ROLE_SECONDARY,
                ])->save());
            });
    }

    private function recordRetiredTicketKey(Ticket $source, Ticket $target, ?User $actor): void
    {
        if (! Schema::hasTable('ticket_key_aliases')) {
            throw new InvalidArgumentException('The Ticket key alias migration must be applied before merging Tickets.');
        }

        TicketKeyAlias::query()->where('ticket_id', $source->id)->update(['ticket_id' => $target->id]);
        TicketKeyAlias::query()->updateOrCreate(
            ['alias_key' => strtoupper($source->ticket_key)],
            [
                'ticket_id' => $target->id,
                'source_ticket_id' => $source->id,
                'created_by' => $actor?->id,
                'reason_code' => 'ticket_merge',
                'metadata' => ['target_ticket_key' => $target->ticket_key],
            ],
        );
    }

    private function canonicalizeCorrelationConflicts(Ticket $source, Ticket $target, ?User $actor): void
    {
        if (! Schema::hasTable('email_ticket_correlation_conflicts')) {
            return;
        }

        $conflicts = EmailTicketCorrelationConflict::query()
            ->where('status', EmailTicketCorrelationConflict::STATUS_PENDING)
            ->lockForUpdate()
            ->get()
            ->filter(fn (EmailTicketCorrelationConflict $conflict): bool => in_array(
                (int) $source->id,
                collect($conflict->candidate_ticket_ids)->map(fn ($id): int => (int) $id)->all(),
                true,
            ));

        foreach ($conflicts as $conflict) {
            $candidateIds = collect($conflict->candidate_ticket_ids)
                ->map(fn ($id): int => (int) $id === (int) $source->id ? (int) $target->id : (int) $id)
                ->unique()
                ->sort()
                ->values();
            $evidence = collect($conflict->evidence ?? [])->map(
                fn ($ids) => collect($ids)->map(
                    fn ($id): int => (int) $id === (int) $source->id ? (int) $target->id : (int) $id,
                )->unique()->sort()->values()->all(),
            )->all();
            $conflict->forceFill([
                'candidate_ticket_ids' => $candidateIds->all(),
                'evidence' => $evidence,
            ])->save();

            if ($candidateIds->count() !== 1 || ! $actor) {
                continue;
            }

            $message = EmailMessage::query()->whereKey($conflict->email_message_id)->lockForUpdate()->first();
            if ($message && $message->ticket_id === null) {
                $this->linkInboundEmailToTicket->handle($message, $target);
            }
            $conflict->forceFill([
                'status' => EmailTicketCorrelationConflict::STATUS_RESOLVED,
                'resolved_ticket_id' => $target->id,
                'resolved_by' => $actor->id,
                'resolution_reason' => 'All candidates became the same Ticket during an authorized Ticket merge.',
                'resolved_at' => now(),
            ])->save();
        }
    }
}
