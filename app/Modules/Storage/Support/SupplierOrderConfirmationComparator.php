<?php

namespace App\Modules\Storage\Support;

use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;

/**
 * Compares immutable supplier evidence with an existing manual Purchase Order.
 *
 * Missing source facts are wildcards. Explicit source facts must be
 * reproducible from the manual order or the import fails closed for review.
 */
final class SupplierOrderConfirmationComparator
{
    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    public function differences(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderImport $import,
        array $document,
        PurchaseOrderAutomationPolicy $policy,
    ): array {
        $differences = [];
        $tolerance = $this->decimal($policy->amount_tolerance) ?? BigDecimal::of('0.01');

        if ((int) $purchaseOrder->deliver_to_warehouse_id
            !== (int) data_get($document, 'destination_warehouse_id')) {
            $differences[] = 'destination_warehouse_differs';
        }
        if (strtoupper((string) $purchaseOrder->currency)
            !== strtoupper((string) data_get($document, 'currency', 'NOK'))) {
            $differences[] = 'currency_differs';
        }

        $sourceOrderedAt = data_get($document, 'ordered_at');
        if (filled($sourceOrderedAt)
            && data_get($document, 'ordered_at_provenance') === 'explicit') {
            if ($purchaseOrder->ordered_at === null) {
                $differences[] = 'ordered_date_missing';
            } elseif ($purchaseOrder->ordered_at->toDateString()
                !== CarbonImmutable::parse((string) $sourceOrderedAt)->toDateString()) {
                $differences[] = 'ordered_date_differs';
            }
        }

        [$lineDifferences, $purchaseGoodsSubtotal] = $this->compareLines(
            $purchaseOrder,
            $import,
            $tolerance,
        );
        $differences = array_merge($differences, $lineDifferences);
        $differences = array_merge(
            $differences,
            $this->compareCommercialTotals(
                $purchaseOrder,
                $document,
                $purchaseGoodsSubtotal,
                $this->purchaseTaxTotal($purchaseOrder),
                $tolerance,
            ),
        );

        return array_slice(array_values(array_unique($differences)), 0, 30);
    }

    /**
     * @return array{0: list<string>, 1: ?BigDecimal}
     */
    private function compareLines(
        PurchaseOrder $purchaseOrder,
        PurchaseOrderImport $import,
        BigDecimal $tolerance,
    ): array {
        $purchaseLines = $purchaseOrder->lines->values();
        $sourceLines = $import->lines->values();
        $differences = [];

        if ($purchaseLines->count() !== $sourceLines->count()) {
            $differences[] = 'line_count_differs';
        }

        $edges = [];
        $fallbackDifferences = [];
        foreach ($sourceLines as $sourceIndex => $sourceLine) {
            $sameItem = $purchaseLines->keys()->filter(
                fn (int $index): bool => (int) $purchaseLines[$index]->item_id
                    === (int) $sourceLine->item_id,
            )->values()->all();
            if ($sameItem === []) {
                $fallbackDifferences[$sourceIndex] = ['line_item_set_differs'];
                $edges[$sourceIndex] = [];

                continue;
            }

            $sameQuantity = array_values(array_filter(
                $sameItem,
                fn (int $index): bool => (int) $purchaseLines[$index]->qty_ordered
                    === (int) $sourceLine->quantity,
            ));
            if ($sameQuantity === []) {
                $fallbackDifferences[$sourceIndex] = ['line_quantity_differs'];
                $edges[$sourceIndex] = [];

                continue;
            }

            $candidateDifferences = [];
            $edges[$sourceIndex] = [];
            foreach ($sameQuantity as $purchaseIndex) {
                $candidateDifferences[$purchaseIndex] = $this->lineDifferences(
                    $purchaseLines[$purchaseIndex],
                    $sourceLine,
                    $tolerance,
                );
                if ($candidateDifferences[$purchaseIndex] === []) {
                    $edges[$sourceIndex][] = $purchaseIndex;
                }
            }

            if ($edges[$sourceIndex] === []) {
                usort($candidateDifferences, fn (array $left, array $right): int => count($left) <=> count($right));
                $fallbackDifferences[$sourceIndex] = $candidateDifferences[0] ?? ['line_commercial_facts_differ'];
            }
        }

        $purchaseMatch = [];
        $augment = function (int $sourceIndex, array &$seen) use (&$augment, &$purchaseMatch, $edges): bool {
            foreach ($edges[$sourceIndex] ?? [] as $purchaseIndex) {
                if (isset($seen[$purchaseIndex])) {
                    continue;
                }
                $seen[$purchaseIndex] = true;
                if (! isset($purchaseMatch[$purchaseIndex])
                    || $augment($purchaseMatch[$purchaseIndex], $seen)) {
                    $purchaseMatch[$purchaseIndex] = $sourceIndex;

                    return true;
                }
            }

            return false;
        };

        foreach ($sourceLines->keys() as $sourceIndex) {
            $seen = [];
            if (! $augment((int) $sourceIndex, $seen)) {
                $differences = array_merge(
                    $differences,
                    $fallbackDifferences[$sourceIndex] ?? ['line_assignment_differs'],
                );
            }
        }

        $purchaseGoodsSubtotal = BigDecimal::zero();
        foreach ($purchaseLines as $purchaseLine) {
            $unitCost = $this->decimal($purchaseLine->unit_cost);
            if ($unitCost === null) {
                $purchaseGoodsSubtotal = null;
                break;
            }
            $purchaseGoodsSubtotal = $purchaseGoodsSubtotal->plus(
                $unitCost->multipliedBy((int) $purchaseLine->qty_ordered),
            );
        }

        return [array_values(array_unique($differences)), $purchaseGoodsSubtotal];
    }

    /** @return list<string> */
    private function lineDifferences(mixed $purchaseLine, mixed $sourceLine, BigDecimal $tolerance): array
    {
        $differences = [];
        $purchaseSku = SupplierSkuIdentity::normalize($purchaseLine->supplier_sku_snapshot);
        $sourceSku = SupplierSkuIdentity::normalize($sourceLine->supplier_sku);
        if ($sourceSku !== '') {
            if ($purchaseSku === '') {
                $differences[] = 'line_supplier_sku_missing';
            } elseif ($purchaseSku !== $sourceSku) {
                $differences[] = 'line_supplier_sku_differs';
            }
        }

        $sourceUnitCost = $this->sourceUnitCost($sourceLine);
        $purchaseUnitCost = $this->decimal($purchaseLine->unit_cost);
        if ($sourceUnitCost !== null) {
            if ($purchaseUnitCost === null) {
                $differences[] = 'line_unit_cost_missing';
            } elseif (! $this->withinTolerance($purchaseUnitCost, $sourceUnitCost, $tolerance)) {
                $differences[] = 'line_unit_cost_differs';
            }
        }

        $sourceLineTotal = $this->sourceLineTotal($sourceLine);
        if ($sourceLineTotal !== null) {
            if ($purchaseUnitCost === null) {
                $differences[] = 'line_total_unverifiable';
            } else {
                $purchaseLineTotal = $purchaseUnitCost->multipliedBy(
                    (int) $purchaseLine->qty_ordered,
                );
                if (! $this->withinTolerance($purchaseLineTotal, $sourceLineTotal, $tolerance)) {
                    $differences[] = 'line_total_differs';
                }
            }
        }

        $sourceTaxRate = $this->decimal($sourceLine->tax_rate);
        if ($sourceTaxRate !== null) {
            if ($sourceTaxRate->isNegative() || $sourceTaxRate->isGreaterThan(BigDecimal::of(100))) {
                $differences[] = 'line_tax_rate_invalid';
            } else {
                $purchaseTaxRate = $this->decimal($purchaseLine->tax_rate);
                if ($purchaseTaxRate === null) {
                    $differences[] = 'line_tax_rate_missing';
                } elseif (! $purchaseTaxRate->toScale(2, RoundingMode::HALF_UP)->isEqualTo(
                    $sourceTaxRate->toScale(2, RoundingMode::HALF_UP),
                )) {
                    $differences[] = 'line_tax_rate_differs';
                }
            }
        }

        return array_values(array_unique($differences));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return list<string>
     */
    private function compareCommercialTotals(
        PurchaseOrder $purchaseOrder,
        array $document,
        ?BigDecimal $purchaseGoodsSubtotal,
        ?BigDecimal $purchaseTaxTotal,
        BigDecimal $tolerance,
    ): array {
        $differences = [];
        $totals = (array) data_get($document, 'totals', []);
        $sourceGoods = $this->decimal($totals['goods_subtotal'] ?? null);
        $freight = $this->decimal($totals['freight'] ?? null);
        $discount = $this->decimal($totals['discount'] ?? null);
        $other = $this->decimal($totals['other_charges'] ?? null);
        $explicitTotalExTax = $this->decimal($totals['total_ex_tax'] ?? null);
        $sourceTaxTotal = $this->decimal($totals['tax_total'] ?? null);
        $sourceTotalIncTax = $this->decimal($totals['total_inc_tax'] ?? null);

        foreach ([
            $sourceGoods,
            $freight,
            $discount,
            $other,
            $explicitTotalExTax,
            $sourceTaxTotal,
            $sourceTotalIncTax,
        ] as $amount) {
            if ($amount !== null && $amount->isNegative()) {
                $differences[] = 'source_commercial_value_invalid';
            }
        }

        if ($sourceGoods !== null) {
            if ($purchaseGoodsSubtotal === null) {
                $differences[] = 'goods_subtotal_unverifiable';
            } elseif (! $this->withinTolerance($purchaseGoodsSubtotal, $sourceGoods, $tolerance)) {
                $differences[] = 'goods_subtotal_differs';
            }
        }

        $chargesComplete = $freight !== null && $discount !== null && $other !== null;
        $sourceBreakdownTotal = $sourceGoods !== null && $chargesComplete
            ? $sourceGoods->plus($freight)->plus($other)->minus($discount)
            : null;
        $effectiveTotalExTax = $explicitTotalExTax ?? $sourceBreakdownTotal;

        if ($explicitTotalExTax !== null && $sourceBreakdownTotal !== null
            && ! $this->withinTolerance($explicitTotalExTax, $sourceBreakdownTotal, $tolerance)) {
            $differences[] = 'source_total_breakdown_differs';
        }
        if ($explicitTotalExTax !== null && ! $chargesComplete) {
            $differences[] = 'source_charge_breakdown_incomplete';
        }

        if ($effectiveTotalExTax !== null) {
            if ($purchaseGoodsSubtotal === null) {
                $differences[] = 'order_total_unverifiable';
            } elseif (! $chargesComplete) {
                $differences[] = 'source_charge_breakdown_incomplete';
            } else {
                $purchaseExpectedTotal = $purchaseGoodsSubtotal
                    ->plus($freight)
                    ->plus($other)
                    ->minus($discount);
                if (! $this->withinTolerance(
                    $purchaseExpectedTotal,
                    $effectiveTotalExTax,
                    $tolerance,
                )) {
                    $differences[] = 'total_ex_tax_differs';
                }
            }
        }

        $sourceValues = [
            'goods_subtotal' => $sourceGoods,
            'freight' => $freight,
            'discount' => $discount,
            'other_charges' => $other,
            'tax_total' => $sourceTaxTotal,
            'total_ex_tax' => $effectiveTotalExTax,
            'total_inc_tax' => $sourceTotalIncTax,
        ];
        $manualSnapshot = data_get($purchaseOrder->metadata, 'commercial_snapshot');
        if (is_array($manualSnapshot)) {
            foreach ($sourceValues as $field => $source) {
                if ($source === null || ! array_key_exists($field, $manualSnapshot)) {
                    continue;
                }
                $manual = $this->decimal($manualSnapshot[$field]);
                if ($manual === null || ! $this->withinTolerance($manual, $source, $tolerance)) {
                    $differences[] = $field.'_differs';
                }
            }
        }

        $adjustmentsPresent = $chargesComplete && collect([$freight, $discount, $other])
            ->contains(fn (BigDecimal $amount): bool => ! $amount->isZero());
        if ($sourceTaxTotal !== null) {
            $taxVerified = false;
            $taxDiffers = false;

            if (is_array($manualSnapshot) && array_key_exists('tax_total', $manualSnapshot)) {
                $manualTax = $this->decimal($manualSnapshot['tax_total']);
                if ($manualTax !== null
                    && $this->withinTolerance($manualTax, $sourceTaxTotal, $tolerance)) {
                    $taxVerified = true;
                } else {
                    $taxDiffers = true;
                }
            }

            if ($chargesComplete && ! $adjustmentsPresent && $purchaseTaxTotal !== null) {
                if ($this->withinTolerance($purchaseTaxTotal, $sourceTaxTotal, $tolerance)) {
                    $taxVerified = true;
                } else {
                    $differences[] = 'tax_total_differs';
                    $taxDiffers = true;
                }
            }

            if (! $taxVerified && ! $taxDiffers) {
                $differences[] = 'aggregate_tax_unverifiable';
            }
        }

        if ($sourceTotalIncTax !== null) {
            if ($effectiveTotalExTax === null || $sourceTaxTotal === null) {
                $differences[] = 'total_inc_tax_unverifiable';
            } elseif (! $this->withinTolerance(
                $effectiveTotalExTax->plus($sourceTaxTotal),
                $sourceTotalIncTax,
                $tolerance,
            )) {
                $differences[] = 'total_inc_tax_differs';
            }
        }

        return array_values(array_unique($differences));
    }

    private function purchaseTaxTotal(PurchaseOrder $purchaseOrder): ?BigDecimal
    {
        $total = BigDecimal::zero();
        foreach ($purchaseOrder->lines as $line) {
            $unitCost = $this->decimal($line->unit_cost);
            $taxRate = $this->decimal($line->tax_rate);
            if ($unitCost === null || $taxRate === null) {
                return null;
            }

            $lineTax = $unitCost
                ->multipliedBy((int) $line->qty_ordered)
                ->multipliedBy($taxRate)
                ->dividedBy(100, 2, RoundingMode::HALF_UP);
            $total = $total->plus($lineTax);
        }

        return $total;
    }

    private function sourceUnitCost(mixed $line): ?BigDecimal
    {
        $unitPrice = $this->decimal($line->unit_price);
        if ($unitPrice !== null) {
            return $unitPrice->toScale(2, RoundingMode::HALF_UP);
        }

        $lineTotal = $this->decimal($line->line_total);
        $quantity = (int) $line->quantity;
        if ($lineTotal === null || $quantity < 1) {
            return null;
        }

        return $lineTotal->dividedBy($quantity, 2, RoundingMode::HALF_UP);
    }

    private function sourceLineTotal(mixed $line): ?BigDecimal
    {
        $lineTotal = $this->decimal($line->line_total);
        if ($lineTotal !== null) {
            return $lineTotal;
        }

        $unitPrice = $this->decimal($line->unit_price);
        $quantity = (int) $line->quantity;

        return $unitPrice !== null && $quantity > 0
            ? $unitPrice->multipliedBy($quantity)
            : null;
    }

    private function decimal(mixed $value): ?BigDecimal
    {
        if ($value === null || $value === '' || ! is_numeric($value)) {
            return null;
        }

        return BigDecimal::of((string) $value);
    }

    private function withinTolerance(
        BigDecimal $left,
        BigDecimal $right,
        BigDecimal $tolerance,
    ): bool {
        return $left->minus($right)->abs()->isLessThanOrEqualTo($tolerance);
    }
}
