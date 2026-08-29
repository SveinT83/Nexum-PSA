<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Notification\Notifications\TicketCommentAdded;
use App\Modules\Relationship\Actions\SyncTicketMessageToRelationship;
use App\Modules\Ticket\Jobs\SendTicketInternalNotificationEmail;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Services\TicketRuleMessageMutationEventFactory;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AddTicketMessage
{
    public function __construct(
        private readonly DispatchTicketRuleMutationEvent $dispatchRules,
        private readonly TicketRuleMessageMutationEventFactory $messageEvents,
    ) {}

    public function handle(Ticket $ticket, array $data, ?User $actor = null): TicketMessage
    {
        $managedSystemActor = $actor?->isSystemActor() === true;
        if ($managedSystemActor) {
            // A managed actor is never presented as a technician and cannot
            // turn an unattended note into customer-visible delivery.
            $data['type'] = 'internal_note';
            $data['visibility'] = 'internal';
            $data['suppress_notifications'] = true;
            $data['suppress_workflow_trigger'] = true;
            unset($data['notify_user_id']);
            if (is_array($data['metadata'] ?? null)) {
                unset($data['metadata']['notify_user_id']);
            }
        }

        $authorType = $this->authorType($data, $managedSystemActor);
        $suppressReplyDelivery = (bool) ($data['_suppress_reply_delivery'] ?? false);

        return DB::transaction(function () use ($ticket, $data, $actor, $authorType, $suppressReplyDelivery) {
            $beforeOwnerId = $ticket->owner_id ? (int) $ticket->owner_id : null;
            $message = TicketMessage::create([
                'ticket_id' => $ticket->id,
                'author_id' => $actor?->id,
                'author_type' => $authorType,
                'type' => $data['type'] ?? 'internal_note',
                'visibility' => $data['visibility'] ?? 'internal',
                'subject' => $data['subject'] ?? null,
                'body' => $data['body'],
                'metadata' => $this->messageMetadata($data, $actor),
                'idempotency_key' => $data['idempotency_key'] ?? null,
                'idempotency_fingerprint' => $data['idempotency_fingerprint'] ?? null,
            ]);

            foreach (($data['attachments'] ?? []) as $attachment) {
                app(StoreTicketAttachment::class)->fromUpload($message, $attachment, $actor);
            }

            $externalAuthor = in_array($authorType, ['portal_user', 'external'], true);
            $ticketUpdates = [
                'updated_by' => $actor?->id,
                'is_unread' => $externalAuthor,
            ];

            if ($message->type === 'customer_reply'
                && $message->visibility === 'public'
                && ! $externalAuthor
                && ! $ticket->first_responded_at) {
                $ticketUpdates['first_responded_at'] = now();
            }

            $ticket->forceFill($ticketUpdates)->touch();
            $claimedTicket = null;
            if (! ($data['_suppress_assignment_claim'] ?? false)) {
                $claimedTicket = app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'message_added');
            }

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'message_added',
                'message' => ucfirst(str_replace('_', ' ', $message->type)).' added.',
                'after' => [
                    'message_id' => $message->id,
                    'type' => $message->type,
                    'visibility' => $message->visibility,
                    'attachments_count' => $message->fileAttachments()->count(),
                ],
            ]);

            if ($message->type === 'customer_reply' && ! $suppressReplyDelivery) {
                SendTicketReplyEmail::dispatch($message->id)->afterCommit();
                DB::afterCommit(fn () => app(SyncTicketMessageToRelationship::class)->handle($message->id));
            } elseif (! empty($message->metadata['notify_user_id'])) {
                SendTicketInternalNotificationEmail::dispatch($message->id)->afterCommit();
            }

            if (
                ! $suppressReplyDelivery &&
                $message->type === 'customer_reply'
                && $message->visibility === 'public'
                && $ticket->isPortalVisible()
                && $ticket->client_id
            ) {
                app(SendCustomerPortalNotification::class)->handle(
                    type: 'portal_ticket_reply',
                    clientId: (int) $ticket->client_id,
                    siteId: $ticket->site_id ? (int) $ticket->site_id : null,
                    title: 'New reply on '.$ticket->ticket_key,
                    body: ($actor?->name ?: 'Support').' replied to '.$ticket->subject.'.',
                    url: route('customer-portal.tickets.show', $ticket),
                    sourceType: Ticket::class,
                    sourceId: $ticket->id,
                    metadata: [
                        'ticket_key' => $ticket->ticket_key,
                        'message_id' => $message->id,
                    ],
                );
            }

            if (! ($data['suppress_workflow_trigger'] ?? false)) {
                $advanced = app(ApplyTicketWorkflowActionTrigger::class)->handle(
                    $ticket->refresh(),
                    $this->workflowActionFor($message),
                    $actor
                );

                if (! $advanced && (bool) ($message->metadata['is_solution'] ?? false)) {
                    app(AutoAdvanceTicketWorkflow::class)->handle($ticket->refresh(), $actor);
                }
            }

            $eventContext = $data;
            if ($claimedTicket) {
                $eventContext['_assignment_before_owner_id'] = $beforeOwnerId;
                $eventContext['_assignment_after_owner_id'] = (int) $claimedTicket->owner_id;
            }
            $mutationEvent = $this->messageEvents->make(
                $ticket->refresh(),
                $message,
                $eventContext,
                $actor,
            );
            if (! ($data['_suppress_ticket_rule_dispatch'] ?? false)) {
                $this->dispatchRules->handle($ticket->refresh(), $mutationEvent, $actor);
            }

            // Notify the ticket owner (if not the comment author)
            if (! ($data['suppress_notifications'] ?? false)
                && $ticket->owner_id
                && $ticket->owner_id !== $actor?->id) {
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

    private function authorType(array $data, bool $managedSystemActor): string
    {
        if ($managedSystemActor) {
            return 'system';
        }

        $authorType = $data['_author_type'] ?? 'user';
        if (! is_string($authorType) || ! in_array($authorType, ['user', 'portal_user', 'external'], true)) {
            throw ValidationException::withMessages([
                '_author_type' => 'The internal Ticket message author type is invalid.',
            ]);
        }

        return $authorType;
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
