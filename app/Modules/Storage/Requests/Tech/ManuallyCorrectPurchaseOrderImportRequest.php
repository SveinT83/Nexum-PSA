<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManuallyCorrectPurchaseOrderImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('storage.purchase_import_resolve') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $correction = $this->input('correction');
        if (! is_array($correction)) {
            return;
        }

        $correction['currency'] = strtoupper(trim((string) ($correction['currency'] ?? '')));
        $correction['totals'] = array_merge([
            'freight' => '0',
            'discount' => '0',
            'other_charges' => '0',
            'total_ex_tax' => null,
        ], is_array($correction['totals'] ?? null) ? $correction['totals'] : []);

        $this->merge(['correction' => $correction]);
    }

    public function rules(): array
    {
        $money = ['required', 'numeric', 'min:0', 'max:999999999999.99'];

        return [
            'correction' => [
                'required',
                'array:supplier_name,external_order_number,ordered_at,currency,destination_warehouse_id,lines,totals,audit_reason',
            ],
            'correction.supplier_name' => ['required', 'string', 'max:500'],
            'correction.external_order_number' => ['required', 'string', 'max:255'],
            'correction.ordered_at' => ['required', 'date_format:Y-m-d'],
            'correction.currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'correction.destination_warehouse_id' => [
                'required',
                'integer',
                Rule::exists('storage_warehouses', 'id')
                    ->where(fn ($query) => $query->where('is_active', true)->whereNull('deleted_at')),
            ],
            'correction.lines' => ['required', 'array', 'min:1', 'max:500'],
            'correction.lines.*' => [
                'required',
                'array:supplier_sku,description,quantity,unit_price,line_total,tax_rate',
            ],
            'correction.lines.*.supplier_sku' => [
                'nullable',
                'string',
                'max:255',
                'required_without:correction.lines.*.description',
            ],
            'correction.lines.*.description' => [
                'nullable',
                'string',
                'max:2000',
                'required_without:correction.lines.*.supplier_sku',
            ],
            'correction.lines.*.quantity' => ['required', 'integer', 'min:1', 'max:1000000'],
            'correction.lines.*.unit_price' => ['nullable', 'numeric', 'min:0', 'max:999999999999.99'],
            'correction.lines.*.line_total' => $money,
            'correction.lines.*.tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'correction.totals' => ['required', 'array:freight,discount,other_charges,total_ex_tax'],
            'correction.totals.freight' => $money,
            'correction.totals.discount' => $money,
            'correction.totals.other_charges' => $money,
            'correction.totals.total_ex_tax' => $money,
            'correction.audit_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ];
    }
}
