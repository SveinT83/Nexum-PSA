<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Collection;

class TicketTimeRateOptions
{
    /*
    |--------------------------------------------------------------------------
    | Ticket time rate selection
    |--------------------------------------------------------------------------
    |
    | Time entries must keep a billing snapshot, but the source rate can come
    | from an accepted client contract or from global rates that are allowed
    | when no contract applies. This query returns normalized option payloads
    | for both sources so UI and actions use the same rate rules.
    |
    */
    public function forTicket(Ticket $ticket): Collection
    {
        return $this->contractRates($ticket)
            ->concat($this->withoutContractRates())
            ->unique('key')
            ->values();
    }

    public function findForTicket(Ticket $ticket, string $key): ?array
    {
        return $this->forTicket($ticket)->firstWhere('key', $key);
    }

    private function contractRates(Ticket $ticket): Collection
    {
        if (! $ticket->client_id) {
            return collect();
        }

        $today = now()->toDateString();

        return Contracts::query()
            ->with(['items.timeRates' => fn ($query) => $query->where('is_active', true)])
            ->where('client_id', $ticket->client_id)
            ->where('approval_status', 'won')
            ->where(function ($query) use ($today) {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', $today);
            })
            ->where(function ($query) use ($today) {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', $today);
            })
            ->latest('accepted_at')
            ->latest('id')
            ->get()
            ->flatMap(function (Contracts $contract) {
                return $contract->items->flatMap(function ($item) use ($contract) {
                    return $item->timeRates->map(fn (ContractItemTimeRate $rate) => [
                        'key' => 'contract:' . $rate->id,
                        'source' => 'contract',
                        'label' => trim($item->name . ' - ' . $rate->name),
                        'description' => 'Contract #' . $contract->id . ' / ' . number_format((float) $rate->amount_ex_vat, 2, '.', ' ') . ' ' . $rate->currency . ' per ' . $rate->unit,
                        'contract_id' => $contract->id,
                        'contract_item_id' => $item->id,
                        'contract_item_time_rate_id' => $rate->id,
                        'time_rate_id' => $rate->time_rate_id,
                        'rate_name' => $rate->name,
                        'rate_code' => $rate->code,
                        'rate_type' => $rate->rate_type,
                        'rate_unit' => $rate->unit,
                        'rate_amount_ex_vat' => $rate->amount_ex_vat,
                        'rate_currency' => $rate->currency,
                        'billing_basis' => 'contract',
                    ]);
                });
            })
            ->values();
    }

    private function withoutContractRates(): Collection
    {
        return TimeRate::query()
            ->where('is_active', true)
            ->where('applies_without_contract', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TimeRate $rate) => [
                'key' => 'global:' . $rate->id,
                'source' => 'global',
                'label' => $rate->name,
                'description' => number_format((float) $rate->amount_ex_vat, 2, '.', ' ') . ' ' . $rate->currency . ' per ' . $rate->unit,
                'contract_id' => null,
                'contract_item_id' => null,
                'contract_item_time_rate_id' => null,
                'time_rate_id' => $rate->id,
                'rate_name' => $rate->name,
                'rate_code' => $rate->code,
                'rate_type' => $rate->rate_type,
                'rate_unit' => $rate->unit,
                'rate_amount_ex_vat' => $rate->amount_ex_vat,
                'rate_currency' => $rate->currency,
                'billing_basis' => 'without_contract',
            ])
            ->values();
    }
}
