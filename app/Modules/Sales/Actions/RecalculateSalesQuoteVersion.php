<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesQuoteVersion;

class RecalculateSalesQuoteVersion
{
    public function handle(SalesQuoteVersion $version): SalesQuoteVersion
    {
        $version->load('lines');

        $subtotal = 0.0;
        $vat = 0.0;
        $inc = 0.0;
        $margin = 0.0;

        foreach ($version->lines as $line) {
            $base = (float) $line->unit_price_ex_vat * (float) $line->quantity;
            $discount = $line->discount_type === 'percent'
                ? $base * ((float) $line->discount_value / 100)
                : (float) $line->discount_value;
            $lineTotal = max(0, $base - $discount);
            $vatAmount = $line->vat_rate !== null ? $lineTotal * ((float) $line->vat_rate / 100) : 0;
            $lineMargin = $lineTotal - ((float) $line->unit_cost_ex_vat * (float) $line->quantity);

            $line->forceFill([
                'line_total_ex_vat' => round($lineTotal, 2),
                'vat_amount' => round($vatAmount, 2),
                'line_total_inc_vat' => round($lineTotal + $vatAmount, 2),
                'margin_amount' => round($lineMargin, 2),
                'margin_percent' => $lineTotal > 0 ? round(($lineMargin / $lineTotal) * 100, 2) : 0,
            ])->save();

            $subtotal += $lineTotal;
            $vat += $vatAmount;
            $inc += $lineTotal + $vatAmount;
            $margin += $lineMargin;
        }

        $version->forceFill([
            'subtotal_ex_vat' => round($subtotal, 2),
            'vat_total' => round($vat, 2),
            'total_ex_vat' => round($subtotal, 2),
            'total_inc_vat' => round($inc, 2),
            'margin_amount' => round($margin, 2),
            'margin_percent' => $subtotal > 0 ? round(($margin / $subtotal) * 100, 2) : 0,
        ])->save();

        $opportunity = $version->quote->opportunity;
        $opportunity->forceFill([
            'estimated_value_ex_vat' => $version->total_ex_vat,
            'weighted_value_ex_vat' => round((float) $version->total_ex_vat * ((int) $opportunity->probability_percent / 100), 2),
            'current_quote_version_id' => $version->id,
        ])->save();

        return $version->refresh();
    }
}
