<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class LinkInboundEmailToTicket
{
    public function __construct(
        private readonly InboundEmailNotificationFanoutReadiness $fanoutReadiness,
    ) {}

    public function handle(EmailMessage $email, Ticket $ticket): TicketMessage
    {
        if (! $this->fanoutReadiness->ready()
            || DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                ->where('id', 1)
                ->value('status') !== AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED) {
            throw new RuntimeException('inbound_ticket_message_pointer_repair_pending');
        }

        return $this->linkReady($email, $ticket, true);
    }

    private function linkReady(
        EmailMessage $email,
        Ticket $ticket,
        bool $mayRetryUniqueRace,
    ): TicketMessage {
        try {
            return DB::transaction(function () use ($email, $ticket) {
                $email = EmailMessage::query()->whereKey($email->id)->lockForUpdate()->firstOrFail();
                $ticket = Ticket::query()->whereKey($ticket->id)->lockForUpdate()->firstOrFail();
                $existing = TicketMessage::query()
                    ->withTrashed()
                    ->where('source_inbound_email_message_id', $email->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ((int) $existing->ticket_id !== (int) $ticket->id) {
                        throw new RuntimeException('inbound_ticket_message_pointer_conflict');
                    }
                    if ($existing->trashed()
                        || (int) $existing->inbound_email_message_id !== (int) $email->id) {
                        throw new RuntimeException('inbound_ticket_message_pointer_deleted');
                    }
                    $email->forceFill([
                        'ticket_id' => $ticket->id,
                        'state' => 'linked',
                    ])->save();

                    $this->inheritEmailTags($email, $ticket);
                    $email->loadMissing('attachments');
                    $this->copyEmailAttachments($email, $existing);

                    return $existing;
                }

                $email->loadMissing('attachments');

                $message = TicketMessage::create([
                    'ticket_id' => $ticket->id,
                    'source_inbound_email_message_id' => $email->id,
                    'inbound_email_message_id' => $email->id,
                    'author_id' => null,
                    'author_type' => 'contact',
                    'type' => 'customer_reply',
                    'visibility' => 'public',
                    'subject' => $email->subject,
                    'body' => $this->body($email),
                    'metadata' => [
                        'email_message_id' => $email->id,
                        'email_account_id' => $email->account_id,
                        'from_name' => $email->from_name,
                        'from_email' => $email->from_email,
                        'message_id' => $email->message_id,
                        'in_reply_to' => $email->in_reply_to,
                        'references' => $email->references,
                    ],
                ]);

                $ticket->forceFill([
                    'is_unread' => true,
                ])->touch();

                $email->forceFill([
                    'ticket_id' => $ticket->id,
                    'state' => 'linked',
                ])->save();

                $this->inheritEmailTags($email, $ticket);
                $this->copyEmailAttachments($email, $message);

                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => null,
                    'type' => 'inbound_email_linked',
                    'message' => 'Customer reply received by email.',
                    'after' => [
                        'ticket_message_id' => $message->id,
                        'email_message_id' => $email->id,
                        'from_email' => $email->from_email,
                        'attachments_count' => $message->fileAttachments()->count(),
                    ],
                ]);

                app(ApplyTicketWorkflowActionTrigger::class)->handle(
                    $ticket->refresh(),
                    TicketAction::CUSTOMER_REPLY_RECEIVED,
                );

                return $message;
            });
        } catch (QueryException) {
            // The Email-row lock serializes normal callers. If a legacy/raw
            // writer nevertheless wins the unique pointer, re-enter through
            // the exact idempotent path without retaining SQL bindings.
            if (! $mayRetryUniqueRace) {
                throw new RuntimeException('inbound_ticket_message_pointer_store_failed');
            }

            $winner = TicketMessage::query()
                ->withTrashed()
                ->where('source_inbound_email_message_id', $email->id)
                ->first();
            if (! $winner) {
                throw new RuntimeException('inbound_ticket_message_pointer_store_failed');
            }
            if ((int) $winner->ticket_id !== (int) $ticket->id) {
                throw new RuntimeException('inbound_ticket_message_pointer_conflict');
            }
            if ($winner->trashed()
                || (int) $winner->inbound_email_message_id !== (int) $email->id) {
                throw new RuntimeException('inbound_ticket_message_pointer_deleted');
            }

            return $this->linkReady(
                $email->fresh() ?? $email,
                $ticket->fresh() ?? $ticket,
                false,
            );
        }
    }

    private function body(EmailMessage $email): string
    {
        // The ticket message body is required even when the source email only had attachments or unreadable HTML.
        $body = $email->body_text ?: trim(strip_tags((string) $email->body_html_sanitized));
        $body = BodyNormalizer::stripQuotedHistory($body);

        return $body !== '' ? $body : '[Inbound email had no readable body.]';
    }

    private function inheritEmailTags(EmailMessage $email, Ticket $ticket): void
    {
        $email->loadMissing('tags');

        foreach ($email->tags as $tag) {
            if (! $ticket->tags()->where('tags.id', $tag->id)->exists()) {
                $ticket->tags()->attach($tag->id, ['module' => 'ticket']);
            }
        }
    }

    private function copyEmailAttachments(EmailMessage $email, TicketMessage $message): void
    {
        foreach ($email->attachments as $attachment) {
            app(StoreTicketAttachment::class)->fromEmailAttachment($message, $attachment);
        }
    }
}
