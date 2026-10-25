<?php

namespace App\Modules\Commercial\Livewire\Tech\Contracts;

use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\ServiceTimeRate;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryServiceManager;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ContractItemsEditor extends Component
{
    public $contract;

    #[Locked]
    public int $contractId;

    public $items = [];

    public $availableServices = [];

    public $availableSlas = [];

    public $isEditable = false;

    protected $rules = [
        'items.*.id' => 'nullable|integer',
        'items.*.service_id' => 'required|integer|exists:services,id',
        'items.*.cloudfactory_offer_id' => 'nullable|uuid|exists:cloudfactory_offers,id',
        'items.*.name' => 'required|string|max:255',
        'items.*.sku' => 'nullable|string|max:255',
        'items.*.customer_description' => 'nullable|string|max:2000',
        'items.*.customer_unit_singular' => 'nullable|string|max:255',
        'items.*.customer_unit_plural' => 'nullable|string|max:255',
        'items.*.unit_price' => 'required|numeric|min:0',
        'items.*.price_currency' => 'required|string|size:3|in:NOK',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.unit' => 'nullable|string|max:255',
        'items.*.billing_interval' => 'required|in:monthly,quarterly,yearly,one_time',
        'items.*.discount_value' => 'nullable|numeric|min:0',
        'items.*.discount_type' => 'nullable|in:percent,amount',
        'items.*.setup_fee' => 'nullable|numeric|min:0',
        'items.*.sla_id' => 'nullable|integer|exists:sla,id',
        'items.*.uses_contract_default_sla' => 'nullable|boolean',
        'items.*.time_rates.*.id' => 'nullable|integer',
        'items.*.time_rates.*.time_rate_id' => 'nullable|integer|exists:time_rates,id',
        'items.*.time_rates.*.service_time_rate_id' => 'nullable|integer|exists:service_time_rates,id',
        'items.*.time_rates.*.name' => 'required|string|max:255',
        'items.*.time_rates.*.code' => 'nullable|string|max:255',
        'items.*.time_rates.*.unit' => 'required|string|max:255',
        'items.*.time_rates.*.rate_type' => 'required|string|max:255',
        'items.*.time_rates.*.amount_ex_vat' => 'nullable|numeric|min:0',
        'items.*.time_rates.*.currency' => 'required|string|size:3',
        'items.*.time_rates.*.is_active' => 'nullable|boolean',
        'items.*.time_rates.*.is_customer_visible' => 'nullable|boolean',
    ];

    public function mount(Contracts $contract)
    {
        $this->contractId = (int) $contract->getKey();
        $this->contract = $contract;
        $this->isEditable = $contract->isEditable();
        $this->availableServices = Services::query()->with('sla')->orderBy('name')->get();
        $this->availableSlas = Sla::query()->orderByDesc('is_default')->orderBy('name')->get();
        $this->loadItems();
    }

    public function loadItems()
    {
        $this->contract = Contracts::query()
            ->with(['client', 'sla'])
            ->findOrFail($this->contractId);
        $this->isEditable = $this->contract->isEditable();
        $this->items = $this->contract->items()
            ->with(['service.costRelations.cost'])
            ->with('slaPolicy')
            ->with('timeRates')
            ->get()
            ->map(function ($item) {
                $data = $item->toArray();
                $data['tax_rate'] = $item->service->taxable ?? 0;
                $data['item_cost'] = $item->cost_unit_price !== null
                    ? (float) $item->cost_unit_price
                    : ($item->service ? $item->service->costRelations->sum(fn ($cr) => $cr->cost->cost ?? 0) : 0);
                $data['uses_contract_default_sla'] = (bool) ($item->uses_contract_default_sla ?? true);
                $data['sla_label'] = $item->uses_contract_default_sla
                    ? ($this->contract->sla?->name ? 'Contract default: '.$this->contract->sla->name : 'Contract default')
                    : ($item->slaPolicy?->name ?? 'Custom SLA');
                $data['time_rates'] = $item->timeRates
                    ->map(fn (ContractItemTimeRate $rate) => [
                        'id' => $rate->id,
                        'time_rate_id' => $rate->time_rate_id,
                        'name' => $rate->name,
                        'code' => $rate->code,
                        'unit' => $rate->unit,
                        'rate_type' => $rate->rate_type,
                        'amount_ex_vat' => $rate->amount_ex_vat,
                        'currency' => $rate->currency,
                        'is_active' => $rate->is_active,
                        'is_customer_visible' => $rate->is_customer_visible,
                    ])
                    ->values()
                    ->toArray();

                return $data;
            })->toArray();
    }

    public function addItem()
    {
        $contract = $this->editableContract();

        $this->items[] = [
            'contract_id' => $contract->id,
            'service_id' => null,
            'name' => '',
            'source' => 'nexum',
            'cloudfactory_offer_id' => null,
            'cost_currency' => 'NOK',
            'price_currency' => 'NOK',
            'sku' => '',
            'customer_description' => '',
            'customer_unit_singular' => '',
            'customer_unit_plural' => '',
            'unit_price' => 0,
            'quantity' => 1,
            'cost_unit_price' => 0,
            'unit' => '',
            'billing_interval' => 'monthly',
            'discount_value' => 0,
            'discount_type' => 'percent',
            'setup_fee' => 0,
            'sla_id' => null,
            'uses_contract_default_sla' => true,
            'sla_snapshot' => null,
            'sla_label' => $contract->sla?->name ? 'Contract default: '.$contract->sla->name : 'Contract default',
            'tax_rate' => 0,
            'item_cost' => 0,
            'time_rates' => [],
        ];
    }

    public function updatedItems($value, $key)
    {
        $engine = new \App\Modules\Commercial\Actions\ContractItemWorkflow(Contracts::query()->findOrFail($this->contractId));
        $engine->items = $this->items;
        try {
            $engine->updatedItems($value, $key);
            $engine->saveItem((int) explode('.', $key)[0]);
        } finally {
            $this->items = $engine->items;
            $this->contract = $engine->contract;
        }
    }

    /**
     * Controlled correction for an editable line whose stored cadence no longer
     * matches the verified Service catalogue definition.
     */
    public function syncBillingIntervalFromService(int $index): void
    {
        $engine = new \App\Modules\Commercial\Actions\ContractItemWorkflow(Contracts::query()->findOrFail($this->contractId));
        $engine->items = $this->items;
        try {
            $engine->syncBillingIntervalFromService($index);
        } finally {
            $this->items = $engine->items;
            $this->contract = $engine->contract;
        }
    }

    private function applyCloudFactoryOffer(int $index, Offer $offer): void
    {
        $service = Services::with('costRelations.cost')->find($offer->service_id);
        if (! $service) {
            return;
        }

        $internalCost = (float) $service->costRelations
            ->filter(fn ($relation) => ! ($relation->cost?->managed_externally ?? false))
            ->sum(fn ($relation) => $relation->cost?->cost ?? 0);
        $providerCost = (float) ($offer->normalizedCost() ?? 0);

        $this->items[$index]['cloudfactory_offer_id'] = $offer->id;
        $this->items[$index]['source'] = 'cloudfactory';
        $this->items[$index]['unit_price'] = app(CloudFactoryServiceManager::class)
            ->calculatedSalePrice($offer, $service);
        $this->items[$index]['billing_interval'] = $offer->commercialBillingInterval();
        $this->items[$index]['item_cost'] = $internalCost + $providerCost;
        $this->items[$index]['cost_unit_price'] = $internalCost + $providerCost;
        $this->items[$index]['cost_currency'] = $offer->currency ?: 'NOK';
        $this->items[$index]['price_currency'] = strtoupper(trim((string) ($offer->currency ?: 'NOK')));
        $this->items[$index]['licence_metadata'] = array_replace(
            $this->items[$index]['licence_metadata'] ?? [],
            [
                'cloudfactory_raw_cost' => $offer->cost,
                'cloudfactory_raw_msrp' => $offer->msrp,
                'cloudfactory_commitment_term' => $offer->recurrence_term,
                'cloudfactory_billing_term' => $offer->billing_term,
            ]
        );
    }

    public function calculateLineTotals($index)
    {
        return (new \App\Modules\Commercial\Support\ContractEditorPricing($this->items))->calculateLineTotals($index);
    }

    public function calculateBillingTotals(): array
    {
        return (new \App\Modules\Commercial\Support\ContractEditorPricing($this->items))->calculateBillingTotals();
    }

    protected function calculateQuantity(Services $service): int
    {
        $unitName = $service->unit->name ?? '';

        // "Bruker" unit (ID 6)
        if ($unitName === 'Bruker' || $service->unitId == 6) {
            return $this->contract->client->contacts()->count() ?: 1;
        }

        // Logic for "Sites" or similar could go here
        // If the service name or unit implies a count of sites
        if (str_contains(strtolower($unitName), 'site') || str_contains(strtolower($service->name), 'lokasjon')) {
            return $this->contract->client->sites()->count() ?: 1;
        }

        return 1;
    }

    public function saveItem($index)
    {
        $engine = new \App\Modules\Commercial\Actions\ContractItemWorkflow(Contracts::query()->findOrFail($this->contractId));
        $engine->items = $this->items;
        try {
            $engine->saveItem($index);
        } finally {
            $this->items = $engine->items;
            $this->contract = $engine->contract;
        }
    }

    public function removeItem($index)
    {
        $engine = new \App\Modules\Commercial\Actions\ContractItemWorkflow(Contracts::query()->findOrFail($this->contractId));
        $engine->items = $this->items;
        try {
            $engine->removeItem($index);
        } finally {
            $this->items = $engine->items;
            $this->contract = $engine->contract;
        }
    }

    public function calculateTotalCost()
    {
        return (new \App\Modules\Commercial\Support\ContractEditorPricing($this->items))->calculateTotalCost();
    }

    public function calculateTotalDiscount()
    {
        return (new \App\Modules\Commercial\Support\ContractEditorPricing($this->items))->calculateTotalDiscount();
    }

    public function calculateAnnualProfit()
    {
        return (new \App\Modules\Commercial\Support\ContractEditorPricing($this->items))->calculateAnnualProfit();
    }

    protected function timeRatesForService(Services $service): array
    {
        $serviceRates = $service->serviceTimeRates
            ->where('is_active', true)
            ->filter(fn ($serviceRate) => $serviceRate->timeRate?->is_active);

        if ($serviceRates->isEmpty()) {
            return TimeRate::query()
                ->where('is_active', true)
                ->where('applies_with_contract', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->get()
                ->map(fn (TimeRate $rate) => [
                    'time_rate_id' => $rate->id,
                    'name' => $rate->name,
                    'code' => $rate->code,
                    'unit' => $rate->unit,
                    'rate_type' => $rate->rate_type,
                    'amount_ex_vat' => $rate->amount_ex_vat,
                    'currency' => $rate->currency,
                    'is_active' => true,
                    'is_customer_visible' => $rate->is_customer_visible,
                ])
                ->toArray();
        }

        return $serviceRates
            ->sortBy(fn ($serviceRate) => [$serviceRate->timeRate->sort_order, $serviceRate->timeRate->name])
            ->map(fn ($serviceRate) => [
                'service_time_rate_id' => $serviceRate->id,
                'time_rate_id' => $serviceRate->time_rate_id,
                'name' => $serviceRate->timeRate->name,
                'code' => $serviceRate->timeRate->code,
                'unit' => $serviceRate->timeRate->unit,
                'rate_type' => $serviceRate->timeRate->rate_type,
                'amount_ex_vat' => $serviceRate->amount_ex_vat ?? $serviceRate->timeRate->amount_ex_vat,
                'currency' => $serviceRate->timeRate->currency,
                'is_active' => true,
                'is_customer_visible' => $serviceRate->timeRate->is_customer_visible,
            ])
            ->values()
            ->toArray();
    }

    protected function syncTimeRates(ContractItem $item, array $rates): void
    {
        $keep = [];

        foreach ($rates as $index => $rate) {
            if (empty($rate['name'])) {
                continue;
            }

            if (! empty($rate['service_time_rate_id'])) {
                ServiceTimeRate::query()
                    ->whereKey((int) $rate['service_time_rate_id'])
                    ->where('service_id', $item->service_id)
                    ->firstOrFail();
            }

            $payload = [
                'time_rate_id' => $rate['time_rate_id'] ?? null,
                'service_time_rate_id' => $rate['service_time_rate_id'] ?? null,
                'name' => $rate['name'],
                'code' => $rate['code'] ?? null,
                'rate_type' => $rate['rate_type'] ?? 'labor',
                'unit' => $rate['unit'] ?? 'hour',
                'amount_ex_vat' => $rate['amount_ex_vat'] ?? 0,
                'currency' => $rate['currency'] ?? 'NOK',
                'is_active' => (bool) ($rate['is_active'] ?? true),
                'is_customer_visible' => (bool) ($rate['is_customer_visible'] ?? false),
                'sort_order' => $index,
            ];

            $snapshot = isset($rate['id'])
                ? $item->timeRates()->whereKey((int) $rate['id'])->firstOrFail()
                : null;

            if ($snapshot) {
                $snapshot->update($payload);
            } else {
                $snapshot = $item->timeRates()->create($payload);
            }

            $keep[] = $snapshot->id;
        }

        $item->timeRates()
            ->when($keep !== [], fn ($query) => $query->whereNotIn('id', $keep))
            ->delete();
    }

    protected function slaSnapshot(Sla $sla): array
    {
        return $sla->only([
            'id',
            'name',
            'description',
            'low_firstResponse',
            'low_firstResponse_type',
            'low_onsite',
            'low_onsite_type',
            'medium_firstResponse',
            'medium_firstResponse_type',
            'medium_onsite',
            'medium_onsite_type',
            'high_firstResponse',
            'high_firstResponse_type',
            'high_onsite',
            'high_onsite_type',
        ]);
    }

    protected function syncMissingContractTermSnapshots(): void
    {
        $contract = $this->contract->fresh(['items.service.serviceTerms']);

        if (! $contract) {
            return;
        }

        $snapshots = app(BuildContractTermSnapshots::class)->handle($contract);
        $updates = [];

        foreach ($snapshots as $field => $content) {
            if (empty($contract->$field) && $content !== '') {
                $updates[$field] = $content;
            }
        }

        if ($updates !== []) {
            $contract->update($updates);
            $this->contract = $contract->fresh();
        }
    }

    /** @return array<string, string> */
    private function rulesForItem(int $index): array
    {
        $prefix = 'items.'.$index.'.';

        return collect($this->rules)
            ->mapWithKeys(fn (string $rule, string $field): array => [
                str_replace('items.*.', $prefix, $field) => $rule,
            ])
            ->all();
    }

    /**
     * Reload the authoritative contract state for every mutation. A row lock
     * makes item persistence serialize with sent/accepted status transitions.
     */
    private function editableContract(bool $lockForUpdate = false): Contracts
    {
        $query = Contracts::query()->with(['client', 'sla']);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $contract = $query->findOrFail($this->contractId);
        abort_unless($contract->isEditable(), 409, 'Accepted and sent contract items are immutable.');

        $this->contract = $contract;
        $this->isEditable = true;

        return $contract;
    }

    public function render()
    {
        return view('commercial::Livewire.Tech.Contract.contract-items-editor');
    }
}
