<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketSlaResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StoreScheduledTicketOccurrence
{
    public function __construct(
        private readonly TicketSlaResolver $slaResolver
    ) {}

    public function handle(Ticket $parent, \Carbon\Carbon $plannedStart): Ticket
    {
        return DB::transaction(function () use ($parent, $plannedStart) {
            $schedule = $parent->schedule;

            // Resolve SLA for the new occurrence
            $sla = $this->slaResolver->resolve([
                'priority_id' => $parent->priority_id,
                'planned_start_at' => $plannedStart,
                'sla_mode' => $schedule?->sla_mode ?? 'defer_until_planned_start',
            ], $parent->priority);

            // Create the occurrence ticket
            $occurrence = Ticket::create([
                'ticket_key' => $this->nextTicketKey(),
                'type' => $parent->type,
                'ticket_type_id' => $parent->ticket_type_id,
                'queue_id' => $parent->queue_id,
                'status_id' => $this->initialStatusId(),
                'priority_id' => $parent->priority_id,
                'sla_id' => $sla['sla_id'],
                'sla_snapshot' => $sla['sla_snapshot'],
                'workflow_id' => $parent->workflow_id,
                'workflow_version_id' => $parent->workflow_version_id,
                'workflow_state_key' => 'new', // Always start as new
                'category_id' => $parent->category_id,
                'client_id' => $parent->client_id,
                'work_context_id' => $parent->work_context_id,
                'site_id' => $parent->site_id,
                'contact_id' => $parent->contact_id,
                'asset_id' => $parent->asset_id,
                'owner_id' => $parent->owner_id,
                'created_by' => $parent->created_by,
                'channel' => 'scheduled',
                'subject' => $parent->subject,
                'description' => $parent->description,
                'first_response_due_at' => $sla['first_response_due_at'],
                'resolve_due_at' => $sla['resolve_due_at'],
                'metadata' => array_merge($parent->metadata ?? [], [
                    'is_occurrence' => true,
                    'parent_ticket_id' => $parent->id,
                    'occurrence_planned_start' => $plannedStart->toISOString(),
                ]),
            ]);

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

    private function nextTicketKey(): string
    {
        $prefix = config('ticket.key_prefix', 'TD-');
        $latest = Ticket::query()
            ->where('ticket_key', 'like', $prefix.'%')
            ->orderByDesc('id')
            ->first();

        if (! $latest) {
            return $prefix.'1000';
        }

        $number = (int) str_replace($prefix, '', $latest->ticket_key);

        return $prefix.($number + 1);
    }

    private function initialStatusId(): int
    {
        return TicketStatus::query()->where('is_default', true)->value('id')
            ?? TicketStatus::query()->orderBy('sort_order')->value('id');
    }
}
