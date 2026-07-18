<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Actions\EnsureSalesQuoteDraft;
use App\Modules\Sales\Actions\RecalculateSalesQuoteVersion;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketSalesContext;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnsureTicketSalesQuote
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly StoreSalesOpportunity $storeOpportunity,
        private readonly EnsureSalesQuoteDraft $ensureDraft,
        private readonly RecalculateSalesQuoteVersion $recalculate,
    ) {}

    public function handle(Ticket $ticket, User $actor): SalesQuoteVersion
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::CREATE_QUOTE, $actor)) {
            throw ValidationException::withMessages(['quote' => $reason]);
        }

        if (! $ticket->client_id) {
            throw ValidationException::withMessages(['quote' => 'Link a client before creating a quote.']);
        }

        if (! $ticket->plannedLines()->whereIn('status', ['planned', 'quoted'])->exists()) {
            throw ValidationException::withMessages(['quote' => 'Add at least one planned cost before creating a quote.']);
        }

        $version = DB::transaction(function () use ($ticket, $actor): SalesQuoteVersion {
            $context = TicketSalesContext::query()->with('opportunity.currentQuoteVersion')->where('ticket_id', $ticket->id)->first();

            if (! $context) {
                $opportunity = $this->storeOpportunity->handle([
                    'client_id' => $ticket->client_id,
                    'primary_contact_id' => $ticket->contact_id,
                    'owner_id' => $ticket->owner_id ?: $actor->id,
                    'title' => $ticket->subject,
                    'type' => 'ticket_service_quote',
                    'status' => 'qualified',
                    'summary' => 'Created from Ticket '.$ticket->ticket_key.'.',
                    'needs' => $ticket->description,
                    'metadata' => [
                        'ticket_id' => $ticket->id,
                        'ticket_key' => $ticket->ticket_key,
                        'origin' => 'ticket',
                    ],
                ], $actor);

                $context = TicketSalesContext::query()->create([
                    'ticket_id' => $ticket->id,
                    'opportunity_id' => $opportunity->id,
                    'created_by' => $actor->id,
                ]);
            } else {
                $opportunity = $context->opportunity;
            }

            $version = $this->ensureDraft->handle($opportunity, $actor);
            $existingPlannedIds = $version->lines()->get()
                ->map(fn ($line) => (int) data_get($line->snapshot, 'ticket_planned_line_id'))
                ->filter()->all();

            foreach ($ticket->plannedLines()->whereIn('status', ['planned', 'quoted'])->orderBy('id')->get() as $planned) {
                if (in_array($planned->id, $existingPlannedIds, true)) {
                    continue;
                }

                SalesQuoteLine::query()->create([
                    'quote_version_id' => $version->id,
                    'section' => $planned->section,
                    'sort_order' => $version->lines()->count() * 10 + 10,
                    'source_type' => $planned->source_type,
                    'source_id' => $planned->source_id,
                    'downstream_type' => $planned->downstream_type,
                    'is_optional' => false,
                    'sku' => $planned->sku,
                    'name' => $planned->name,
                    'description' => $planned->description,
                    'quantity' => $planned->quantity,
                    'unit' => $planned->unit,
                    'unit_cost_ex_vat' => $planned->unit_cost_ex_vat,
                    'unit_price_ex_vat' => $planned->unit_price_ex_vat,
                    'discount_value' => 0,
                    'discount_type' => 'amount',
                    'vat_rate' => $planned->vat_rate,
                    'snapshot' => array_merge($planned->snapshot ?: [], ['ticket_planned_line_id' => $planned->id]),
                ]);

                $planned->forceFill(['status' => 'quoted', 'updated_by' => $actor->id])->save();
            }

            $this->recalculate->handle($version);
            $context->forceFill(['quote_id' => $version->quote_id])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor->id,
                'type' => 'ticket_quote_prepared',
                'subject' => 'Ticket quote prepared',
                'body' => 'Quote prepared from Ticket '.$ticket->ticket_key.'.',
                'metadata' => ['ticket_id' => $ticket->id, 'quote_version_id' => $version->id],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'sales_quote_prepared',
                'message' => 'Sales quote prepared from planned Ticket scope.',
                'after' => ['opportunity_id' => $opportunity->id, 'quote_version_id' => $version->id],
            ]);

            return $version->refresh();
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::CREATE_QUOTE, $actor);

        return $version;
    }
}
