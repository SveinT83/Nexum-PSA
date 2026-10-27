<?php

namespace App\Modules\Documentation\Support;

use App\Modules\Documentation\Models\Vendor;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** One fixed master-data schema for web and API. Import provenance is system-owned. */
class VendorData
{
    public const FIELDS = ['name', 'vendor_code', 'org_no', 'url', 'phone', 'email',
        'default_lead_time_days', 'note', 'terms', 'is_vendor', 'is_supplier', 'is_manufacturer', 'is_active'];

    public function validated(Request $request, ?Vendor $vendor = null, string $role = 'vendors', bool $partial = false): array
    {
        $rules = [
            'name' => [$partial ? 'sometimes' : 'required', 'string', 'max:255'],
            'vendor_code' => ['nullable', 'string', 'max:255', Rule::unique('vendors', 'vendor_code')->ignore($vendor?->id)],
            'org_no' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'default_lead_time_days' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'is_vendor' => ['sometimes', 'boolean'],
            'is_supplier' => ['sometimes', 'boolean'],
            'is_manufacturer' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
        $data = $request->validate($rules);
        if (array_key_exists('default_lead_time_days', $data)) {
            $data['default_lead_time_days'] ??= 0;
        }

        return $partial ? $data : $data + [
            'default_lead_time_days' => 0, 'is_vendor' => $role !== 'suppliers',
            'is_supplier' => $role === 'suppliers', 'is_manufacturer' => $role !== 'suppliers',
            'is_active' => false,
        ];
    }
}
