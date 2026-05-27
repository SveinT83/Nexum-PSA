<?php

namespace App\Modules\Documentation\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Menus\SideBar\DocumentationsMenu;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Validation\Rule;

class VendorController extends Controller
{
    /**
     * Show the canonical vendor/supplier register inside Documentation.
     */
    public function index(Request $request, string $role = 'vendors'): View
    {
        $role = $role === 'suppliers' ? 'suppliers' : 'vendors';
        $search = trim((string) $request->input('q', ''));

        $vendors = Vendor::query()
            ->when($role === 'suppliers', fn ($query) => $query->where('is_supplier', true))
            ->when($role === 'vendors', fn ($query) => $query->where(function ($query): void {
                $query->where('is_vendor', true)->orWhere('is_manufacturer', true);
            }))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('vendor_code', 'like', "%{$search}%")
                        ->orWhere('org_no', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('url', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('documentation::Tech.vendors.index', [
            'sidebarMenuItems' => (new DocumentationsMenu())->DocumentationsMenu(),
            'vendors' => $vendors,
            'role' => $role,
            'search' => $search,
        ]);
    }

    /**
     * Show the fixed create form for vendor/supplier master data.
     */
    public function create(string $role = 'vendors'): View
    {
        return view('documentation::Tech.vendors.form', [
            'sidebarMenuItems' => (new DocumentationsMenu())->DocumentationsMenu(),
            'vendor' => new Vendor([
                'is_vendor' => $role !== 'suppliers',
                'is_supplier' => $role === 'suppliers',
                'is_manufacturer' => $role !== 'suppliers',
                'is_active' => true,
            ]),
            'role' => $role === 'suppliers' ? 'suppliers' : 'vendors',
        ]);
    }

    /**
     * Persist a new vendor/supplier.
     */
    public function store(Request $request, string $role = 'vendors'): RedirectResponse
    {
        $vendor = Vendor::create($this->validated($request, null, $role));

        return redirect()->route('tech.documentations.vendors.show', $vendor)
            ->with('success', 'Vendor saved.');
    }

    /**
     * Show the fixed vendor/supplier profile.
     */
    public function show(Vendor $vendor): View
    {
        return view('documentation::Tech.vendors.show', [
            'sidebarMenuItems' => (new DocumentationsMenu())->DocumentationsMenu(),
            'vendor' => $vendor,
        ]);
    }

    /**
     * Show the fixed edit form for vendor/supplier master data.
     */
    public function edit(Vendor $vendor): View
    {
        return view('documentation::Tech.vendors.form', [
            'sidebarMenuItems' => (new DocumentationsMenu())->DocumentationsMenu(),
            'vendor' => $vendor,
            'role' => $vendor->is_supplier && ! $vendor->is_vendor && ! $vendor->is_manufacturer ? 'suppliers' : 'vendors',
        ]);
    }

    /**
     * Update an existing vendor/supplier.
     */
    public function update(Request $request, Vendor $vendor): RedirectResponse
    {
        $vendor->update($this->validated($request, $vendor));

        return redirect()->route('tech.documentations.vendors.show', $vendor)
            ->with('success', 'Vendor updated.');
    }

    /**
     * Shared validation keeps the fixed Documentation-owned vendor schema stable.
     */
    private function validated(Request $request, ?Vendor $vendor = null, string $role = 'vendors'): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'vendor_code' => ['nullable', 'string', 'max:255', Rule::unique('vendors', 'vendor_code')->ignore($vendor?->id)],
            'org_no' => ['nullable', 'string', 'max:255'],
            'url' => ['nullable', 'string', 'max:2048'],
            'phone' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'default_lead_time_days' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string'],
            'terms' => ['nullable', 'string'],
            'is_vendor' => ['nullable', 'boolean'],
            'is_supplier' => ['nullable', 'boolean'],
            'is_manufacturer' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ]) + [
            'default_lead_time_days' => 0,
            'is_vendor' => $role !== 'suppliers',
            'is_supplier' => $role === 'suppliers',
            'is_manufacturer' => $role !== 'suppliers',
            'is_active' => false,
        ];
    }
}
