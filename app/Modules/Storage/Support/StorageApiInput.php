<?php

namespace App\Modules\Storage\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StorageApiInput
{
    /** Reject unsupported top-level keys before browser requests add system-owned defaults. */
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Storage API field.'));
        }
    }

    public static function legacyShape(Request $request): void
    {
        $name = $request->route()->getName();
        $lists = [];
        if (in_array($name, ['api.v1.storage.purchase-orders.store', 'api.v1.storage.purchase-orders.update'], true)) {
            $lists['lines'] = ['id', 'item_id', 'qty_ordered', 'qty_cancelled', 'cancellation_reason', 'unit_cost', 'tax_rate', 'expected_at', 'supplier_sku', 'metadata'];
        } elseif ($name === 'api.v1.storage.purchase-orders.shipments.store') {
            $lists['allocations'] = ['purchase_order_line_id', 'qty_allocated'];
            $lists['trackings'] = ['shipping_carrier_id', 'tracking_number', 'tracking_type', 'label', 'direct_url', 'sort_order'];
        } elseif ($name === 'api.v1.storage.purchase-orders.receipts.store') {
            $lists['lines'] = ['purchase_order_line_id', 'qty_accepted', 'qty_rejected', 'discrepancy_note', 'over_receipt_reason', 'units', 'serial_numbers', 'batch_no', 'expiry_date'];
        }
        foreach ($lists as $field => $allowed) {
            foreach ((array) $request->input($field, []) as $index => $row) {
                if (! is_array($row)) {
                    continue; // The authoritative validator reports malformed row types.
                }
                foreach (array_diff(array_keys($row), $allowed) as $unknown) {
                    throw ValidationException::withMessages([$field.'.'.$index.'.'.$unknown => 'Unsupported Storage API field.']);
                }
                if (isset($row['units']) && is_array($row['units'])) {
                    foreach ($row['units'] as $unitIndex => $unit) {
                        if (! is_array($unit)) {
                            continue;
                        }
                        foreach (array_diff(array_keys($unit), ['serial_no', 'batch_no', 'expiry_date', 'quantity']) as $unknown) {
                            throw ValidationException::withMessages([$field.'.'.$index.'.units.'.$unitIndex.'.'.$unknown => 'Unsupported Storage API field.']);
                        }
                    }
                }
            }
        }
    }

    public static function page(Request $request): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($data['per_page'] ?? 25);
    }

    /** Reuse the browser normalizers, validation and authorization without redirecting. */
    public static function validated(Request $request, string $class): array
    {
        $form = FormRequest::createFrom($request, new $class);
        // TrimStrings/ConvertEmptyStringsToNull run before routing. Profile definitions
        // contain meaningful whitespace (for example thousands separators); restore
        // structured domain documents from JSON before browser validation.
        $raw = $request->isJson() ? self::payload($request) : [];
        foreach (['definition', 'matching_scope', 'policy_overrides', 'export', 'correction'] as $field) {
            if (is_array($raw) && array_key_exists($field, $raw)) {
                $form->merge([$field => $raw[$field]]);
            }
        }

        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->setUserResolver($request->getUserResolver());
        $route = clone $request->route();
        if ($id = $route->parameter('profile')) {
            $route->setParameter('purchaseOrderImportProfile', \App\Modules\Storage\Models\PurchaseOrderImportProfile::findOrFail($id));
        }
        if ($id = $route->parameter('import')) {
            $route->setParameter('purchaseOrderImport', \App\Modules\Storage\Models\PurchaseOrderImport::findOrFail($id));
        }
        $form->setRouteResolver(fn () => $route);
        $form->validateResolved();

        return $form->validated();
    }

    public static function payload(Request $request): array
    {
        if (! $request->isJson()) {
            return $request->all();
        }
        try {
            $value = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
        }
        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
        }

        return $value;
    }

    public static function revision(mixed $value): string
    {
        $sort = function (&$data) use (&$sort): void {
            if (! is_array($data)) {
                return;
            }
            if (! array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$child) {
                $sort($child);
            }
        };
        $sort($value);

        return '"'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)).'"';
    }
}
