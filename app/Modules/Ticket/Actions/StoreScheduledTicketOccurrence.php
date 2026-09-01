<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class StoreScheduledTicketOccurrence
{
    public function __construct(
        private readonly StoreTicket $storeTicket,
    ) {}

    public function handle(Ticket $parent, \Carbon\Carbon $plannedStart): Ticket
    {
        return DB::transaction(function () use ($parent, $plannedStart) {
            $schedule = $parent->schedule;

            $actor = $parent->created_by
                ? User::query()->find((int) $parent->created_by)
                : null;

            $occurrence = $this->storeTicket->handle([
                'ticket_type_id' => $parent->ticket_type_id,
                'queue_id' => $parent->queue_id,
                'priority_id' => $parent->priority_id,
                'workflow_id' => $parent->workflow_id,
                '_workflow_version_id' => $parent->workflow_version_id,
                'category_id' => $parent->category_id,
                'client_id' => $parent->client_id,
                'site_id' => $parent->site_id,
                'contact_id' => $parent->contact_id,
                'asset_id' => $parent->asset_id,
                'owner_id' => $parent->owner_id,
                'channel' => 'scheduled',
                'subject' => $parent->subject,
                'description' => $parent->description,
                'sla_mode' => $schedule?->sla_mode ?? 'defer_until_planned_start',
                '_sla_planned_start_at' => $plannedStart,
                '_created_by_id' => $parent->created_by,
                '_skip_initial_description_note' => true,
                '_source_action' => 'StoreScheduledTicketOccurrence',
                '_delivery_key' => 'scheduled-occurrence:'.$parent->id.':'.$plannedStart->toISOString(),
                'suppress_notifications' => true,
                'metadata' => array_merge($parent->metadata ?? [], [
                    'is_occurrence' => true,
                    'parent_ticket_id' => $parent->id,
                    'occurrence_planned_start' => $plannedStart->toISOString(),
                ]),
            ], $actor);

            // Clone messages from parent (internal notes/templates)
            foreach ($parent->messages()->where('visibility', 'internal')->get() as $message) {
                $newMessage = TicketMessage::create([
                    'ticket_id' => $occurrence->id,
                    'author_id' => $message->author_id,
                    'author_type' => $message->author_type,
                    'type' => $message->type,
                    'visibility' => $message->visibility,
                    'subject' => $message->subject,
                    'body' => $message->body,
                    'metadata' => array_merge($message->metadata ?? [], ['cloned_from_message_id' => $message->id]),
                ]);

                // Link attachments by reference (simplified)
                foreach ($message->fileAttachments as $attachment) {
                    TicketAttachment::create([
                        'ticket_id' => $occurrence->id,
                        'ticket_message_id' => $newMessage->id,
                        'uploaded_by' => $attachment->uploaded_by,
                        'source' => 'cloned',
                        'filename' => $attachment->filename,
                        'original_filename' => $attachment->original_filename,
                        'content_type' => $attachment->content_type,
                        'size_bytes' => $attachment->size_bytes,
                        'disk' => $attachment->disk,
                        'path' => $attachment->path,
                        'checksum_sha1' => $attachment->checksum_sha1,
                    ]);
                }
            }

            return $occurrence;
        });
    }
}
