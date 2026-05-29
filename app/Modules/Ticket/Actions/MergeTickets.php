<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Task\Models\Task;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MergeTickets
{
    public function handle(Ticket $source, Ticket $target, ?User $actor = null, ?string $reason = null): Ticket
    {
        if ($source->is($target)) {
            throw new InvalidArgumentException('A ticket cannot be merged into itself.');
        }

        if ($source->trashed()) {
            throw new InvalidArgumentException('A deleted ticket cannot be merged.');
        }

        if ($target->trashed() || $target->merged_into_ticket_id) {
            throw new InvalidArgumentException('The target ticket is not available for merging.');
        }

        return DB::transaction(function () use ($source, $target, $actor, $reason) {
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
            Task::where('owner_type', $source->getMorphClass())
                ->where('owner_id', $source->id)
                ->update(['owner_id' => $target->id]);

            DB::table('ticket_time_entry_allocations')
                ->where('ticket_id', $source->id)
                ->update(['ticket_id' => $target->id]);

            $source->tags->each(function ($tag) use ($target) {
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

            return $target->fresh();
        });
    }
}
