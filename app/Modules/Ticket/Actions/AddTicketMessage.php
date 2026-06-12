<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Jobs\SendTicketInternalNotificationEmail;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Actions\ApplyTicketWorkflowActionTrigger;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;

class AddTicketMessage
{
    public function handle(Ticket $ticket, array $data, ?User $actor = null): TicketMessage
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_id' => $actor?->id,
                'author_type' => 'user',
                'type' => $data['type'] ?? 'internal_note',
                'visibility' => $data['visibility'] ?? 'internal',
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'metadata' => $this->messageMetadata($data, $actor),
            ]);

            foreach (($data['attachments'] ?? []) as $attachment) {
                app(StoreTicketAttachment::class)->fromUpload($message, $attachment, $actor);
            }

            $ticketUpdates = [
                'updated_by' => $actor?->id,
                'is_unread' => false,
            ];

            if ($message->type === 'customer_reply' && $message->visibility === 'public' && ! $ticket->first_responded_at) {
                $ticketUpdates['first_responded_at'] = now();
            }

            $ticket->forceFill($ticketUpdates)->touch();
            app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'message_added');

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'message_added',
                'message' => ucfirst(str_replace('_', ' ', $message->type)) . ' added.',
                'after' => [
                    'message_id' => $message->id,
                    'type' => $message->type,
                    'visibility' => $message->visibility,
                    'attachments_count' => $message->fileAttachments()->count(),
                ],
            ]);

            if ($message->type === 'customer_reply') {
                SendTicketReplyEmail::dispatch($message->id)->afterCommit();
            } elseif (! empty($message->metadata['notify_user_id'])) {
                SendTicketInternalNotificationEmail::dispatch($message->id)->afterCommit();
            }

            // Svein's workflow trigger (from Dev branch)
            app(ApplyTicketWorkflowActionTrigger::class)->handle(
                $ticket->refresh(),
                $this->workflowActionFor($message),
                $actor
            );

            // Notify the ticket owner (if not the comment author)
            if ($ticket->owner_id && $ticket->owner_id !== $actor?->id) {
                $owner = User::find($ticket->owner_id);
                if ($owner) {
                    $owner->notify(new TicketCommentAdded(
                        ticket: $ticket,
                        commentAuthor: $actor?->name ?? 'System',
                        commentPreview: str($message->body)->limit(150),
                    ));
                }
            }

            return $message;
        });
    }

    private function messageMetadata(array $data, ?User $actor): array
    {
        $metadata = $data['metadata'] ?? [];

        if (($data['type'] ?? 'internal_note') === 'customer_reply') {
            $intent = $data['reply_intent'] ?? TicketAction::CUSTOMER_UPDATE;
            $metadata['reply_intent'] = $intent;
            $metadata['reply_contact_id'] = $data['reply_contact_id'] ?? null;
            $metadata['cc'] = $this->parseCc($data['cc'] ?? null);

            if ($intent === TicketAction::SEND_SOLUTION) {
                $metadata['is_solution'] = true;
                $metadata['solution_marked_at'] = now()->toISOString();
                $metadata['solution_marked_by'] = $actor?->id;
            }
        }

        if (($data['type'] ?? 'internal_note') === 'internal_note') {
            if (($data['reply_intent'] ?? null) === TicketAction::SEND_SOLUTION) {
                $metadata['reply_intent'] = TicketAction::SEND_SOLUTION;
                $metadata['is_solution'] = true;
                $metadata['solution_marked_at'] = now()->toISOString();
                $metadata['solution_marked_by'] = $actor?->id;
            }

            if (! empty($data['notify_user_id'])) {
                $metadata['notify_user_id'] = (int) $data['notify_user_id'];
            }
        }

        return $metadata;
    }

    private function parseCc(?string $cc): array
    {
        return collect(preg_split('/[,;\s]+/', (string) $cc))
            ->map(fn ($email) => trim($email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->values()
            ->all();
    }

    private function workflowActionFor(TicketMessage $message): string
    {
        if (($message->metadata['reply_intent'] ?? null) === TicketAction::SEND_SOLUTION) {
            return TicketAction::SEND_SOLUTION;
        }

        if ($message->type !== 'customer_reply') {
            return TicketAction::ADD_INTERNAL_NOTE;
        }

        return $message->metadata['reply_intent'] ?? TicketAction::CUSTOMER_UPDATE;
    }
}
