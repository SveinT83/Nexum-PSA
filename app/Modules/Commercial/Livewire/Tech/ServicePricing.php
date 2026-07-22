<?php

namespace App\Modules\Commercial\Livewire\Tech;

use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\CostRelations;
use App\Modules\Commercial\Models\Services\Services;
use Livewire\Component;

class ServicePricing extends Component
{
    public ?Services $service = null;

    public array $selected = [];

    public string $enabled = 'enabled';

    public $price_ex_vat = 0;

    public $taxable = 0;

    public $billing_cycle = 'monthly';

    public $one_time_fee = 0;

    public $recurrence_value_x = null;

    public $one_time_fee_recurrence = '';

    public function mount(?Services $service = null, string $enabled = 'enabled'): void
    {
        $this->enabled = $enabled;
        if (! $service || ! $service->exists) {
            $routeService = request()->route('service');
            if ($routeService instanceof Services) {
                $this->service = $routeService;
            } elseif ($routeService) {
                $this->service = Services::find($routeService);
            }
        } else {
            $this->service = $service;
        }

        if ($this->service) {
            $this->selected = CostRelations::where('serviceId', $this->service->id)
                ->pluck('costId')
                ->map(fn ($id) => (string) $id)
                ->all();

            $this->price_ex_vat = $this->service->price_ex_vat;
            $this->taxable = $this->service->taxable;
            $this->billing_cycle = $this->service->billing_cycle;
            $this->one_time_fee = $this->service->one_time_fee;
            $this->recurrence_value_x = $this->service->recurrence_value_x;
            $this->one_time_fee_recurrence = $this->service->one_time_fee_recurrence;
        } else {
            // Default values for new service
            $this->price_ex_vat = old('price_ex_vat', 0);
            $this->taxable = old('taxable', 0);
            $this->billing_cycle = old('billing_cycle', 'monthly');
            $this->one_time_fee = old('one_time_fee', 0);
            $this->recurrence_value_x = old('recurrence_value_x');
            $this->one_time_fee_recurrence = old('one_time_fee_recurrence', '');
        }

        $this->checkRecommendedPrice();
    }

    public function checkRecommendedPrice()
    {
        if (empty($this->price_ex_vat) || (float) $this->price_ex_vat == 0) {
            $totalCost = (float) $this->getTotalCostProperty();
            if ($totalCost > 0) {
                $this->price_ex_vat = $totalCost * 2;
            }
        }
    }

    public function getTotalCostProperty()
    {
        return Cost::whereIn('id', $this->selected)->sum('cost');
    }

    public function getCommissionProperty()
    {
        return (float) $this->price_ex_vat - (float) $this->getTotalCostProperty();
    }

    public function updatedSelected(): void
    {
        if ($this->integrationOwnsService()) {
            $this->restoreSelectedCosts();

            return;
        }
        $this->checkRecommendedPrice();
        if (! $this->service || ! $this->service->exists) {
            $this->dispatch('costsUpdated', $this->selected);

            return;
        }

        $managed = CostRelations::query()
            ->where('serviceId', $this->service->id)
            ->whereHas('cost', fn ($query) => $query->integrationManaged())
            ->pluck('costId')
            ->map(fn ($id) => (string) $id)
            ->all();
        $manual = Cost::query()
            ->whereIn('id', $this->selected)
            ->editableInNexum()
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();
        $keep = array_values(array_unique(array_merge($managed, $manual)));
        $this->selected = $keep;

        CostRelations::where('serviceId', $this->service->id)
            ->whereHas('cost', fn ($query) => $query->editableInNexum())
            ->whereNotIn('costId', $keep)
            ->delete();

        $existing = CostRelations::where('serviceId', $this->service->id)
            ->pluck('costId')
            ->map(fn ($id) => (string) $id)
            ->all();

        $toInsert = array_diff($keep, $existing);

        $manualToInsert = Cost::query()
            ->whereIn('id', $toInsert)
            ->editableInNexum()
            ->pluck('id');

        foreach ($manualToInsert as $costId) {
            CostRelations::create([
                'serviceId' => $this->service->id,
                'costId' => $costId,
            ]);
        }

        $this->service->load('costRelations.cost.vendor');
    }

    public function remove($costId): void
    {
        if ($this->integrationOwnsService()) {
            return;
        }

        if (Cost::query()->whereKey($costId)->integrationManaged()->exists()) {
            return;
        }

        $this->selected = array_values(array_diff($this->selected, [(string) $costId]));
        $this->updatedSelected();
    }

    public function render()
    {
        $linked = collect([]);

        if ($this->service && $this->service->exists) {
            $linked = CostRelations::with(['cost.vendor', 'cost.sourceIntegration'])
                ->where('serviceId', $this->service->id)
                ->get();
        } else {
            // For new services, show costs from memory ($this->selected)
            $costs = Cost::with('vendor')->whereIn('id', $this->selected)->get();
            foreach ($costs as $cost) {
                $item = new CostRelations;
                $item->costId = $cost->id;
                $item->setRelation('cost', $cost);
                $linked->push($item);
            }
        }

        return view('commercial::Livewire.Tech.service-pricing', [
            'allCosts' => Cost::with(['vendor', 'sourceIntegration'])
                ->editableInNexum()
                ->orderBy('name')
                ->get(),
            'linked' => $linked,
        ]);
    }

    private function integrationOwnsService(): bool
    {
        if (! $this->service || ! $this->service->exists) {
            return false;
        }

        $this->service->loadMissing('sourceIntegration');

        return $this->service->isIntegrationManaged();
    }

    private function restoreSelectedCosts(): void
    {
        if (! $this->service || ! $this->service->exists) {
            return;
        }

        $this->selected = CostRelations::query()
            ->where('serviceId', $this->service->id)
            ->pluck('costId')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
