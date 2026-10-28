<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Actions\DraftTicketTimeInvoiceText;
use App\Modules\Ticket\Actions\PickTicketStorageReservation;
use App\Modules\Ticket\Actions\ReleaseTicketStorageReservation;
use App\Modules\Ticket\Actions\UpdateTicketStorageReservation;
use App\Modules\Ticket\Actions\UpdateTicketTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Resources\Api\V1\TicketCostEntryResource;
use App\Modules\Ticket\Resources\Api\V1\TicketTimeEntryResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OpenApi\Attributes as OA;

/** Explicit ledger actions preserve pending billing and existing stock lifecycle transactions. */
final class TicketLedgerController extends Controller
{
    #[OA\Patch(path: '/api/v1/tickets/{ticket}/time-entries/{timeEntry}', operationId: 'ticketTicketLedgerControllerupdateTime', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'timeEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.time.update'], 'domain-permissions' => 'ticket.register_time', 'additional-domain-permissions' => []], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['work_date', 'minutes', 'rate_key', 'invoice_text'], properties: [new OA\Property(property: 'work_date', type: 'string', format: 'date'), new OA\Property(property: 'minutes', type: 'integer', minimum: 1, maximum: 1440), new OA\Property(property: 'rate_key', type: 'string', maxLength: 100), new OA\Property(property: 'invoice_text', type: 'string', maxLength: 2000), new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketTimeEntryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function updateTime(Request $request, Ticket $ticket, TicketTimeEntry $timeEntry, TicketTimeRateOptions $rates, UpdateTicketTimeEntry $update)
    {
        $this->assert($ticket, $timeEntry);
        $this->action($ticket, TicketAction::REGISTER_TIME, $request);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, [
            'work_date' => ['required', 'date'], 'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'rate_key' => ['required', 'string', 'max:100'], 'invoice_text' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $rate = $rates->findForTicket($ticket, $data['rate_key']);
        if (! $rate) {
            throw ValidationException::withMessages(['rate_key' => 'Select an available time rate for this Ticket.']);
        }

        return new TicketTimeEntryResource($update->handle($ticket, $timeEntry, $data, $rate, $request->user()));
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/time-entries/invoice-text-suggestion', operationId: 'ticketTicketLedgerControllerdraftInvoiceText', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string'))], x: ['required-scopes' => ['tickets.time.suggest', 'tickets.messages.read', 'tickets.read'], 'domain-permissions' => 'ticket.register_time', 'additional-domain-permissions' => ['ticket.view']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'existing_text', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'rate_key', type: 'string', maxLength: 100, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketInvoiceTextSuggestion')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function draftInvoiceText(Request $request, Ticket $ticket, DraftTicketTimeInvoiceText $draft)
    {
        $this->assert($ticket);
        $this->action($ticket, TicketAction::REGISTER_TIME, $request);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['existing_text' => ['nullable', 'string', 'max:2000'], 'rate_key' => ['nullable', 'string', 'max:100']]);

        return response()->json(['data' => $draft->handle($ticket, $request->user(), $data)]);
    }

    #[OA\Patch(path: '/api/v1/tickets/{ticket}/cost-entries/{costEntry}', operationId: 'ticketTicketLedgerControllerupdateCost', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'costEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.costs.update'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Release of storage reservation: native storage.release policy.']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['quantity'], properties: [new OA\Property(property: 'quantity', type: 'integer', minimum: 0, maximum: 100000), new OA\Property(property: 'invoice_text', type: 'string', maxLength: 2000, nullable: true), new OA\Property(property: 'note', type: 'string', maxLength: 2000, nullable: true)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketCostEntryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function updateCost(Request $request, Ticket $ticket, TicketCostEntry $costEntry, UpdateTicketStorageReservation $update, ReleaseTicketStorageReservation $release)
    {
        $this->assert($ticket, $costEntry);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['quantity' => ['required', 'integer', 'min:0', 'max:100000'], 'invoice_text' => ['nullable', 'string', 'max:2000'], 'note' => ['nullable', 'string', 'max:2000']]);
        try {
            $entry = (int) $data['quantity'] === 0
                ? $this->releaseEntry($ticket, $costEntry, $request, $release)
                : $update->handle($ticket, $costEntry, $data, $request->user());
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cost_entry' => $exception->getMessage()]);
        }

        return new TicketCostEntryResource($entry);
    }

    #[OA\Delete(path: '/api/v1/tickets/{ticket}/cost-entries/{costEntry}', operationId: 'ticketTicketLedgerControllerreleaseCost', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'costEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.costs.release'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => [], 'conditional-authorization' => ['Storage reservation release: native storage.release policy.']], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketCostEntryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function releaseCost(Request $request, Ticket $ticket, TicketCostEntry $costEntry, ReleaseTicketStorageReservation $release)
    {
        $this->assert($ticket, $costEntry);
        try {
            $entry = $this->releaseEntry($ticket, $costEntry, $request, $release);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cost_entry' => $exception->getMessage()]);
        }

        return new TicketCostEntryResource($entry);
    }

    #[OA\Get(path: '/api/v1/tickets/{ticket}/cost-entries/{costEntry}/pick-preview', operationId: 'ticketTicketLedgerControllerpickPreview', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'costEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.costs.pick'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => ['storage.pick']], responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketCostPickPreview')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function pickPreview(Request $request, Ticket $ticket, TicketCostEntry $costEntry)
    {
        $this->assert($ticket, $costEntry);
        app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->authorize($request, 'storage.pick');

        return response()->json(['data' => app(\App\Modules\Ticket\Services\TicketCostPickPreview::class)->preview($ticket, $costEntry, $request->user())]);
    }

    #[OA\Post(path: '/api/v1/tickets/{ticket}/cost-entries/{costEntry}/pick', operationId: 'ticketTicketLedgerControllerpickCost', security: [['bearerAuth' => []]], tags: ['Tickets'], parameters: [new OA\Parameter(name: 'ticket', in: 'path', required: true, schema: new OA\Schema(type: 'string')), new OA\Parameter(name: 'costEntry', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], x: ['required-scopes' => ['tickets.costs.pick'], 'domain-permissions' => 'ticket.update', 'additional-domain-permissions' => ['storage.pick']], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['preview_token'], properties: [new OA\Property(property: 'preview_token', type: 'string', maxLength: 8192)])), responses: [new OA\Response(response: 200, description: 'Explicit persisted read-back or preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/TicketCostEntryReadback')])), new OA\Response(response: 401, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 403, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 404, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError')), new OA\Response(response: 422, description: 'Authorization, ownership or validation failure', content: new OA\JsonContent(ref: '#/components/schemas/ParityReadError'))])]
    public function pickCost(Request $request, Ticket $ticket, TicketCostEntry $costEntry, PickTicketStorageReservation $pick)
    {
        $this->assert($ticket, $costEntry);
        app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->authorize($request, 'storage.pick');
        $this->action($ticket, TicketAction::PICK_ITEM, $request);
        $data = \App\Modules\Integration\Support\DomainApiInput::validate($request, ['preview_token' => ['required', 'string', 'max:8192']]);
        try {
            $entry = DB::transaction(function () use ($ticket, $costEntry, $request, $pick, $data) {
                $entry = TicketCostEntry::query()->where('ticket_id', $ticket->id)->lockForUpdate()->findOrFail($costEntry->id);
                // A successful retry returns the existing pick; it never consumes stock twice.
                if ($entry->status === 'picked') {
                    return $entry;
                }
                app(\App\Modules\Ticket\Services\TicketCostPickPreview::class)->assertCurrent($data['preview_token'], $entry, $request->user());

                return $pick->handle($ticket, $entry, $request->user());
            });
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['cost_entry' => $exception->getMessage()]);
        }

        return new TicketCostEntryResource($entry);
    }

    private function releaseEntry(Ticket $ticket, TicketCostEntry $cost, Request $request, ReleaseTicketStorageReservation $release): TicketCostEntry
    {
        return DB::transaction(function () use ($ticket, $cost, $request, $release) {
            $entry = TicketCostEntry::query()->where('ticket_id', $ticket->id)->lockForUpdate()->findOrFail($cost->id);

            return $entry->status === 'released' && $entry->billing_status === 'cancelled'
                ? $entry : $release->handle($ticket, $entry, $request->user());
        });
    }

    private function assert(Ticket $ticket, TicketCostEntry|TicketTimeEntry|null $entry = null): void
    {
        app(TicketMutationScopeGuard::class)->assert($ticket);
        if ($entry) {
            abort_unless((int) $entry->ticket_id === (int) $ticket->id, 404);
        }
    }

    private function action(Ticket $ticket, string $action, Request $request): void
    {
        if ($reason = app(TicketActionGuard::class)->reason($ticket, $action, $request->user())) {
            throw ValidationException::withMessages(['ticket' => $reason]);
        }
    }
}
