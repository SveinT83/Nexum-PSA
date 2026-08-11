<?php

namespace App\Modules\Storage\Requests\Tech;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PostPurchaseReceiptRequest extends FormRequest
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

                $line['qty_accepted'] = (int) ($line['qty_accepted'] ?? 0);
                $line['qty_rejected'] = (int) ($line['qty_rejected'] ?? 0);

                $serials = collect(preg_split('/\R+/', (string) ($line['serial_numbers'] ?? '')))
                    ->map(fn (string $serial): string => trim($serial))
                    ->filter()
                    ->values();

                if ($serials->isNotEmpty()) {
                    $line['units'] = $serials
                        ->map(fn (string $serial): array => array_filter([
                            'serial_no' => $serial,
                            'batch_no' => filled($line['batch_no'] ?? null) ? trim((string) $line['batch_no']) : null,
                            'expiry_date' => $line['expiry_date'] ?? null,
                            'quantity' => 1,
                        ], fn ($value): bool => $value !== null && $value !== ''))
                        ->all();
                } elseif (
                    $line['qty_accepted'] > 0
                    && (filled($line['batch_no'] ?? null) || filled($line['expiry_date'] ?? null))
                ) {
                    $line['units'] = [array_filter([
                        'batch_no' => filled($line['batch_no'] ?? null) ? trim((string) $line['batch_no']) : null,
                        'expiry_date' => $line['expiry_date'] ?? null,
                        'quantity' => $line['qty_accepted'],
                    ], fn ($value): bool => $value !== null && $value !== '')];
                } else {
                    $line['units'] = $line['units'] ?? [];
                }

                return $line;
            })
            ->all();

        $this->merge(['lines' => $lines]);
    }

    public function rules(): array
    {
        return [
            'idempotency_token' => ['required', 'uuid', 'max:36'],
            'purchase_shipment_id' => ['nullable', 'integer', 'exists:storage_purchase_shipments,id'],
            'delivery_note_ref' => ['nullable', 'string', 'max:255'],
            'received_at' => ['nullable', 'date'],
            'warehouse_id' => ['nullable', 'integer', 'exists:storage_warehouses,id'],
            'room_id' => ['nullable', 'integer', 'exists:storage_rooms,id'],
            'box_id' => ['nullable', 'integer', 'exists:storage_boxes,id'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*' => ['array'],
            'lines.*.purchase_order_line_id' => [
                'required',
                'integer',
                'exists:storage_purchase_order_lines,id',
            ],
            'lines.*.qty_accepted' => ['required', 'integer', 'min:0'],
            'lines.*.qty_rejected' => ['required', 'integer', 'min:0'],
            'lines.*.discrepancy_note' => ['nullable', 'string', 'max:2000'],
            'lines.*.over_receipt_reason' => ['nullable', 'string', 'max:2000'],
            'lines.*.units' => ['array'],
            'lines.*.units.*.serial_no' => ['nullable', 'string', 'max:255'],
            'lines.*.units.*.batch_no' => ['nullable', 'string', 'max:255'],
            'lines.*.units.*.expiry_date' => ['nullable', 'date'],
            'lines.*.units.*.quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $hasQuantity = collect($this->input('lines', []))
                    ->contains(
                        fn (mixed $line): bool => is_array($line)
                            && (
                                (int) ($line['qty_accepted'] ?? 0) > 0
                                || (int) ($line['qty_rejected'] ?? 0) > 0
                            )
                    );

                if (! $hasQuantity) {
                    $validator->errors()->add(
                        'lines',
                        'Enter an accepted or rejected quantity for at least one line.'
                    );
                }
            },
        ];
    }
}
