<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Exceptions\TicketMessageIdempotencyConflict;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class StoreIdempotentTicketInternalNote
{
    public function __construct(
        private readonly AddTicketMessage $messages,
        private readonly TicketActionGuard $guard,
    ) {}

    /** @return array{message: TicketMessage, created: bool} */
    public function handle(Ticket $ticket, array $data, User $actor): array
    {
        $this->authorize($actor);
        $key = trim((string) ($data['idempotency_key'] ?? ''));
        if ($key === '') {
            throw ValidationException::withMessages(['idempotency_key' => 'An idempotency key is required.']);
        }

        $fingerprint = hash('sha256', json_encode([
            'body' => (string) ($data['body'] ?? ''),
            'metadata' => (array) ($data['metadata'] ?? []),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $existing = $this->findExisting($ticket, $key);
        if ($existing) {
            return $this->replayOrConflict($existing, $actor, $fingerprint);
        }

        if ($reason = $this->guard->reason($ticket, TicketAction::ADD_INTERNAL_NOTE, $actor)) {
            throw ValidationException::withMessages(['body' => $reason]);
        }

        try {
            $message = $this->messages->handle($ticket, [
                'type' => 'internal_note',
                'visibility' => 'internal',
                'body' => (string) $data['body'],
                'metadata' => (array) ($data['metadata'] ?? []),
                'idempotency_key' => $key,
                'idempotency_fingerprint' => $fingerprint,
                'suppress_workflow_trigger' => true,
                'suppress_notifications' => (bool) ($data['suppress_notifications'] ?? false),
            ], $actor);
        } catch (QueryException $exception) {
            $winner = $this->findExisting($ticket, $key);
            if (! $winner) {
                throw $exception;
            }

            return $this->replayOrConflict($winner, $actor, $fingerprint);
        }

        return ['message' => $message, 'created' => true];
    }

    private function authorize(User $actor): void
    {
        if (! $actor->isActive() && ! $actor->isSystemActor()) {
            throw new AuthorizationException('An active user or managed system actor is required.');
        }
        if (Permission::query()->where('name', 'ticket.note_internal')->where('guard_name', 'web')->exists()
            && ! $actor->can('ticket.note_internal')) {
            throw new AuthorizationException('Missing permission: ticket.note_internal.');
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
                'The idempotency key is reserved for a different or deleted Ticket message.'
            );
        }

        return ['message' => $message, 'created' => false];
    }
}
