<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterTicketTimeEntry
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    /*
    |--------------------------------------------------------------------------
    | Time registration is billable intent, not immediate billing
    |--------------------------------------------------------------------------
    |
    | The entry records technician time and the selected rate snapshot. It does
    | not consume contract minutes or create invoice lines yet. Billing and
    | timebank settlement can later process entries with pending statuses.
    |
    */
    public function handle(Ticket $ticket, array $data, array $rateOption, ?User $actor = null): TicketTimeEntry
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::REGISTER_TIME, $actor)) {
            throw ValidationException::withMessages(['time_entry' => $reason]);
        }

        $entry = DB::transaction(function () use ($ticket, $data, $rateOption, $actor) {
            $entry = TicketTimeEntry::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor?->id,
                'type' => 'manual',
                'work_date' => $data['work_date'],
                'minutes' => $data['minutes'],
                'cost_account' => $rateOption['rate_code'] ?? null,
                'note' => $data['note'] ?? null,
                'billable' => true,
                'billing_status' => 'pending',
                'timebank_status' => 'pending',
                'billing_basis' => $rateOption['billing_basis'] ?? null,
                'invoice_text' => $data['invoice_text'],
                'contract_id' => $rateOption['contract_id'] ?? null,
                'contract_item_id' => $rateOption['contract_item_id'] ?? null,
                'contract_item_time_rate_id' => $rateOption['contract_item_time_rate_id'] ?? null,
                'time_rate_id' => $rateOption['time_rate_id'] ?? null,
                'rate_name' => $rateOption['rate_name'] ?? null,
                'rate_code' => $rateOption['rate_code'] ?? null,
                'rate_type' => $rateOption['rate_type'] ?? null,
                'rate_unit' => $rateOption['rate_unit'] ?? null,
                'rate_amount_ex_vat' => $rateOption['rate_amount_ex_vat'] ?? null,
                'rate_currency' => $rateOption['rate_currency'] ?? 'NOK',
            ]);

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'time_entry_added',
                'message' => 'Time registered on ticket.',
                'after' => [
                    'time_entry_id' => $entry->id,
                    'minutes' => $entry->minutes,
                    'rate_name' => $entry->rate_name,
                    'billing_status' => $entry->billing_status,
                    'timebank_status' => $entry->timebank_status,
                ],
            ]);

            app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'time_entry_added');

            return $entry;
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::REGISTER_TIME, $actor);

        return $entry;
    }
}
