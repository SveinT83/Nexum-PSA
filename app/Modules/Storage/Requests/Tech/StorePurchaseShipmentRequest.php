<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $allocations = collect($this->input('allocations', []))
            ->filter(
                fn (mixed $allocation): bool => ! is_array($allocation)
                    || (int) ($allocation['qty_allocated'] ?? 0) > 0
            )
            ->values()
            ->all();

        $trackings = collect($this->input('trackings', []))
            ->filter(
                fn (mixed $tracking): bool => ! is_array($tracking)
                    || filled($tracking['tracking_number'] ?? null)
            )
            ->values()
            ->map(function (mixed $tracking, int $index): mixed {
                if (! is_array($tracking)) {
                    return $tracking;
                }

                $tracking['tracking_type'] = $tracking['tracking_type'] ?? 'parcel';
                $tracking['sort_order'] = $tracking['sort_order'] ?? $index;

                return $tracking;
            })
            ->all();

        $this->merge([
            'status' => $this->input('status', 'pending'),
            'allocations' => $allocations,
            'trackings' => $trackings,
        ]);
    }

    public function rules(): array
    {
        return [
            'shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'reference' => ['nullable', 'string', 'max:255'],
            'status' => ['required', Rule::in([
                'pending',
                'in_transit',
                'delivered',
            ])],
            'shipped_at' => ['nullable', 'date'],
            'expected_at' => ['nullable', 'date'],
            'delivered_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'allocations' => ['array'],
            'allocations.*' => ['array'],
            'allocations.*.purchase_order_line_id' => [
                'required',
                'integer',
                'exists:storage_purchase_order_lines,id',
            ],
            'allocations.*.qty_allocated' => ['required', 'integer', 'min:1'],
            'trackings' => ['array'],
            'trackings.*' => ['array'],
            'trackings.*.shipping_carrier_id' => ['nullable', 'integer', 'exists:shipping_carriers,id'],
            'trackings.*.tracking_number' => ['required', 'string', 'max:255'],
            'trackings.*.tracking_type' => [
                'required',
                Rule::in(['master', 'parcel', 'last_mile', 'other', 'legacy']),
            ],
            'trackings.*.label' => ['nullable', 'string', 'max:255'],
            'trackings.*.direct_url' => ['nullable', 'url', 'max:2048'],
            'trackings.*.sort_order' => ['required', 'integer', 'min:0'],
        ];
    }
}
