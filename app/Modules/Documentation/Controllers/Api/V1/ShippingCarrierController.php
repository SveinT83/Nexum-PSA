<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Documentation\Actions\SaveShippingCarrier;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Resources\Api\V1\ShippingCarrierResource;
use App\Modules\Documentation\Support\DocumentationApiInput;
use App\Modules\Documentation\Support\ShippingTrackingLinkResolver;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ShippingCarrierController extends Controller
{
    public function index(Request $request)
    {
        $perPage = DocumentationApiInput::page($request);
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:255',
            'lifecycle_state' => ['sometimes', Rule::in(array_keys(ShippingCarrier::lifecycleOptions()))]]);
        $query = ShippingCarrier::with('vendor')->orderBy('sort_order')->orderBy('name')->orderBy('id');
        if ($needle = trim($data['q'] ?? '')) {
            $query->where(fn ($q) => $q->where('name', 'like', '%'.$needle.'%')->orWhere('code', 'like', '%'.$needle.'%')
                ->orWhere('legal_name', 'like', '%'.$needle.'%')->orWhere('website_url', 'like', '%'.$needle.'%'));
        }
        if (isset($data['lifecycle_state'])) {
            $query->where('lifecycle_state', $data['lifecycle_state']);
        }

        return ShippingCarrierResource::collection($query->paginate($perPage));
    }

    public function store(Request $request, SaveShippingCarrier $save)
    {
        $carrier = $save->handle(DocumentationApiInput::carrier($request), $request->user());
        $request->route()->setParameter('shippingCarrier', $carrier);
        $request->route()->defaults['documentation_target'] = 'shippingCarrier';

        return (new ShippingCarrierResource($carrier->load('vendor')))->response()->setStatusCode(201);
    }

    public function show(ShippingCarrier $shippingCarrier)
    {
        return new ShippingCarrierResource($shippingCarrier->load('vendor'));
    }

    public function update(Request $request, ShippingCarrier $shippingCarrier, SaveShippingCarrier $save)
    {
        // PATCH retains omitted values; validation still applies to the complete
        // resulting profile using the authoritative browser rules.
        $base = $shippingCarrier->only(ShippingCarrierResource::FIELDS);
        $base['verified_at'] = $shippingCarrier->verified_at?->toDateString();
        $request->merge(array_replace($base, $request->all()));

        return new ShippingCarrierResource($save->handle(DocumentationApiInput::carrier($request),
            $request->user(), $shippingCarrier)->load('vendor'));
    }

    public function preview(Request $request, ShippingCarrier $shippingCarrier, ShippingTrackingLinkResolver $resolver)
    {
        $data = $request->validate(['tracking_number' => 'required|string|max:255',
            'direct_url' => 'sometimes|nullable|string|max:2048']);

        return response()->json(['data' => ['shipping_carrier_id' => $shippingCarrier->id,
            'tracking_number' => $data['tracking_number'],
            'url' => $resolver->forCarrier($shippingCarrier, $data['tracking_number'], $data['direct_url'] ?? null),
            'link_visibility' => $shippingCarrier->link_visibility,
            'provider_executed' => false]]);
    }
}
