<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Ticket\Actions\ReviewTicketClientTransfer;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Preview and commit share the same authorization and destination checks. */
final class TicketClientTransferController
{
    public function __invoke(Request $request, Ticket $ticket, ReviewTicketClientTransfer $review, UpdateTicketFields $update): JsonResponse
    {
        $preview = $request->routeIs('api.v1.tickets.client-transfer.preview');
        $rules = ['client_id' => ['required', 'integer', 'min:1']];
        foreach (['contact_id', 'site_id', 'asset_id'] as $field) {
            $rules[$field] = ['sometimes', 'nullable', 'integer', 'min:1'];
        }
        if (! $preview) {
            $rules['revision'] = ['required', 'string', 'size:64'];
            $rules['idempotency_key'] = ['required', 'string', 'min:8', 'max:200'];
        }
        $input = DomainApiInput::validate($request, $rules);
        $actor = $request->user();
        $output = DB::transaction(function () use ($ticket, $actor, $input, $preview, $review, $update) {
            $ticket = Ticket::whereKey($ticket->id)->lockForUpdate()->firstOrFail();
            $reason = app(TicketActionGuard::class)->reason($ticket, TicketAction::UPDATE_FIELDS, $actor);
            abort_if($reason !== null, 422, (string) $reason);
            $fields = array_intersect_key($input, array_flip(['client_id', 'contact_id', 'site_id', 'asset_id']));
            $context = $review->handle($ticket, $fields, $actor);
            ksort($fields);
            $fingerprint = hash('sha256', json_encode([$fields, $input['revision'] ?? null], JSON_THROW_ON_ERROR));
            if (! $preview) {
                $key = hash('sha256', $input['idempotency_key']);
                $receipt = $ticket->events()->where('type', 'client_transfer_confirmed')->where('actor_id', $actor->id)
                    ->where('metadata->key_hash', $key)->first();
                if ($receipt) {
                    abort_unless(hash_equals($receipt->metadata['fingerprint'], $fingerprint) && (int) $ticket->client_id === (int) $fields['client_id'], 409, 'Review the current Ticket before retrying.');
                    $original = $receipt->metadata['result'];
                    foreach (['site_id', 'contact_id', 'asset_id'] as $field) {
                        abort_if($ticket->{$field} != $original[$field], 409, 'The transferred Ticket context changed. Read the current Ticket.');
                    }
                    abort_if($ticket->isPortalVisible() !== $original['portal_visible'], 409, 'Portal publication changed. Read the current Ticket.');
                    return $original + ['replayed' => true];
                }
            }
            $costs = TicketCostEntry::where('ticket_id', $ticket->id);
            $impact = ['tasks' => $ticket->tasks()->count(), 'attachments' => $ticket->attachments()->count(),
                'time_entries' => $ticket->timeEntries()->count(), 'cost_entries' => (clone $costs)->count(),
                'reservations' => $costs->whereNotNull('storage_reservation_id')->distinct()->count('storage_reservation_id')];
            $revision = hash('sha256', json_encode([$ticket->getRawOriginal(), $fields, $impact, $ticket->events()->max('id'), $ticket->messages()->max('id')], JSON_THROW_ON_ERROR));
            $before = $ticket->client_id;
            abort_if((int) $before === (int) $fields['client_id'], 409, 'The Ticket already belongs to the destination Client.');
            $summary = ['ticket_id' => $ticket->id, 'ticket_number' => $ticket->ticket_key, 'source_client_id' => $before,
                'client_id' => (int) $fields['client_id'], 'portal_visible' => false, 'impact' => $impact] + $context;
            if ($preview) {
                return $summary + ['revision' => $revision, 'state' => 'preview'];
            }
            abort_unless(hash_equals($revision, $input['revision']), 409, 'The Ticket or selected destination changed. Review a new preview.');
            $saved = $update->handle($ticket, $fields, $actor);
            $result = array_replace($summary, $saved->only(['client_id', 'contact_id', 'site_id', 'asset_id']), ['portal_visible' => $saved->isPortalVisible(), 'state' => 'transferred']);
            TicketEvent::create(['ticket_id' => $saved->id, 'actor_id' => $actor->id, 'type' => 'client_transfer_confirmed',
                'message' => 'Reviewed Ticket transfer confirmed.', 'metadata' => ['key_hash' => $key, 'fingerprint' => $fingerprint, 'result' => $result]]);
            return $result + ['replayed' => false];
        }, 3);

        return response()->json(['data' => $output])->header('Cache-Control', 'private, no-store');
    }
}
