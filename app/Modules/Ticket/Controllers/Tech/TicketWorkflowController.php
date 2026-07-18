<?php

namespace App\Modules\Ticket\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Actions\AcceptTicketQuoteFromMessage;
use App\Modules\Ticket\Actions\ClassifyTicketWorkflowEvidence;
use App\Modules\Ticket\Actions\ConvertApprovedTicketPlannedLine;
use App\Modules\Ticket\Actions\DecideTicketWorkflowReview;
use App\Modules\Ticket\Actions\DeleteTicketPlannedLine;
use App\Modules\Ticket\Actions\EnsureTicketSalesQuote;
use App\Modules\Ticket\Actions\EscalateTicketWorkflow;
use App\Modules\Ticket\Actions\RecordTicketTimerStarted;
use App\Modules\Ticket\Actions\RequestTicketPurchase;
use App\Modules\Ticket\Actions\RequestTicketWorkflowReview;
use App\Modules\Ticket\Actions\SendTicketSalesQuote;
use App\Modules\Ticket\Actions\StoreTicketPlannedLine;
use App\Modules\Ticket\Actions\TransitionTicketWorkflow;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketWorkflowController extends Controller
{
    public function startTimer(Request $request, Ticket $ticket, RecordTicketTimerStarted $action): JsonResponse
    {
        $result = $action->handle($ticket, $request->user());

        return response()->json(['data' => [
            'ticket_key' => $result['ticket']->ticket_key,
            'status_id' => $result['ticket']->status_id,
            'workflow_state_key' => $result['ticket']->workflow_state_key,
            'transitioned' => $result['transitioned'],
        ]]);
    }

    public function transition(Request $request, Ticket $ticket, string $transitionKey, TransitionTicketWorkflow $action): RedirectResponse
    {
        $data = $request->validate(['idempotency_key' => ['nullable', 'string', 'max:100']]);
        $action->handle($ticket, $transitionKey, $request->user(), $data['idempotency_key'] ?? null);

        return $this->back($ticket, 'Workflow step completed.');
    }

    public function escalate(Request $request, Ticket $ticket, string $pathKey, EscalateTicketWorkflow $action): RedirectResponse
    {
        $data = $request->validate([
            'owner_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'reason' => ['nullable', 'string', 'max:2000'],
            'allow_repeat' => ['nullable', 'boolean'],
        ]);
        $action->handle($ticket, $pathKey, $data, $request->user());

        return $this->back($ticket, 'Ticket escalated to the selected workflow.');
    }

    public function storePlannedLine(Request $request, Ticket $ticket, StoreTicketPlannedLine $action): RedirectResponse
    {
        $action->handle($ticket, $this->plannedLineData($request), $request->user());

        return $this->back($ticket, 'Planned cost added. No stock or billing record was created.');
    }

    public function destroyPlannedLine(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, DeleteTicketPlannedLine $action): RedirectResponse
    {
        $action->handle($ticket, $plannedLine, $request->user());

        return $this->back($ticket, 'Planned cost removed.');
    }

    public function createQuote(Request $request, Ticket $ticket, EnsureTicketSalesQuote $action): RedirectResponse
    {
        $action->handle($ticket, $request->user());

        return $this->back($ticket, 'Sales quote prepared from the Ticket scope.');
    }

    public function sendQuote(Request $request, Ticket $ticket, SendTicketSalesQuote $action): RedirectResponse
    {
        $data = $request->validate([
            'subject' => ['nullable', 'string', 'max:255'],
            'body' => ['nullable', 'string', 'max:10000'],
            'reply_contact_id' => ['nullable', 'integer', 'exists:client_users,id'],
            'cc' => ['nullable', 'string', 'max:1000'],
        ]);
        $action->handle($ticket, $data, $request->user());

        return $this->back($ticket, 'Quote sent as a Ticket reply with PDF and acceptance link.');
    }

    public function acceptQuoteFromMessage(
        Request $request,
        Ticket $ticket,
        TicketMessage $message,
        SalesQuoteVersion $version,
        AcceptTicketQuoteFromMessage $action,
    ): RedirectResponse {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $version->loadMissing('quote');
        $action->handle($ticket, $message, $version, $data, $request->user());

        return $this->back($ticket, 'Customer acceptance recorded on the current quote version.');
    }

    public function requestReview(Request $request, Ticket $ticket, RequestTicketWorkflowReview $action): RedirectResponse
    {
        $data = $request->validate([
            'gate_key' => ['nullable', 'string', 'max:100'],
            'assigned_reviewer_id' => ['nullable', 'integer', 'exists:user_management,id'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'separation_of_duties' => ['nullable', 'boolean'],
        ]);
        $action->handle($ticket, $data, $request->user());

        return $this->back($ticket, 'Senior review requested.');
    }

    public function decideReview(Request $request, Ticket $ticket, TicketWorkflowReview $review, DecideTicketWorkflowReview $action): RedirectResponse
    {
        abort_unless((int) $review->ticket_id === (int) $ticket->id, 404);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'sent_back'])],
            'comment' => ['nullable', 'string', 'max:2000'],
        ]);
        $action->handle($review, $data['decision'], $data['comment'] ?? null, $request->user());

        return $this->back($ticket, $data['decision'] === 'approved' ? 'Senior review approved.' : 'Ticket sent back with review feedback.');
    }

    public function classifyEvidence(Request $request, Ticket $ticket, ClassifyTicketWorkflowEvidence $action): RedirectResponse
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
        $action->handle($ticket, $data, $request->user());

        return $this->back($ticket, 'Evidence classified for the workflow.');
    }

    public function convertPlannedLine(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, ConvertApprovedTicketPlannedLine $action): RedirectResponse
    {
        $action->handle($ticket, $plannedLine, $request->user());

        return $this->back($ticket, 'Approved scope converted to an actual Ticket cost.');
    }

    public function requestPurchase(Request $request, Ticket $ticket, TicketPlannedLine $plannedLine, RequestTicketPurchase $action): RedirectResponse
    {
        $action->handle($ticket, $plannedLine, $request->user());

        return $this->back($ticket, 'Draft purchase need created. No vendor order was sent.');
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

    private function back(Ticket $ticket, string $message): RedirectResponse
    {
        return redirect()->route('tech.tickets.show', $ticket->refresh())->with('success', $message);
    }
}
