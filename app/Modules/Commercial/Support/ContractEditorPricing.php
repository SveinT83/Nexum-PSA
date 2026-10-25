<?php

namespace App\Modules\Commercial\Support;

use InvalidArgumentException;
use OverflowException;

/** Pure existing staff-editor economics, distinct from the immutable customer document. */
class ContractEditorPricing
{
    public function __construct(public array $items = []) {}

    public function calculateLineTotals($index)
    {
        $item = $this->items[$index] ?? null;
        if (! $item) {
            return [
                'total' => '0,00 kr',
                'discount_total' => 0,
                'total_numeric' => 0,
            ];
        }

        try {
            $line = app(ContractPricing::class)->calculateLine($item);
        } catch (InvalidArgumentException|OverflowException) {
            // Livewire retains invalid public input after validation fails. Do
            // not let the totals preview turn that useful validation response
            // into a second rendering exception.
            return [
                'total' => '—',
                'discount_total' => 0,
                'total_numeric' => 0,
            ];
        }

        return [
            'total' => $line['included'] ? 'Inkludert' : $line['line_total']['display'],
            'discount_total' => $line['discount']['minor'],
            'total_numeric' => $line['line_total']['decimal'],
            'line' => $line,
        ];
    }

    public function calculateBillingTotals(): array
    {
        try {
            return app(ContractPricing::class)->calculateTotals($this->items);
        } catch (InvalidArgumentException|OverflowException) {
            return [];
        }
    }

    public function calculateTotalCost()
    {
        $totalCost = 0;
        foreach ($this->items as $item) {
            $totalCost += (float) ($item['item_cost'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }

        return number_format($totalCost, 2, ',', ' ').' kr';
    }

    public function calculateTotalDiscount()
    {
        $totalDiscountMinor = 0;
        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $totalDiscountMinor += $totals['discount_total'] ?? 0;
        }

        return app(ContractPricing::class)->formatMinor($totalDiscountMinor);
    }

    public function calculateAnnualProfit()
    {
        $annualProfit = 0;

        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $revenuePerPeriod = $totals['total_numeric'] ?? 0;
            $costPerPeriod = (float) ($item['item_cost'] ?? 0) * (int) ($item['quantity'] ?? 1);

            $billingInterval = $item['billing_interval'] ?? 'monthly';
            $multiplier = 0;

            switch ($billingInterval) {
                case 'monthly':
                    $multiplier = 12;
                    break;
                case 'quarterly':
                    $multiplier = 4;
                    break;
                case 'yearly':
                    $multiplier = 1;
                    break;
                case 'one_time':
                default:
                    $multiplier = 0; // One-time charges do not contribute to recurring annual profit.
                    break;
            }

            $annualProfit += ($revenuePerPeriod - $costPerPeriod) * $multiplier;
        }

        return number_format($annualProfit, 2, ',', ' ').' kr';
    }

    public function summary(): array
    {
        return ['total_cost_display' => $this->calculateTotalCost(), 'total_discount_display' => $this->calculateTotalDiscount(), 'annual_profit_display' => $this->calculateAnnualProfit()];
    }
}
