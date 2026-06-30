<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class SyncExternalTicketMessage
{
    public function handle(Ticket $ticket, array $data): array
    {
        return DB::transaction(function () use ($ticket, $data): array {
            $source = $data['source'];
            $externalId = $data['external_id'];
            $occurredAt = isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now();

            $message = TicketMessage::query()
                ->where('ticket_id', $ticket->id)
                ->where('metadata->external_source', $source)
                ->where('metadata->external_id', $externalId)
                ->first();

            $created = ! $message;
            $metadata = array_merge(
                $this->syncMetadata($message?->metadata ?? []),
                $this->syncMetadata($data['metadata'] ?? []),
                [
                    'external_source' => $source,
                    'external_id' => $externalId,
                    'external_author_name' => $data['author_name'] ?? null,
                    'external_author_email' => $data['author_email'] ?? null,
                    'external_occurred_at' => $occurredAt->toISOString(),
                    'external_synced_at' => now()->toISOString(),
                ]
            );

            if (! $message) {
                $message = new TicketMessage([
                    'ticket_id' => $ticket->id,
                    'author_id' => null,
                    'author_type' => 'external',
                ]);
            }

            $message->forceFill([
                'type' => $data['type'] ?? 'internal_note',
                'visibility' => $data['visibility'] ?? 'internal',
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'metadata' => $metadata,
            ])->save();

            if ($created) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => null,
                    'type' => 'external_message_synced',
                    'message' => 'External '.str_replace('_', ' ', $source).' message synced.',
                    'after' => [
                        'message_id' => $message->id,
                        'external_source' => $source,
                        'external_id' => $externalId,
                        'type' => $message->type,
                        'visibility' => $message->visibility,
                    ],
                ]);
            }

            $ticketUpdates = [
                'is_unread' => true,
                'updated_by' => null,
            ];

            if ($message->type === 'customer_reply' && $message->visibility === 'public' && ! $ticket->first_responded_at) {
                $ticketUpdates['first_responded_at'] = $occurredAt;
            }

            $ticket->forceFill($ticketUpdates)->touch();

            if ($created) {
                app(ApplyTicketWorkflowActionTrigger::class)->handle(
                    $ticket->refresh(),
                    $this->workflowActionFor($message),
                    null
                );
            }

            return [$message->refresh(), $created];
        });
    }

    private function workflowActionFor(TicketMessage $message): string
    {
        if ($message->type !== 'customer_reply') {
            return TicketAction::ADD_INTERNAL_NOTE;
        }

        return TicketAction::CUSTOMER_REPLY_RECEIVED;
    }

    private function syncMetadata(array $metadata): array
    {
        unset(
            $metadata['reply_intent'],
            $metadata['is_solution'],
            $metadata['solution_marked_at'],
            $metadata['solution_marked_by'],
            $metadata['notify_user_id']
        );

        return $metadata;
    }
}
