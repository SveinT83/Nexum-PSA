<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Exceptions\TicketMessageIdempotencyConflict;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMessageFingerprint;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Browser-equivalent notes, distinct from the quiet internal automation note contract. */
final class StoreIdempotentTicketApiNote
{
    public function handle(Ticket $ticket, array $data, User $actor): array
    {
        $fingerprint = hash('sha256', json_encode([
            'type' => 'internal_note', 'body' => $data['body'],
            'notify_user_id' => isset($data['notify_user_id']) ? (int) $data['notify_user_id'] : null,
            'mark_as_solution' => (bool) ($data['mark_as_solution'] ?? false),
            'attachments' => TicketMessageFingerprint::attachments($data['attachments'] ?? []),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($ticket, $data, $actor, $fingerprint) {
            $ticket = Ticket::query()->lockForUpdate()->findOrFail($ticket->id);
            app(TicketMutationScopeGuard::class)->assert($ticket);
            $existing = TicketMessage::withTrashed()->where('ticket_id', $ticket->id)
                ->where('idempotency_key', $data['idempotency_key'])->first();
            if ($existing) {
                if ($existing->trashed() || (int) $existing->author_id !== (int) $actor->id
                    || ! hash_equals((string) $existing->idempotency_fingerprint, $fingerprint)) {
                    throw new TicketMessageIdempotencyConflict('The idempotency key is reserved for a different or deleted Ticket message.');
                }

                return ['message' => $existing, 'created' => false];
            }
            if ($reason = app(TicketActionGuard::class)->reason($ticket, TicketAction::ADD_INTERNAL_NOTE, $actor)) {
                throw ValidationException::withMessages(['body' => $reason]);
            }
            $solution = (bool) ($data['mark_as_solution'] ?? false);
            if ($solution && ! app(TicketSolutionPolicy::class)->allowsInternalSolutionNotes()) {
                throw ValidationException::withMessages(['mark_as_solution' => 'Internal solution notes are disabled by Ticket solution policy.']);
            }
            $payload = array_merge($data, [
                'type' => 'internal_note', 'visibility' => 'internal',
                'reply_intent' => $solution ? TicketAction::SEND_SOLUTION : null,
                'idempotency_fingerprint' => $fingerprint,
            ]);
            unset($payload['mark_as_solution']);

            return ['message' => app(AddTicketMessage::class)->handle($ticket, $payload, $actor), 'created' => true];
        });
    }
}
