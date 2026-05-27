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
        abort_unless((int) $line->economy_order_id === (int) $order->id, 404);
        abort_unless($order->status === 'draft', 422);

        DB::transaction(function () use ($order, $line): void {
            $this->unlockSource($line);
            $line->delete();
            $this->recalculate($order->refresh());
        });
    }

    private function unlockSource(EconomyOrderLine $line): void
    {
        if ($line->source_type === (new TicketTimeEntry())->getMorphClass()) {
            TicketTimeEntry::query()
                ->whereKey($line->source_id)
                ->update([
                    'billing_status' => 'pending',
                    'timebank_status' => 'pending',
                ]);

            TicketTimeEntryAllocation::query()
                ->where('ticket_time_entry_id', $line->source_id)
                ->delete();
        }

        if ($line->source_type === (new TicketCostEntry())->getMorphClass()) {
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
