<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Support\Facades\DB;

class UpdateTicketTimeEntry
{
    public function handle(Ticket $ticket, TicketTimeEntry $entry, array $data, array $rateOption, ?User $actor = null): TicketTimeEntry
    {
        return DB::transaction(function () use ($ticket, $entry, $data, $rateOption, $actor) {
            $before = $entry->only([
                'work_date',
                'minutes',
                'rate_name',
                'invoice_text',
                'note',
            ]);

            $entry->forceFill([
                'work_date' => $data['work_date'],
                'minutes' => $data['minutes'],
                'cost_account' => $rateOption['rate_code'] ?? null,
                'note' => $data['note'] ?? null,
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
            ])->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'time_entry_updated',
                'message' => 'Ticket time entry updated.',
                'before' => $before,
                'after' => [
                    'time_entry_id' => $entry->id,
                    'work_date' => $entry->work_date?->toDateString(),
                    'minutes' => $entry->minutes,
                    'rate_name' => $entry->rate_name,
                    'invoice_text' => $entry->invoice_text,
                    'note' => $entry->note,
                ],
            ]);

            return $entry->refresh();
        });
    }
}
