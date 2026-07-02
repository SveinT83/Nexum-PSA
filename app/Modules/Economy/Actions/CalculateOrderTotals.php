<?php

namespace App\Modules\Economy\Actions;

use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;

class CalculateOrderTotals
{
    public function __construct(private readonly EnsureEconomyDefaults $defaults)
    {
    }

    /**
     * Calculate display totals from active lines, including the current default VAT fallback.
     */
    public function forOrder(EconomyOrder $order): array
    {
        $defaultVatRate = $this->defaultVatRate();
        $lines = $order->relationLoaded('lines') ? $order->lines : $order->lines()->get();
        $lineTotals = [];
        $subtotal = 0.0;
        $vat = 0.0;
        $total = 0.0;

        foreach ($lines->where('status', 'active') as $line) {
            $calculated = $this->forLine($line, $defaultVatRate);
            $lineTotals[$line->id] = $calculated;
            $subtotal += $calculated['line_total_ex_vat'];
            $vat += $calculated['vat_amount'] ?? 0;
            $total += $calculated['total_inc_vat'];
        }

        return [
            'subtotal_ex_vat' => round($subtotal, 2),
            'vat_amount' => round($vat, 2),
            'total_inc_vat' => round($total, 2),
            'lines' => $lineTotals,
        ];
    }

    public function forOrders(iterable $orders): array
    {
        $totals = [];

        foreach ($orders as $order) {
            $totals[$order->id] = $this->forOrder($order);
        }

        return $totals;
    }

    private function forLine(EconomyOrderLine $line, ?float $defaultVatRate): array
    {
        $lineTotal = round((float) $line->line_total_ex_vat, 2);
        $vatRate = $line->vat_rate !== null ? (float) $line->vat_rate : $defaultVatRate;
        $vatAmount = $vatRate === null ? null : round($lineTotal * ($vatRate / 100), 2);

        return [
            'line_total_ex_vat' => $lineTotal,
            'vat_rate' => $vatRate,
            'vat_amount' => $vatAmount,
            'total_inc_vat' => round($lineTotal + ($vatAmount ?? 0), 2),
        ];
    }

    private function defaultVatRate(): ?float
    {
        $rate = $this->defaults->handle()->default_vat_rate;

        return $rate === null ? null : (float) $rate;
    }
}
