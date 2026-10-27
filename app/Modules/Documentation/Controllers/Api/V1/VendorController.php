<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Actions\SaveVendor;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Documentation\Resources\Api\V1\VendorResource;
use App\Modules\Documentation\Support\DocumentationApiInput;
use App\Modules\Documentation\Support\VendorData;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Canonical supplier/manufacturer master, shared with Storage references. */
class VendorController extends Controller
{
    public function index(Request $request)
    {
        $perPage = DocumentationApiInput::page($request);
        $data = $request->validate([
            'q' => 'sometimes|nullable|string|max:255',
            'role' => ['sometimes', Rule::in(['all', 'vendors', 'suppliers', 'manufacturers'])],
            'is_active' => 'sometimes|boolean',
        ]);
        $role = $request->route()->defaults['vendor_role'] ?? ($data['role'] ?? 'vendors');
        $query = Vendor::query()->orderBy('name')->orderBy('id');
        if ($role === 'suppliers') {
            $query->where('is_supplier', true);
        } elseif ($role === 'manufacturers') {
            $query->where('is_manufacturer', true);
        } elseif ($role === 'vendors') {
            $query->where(fn ($q) => $q->where('is_vendor', true)->orWhere('is_manufacturer', true));
        }
        if (array_key_exists('is_active', $data)) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($needle = trim($data['q'] ?? '')) {
            $query->where(function ($q) use ($needle): void {
                foreach (['name', 'vendor_code', 'org_no', 'email', 'url'] as $field) {
                    $q->orWhere($field, 'like', '%'.$needle.'%');
                }
            });
        }

        return VendorResource::collection($query->paginate($perPage));
    }

    public function store(Request $request, VendorData $data, SaveVendor $save)
    {
        $vendor = $save->handle($data->validated($request, role: $request->route()->defaults['vendor_role'] ?? 'vendors'));
        $request->route()->setParameter('vendor', $vendor);
        $request->route()->defaults['documentation_target'] = 'vendor';

        return (new VendorResource($vendor))->response()->setStatusCode(201);
    }

    public function show(Vendor $vendor)
    {
        return new VendorResource($vendor);
    }

    public function update(Request $request, Vendor $vendor, VendorData $data, SaveVendor $save)
    {
        return new VendorResource($save->handle($data->validated($request, $vendor, partial: true), $vendor));
    }
}
