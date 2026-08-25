<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractPricing;

/**
 * Keep the sortable monthly summary aligned with the canonical pricing result.
 *
 * The immutable customer document is cleared only while a contract is editable.
 * Operational amendments to an accepted contract may update live rows, but they
 * must never rewrite the customer document that was accepted.
 */
class SyncContractPricingSummary
{
    public function __construct(private readonly ContractPricing $pricing) {}

    public function handle(Contracts|int $contract): void
    {
        $contract = $contract instanceof Contracts
            ? $contract
            : Contracts::query()->find($contract);

        if (! $contract) {
            return;
        }

        $contract->loadMissing('items');
        $totals = $this->pricing->calculateTotals($contract->items);
        $updates = [
            'total_monthly_amount' => $totals['monthly']['decimal'],
        ];

        if ($contract->isEditable() && $contract->customer_document_snapshot !== null) {
            $updates['customer_document_snapshot'] = null;
        }

        $contract->forceFill($updates)->saveQuietly();
    }
}
