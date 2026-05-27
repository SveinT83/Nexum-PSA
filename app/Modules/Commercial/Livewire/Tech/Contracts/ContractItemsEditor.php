<?php

namespace App\Modules\Commercial\Livewire\Tech\Contracts;

use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Models\TimeRate;
use Livewire\Component;

class ContractItemsEditor extends Component
{
    public $contract;
    public $items = [];
    public $availableServices = [];
    public $availableSlas = [];
    public $isEditable = false;

    protected $rules = [
        'items.*.service_id' => 'required|exists:services,id',
        'items.*.name' => 'required|string',
        'items.*.sku' => 'nullable|string',
        'items.*.unit_price' => 'required|numeric',
        'items.*.quantity' => 'required|integer|min:1',
        'items.*.unit' => 'nullable|string',
        'items.*.billing_interval' => 'required|string',
        'items.*.discount_value' => 'nullable|numeric',
        'items.*.discount_type' => 'nullable|string',
        'items.*.setup_fee' => 'nullable|numeric',
        'items.*.sla_id' => 'nullable|exists:sla,id',
        'items.*.uses_contract_default_sla' => 'nullable|boolean',
        'items.*.time_rates.*.amount_ex_vat' => 'nullable|numeric|min:0',
        'items.*.time_rates.*.is_active' => 'nullable|boolean',
    ];

    public function mount(Contracts $contract)
    {
        $this->contract = $contract;
        $this->isEditable = $contract->isEditable();
        $this->availableServices = Services::query()->with('sla')->orderBy('name')->get();
        $this->availableSlas = Sla::query()->orderByDesc('is_default')->orderBy('name')->get();
        $this->loadItems();
    }

    public function loadItems()
    {
        $this->items = $this->contract->items()
            ->with(['service.costRelations.cost'])
            ->with('slaPolicy')
            ->with('timeRates')
            ->get()
            ->map(function ($item) {
                $data = $item->toArray();
                $data['tax_rate'] = $item->service->taxable ?? 0;
                $data['item_cost'] = $item->service ? $item->service->costRelations->sum(fn($cr) => $cr->cost->cost ?? 0) : 0;
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
                    ])
                    ->values()
                    ->toArray();
                return $data;
            })->toArray();
    }

    public function addItem()
    {
        if (!$this->isEditable) {
            return;
        }

        $this->items[] = [
            'contract_id' => $this->contract->id,
            'service_id' => null,
            'name' => '',
            'sku' => '',
            'unit_price' => 0,
            'quantity' => 1,
            'unit' => '',
            'billing_interval' => 'monthly',
            'discount_value' => 0,
            'discount_type' => 'percent',
            'setup_fee' => 0,
            'sla_id' => null,
            'uses_contract_default_sla' => true,
            'sla_snapshot' => null,
            'sla_label' => $this->contract->sla?->name ? 'Contract default: '.$this->contract->sla->name : 'Contract default',
            'tax_rate' => 0,
            'item_cost' => 0,
            'time_rates' => [],
        ];
    }

    public function updatedItems($value, $key)
    {
        if (!$this->isEditable) {
            return;
        }

        // $key looks like "0.service_id"
        $parts = explode('.', $key);
        $index = $parts[0];
        $field = $parts[1];

        if ($field === 'service_id' && $value) {
            $service = Services::with(['unit', 'sla', 'serviceTimeRates.timeRate'])->find($value);
            if ($service) {
                $this->items[$index]['name'] = $service->name;
                $this->items[$index]['sku'] = $service->sku;
                $this->items[$index]['unit_price'] = $service->price_ex_vat;
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

                // Calculate item cost
                $this->items[$index]['item_cost'] = $service->costRelations->sum(fn($cr) => $cr->cost->cost ?? 0);

                // Magic quantity calculation
                $this->items[$index]['quantity'] = $this->calculateQuantity($service);
                $this->items[$index]['time_rates'] = $this->timeRatesForService($service);
            }
        }

        $this->saveItem($index);
    }

    public function calculateLineTotals($index)
    {
        $item = $this->items[$index] ?? null;
        if (!$item) {
            return [
                'total' => '0,00 kr',
                'discount_total' => 0,
                'total_numeric' => 0,
            ];
        }

        $priceExVat = (float)($item['unit_price'] ?? 0) * (int)($item['quantity'] ?? 1);
        $discountValue = (float)($item['discount_value'] ?? 0);
        $discountType = $item['discount_type'] ?? 'percent';
        $taxRate = (float)($item['tax_rate'] ?? 0);

        $discountTotal = 0;
        if ($discountType === 'percent') {
            $discountTotal = $priceExVat * ($discountValue / 100);
        } else {
            $discountTotal = min($priceExVat, $discountValue);
        }

        $subtotal = $priceExVat - $discountTotal;
        $taxTotal = $subtotal * ($taxRate / 100);
        $total = $subtotal + $taxTotal;

        return [
            'total' => number_format($total, 2, ',', ' ') . ' kr',
            'discount_total' => $discountTotal,
            'total_numeric' => $total,
        ];
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
        if (!$this->isEditable) {
            return;
        }

        $itemData = $this->items[$index];
        $timeRates = $itemData['time_rates'] ?? [];
        unset($itemData['time_rates'], $itemData['service']);

        if (empty($itemData['service_id'])) {
            return;
        }

        if (! empty($itemData['uses_contract_default_sla'])) {
            $itemData['sla_id'] = null;
            $itemData['sla_snapshot'] = null;
        } else {
            $sla = ! empty($itemData['sla_id']) ? Sla::query()->find($itemData['sla_id']) : null;
            $itemData['sla_snapshot'] = $sla ? $this->slaSnapshot($sla) : null;
        }

        // Ensure numeric fields are correctly handled (null or numeric)
        $numericFields = ['unit_price', 'quantity', 'discount_value', 'setup_fee'];
        foreach ($numericFields as $field) {
            if (isset($itemData[$field]) && ($itemData[$field] === '' || $itemData[$field] === null)) {
                $itemData[$field] = 0;
            }
        }

        if (isset($itemData['id'])) {
            $item = ContractItem::find($itemData['id']);
            $item->update($itemData);
        } else {
            $item = ContractItem::create($itemData);
            $this->items[$index]['id'] = $item->id;
        }

        $this->syncTimeRates($item, $timeRates);
        $this->syncMissingContractTermSnapshots();
    }

    public function removeItem($index)
    {
        if (!$this->isEditable) {
            return;
        }

        $itemData = $this->items[$index];

        if (isset($itemData['id'])) {
            ContractItem::find($itemData['id'])->delete();
        }

        unset($this->items[$index]);
        $this->items = array_values($this->items);
        $this->syncMissingContractTermSnapshots();
    }

    public function calculateTotalCost()
    {
        $totalCost = 0;
        foreach ($this->items as $item) {
            $totalCost += (float)($item['item_cost'] ?? 0) * (int)($item['quantity'] ?? 1);
        }

        return number_format($totalCost, 2, ',', ' ') . ' kr';
    }

    public function calculateTotalDiscount()
    {
        $totalDiscount = 0;
        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $totalDiscount += $totals['discount_total'] ?? 0;
        }

        return number_format($totalDiscount, 2, ',', ' ') . ' kr';
    }

    public function calculateTotalAmount()
    {
        $totalAmount = 0;
        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $totalAmount += $totals['total_numeric'] ?? 0;
        }

        return number_format($totalAmount, 2, ',', ' ') . ' kr';
    }

    public function calculateAnnualProfit()
    {
        $annualProfit = 0;

        foreach ($this->items as $index => $item) {
            $totals = $this->calculateLineTotals($index);
            $revenuePerPeriod = $totals['total_numeric'] ?? 0;
            $costPerPeriod = (float)($item['item_cost'] ?? 0) * (int)($item['quantity'] ?? 1);

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

        return number_format($annualProfit, 2, ',', ' ') . ' kr';
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
                'sort_order' => $index,
            ];

            $snapshot = isset($rate['id'])
                ? ContractItemTimeRate::query()->where('contract_item_id', $item->id)->find($rate['id'])
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

        if (($snapshots['sla_snapshot'] ?? '') !== '') {
            $updates['sla_snapshot'] = $snapshots['sla_snapshot'];
        }

        if ($updates !== []) {
            $contract->update($updates);
            $this->contract = $contract->fresh();
        }
    }

    public function render()
    {
        return view('commercial::Livewire.Tech.Contract.contract-items-editor');
    }
}
