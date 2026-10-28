<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The approved API merges current rows directly; only exact transport retries reuse a completed result. */
final class MergeTicketsViaApi
{
    public function handle(User $actor, array $data): Ticket
    {
        return DB::transaction(function () use ($actor, $data) {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            $ids = collect($data['source_ticket_ids'])->push($data['target_ticket_id'])->map('intval')->unique()->sort()->values();
            $tickets = Ticket::withTrashed()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            abort_unless($tickets->count() === $ids->count(), 404);
            $target = $tickets->get($data['target_ticket_id']);
            app(TicketMutationScopeGuard::class)->assert($target);
            $sources = $tickets->except($target->id)->values();
            $merge = app(MergeTickets::class);
            foreach ($sources as $source) {
                $merge->authorizeEmailLinkChanges($source, $target, $actor);
            }
            $key = hash('sha256', $data['idempotency_key']);
            $payload = ['target_id' => (int) $target->id, 'source_ids' => $sources->pluck('id')->map('intval')->sort()->values()->all(), 'reason' => $data['reason'] ?? null];
            $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
            $receipt = TicketEvent::query()->where('actor_id', $actor->id)->where('type', 'api_merge_confirmed')->where('metadata->idempotency_key_hash', $key)->first();
            if ($receipt) {
                abort_unless((int) $receipt->ticket_id === (int) $target->id && hash_equals((string) data_get($receipt->metadata, 'request_fingerprint'), $fingerprint), 409, 'The merge key was already used for a different request.');

                return $target;
            }
            foreach ($sources as $source) {
                app(TicketMutationScopeGuard::class)->assert($source);
            }
            try {
                $target = $merge->handleManyCurrent($sources, $target, $actor, $data['reason'] ?? null);
            } catch (InvalidArgumentException $error) {
                throw ValidationException::withMessages(['merge' => $error->getMessage()]);
            }
            TicketEvent::create(['ticket_id' => $target->id, 'actor_id' => $actor->id, 'type' => 'api_merge_confirmed', 'message' => 'Ticket merge API request completed.',
                'metadata' => ['idempotency_key_hash' => $key, 'request_fingerprint' => $fingerprint, 'source_ticket_ids' => $payload['source_ids']]]);

            return $target;
        }, 3);
    }
}
