<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Storage\Models\Item as StorageItem;
use App\Modules\Ticket\Actions\AcceptTicketQuoteFromMessage;
use App\Modules\Ticket\Actions\ClassifyTicketWorkflowEvidence;
use App\Modules\Ticket\Actions\CloseTicket;
use App\Modules\Ticket\Actions\ConvertApprovedTicketPlannedLine;
use App\Modules\Ticket\Actions\DecideTicketWorkflowReview;
use App\Modules\Ticket\Actions\DeleteTicketPlannedLine;
use App\Modules\Ticket\Actions\EnsureTicketSalesQuote;
use App\Modules\Ticket\Actions\EscalateTicketWorkflow;
use App\Modules\Ticket\Actions\RecordTicketTimerStarted;
use App\Modules\Ticket\Actions\RegisterTicketTimeEntry;
use App\Modules\Ticket\Actions\RequestTicketPurchase;
use App\Modules\Ticket\Actions\RequestTicketWorkflowReview;
use App\Modules\Ticket\Actions\ReserveTicketStorageItem;
use App\Modules\Ticket\Actions\SendTicketSalesQuote;
use App\Modules\Ticket\Actions\StoreManualTicketCostEntry;
use App\Modules\Ticket\Actions\StoreTicketPlannedLine;
use App\Modules\Ticket\Actions\TransitionTicketWorkflow;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Resources\Api\V1\TicketResource;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketWorkflowActionController extends Controller
{
    public function startTimer(Request $request, Ticket $ticket, RecordTicketTimerStarted $action)
    {
        $result = $action->handle($ticket, $request->user());

        return response()->json(['data' => [
            'ticket' => (new TicketResource($this->load($result['ticket'])))->resolve(),
            'transitioned' => $result['transitioned'],
        ]]);
    }

    public function storeTimeEntry(
        Request $request,
        Ticket $ticket,
        TicketTimeRateOptions $rates,
        RegisterTicketTimeEntry $action,
    ) {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'rate_key' => ['required', 'string', 'max:100'],
            'invoice_text' => ['required', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);
        $rate = $rates->findForTicket($ticket, $data['rate_key']);

        if (! $rate) {
            throw ValidationException::withMessages([
                'rate_key' => 'Select an available time rate for this Ticket.',
            ]);
        }

        $entry = $action->handle($ticket, $data, $rate, $request->user());

        return response()->json([
            'data' => $entry->toArray(),
            'ticket' => (new TicketResource($this->load($ticket)))->resolve(),
        ], 201);
    }

    public function storeCostEntry(
        Request $request,
        Ticket $ticket,
        ReserveTicketStorageItem $reserve,
        StoreManualTicketCostEntry $manual,
    ) {
        $data = $request->validate([
            'cost_mode' => ['required', Rule::in(['storage', 'manual'])],
            'storage_item_id' => ['nullable', 'required_if:cost_mode,storage', 'integer', 'exists:storage_items,id'],
            'item_name' => ['nullable', 'required_if:cost_mode,manual', 'string', 'max:255'],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'unit_price_ex_vat' => ['nullable', 'required_if:cost_mode,manual', 'numeric', 'min:0', 'max:9999999999.99'],
            'currency' => ['nullable', 'string', 'size:3'],
            'invoice_text' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($data['cost_mode'] === 'storage') {
            $item = StorageItem::query()->where('status', 'active')->findOrFail((int) $data['storage_item_id']);
            $entry = $reserve->handle($ticket, $item, $data, $request->user());
        } else {
            $entry = $manual->handle($ticket, [
                'item_name' => $data['item_name'],
                'quantity' => $data['quantity'],
                'unit_price_ex_vat' => $data['unit_price_ex_vat'],
                'currency' => Str::upper($data['currency'] ?? 'NOK'),
                'invoice_text' => $data['invoice_text'] ?? null,
                'note' => $data['note'] ?? null,
            ], $request->user());
        }

        return response()->json([
            'data' => $entry->toArray(),
            'ticket' => (new TicketResource($this->load($ticket)))->resolve(),
        ], 201);
    }

    public function decisions(Request $request, Ticket $ticket, TicketWorkflowRuntime $runtime, TicketActionGuard $guard)
    {
        $transitions = $runtime->availableTransitionDecisions($ticket);

        return response()->json(['data' => [
            'workflow_id' => $ticket->workflow_id,
            'workflow_version_id' => $ticket->workflow_version_id,
            'state' => $runtime->currentState($ticket),
            'steps' => $runtime->stateProgress($ticket, $transitions),
            'transitions' => $transitions,
            'escalations' => $runtime->escalationDecisions($ticket),
            'actions' => $guard->decisionMap($ticket, $request->user()),
        ]]);
    }

    public function transition(Request $request, Ticket $ticket, string $transitionKey, TransitionTicketWorkflow $action)
    {
        $data = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:100']]);

        return new TicketResource($this->load($action->handle($ticket, $transitionKey, $request->user(), $data['idempotency_key'] ?? null)));
    }

    public function escalate(Request $request, Ticket $ticket, string $pathKey, EscalateTicketWorkflow $action)
    {
        $data = $request->validate([
            'owner_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'allow_repeat' => ['nullable', 'boolean'],
        ]);

        return new TicketResource($this->load($action->handle($ticket, $pathKey, $data, $request->user())));
    }

    public function close(Request $request, Ticket $ticket, CloseTicket $action)
    {
        $data = $request->validate([
            'outcome' => ['nullable', Rule::in(['completed', 'customer_declined', 'cancelled', 'no_sale'])],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return new TicketResource($this->load($action->handle($ticket, $request->user(), $data['outcome'] ?? 'completed', $data['reason'] ?? null)));
    }

    public function storePlannedLine(Request $request, Ticket $ticket, StoreTicketPlannedLine $action)
    {
        $line = $action->handle($ticket, $this->plannedLineData($request), $request->user());

        return response()->json(['data' => $this->line($line)], 201);
    }

    public function destroyPlannedLine(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, DeleteTicketPlannedLine $action)
    {
        $action->handle($ticket, $plannedLine, $request->user());

        return response()->noContent();
    }

    public function convertPlannedLine(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, ConvertApprovedTicketPlannedLine $action)
    {
        $entry = $action->handle($ticket, $plannedLine, $request->user());

        return response()->json(['data' => $entry->toArray()]);
    }

    public function requestPurchase(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, RequestTicketPurchase $action)
    {
        $line = $action->handle($ticket, $plannedLine, $request->user());

        return response()->json(['data' => $line->load('purchaseOrder')->toArray()], 201);
    }

    public function createQuote(Request $request, Ticket $ticket, EnsureTicketSalesQuote $action)
    {
        return response()->json(['data' => $this->quote($action->handle($ticket, $request->user()))], 201);
    }

    public function sendQuote(Request $request, Ticket $ticket, SendTicketSalesQuote $action)
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'reply_contact_id' => ['nullable', 'integer', 'exists:client_users,id'],
            'cc' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->quote($action->handle($ticket, $data, $request->user()))]);
    }

    public function acceptQuoteFromMessage(Request $request, Ticket $ticket, TicketMessage $message, SalesQuoteVersion $version, AcceptTicketQuoteFromMessage $action)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $version->loadMissing('quote');

        return response()->json(['data' => $this->quote($action->handle($ticket, $message, $version, $data, $request->user()))]);
    }

    public function requestReview(Request $request, Ticket $ticket, RequestTicketWorkflowReview $action)
    {
        $data = $request->validate([
            'gate_key' => ['nullable', 'string', 'max:100'],
            'assigned_reviewer_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'separation_of_duties' => ['nullable', 'boolean'],
        ]);

        return response()->json(['data' => $action->handle($ticket, $data, $request->user())->toArray()], 201);
    }

    public function decideReview(Request $request, Ticket $ticket, TicketWorkflowReview $review, DecideTicketWorkflowReview $action)
    {
        abort_unless((int) $review->ticket_id === (int) $ticket->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'sent_back'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $action->handle($review, $data['decision'], $data['comment'] ?? null, $request->user())->toArray()]);
    }

    public function classifyEvidence(Request $request, Ticket $ticket, ClassifyTicketWorkflowEvidence $action)
    {
        $data = $request->validate([
            'evidence_type' => ['required', Rule::in(['customer_response', 'signature', 'manual_approval'])],
            'source_type' => ['required', Rule::in(['message', 'attachment'])],
            'source_id' => ['required', 'integer'],
            'scope_key' => ['nullable', 'string', 'max:255'],
            'subject_name' => ['nullable', 'string', 'max:255'],
            'evidenced_at' => ['nullable', 'date'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $action->handle($ticket, $data, $request->user())->toArray()], 201);
    }

    private function plannedLineData(Request $request): array
    {
        return $request->validate([
            'storage_item_id' => ['nullable', 'integer', 'exists:storage_items,id'],
            'line_type' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:100'],
            'downstream_type' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'name' => ['required_without:storage_item_id', 'nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'quantity' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'unit' => ['nullable', 'string', 'max:50'],
            'unit_cost_ex_vat' => ['nullable', 'numeric', 'min:0'],
            'unit_price_ex_vat' => ['nullable', 'numeric', 'min:0'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);
    }

    private function load(Ticket $ticket): Ticket
    {
        return $ticket->refresh()->load(['queue', 'status', 'priority', 'client', 'site', 'contact', 'owner', 'asset', 'workflow', 'workflowVersion']);
    }

    private function line(TicketPlannedLine $line): array
    {
        return $line->load(['storageItem', 'approvedQuoteVersion', 'convertedCostEntry'])->toArray();
    }

    private function quote(SalesQuoteVersion $version): array
    {
        return $version->load(['quote', 'lines'])->toArray();
    }
}
