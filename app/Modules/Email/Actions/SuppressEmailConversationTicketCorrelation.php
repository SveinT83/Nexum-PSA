<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailConversationTicketSuppression;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SuppressEmailConversationTicketCorrelation
{
    public function __construct(
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailConversationProjector $conversations,
    ) {}

    public function handle(
        EmailMailboxPlacement $placement,
        User $actor,
        ?Ticket $sourceTicket = null,
        string $reasonCode = 'not_ticket',
    ): EmailConversationTicketSuppression {
        $placement->loadMissing(['message.account']);

        if (! $placement->message || ! $this->mailboxAccess->canOrganizeMessage($actor, $placement->message)) {
            throw new AuthorizationException('Mailbox Organize access is required to suppress Ticket correlation.');
        }

        $conversation = $this->conversations->assignPlacement($placement);

        if (! $conversation) {
            throw ValidationException::withMessages([
                'conversation' => 'A durable Mail conversation is required before Ticket correlation can be suppressed.',
            ]);
        }

        return DB::transaction(function () use ($placement, $conversation, $actor, $sourceTicket, $reasonCode): EmailConversationTicketSuppression {
            $lockedPlacement = EmailMailboxPlacement::query()->whereKey($placement->id)->lockForUpdate()->firstOrFail();
            $lockedConversation = EmailConversation::query()->whereKey($conversation->id)->lockForUpdate()->firstOrFail();
            $suppression = EmailConversationTicketSuppression::query()
                ->where('account_id', $lockedConversation->account_id)
                ->where('conversation_key', $lockedConversation->conversation_key)
                ->lockForUpdate()
                ->first();

            $payload = [
                'email_conversation_id' => $lockedConversation->id,
                'status' => EmailConversationTicketSuppression::STATUS_ACTIVE,
                'reason_code' => $reasonCode,
                'suppressed_by' => $actor->id,
                'source_ticket_id' => $sourceTicket?->id,
                'suppressed_at' => now(),
                'lifted_by' => null,
                'lifted_at' => null,
                'metadata' => [
                    'source_email_message_id' => (int) $lockedPlacement->email_message_id,
                    'source_email_mailbox_placement_id' => (int) $lockedPlacement->id,
                ],
            ];

            if ($suppression) {
                $suppression->forceFill($payload)->save();
            } else {
                $suppression = EmailConversationTicketSuppression::query()->create($payload + [
                    'account_id' => $lockedConversation->account_id,
                    'conversation_key' => $lockedConversation->conversation_key,
                ]);
            }

            $messageIds = EmailMailboxPlacement::query()
                ->where('email_conversation_id', $lockedConversation->id)
                ->where('account_id', $lockedConversation->account_id)
                ->pluck('email_message_id')
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->values();
            $tag = Tag::firstOrCreate(
                ['name' => 'not-ticket'],
                ['slug' => 'not-ticket', 'color' => '#6c757d', 'active' => true],
            );

            EmailMessage::query()->whereIn('id', $messageIds)->lockForUpdate()->get()->each(
                function (EmailMessage $message) use ($tag, $sourceTicket): void {
                    if (! $message->tags()->where('tags.id', $tag->id)->exists()) {
                        $message->tags()->attach($tag->id, ['module' => 'email']);
                    }

                    if ($sourceTicket === null || (int) $message->ticket_id === (int) $sourceTicket->id) {
                        $message->forceFill(['ticket_id' => null, 'state' => 'untriaged'])->save();
                    }
                },
            );

            $links = EmailTicketConversationLink::query()
                ->where('email_conversation_id', $lockedConversation->id)
                ->where('status', EmailTicketConversationLink::STATUS_ACTIVE)
                ->when($sourceTicket, fn ($query) => $query->where('ticket_id', $sourceTicket->id))
                ->lockForUpdate()
                ->get();

            foreach ($links as $link) {
                $duplicate = EmailTicketConversationLink::query()
                    ->where('ticket_id', $link->ticket_id)
                    ->where('email_message_id', $link->email_message_id)
                    ->where('status', EmailTicketConversationLink::STATUS_UNLINKED)
                    ->whereKeyNot($link->id)
                    ->first();

                if ($duplicate) {
                    $link->delete();
                    continue;
                }

                $metadata = $link->metadata ?? [];
                $metadata['ticket_suppression_id'] = $suppression->id;
                $link->forceFill([
                    'status' => EmailTicketConversationLink::STATUS_UNLINKED,
                    'metadata' => $metadata,
                    'unlinked_at' => now(),
                ])->save();
            }

            return $suppression->fresh();
        });
    }
}
