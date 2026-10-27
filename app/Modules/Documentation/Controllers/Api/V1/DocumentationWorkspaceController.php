<?php

namespace App\Modules\Documentation\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Documentation\Models\DocumentationApiCommand;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Support\DocumentationApiAccess;
use App\Modules\Documentation\Support\DocumentationApiInput;
use Illuminate\Http\Request;

class DocumentationWorkspaceController extends Controller
{
    /** Explicit request filters replace the browser's mutable session context. */
    public function context(Request $request)
    {
        return response()->json(['data' => [
            'scope_types' => ['internal', 'client', 'site'],
            'limits' => ['default_per_page' => 25, 'maximum_per_page' => 100],
            'carrier_lifecycle' => ShippingCarrier::lifecycleOptions(),
            'tracking_methods' => ShippingCarrier::trackingMethodOptions(),
            'link_visibility' => ShippingCarrier::linkVisibilityOptions(),
            'verification_states' => ShippingCarrier::verificationOptions(),
            'vendor_defaults' => ['default_lead_time_days' => 0, 'is_vendor' => true, 'is_supplier' => false, 'is_manufacturer' => true, 'is_active' => false],
            'supplier_defaults' => ['default_lead_time_days' => 0, 'is_vendor' => false, 'is_supplier' => true, 'is_manufacturer' => false, 'is_active' => false],
            'carrier_defaults' => ['lifecycle_state' => 'active', 'sort_order' => 100, 'service_tags' => [],
                'tracking_method' => 'generic_page', 'allowed_tracking_hosts' => [], 'link_visibility' => 'normal', 'verification_state' => 'unverified'],
            'catalogs' => ['clients' => route('api.v1.documentation.lookups', 'clients'),
                'sites' => route('api.v1.documentation.lookups', 'sites'),
                'categories' => route('api.v1.knowledge.documentation-categories.index'),
                'templates' => route('api.v1.knowledge.documentation-templates.index'),
                'vendors' => route('api.v1.documentation.lookups', 'vendors'),
                'carriers' => route('api.v1.documentation.shipping-carriers.index')],
            'capabilities' => ['immutable_revisions' => false, 'approval_workflow' => false, 'revision_issue' => 275],
        ]]);
    }

    public function lookups(Request $request, string $kind)
    {
        $perPage = DocumentationApiInput::page($request);
        $data = $request->validate(['q' => 'sometimes|nullable|string|max:255', 'client_id' => 'sometimes|integer|exists:clients,id']);
        DocumentationApiAccess::authorizeAny($request->user(), $kind === 'vendors'
            ? ['documentation.view', 'documentation.carrier_manage']
            : ['documentation.view', 'documentation.create', 'documentation.update']);
        if ($kind === 'vendors') {
            $query = \App\Modules\Documentation\Models\Vendor::query()->select(['id', 'name', 'vendor_code']);
        } elseif ($kind === 'clients') {
            $query = Client::query()->where('active', true)->select(['id', 'name']);
        } else {
            abort_unless($kind === 'sites', 404);
            $request->validate(['client_id' => 'required|integer|exists:clients,id']);
            $query = ClientSite::query()->where('client_id', $data['client_id'])->select(['id', 'name', 'client_id']);
        }
        if ($needle = trim($data['q'] ?? '')) {
            $query->where('name', 'like', '%'.$needle.'%');
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate($perPage));
    }

    public function operation(Request $request, string $operation)
    {
        $command = DocumentationApiCommand::whereKey($operation)->where('actor_id', $request->user()->id)
            ->where('token_hash', DocumentationApiAccess::tokenHash($request))->firstOrFail();
        DocumentationApiAccess::authorize($request->user(), $command->result['permissions']);
        abort_unless($request->user()->tokenCan($command->result['ability']), 403);

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation,
            'state' => $command->state, 'status' => $command->result['status'],
            'result' => $command->result['body'], 'revision' => $command->result['etag'],
            'reconciliation_required' => $command->state === 'unknown',
            'created_at' => $command->created_at]]);
    }
}
