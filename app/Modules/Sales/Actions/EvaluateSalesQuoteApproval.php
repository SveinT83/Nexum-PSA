<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;

class EvaluateSalesQuoteApproval
{
    public const DEFAULT_POLICY = [
        'enabled' => true,
        'discount_percent_threshold' => 20,
        'minimum_margin_percent' => 10,
        'quote_total_ex_vat_threshold' => 100000,
        'manual_line_ex_vat_threshold' => 50000,
    ];

    public function handle(SalesQuoteVersion $version): array
    {
        $version->loadMissing('lines');
        $policy = array_merge(self::DEFAULT_POLICY, (array) SalesSetting::get('cpq_approval_policy', []));

        if (! (bool) ($policy['enabled'] ?? true)) {
            return ['required' => false, 'reasons' => [], 'policy' => $policy];
        }

        $reasons = [];

        $discountThreshold = (float) ($policy['discount_percent_threshold'] ?? 0);
        if ($discountThreshold > 0) {
            foreach ($version->lines as $line) {
                if ($line->discount_type === 'percent' && (float) $line->discount_value > $discountThreshold) {
                    $reasons[] = 'Discount above '.$discountThreshold.'% on '.$line->name.'.';
                }
            }
        }

        $marginThreshold = (float) ($policy['minimum_margin_percent'] ?? 0);
        if ($marginThreshold > 0 && (float) $version->margin_percent < $marginThreshold) {
            $reasons[] = 'Quote margin below '.$marginThreshold.'%.';
        }

        $totalThreshold = (float) ($policy['quote_total_ex_vat_threshold'] ?? 0);
        if ($totalThreshold > 0 && (float) $version->total_ex_vat > $totalThreshold) {
            $reasons[] = 'Quote total above '.number_format($totalThreshold, 2, '.', ' ').' NOK ex VAT.';
        }

        $manualThreshold = (float) ($policy['manual_line_ex_vat_threshold'] ?? 0);
        if ($manualThreshold > 0) {
            $version->lines
                ->filter(fn (SalesQuoteLine $line): bool => $line->source_type === 'custom' && (float) $line->line_total_ex_vat > $manualThreshold)
                ->each(fn (SalesQuoteLine $line) => $reasons[] = 'Manual line above '.number_format($manualThreshold, 2, '.', ' ').' NOK: '.$line->name.'.');
        }

        return [
            'required' => $reasons !== [],
            'reasons' => array_values(array_unique($reasons)),
            'policy' => $policy,
        ];
    }
}
