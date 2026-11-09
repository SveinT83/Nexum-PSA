<?php

namespace App\Modules\Economy\Actions;

use App\Modules\Commercial\Models\TimebankCorrection;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Support\EconomyMutex;

/** Retain original draft lines and source evidence while removing their commercial value. */
class ReverseCustomerTimeCharge
{
    public function handle(TimebankCorrection $correction): void
    {
        app(EconomyMutex::class)->run(function () use ($correction): void {
            $ids = $correction->snapshot['result']['draft_line_ids'] ?? [];
            foreach (EconomyOrderLine::with('order')->whereIn('id', $ids)->lockForUpdate()->get() as $line) {
                abort_unless($line->order?->status === 'draft' && $line->status === 'active'
                    && (int) $line->client_id === (int) $correction->client_id, 409, 'Financial correction required.');
                $line->forceFill(['status' => 'reversed', 'metadata' => array_merge($line->metadata ?? [],
                    ['timebank_correction_id' => $correction->id])])->save();
                $totals = app(CalculateOrderTotals::class)->forOrder($line->order->fresh());
                unset($totals['lines']);
                $line->order->forceFill($totals)->save();
            }
        }, false);
    }
}
