<?php

namespace App\Modules\Documentation\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Menus\SideBar\DocumentationsMenu;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Documentation\Requests\ShippingCarrierRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingCarrierController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->input('q', ''));
        $lifecycle = (string) $request->input('lifecycle_state', '');

        if (! array_key_exists($lifecycle, ShippingCarrier::lifecycleOptions())) {
            $lifecycle = '';
        }

        $carriers = ShippingCarrier::query()
            ->with('vendor')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%")
                        ->orWhere('legal_name', 'like', "%{$search}%")
                        ->orWhere('website_url', 'like', "%{$search}%");
                });
            })
            ->when($lifecycle !== '', fn ($query) => $query->where('lifecycle_state', $lifecycle))
            ->orderBy('sort_order')
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        return view('documentation::Tech.shipping-carriers.index', [
            'sidebarMenuItems' => (new DocumentationsMenu)->DocumentationsMenu(),
            'carriers' => $carriers,
            'search' => $search,
            'lifecycle' => $lifecycle,
            'lifecycleOptions' => ShippingCarrier::lifecycleOptions(),
        ]);
    }

    public function create(): View
    {
        return $this->formView(new ShippingCarrier([
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'sort_order' => 100,
            'service_tags' => [],
            'tracking_method' => ShippingCarrier::TRACKING_GENERIC_PAGE,
            'allowed_tracking_hosts' => [],
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'verification_state' => ShippingCarrier::VERIFICATION_UNVERIFIED,
        ]));
    }

    public function store(ShippingCarrierRequest $request): RedirectResponse
    {
        $carrier = ShippingCarrier::query()->create([
            ...$request->carrierData(),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        return redirect()
            ->route('tech.documentations.shipping-carriers.show', $carrier)
            ->with('success', 'Shipping carrier created.');
    }

    public function show(ShippingCarrier $shippingCarrier): View
    {
        return view('documentation::Tech.shipping-carriers.show', [
            'sidebarMenuItems' => (new DocumentationsMenu)->DocumentationsMenu(),
            'carrier' => $shippingCarrier->load(['vendor', 'creator', 'updater']),
        ]);
    }

    public function edit(ShippingCarrier $shippingCarrier): View
    {
        return $this->formView($shippingCarrier);
    }

    public function update(
        ShippingCarrierRequest $request,
        ShippingCarrier $shippingCarrier,
    ): RedirectResponse {
        $shippingCarrier->forceFill([
            ...$request->carrierData(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return redirect()
            ->route('tech.documentations.shipping-carriers.show', $shippingCarrier)
            ->with('success', 'Shipping carrier updated.');
    }

    private function formView(ShippingCarrier $carrier): View
    {
        return view('documentation::Tech.shipping-carriers.form', [
            'sidebarMenuItems' => (new DocumentationsMenu)->DocumentationsMenu(),
            'carrier' => $carrier,
            'vendors' => Vendor::query()->orderBy('name')->get(['id', 'name', 'vendor_code']),
            'lifecycleOptions' => ShippingCarrier::lifecycleOptions(),
            'trackingMethodOptions' => ShippingCarrier::trackingMethodOptions(),
            'linkVisibilityOptions' => ShippingCarrier::linkVisibilityOptions(),
            'verificationOptions' => ShippingCarrier::verificationOptions(),
        ]);
    }
}
