<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Services\Services;

/** Current package unit allocation and suggested pricing, shared without Livewire state. */
class PackagePricingCalculator
{
    public function __construct(public array $selectedServiceIds = []) {}

    public function pricingByUnit(): array
    {
        $data = [];

        // Fetch services fresh to ensure we're not using stale cached data
        $services = Services::with(['costRelations.cost.unit', 'unit'])
            ->whereIn('id', $this->selectedServiceIds)
            ->get();

        foreach ($services as $service) {
            // 1. Attribute EVERYTHING (Service Price AND all its Costs) to the service's own unit
            $unit = $service->unit;
            $unitId = $unit ? $unit->id : 0; // 0 for 'No Unit'
            $unitName = $unit ? ($unit->name) : '-';

            if (! isset($data[$unitId])) {
                $data[$unitId] = [
                    'unit_name' => $unitName,
                    'cost' => 0.0,
                    'suggested_sales_price' => 0.0,
                    'has_services' => false,
                ];
            }

            $data[$unitId]['suggested_sales_price'] += (float) $service->price_ex_vat;
            $data[$unitId]['has_services'] = true;

            // Attribute all related costs to THIS service's unit, NOT the cost's unit
            foreach ($service->costRelations as $relation) {
                if ($relation->cost) {
                    $data[$unitId]['cost'] += (float) $relation->cost->cost;
                }
            }
        }

        return $data;
    }

    public function preview(array $custom = []): array
    {
        $rows = $this->pricingByUnit();
        $cost = 0;
        $suggested = 0;
        $sale = 0;
        foreach ($rows as $id => &$row) {
            $override = $custom[$id] ?? null;
            $has = $override !== null && $override !== '';
            $price = $has ? (float) $override : (float) $row['suggested_sales_price'];
            $row += ['custom_price' => $override, 'has_custom' => $has, 'sales_price' => $price, 'margin' => $price - $row['cost'], 'margin_percent' => $price > 0 ? ($price - $row['cost']) / $price * 100 : 0];
            $cost += $row['cost'];
            $suggested += $row['suggested_sales_price'];
            $sale += $price;
        }

        return ['rows' => $rows, 'total_cost' => $cost, 'suggested_sales_price' => $suggested, 'sales_price' => $sale, 'margin' => $sale - $cost, 'margin_percent' => $sale > 0 ? ($sale - $cost) / $sale * 100 : 0];
    }
}
