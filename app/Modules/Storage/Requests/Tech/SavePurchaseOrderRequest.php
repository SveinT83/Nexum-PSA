<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SavePurchaseOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $lines = collect($this->input('lines', []))
            ->map(function (mixed $line): mixed {
                if (! is_array($line)) {
                    return $line;
                }

                $line['qty_cancelled'] = $line['qty_cancelled'] ?? 0;

                return $line;
            })
            ->values()
            ->all();

        $this->merge([
            'po_number' => trim((string) $this->input('po_number', '')),
            'vendor_ref' => filled($this->input('vendor_ref'))
                ? trim((string) $this->input('vendor_ref'))
                : null,
            'currency' => strtoupper((string) $this->input('currency', 'NOK')),
            'lines' => $lines,
        ]);
    }

    public function rules(): array
    {
        return [
            'po_number' => ['required', 'string', 'max:100'],
            'vendor_id' => ['required', 'integer', 'exists:vendors,id'],
            'deliver_to_warehouse_id' => ['required', 'integer', 'exists:storage_warehouses,id'],
            'status' => ['required', Rule::in(['draft', 'ordered'])],
            'vendor_ref' => ['nullable', 'string', 'max:255'],
            'ordered_at' => ['nullable', 'date', 'required_if:status,ordered'],
            'expected_at' => ['nullable', 'date', 'after_or_equal:ordered_at'],
            'currency' => ['required', 'string', 'size:3'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.id' => ['nullable', 'integer', 'exists:storage_purchase_order_lines,id'],
            'lines.*.item_id' => ['required', 'integer', 'exists:storage_items,id'],
            'lines.*.qty_ordered' => ['required', 'integer', 'min:1'],
            'lines.*.qty_cancelled' => ['required', 'integer', 'min:0'],
            'lines.*.cancellation_reason' => ['nullable', 'string', 'max:2000'],
            'lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'lines.*.expected_at' => ['nullable', 'date'],
            'lines.*.supplier_sku' => ['nullable', 'string', 'max:255'],
            'lines.*.metadata' => ['nullable', 'array'],
        ];
    }

    public function attributes(): array
    {
        return [
            'po_number' => 'Nexum order number',
            'vendor_ref' => 'supplier order number',
            'vendor_id' => 'supplier',
            'deliver_to_warehouse_id' => 'destination warehouse',
            'lines.*.item_id' => 'line item',
            'lines.*.qty_ordered' => 'ordered quantity',
            'lines.*.qty_cancelled' => 'cancelled quantity',
        ];
    }
}
