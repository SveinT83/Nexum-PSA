<?php

namespace App\Livewire\Tech\Cs;

use App\Models\CS\Packages\Package;
use App\Models\CS\Services\Services;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class PackagePricing extends Component
{
    public ?Package $package = null;

    public array $selectedServiceIds = [];

    public array $customSalesPrices = [];

    public array $lastSuggestedPrices = [];

    public string $enabled = 'enabled';

    public function mount(?Package $package = null, string $enabled = 'enabled'): void
    {
        $this->package = $package;
        $this->enabled = $enabled;

        // Initialize with default units if package exists
        if ($this->package && $this->package->exists) {
            $this->selectedServiceIds = $this->package->services()
                ->pluck('services.id')
                ->map(fn ($id) => (string) $id)
                ->toArray();

            // Vi fjerner hardkodingen som prøvde å gjette enheter basert på navn (user, sites, pc, kunde).
            // I stedet henter vi priser som allerede er lagret på pakken hvis de finnes.
            foreach ($this->package->prices ?? [] as $price) {
                $this->customSalesPrices[$price->unit_id] = number_format((float) $price->amount, 2, '.', '');
            }
        }

        $this->refreshPricing();
    }

    #[On('servicesUpdated')]
    public function updateServices($serviceIds): void
    {
        $this->selectedServiceIds = array_map('strval', $serviceIds);
        $this->refreshPricing();
    }

    private function refreshPricing(): void
    {
        // Get the new pricing data
        $pricing = $this->pricingByUnit();

        foreach ($pricing as $unitId => $data) {
            $newSuggested = (float) $data['suggested_sales_price'];
            $oldSuggested = (float) ($this->lastSuggestedPrices[$unitId] ?? 0);
            $currentCustom = $this->customSalesPrices[$unitId] ?? null;

            // If it was empty/null, or if it exactly matched the previous suggested price
            if ($currentCustom === null || $currentCustom === '' || (float) $currentCustom === $oldSuggested) {
                if ($newSuggested > 0) {
                    $this->customSalesPrices[$unitId] = number_format($newSuggested, 2, '.', '');
                } else {
                    $this->customSalesPrices[$unitId] = null;
                }
            }

            // Update our tracker
            $this->lastSuggestedPrices[$unitId] = $newSuggested;
        }
    }

    #[Computed]
    public function selectedServices()
    {
        return Services::with(['costRelations.cost.unit', 'unit'])
            ->whereIn('id', $this->selectedServiceIds)
            ->get();
    }

    #[Computed]
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

    #[Computed]
    public function pricingRows(): array
    {
        $pricing = $this->pricingByUnit();
        $rows = [];
        foreach ($pricing as $unitId => $data) {
            $customPrice = $this->customSalesPrices[$unitId] ?? null;
            $hasCustom = $customPrice !== null && $customPrice !== '';

            $suggested = (float) $data['suggested_sales_price'];
            $cost = (float) $data['cost'];

            $salesPrice = $hasCustom ? (float) $customPrice : $suggested;
            $margin = $salesPrice - $cost;
            $marginPercent = $salesPrice > 0 ? ($margin / $salesPrice) * 100 : 0;

            // Row is visible if it has services, a custom price, or non-zero cost/price
            $hasActivity = $hasCustom || ($data['has_services'] ?? false) || $cost > 0 || $suggested > 0;

            if ($hasActivity) {
                $rows[$unitId] = array_merge($data, [
                    'custom_price' => $customPrice,
                    'has_custom' => $hasCustom,
                    'sales_price' => $salesPrice,
                    'margin' => $margin,
                    'margin_percent' => $marginPercent,
                ]);
            }
        }

        return $rows;
    }

    #[Computed]
    public function totalCostPrice(): float
    {
        $total = 0.0;
        $pricing = $this->pricingByUnit(); // Call directly to ensure fresh data
        foreach ($pricing as $unitData) {
            $total += (float) $unitData['cost'];
        }

        return (float) $total;
    }

    #[Computed]
    public function totalServiceSalesPrice(): float
    {
        $total = 0.0;
        $pricing = $this->pricingByUnit(); // Call directly to ensure fresh data
        foreach ($pricing as $unitData) {
            $total += (float) $unitData['suggested_sales_price'];
        }

        return (float) $total;
    }

    #[Computed]
    public function currentSalesPrice(): float
    {
        $total = 0.0;
        $pricing = $this->pricingByUnit(); // Call directly to ensure fresh data
        foreach ($pricing as $unitId => $unitData) {
            $custom = $this->customSalesPrices[$unitId] ?? null;
            if ($custom !== null && $custom !== '') {
                $total += (float) $custom;
            } else {
                $total += (float) $unitData['suggested_sales_price'];
            }
        }

        return (float) $total;
    }

    #[Computed]
    public function marginAmount(): float
    {
        return $this->currentSalesPrice - $this->totalCostPrice;
    }

    #[Computed]
    public function marginPercentage(): float
    {
        if ($this->currentSalesPrice <= 0) {
            return 0;
        }

        return ($this->marginAmount / $this->currentSalesPrice) * 100;
    }

    public function render()
    {
        return view('livewire.tech.cs.package-pricing');
    }
}
