<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesSetting;

/** Shared persisted approval-policy contract; no actor/role grants or hidden initialization. */
class SalesApprovalPolicy
{
    public function handle(array $data): SalesSetting
    {
        return SalesSetting::query()->updateOrCreate(['key' => 'cpq_approval_policy'], ['value' => [
            'enabled' => (bool) ($data['enabled'] ?? false), 'discount_percent_threshold' => (float) $data['discount_percent_threshold'],
            'minimum_margin_percent' => (float) $data['minimum_margin_percent'], 'quote_total_ex_vat_threshold' => (float) $data['quote_total_ex_vat_threshold'],
            'manual_line_ex_vat_threshold' => (float) $data['manual_line_ex_vat_threshold'],
        ]]);
    }
}
