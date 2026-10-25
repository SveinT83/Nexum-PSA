<?php

namespace App\Modules\Economy\Actions;

use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntryAllocation;
use Illuminate\Support\Facades\DB;

class DeleteOrderLine
{
    /*
    |--------------------------------------------------------------------------
    | Remove generated line and unlock source record
    |--------------------------------------------------------------------------
    |
    | Deleting an internal order line is an editing workflow. The source ticket
    | time/cost record is put back to pending so manual Generate orders can
    | calculate it again after the technician has corrected the source data.
    |
    */
    public function handle(EconomyOrder $order, EconomyOrderLine $line): void
    {
        app(\App\Modules\Economy\Support\EconomyMutex::class)->run(function () use ($order, $line): void {
            $order = EconomyOrder::query()->lockForUpdate()->findOrFail($order->id);
            $line = EconomyOrderLine::query()->where('economy_order_id', $order->id)->lockForUpdate()->findOrFail($line->id);
            abort_unless((int) $line->economy_order_id === (int) $order->id, 404);
            abort_unless($order->status === 'draft', 422);

            DB::transaction(function () use ($order, $line): void {
                abort_unless((int) $line->client_id === (int) $order->client_id, 409, 'Line financial ownership does not match its order.');
                if ($line->source_type && $line->source_id) {
                    $financialClient = \App\Modules\Economy\Support\EconomySourceOwner::client($line->source_type, $line->source_id, $line->client_id);
                    abort_unless($financialClient === (int) $line->client_id, 409, 'Recorded source ownership changed.');
                }
                $this->unlockSource($line);
                $line->delete();
                $this->recalculate($order->refresh());
            });
        });
    }

    private function unlockSource(EconomyOrderLine $line): void
    {
        if ($line->source_type === (new TicketTimeEntry)->getMorphClass()) {
            TicketTimeEntry::query()
                ->whereKey($line->source_id)
                ->update([
                    'billing_status' => 'pending',
                    'timebank_status' => 'pending',
                ]);

            $entry = TicketTimeEntry::query()->with('ticket')->find($line->source_id);
            if ($entry && (int) $entry->ticket?->client_id !== (int) $line->client_id) {
                TicketTimeEntryAllocation::query()->where('ticket_time_entry_id', $line->source_id)
                    ->update(['economy_order_line_id' => null]);
            } else {
                TicketTimeEntryAllocation::query()->where('ticket_time_entry_id', $line->source_id)->delete();
            }
        }

        // Quick overuse has no queued flag: its durable source key becomes eligible when this line is removed.
        if ($line->source_type === (new \App\Modules\Integration\Models\CloudFactory\BillingPeriod)->getMorphClass()) {
            \App\Modules\Integration\Models\CloudFactory\BillingPeriod::query()
                ->whereKey($line->source_id)->where('client_id', $line->client_id)
                ->where('status', 'queued')->update(['status' => 'confirmed']);
        }

        if ($line->source_type === (new TicketCostEntry)->getMorphClass()) {
            TicketCostEntry::query()
                ->whereKey($line->source_id)
                ->update(['billing_status' => 'pending']);
        }
    }

    private function recalculate(EconomyOrder $order): void
    {
        $lines = $order->lines()->where('status', 'active')->get();
        $order->forceFill([
            'subtotal_ex_vat' => round((float) $lines->sum('line_total_ex_vat'), 2),
            'vat_amount' => round((float) $lines->sum(fn (EconomyOrderLine $line) => (float) ($line->vat_amount ?? 0)), 2),
            'total_inc_vat' => round((float) $lines->sum('total_inc_vat'), 2),
        ])->save();
    }
}
