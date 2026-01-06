<?php

namespace App\Livewire\Tech\Cs;

use App\Models\CS\CostRelations;
use App\Models\CS\Cost;
use App\Models\CS\Services\Services;
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
        if (!$service || !$service->exists) {
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
                ->map(fn($id) => (string) $id)
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
        $this->checkRecommendedPrice();
        if (!$this->service || !$this->service->exists) {
            $this->dispatch('costsUpdated', $this->selected);
            return;
        }

        $keep = $this->selected;

        CostRelations::where('serviceId', $this->service->id)
            ->whereNotIn('costId', $keep)
            ->delete();

        $existing = CostRelations::where('serviceId', $this->service->id)
            ->pluck('costId')
            ->map(fn($id) => (string) $id)
            ->all();

        $toInsert = array_diff($keep, $existing);

        foreach ($toInsert as $costId) {
            CostRelations::create([
                'serviceId' => $this->service->id,
                'costId' => $costId,
            ]);
        }

        $this->service->load('costRelations.cost.vendor');
    }

    public function remove($costId): void
    {
        $this->selected = array_values(array_diff($this->selected, [(string) $costId]));
        $this->updatedSelected();
    }

    public function render()
    {
        $linked = collect([]);

        if ($this->service && $this->service->exists) {
            $linked = CostRelations::with(['cost.vendor'])
                ->where('serviceId', $this->service->id)
                ->get();
        } else {
            // For new services, show costs from memory ($this->selected)
            $costs = Cost::with('vendor')->whereIn('id', $this->selected)->get();
            foreach ($costs as $cost) {
                $item = new CostRelations();
                $item->costId = $cost->id;
                $item->setRelation('cost', $cost);
                $linked->push($item);
            }
        }

        return view('livewire.tech.cs.service-pricing', [
            'allCosts' => Cost::with('vendor')->orderBy('name')->get(),
            'linked'   => $linked,
        ]);
    }
}
