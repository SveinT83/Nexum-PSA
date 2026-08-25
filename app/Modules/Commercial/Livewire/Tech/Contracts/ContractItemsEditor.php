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
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractPricing;
use App\Modules\Integration\Models\CloudFactory\Offer;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryServiceManager;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use OverflowException;

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
        $this->editableContract();
        $parts = explode('.', (string) $key);

        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! isset($this->items[(int) $parts[0]])) {
            return;
        }

        // $key looks like "0.service_id"
        $index = (int) $parts[0];
        $field = $parts[1];

        if ($field === 'service_id' && $value) {
            $service = Services::with([
                'unit',
                'sla',
                'serviceTimeRates.timeRate',
                'costRelations.cost',
                'cloudFactoryOffer',
                'sourceIntegration',
            ])->find($value);
            if ($service) {
                $this->items[$index]['name'] = $service->name;
                $this->items[$index]['sku'] = $service->sku;
                $this->items[$index]['unit_price'] = $service->price_ex_vat;
                $this->items[$index]['price_currency'] = strtoupper(trim((string) ($service->price_currency ?: 'NOK')));
                $this->items[$index]['customer_description'] = app(ContractCustomerDocument::class)
                    ->plainText($service->short_description ?: $service->name);
                $this->items[$index]['customer_unit_singular'] = $service->customer_unit_singular
                    ?: ($service->unit->name ?? '');
                $this->items[$index]['customer_unit_plural'] = $service->customer_unit_plural
                    ?: ($service->unit->name ?? '');
                $this->items[$index]['unit'] = $service->unit->name ?? '';
                $this->items[$index]['billing_interval'] = $service->billing_cycle ?? 'monthly';
                $this->items[$index]['setup_fee'] = $service->setup_fee ?? $service->one_time_fee;
                $this->items[$index]['discount_value'] = $service->default_discount_value;
                $this->items[$index]['discount_type'] = $service->default_discount_type ?? 'percent';
                $this->items[$index]['tax_rate'] = $service->taxable; // Add tax_rate to items array
                $this->items[$index]['sla_id'] = $service->sla_id;
                $this->items[$index]['uses_contract_default_sla'] = empty($service->sla_id);
                $this->items[$index]['sla_snapshot'] = $service->sla ? $this->slaSnapshot($service->sla) : null;
                $this->items[$index]['sla_label'] = $service->sla
                    ? $service->sla->name
                    : ($this->contract->sla?->name ? 'Contract default: '.$this->contract->sla->name : 'Contract default');

                $this->items[$index]['item_cost'] = (float) $service->costRelations
                    ->sum(fn ($relation) => $relation->cost->cost ?? 0);
                $this->items[$index]['cost_unit_price'] = $this->items[$index]['item_cost'];
                $this->items[$index]['cost_currency'] = $service->price_currency ?: 'NOK';
                $this->items[$index]['source'] = $service->source ?: 'nexum';
                $this->items[$index]['cloudfactory_offer_id'] = null;
                $this->items[$index]['licence_metadata'] = null;

                if ($service->isIntegrationManaged() && $service->cloudFactoryOffer) {
                    $this->applyCloudFactoryOffer($index, $service->cloudFactoryOffer);
                }

                // Magic quantity calculation
                $this->items[$index]['quantity'] = $this->calculateQuantity($service);
                $this->items[$index]['time_rates'] = $this->timeRatesForService($service);
            }
        }

        if ($field === 'cloudfactory_offer_id' && $value) {
            $offer = Offer::query()->with(['integration', 'service.sourceIntegration'])->find($value);
            if (
                ! $offer
                || (int) $offer->service_id !== (int) ($this->items[$index]['service_id'] ?? 0)
                || ! $offer->service?->isIntegrationManaged()
                || (string) $offer->integration_id !== (string) $offer->service->source_integration_id
            ) {
                $this->items[$index]['cloudfactory_offer_id'] = null;
                $this->addError('items.'.$index.'.cloudfactory_offer_id', 'Select an offer that belongs to this Service.');

                return;
            }

            $this->applyCloudFactoryOffer($index, $offer);
        }

        $this->saveItem($index);
    }

    /**
     * Controlled correction for an editable line whose stored cadence no longer
     * matches the verified Service catalogue definition.
     */
    public function syncBillingIntervalFromService(int $index): void
    {
        if (empty($this->items[$index]['id'])) {
            return;
        }

        DB::transaction(function () use ($index): void {
            $contract = $this->editableContract(true);
            $item = $contract->items()
                ->with('service')
                ->whereKey((int) $this->items[$index]['id'])
                ->firstOrFail();
            $service = $item->service;

            if (! $service) {
                return;
            }

            $cadence = app(ContractPricing::class)->normalizeCadence($service->billing_cycle);
            $item->update(['billing_interval' => $cadence]);
            $this->items[$index]['billing_interval'] = $cadence;
        });
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
        $item = $this->items[$index] ?? null;
        if (! $item) {
            return [
                'total' => '0,00 kr',
                'discount_total' => 0,
                'total_numeric' => 0,
            ];
        }

        try {
            $line = app(ContractPricing::class)->calculateLine($item);
        } catch (InvalidArgumentException|OverflowException) {
            // Livewire retains invalid public input after validation fails. Do
            // not let the totals preview turn that useful validation response
            // into a second rendering exception.
            return [
                'total' => '—',
                'discount_total' => 0,
                'total_numeric' => 0,
            ];
        }

        return [
            'total' => $line['included'] ? 'Inkludert' : $line['line_total']['display'],
            'discount_total' => $line['discount']['minor'],
            'total_numeric' => $line['line_total']['decimal'],
            'line' => $line,
        ];
    }

    public function calculateBillingTotals(): array
    {
        try {
            return app(ContractPricing::class)->calculateTotals($this->items);
        } catch (InvalidArgumentException|OverflowException) {
            return [];
        }
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
        DB::transaction(function () use ($index): void {
            $contract = $this->editableContract(true);

            if (! isset($this->items[$index]) || empty($this->items[$index]['service_id'])) {
                return;
            }

            $service = Services::with([
                'costRelations.cost',
                'sourceIntegration',
            ])->find($this->items[$index]['service_id']);
            if (! $service) {
                return;
            }

            $offerId = $this->items[$index]['cloudfactory_offer_id'] ?? null;
            $serviceOffer = $service->isIntegrationManaged()
                ? Offer::query()
                    ->where('service_id', $service->id)
                    ->where('integration_id', $service->source_integration_id)
                    ->first()
                : null;
            if ($serviceOffer) {
                if ($offerId && (string) $offerId !== (string) $serviceOffer->id) {
                    $this->addError('items.'.$index.'.cloudfactory_offer_id', 'Select an offer that belongs to this Service.');

                    return;
                }

                $this->applyCloudFactoryOffer($index, $serviceOffer);
            } elseif ($offerId) {
                $this->addError(
                    'items.'.$index.'.cloudfactory_offer_id',
                    'This Service is not linked to that Cloud Factory offer.'
                );

                return;
            } else {
                $this->items[$index]['item_cost'] = (float) $service->costRelations
                    ->sum(fn ($relation) => $relation->cost?->cost ?? 0);
                $this->items[$index]['cost_unit_price'] = $this->items[$index]['item_cost'];
                $this->items[$index]['cost_currency'] = $service->price_currency ?: 'NOK';
                $this->items[$index]['price_currency'] = strtoupper(trim((string) ($service->price_currency ?: 'NOK')));
                $this->items[$index]['source'] = $service->source ?: 'nexum';
                $this->items[$index]['cloudfactory_offer_id'] = null;
            }

            $priceCurrency = strtoupper(trim((string) ($this->items[$index]['price_currency'] ?? 'NOK')));
            $this->items[$index]['price_currency'] = $priceCurrency;

            if ($priceCurrency !== 'NOK') {
                $this->addError('items.'.$index.'.price_currency', 'Kundekontrakter støtter foreløpig bare salgsvaluta NOK.');

                return;
            }

            foreach (['discount_value', 'setup_fee'] as $field) {
                if (($this->items[$index][$field] ?? null) === '' || ($this->items[$index][$field] ?? null) === null) {
                    $this->items[$index][$field] = 0;
                }
            }

            $this->validate($this->rulesForItem((int) $index));

            $submitted = $this->items[$index];
            $timeRates = $submitted['time_rates'] ?? [];
            $itemData = Arr::only($submitted, [
                'service_id',
                'name',
                'sku',
                'customer_description',
                'customer_unit_singular',
                'customer_unit_plural',
                'unit_price',
                'price_currency',
                'quantity',
                'unit',
                'billing_interval',
                'discount_value',
                'discount_type',
                'setup_fee',
                'sla_id',
                'uses_contract_default_sla',
            ]);
            $itemData['contract_id'] = $contract->id;
            $itemData['service_id'] = $service->id;
            $itemData['source'] = $serviceOffer ? 'cloudfactory' : ($service->source ?: 'nexum');
            $itemData['cloudfactory_offer_id'] = $serviceOffer?->id;
            $itemData['cost_unit_price'] = $submitted['cost_unit_price'] ?? 0;
            $itemData['cost_currency'] = $submitted['cost_currency'] ?? ($service->price_currency ?: 'NOK');

            if ($serviceOffer) {
                $itemData['licence_metadata'] = $submitted['licence_metadata'] ?? null;
            }

            if (! empty($itemData['uses_contract_default_sla'])) {
                $itemData['sla_id'] = null;
                $itemData['sla_snapshot'] = null;
            } else {
                $sla = ! empty($itemData['sla_id']) ? Sla::query()->find($itemData['sla_id']) : null;
                $itemData['sla_snapshot'] = $sla ? $this->slaSnapshot($sla) : null;
            }

            if (isset($submitted['id'])) {
                $item = $contract->items()
                    ->whereKey((int) $submitted['id'])
                    ->firstOrFail();
                $item->update($itemData);
            } else {
                $item = $contract->items()->create($itemData);
                $this->items[$index]['id'] = $item->id;
            }

            $this->syncTimeRates($item, $timeRates);
            $this->syncMissingContractTermSnapshots();
        });
    }

    public function removeItem($index)
    {
        DB::transaction(function () use ($index): void {
            $contract = $this->editableContract(true);
            $itemData = $this->items[$index] ?? null;

            if (! $itemData) {
                return;
            }

            if (isset($itemData['id'])) {
                $contract->items()
                    ->whereKey((int) $itemData['id'])
                    ->firstOrFail()
                    ->delete();
            }

            unset($this->items[$index]);
            $this->items = array_values($this->items);
            $this->syncMissingContractTermSnapshots();
        });
    }

    public function calculateTotalCost()
    {
        $totalCost = 0;
        foreach ($this->items as $item) {
            $totalCost += (float) ($item['item_cost'] ?? 0) * (int) ($item['quantity'] ?? 1);
        }

        return number_format($totalCost, 2, ',', ' ').' kr';
    }

    public function calculateTotalDiscount()
    {
        $totalDiscountMinor = 0;
        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $totalDiscountMinor += $totals['discount_total'] ?? 0;
        }

        return app(ContractPricing::class)->formatMinor($totalDiscountMinor);
    }

    public function calculateAnnualProfit()
    {
        $annualProfit = 0;

        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $revenuePerPeriod = $totals['total_numeric'] ?? 0;
            $costPerPeriod = (float) ($item['item_cost'] ?? 0) * (int) ($item['quantity'] ?? 1);

            $billingInterval = $item['billing_interval'] ?? 'monthly';
            $multiplier = 0;

            switch ($billingInterval) {
                case 'monthly':
                    $multiplier = 12;
                    break;
                case 'quarterly':
                    $multiplier = 4;
                    break;
                case 'yearly':
                    $multiplier = 1;
                    break;
                case 'one_time':
                default:
                    $multiplier = 0; // One-time items might not count towards "annual" profit in the same way, but usually profit is calculated for the first year or similar. User said "årlig profitt" (annual profit).
                    break;
            }

            $annualProfit += ($revenuePerPeriod - $costPerPeriod) * $multiplier;
        }

        return number_format($annualProfit, 2, ',', ' ').' kr';
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
