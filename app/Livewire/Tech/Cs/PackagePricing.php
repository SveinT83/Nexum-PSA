<?php

namespace App\Livewire\Tech\Cs;

use App\Models\CS\Packages\Package;
use App\Models\CS\Services\Services;
use Livewire\Component;
use Livewire\Attributes\On;
use Livewire\Attributes\Computed;

class PackagePricing extends Component
{
    public ?Package $package = null;
    public array $selectedServiceIds = [];
    public array $customSalesPrices = [
        'user' => null,
        'site' => null,
        'asset' => null,
        'client' => null,
        'other' => null,
    ];
    public array $lastSuggestedPrices = [
        'user' => 0,
        'site' => 0,
        'asset' => 0,
        'client' => 0,
        'other' => 0,
    ];
    public string $enabled = 'enabled';

    public function mount(?Package $package = null, string $enabled = 'enabled'): void
    {
        $this->package = $package;
        $this->enabled = $enabled;

        if ($this->package && $this->package->exists) {
            $this->selectedServiceIds = $this->package->services()
                ->pluck('services.id')
                ->map(fn($id) => (string) $id)
                ->toArray();

            $this->customSalesPrices = [
                'user' => $this->package->sales_price_user,
                'site' => $this->package->sales_price_site,
                'asset' => $this->package->sales_price_asset,
                'client' => $this->package->sales_price_client,
                'other' => $this->package->sales_price_other,
            ];
        }

        // Initialize last suggested prices
        $pricing = $this->pricingByUnit();
        foreach ($pricing as $unit => $data) {
            $this->lastSuggestedPrices[$unit] = (float)$data['suggested_sales_price'];
        }
    }

    #[On('servicesUpdated')]
    public function updateServices($serviceIds): void
    {
        $this->selectedServiceIds = array_map('strval', $serviceIds);

        // Get the new pricing data
        $pricing = $this->pricingByUnit();

        foreach ($pricing as $unit => $data) {
            $newSuggested = (float)$data['suggested_sales_price'];
            $oldSuggested = (float)($this->lastSuggestedPrices[$unit] ?? 0);
            $currentCustom = $this->customSalesPrices[$unit] ?? null;

            // If it was empty/null, or if it exactly matched the previous suggested price
            // (meaning the user hasn't touched it or chose to keep it matching),
            // we update it to the new suggested price.
            if ($currentCustom === null || $currentCustom === '' || (float)$currentCustom === $oldSuggested) {
                if ($newSuggested > 0) {
                    $this->customSalesPrices[$unit] = number_format($newSuggested, 2, '.', '');
                } else {
                    $this->customSalesPrices[$unit] = null;
                }
            }

            // Update our tracker
            $this->lastSuggestedPrices[$unit] = $newSuggested;
        }
    }

    #[Computed]
    public function selectedServices()
    {
        return Services::with(['costRelations.cost'])
            ->whereIn('id', $this->selectedServiceIds)
            ->get();
    }

    #[Computed]
    public function pricingByUnit(): array
    {
        $units = ['user', 'site', 'asset', 'client', 'other'];
        $data = [];
        foreach ($units as $unit) {
            $data[$unit] = [
                'cost' => 0.0,
                'suggested_sales_price' => 0.0,
                'has_services' => false,
            ];
        }

        // Fetch services fresh to ensure we're not using stale cached data if IDs were recently updated
        $services = Services::with(['costRelations.cost'])
            ->whereIn('id', $this->selectedServiceIds)
            ->get();

        if ($services->isEmpty()) {
            return $data;
        }

        foreach ($services as $service) {
            $serviceUnits = [];
            foreach ($service->costRelations as $relation) {
                if ($relation->cost && $relation->cost->unit) {
                    $u = strtolower($relation->cost->unit);
                    if (array_key_exists($u, $data)) {
                        $data[$u]['cost'] += (float) $relation->cost->cost;
                        $data[$u]['has_services'] = true;
                        $serviceUnits[$u] = true;
                    }
                }
            }

            // Attribute service sales price to units
            $unitCount = count($serviceUnits);
            if ($unitCount === 0) {
                $data['other']['suggested_sales_price'] += (float) $service->price_ex_vat;
                $data['other']['has_services'] = true;
            } else {
                foreach (array_keys($serviceUnits) as $u) {
                    $data[$u]['suggested_sales_price'] += (float) $service->price_ex_vat / $unitCount;
                    $data[$u]['has_services'] = true;
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
        foreach ($pricing as $unit => $data) {
            $customPrice = $this->customSalesPrices[$unit] ?? null;
            $hasCustom = $customPrice !== null && $customPrice !== '';

            $suggested = (float)$data['suggested_sales_price'];
            $cost = (float)$data['cost'];

            $salesPrice = $hasCustom ? (float)$customPrice : $suggested;
            $margin = $salesPrice - $cost;
            $marginPercent = $salesPrice > 0 ? ($margin / $salesPrice) * 100 : 0;

            // Row is visible if it has services, a custom price, or non-zero cost/price
            $hasActivity = $hasCustom || ($data['has_services'] ?? false) || $cost > 0 || $suggested > 0;

            if ($hasActivity) {
                $rows[$unit] = array_merge($data, [
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
            $total += (float)$unitData['cost'];
        }
        return (float)$total;
    }

    #[Computed]
    public function totalServiceSalesPrice(): float
    {
        $total = 0.0;
        $pricing = $this->pricingByUnit(); // Call directly to ensure fresh data
        foreach ($pricing as $unitData) {
            $total += (float)$unitData['suggested_sales_price'];
        }
        return (float)$total;
    }

    #[Computed]
    public function currentSalesPrice(): float
    {
        $total = 0.0;
        $pricing = $this->pricingByUnit(); // Call directly to ensure fresh data
        foreach ($pricing as $unit => $unitData) {
            $custom = $this->customSalesPrices[$unit] ?? null;
            if ($custom !== null && $custom !== '') {
                $total += (float)$custom;
            } else {
                $total += (float)$unitData['suggested_sales_price'];
            }
        }
        return (float)$total;
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
