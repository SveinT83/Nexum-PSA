<?php

namespace App\Livewire\Tech\Cs\Contracts;

use App\Models\CS\Contracts\ContractItem;
use App\Models\CS\Contracts\Contracts;
use App\Models\CS\Services\Services;
use Livewire\Component;

class ContractItemsEditor extends Component
{
    public $contract;
    public $items = [];
    public $availableServices = [];
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
    ];

    public function mount(Contracts $contract)
    {
        $this->contract = $contract;
        $this->isEditable = $contract->isEditable();
        $this->availableServices = Services::all();
        $this->loadItems();
    }

    public function loadItems()
    {
        $this->items = $this->contract->items()
            ->with(['service.costRelations.cost'])
            ->get()
            ->map(function ($item) {
                $data = $item->toArray();
                $data['tax_rate'] = $item->service->taxable ?? 0;
                $data['item_cost'] = $item->service ? $item->service->costRelations->sum(fn($cr) => $cr->cost->cost ?? 0) : 0;
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
            'tax_rate' => 0,
            'item_cost' => 0,
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
            $service = Services::with('unit')->find($value);
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

                // Calculate item cost
                $this->items[$index]['item_cost'] = $service->costRelations->sum(fn($cr) => $cr->cost->cost ?? 0);

                // Magic quantity calculation
                $this->items[$index]['quantity'] = $this->calculateQuantity($service);
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

        if (empty($itemData['service_id'])) {
            return;
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

    public function render()
    {
        return view('livewire.tech.cs.contract.contract-items-editor');
    }
}
