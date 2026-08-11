<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Exceptions\TicketMessageIdempotencyConflict;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketReplyContactResolver;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class StoreIdempotentTicketCustomerReply
{
    public function __construct(
        private readonly AddTicketMessage $addTicketMessage,
        private readonly TicketActionGuard $actionGuard,
        private readonly TicketReplyContactResolver $contactResolver,
    ) {}

    /** @return array{message: TicketMessage, created: bool} */
    public function handle(Ticket $ticket, array $data, ?User $actor): array
    {
        $this->authorize($actor);

        $key = (string) $data['idempotency_key'];
        $fingerprint = $this->fingerprint($data);
        $existing = $this->findExisting($ticket, $key);

        if ($existing) {
            return $this->replayOrConflict($existing, $actor, $fingerprint);
        }

        if (! $ticket->isPortalVisible()) {
            throw ValidationException::withMessages([
                'type' => 'Publish the ticket before replying to the customer.',
            ]);
        }

        if ($reason = $this->actionGuard->reason($ticket, TicketAction::CUSTOMER_REPLY, $actor)) {
            throw ValidationException::withMessages(['type' => $reason]);
        }

        $replyContact = $this->contactResolver->resolve(
            $ticket,
            isset($data['reply_contact_id']) ? (int) $data['reply_contact_id'] : null,
        );

        if (! $replyContact || blank($replyContact->email)) {
            throw ValidationException::withMessages([
                'reply_contact_id' => 'Select an active client contact with an email address.',
            ]);
        }

        $messageData = array_merge($data, [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'reply_contact_id' => $replyContact->id,
            'idempotency_key' => $key,
            'idempotency_fingerprint' => $fingerprint,
        ]);

        try {
            $message = $this->addTicketMessage->handle($ticket, $messageData, $actor);
        } catch (QueryException $exception) {
            $winner = $this->findExisting($ticket, $key);

            if (! $winner) {
                throw $exception;
            }

            return $this->replayOrConflict($winner, $actor, $fingerprint);
        }

        return ['message' => $message, 'created' => true];
    }

    private function authorize(?User $actor): void
    {
        if (! $actor || ! $actor->isActive()) {
            throw new AuthorizationException('An active user is required to reply to a Ticket.');
        }

        if (Permission::query()->where('name', 'ticket.reply_customer')->where('guard_name', 'web')->exists()
            && ! $actor->can('ticket.reply_customer')) {
            throw new AuthorizationException('Missing permission: ticket.reply_customer.');
        }
    }

    private function findExisting(Ticket $ticket, string $key): ?TicketMessage
    {
        return TicketMessage::query()
            ->withTrashed()
            ->where('ticket_id', $ticket->id)
            ->where('idempotency_key', $key)
            ->first();
    }

    /** @return array{message: TicketMessage, created: false} */
    private function replayOrConflict(TicketMessage $message, User $actor, string $fingerprint): array
    {
        if ($message->trashed()
            || (int) $message->author_id !== (int) $actor->id
            || ! hash_equals((string) $message->idempotency_fingerprint, $fingerprint)) {
            throw new TicketMessageIdempotencyConflict(
                'The idempotency key is already reserved for a different or deleted Ticket message.'
            );
        }

        return ['message' => $message, 'created' => false];
    }

    private function fingerprint(array $data): string
    {
        $cc = collect(preg_split('/[,;\s]+/', (string) ($data['cc'] ?? '')))
            ->map(fn ($email) => mb_strtolower(trim($email)))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();

        return hash('sha256', json_encode([
            'body' => (string) $data['body'],
            'reply_intent' => (string) ($data['reply_intent'] ?? TicketAction::CUSTOMER_UPDATE),
            'reply_contact_id' => isset($data['reply_contact_id']) ? (int) $data['reply_contact_id'] : null,
            'cc' => $cc,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
