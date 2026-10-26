<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\StorageApiCommand;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Resources\Api\V1\StorageBoxResource;
use App\Modules\Storage\Resources\Api\V1\StorageWarehouseResource;
use App\Modules\Storage\Support\StorageApiAccess;
use App\Modules\Storage\Support\StorageApiInput;
use App\Modules\Storage\Support\StorageInventoryDefaults;
use Illuminate\Http\Request;

/** Safe API projections of canonical Storage queries; GET never bootstraps defaults. */
class StorageApiQuery
{
    public function handle(Request $request)
    {
        $operation = $request->route()->defaults['storage_operation'];
        StorageApiInput::allowed($request, $request->route()->defaults['storage_fields'] ?? []);
        StorageApiInput::page($request);

        return match ($operation) {
            'context' => $this->context($request),
            'item-edit-context' => $this->itemEditContext($request),
            'order-edit-context' => $this->orderEditContext($request),
            'item-lookup' => $this->itemLookup($request),
            'profile-context' => response()->json(['data' => ['definition' => app(\App\Modules\Storage\Support\SupplierOrderProfileDefaults::class)->definition()]]),
            'suppliers' => $this->suppliers($request),
            'rooms' => response()->json(\App\Modules\Storage\Models\Room::where('is_active', true)
                ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
                ->orderBy('name')->orderBy('id')->paginate(StorageApiInput::page($request))
                ->through(fn ($room) => $room->only(['id', 'warehouse_id', 'name', 'code', 'is_active']))),
            'carriers' => response()->json(ShippingCarrier::where('lifecycle_state', '<>', ShippingCarrier::LIFECYCLE_INACTIVE)
                ->orderBy('sort_order')->orderBy('name')->orderBy('id')->paginate(StorageApiInput::page($request))
                ->through(fn ($carrier) => $carrier->only(['id', 'name', 'code', 'lifecycle_state']))),

            'supplier' => response()->json(['data' => $this->supplier(Vendor::findOrFail($request->route('supplier')))]),
            'summary' => response()->json(['data' => [
                'total_items' => Item::count(),
                'out_of_stock' => Item::where('qty_on_hand', '<=', 0)->count(),
                'should_order' => app(StorageIndexQuery::class)->shouldOrderCount(),
                'reserved' => (int) Item::sum('qty_reserved'),
            ]]),
            'box' => $this->box($request),
            'movements' => $this->movements($request),
            'picking' => $this->picking($request),
            'workspace', 'imports', 'receiving' => $this->workspace($request, $operation),
            'import' => response()->json(['data' => $this->import(PurchaseOrderImport::findOrFail($request->route('import')), $request)]),
            'settings' => $this->settings($request),
            'policy' => $this->policy($request),
            'profiles' => $this->profiles($request),
            'profile' => response()->json(['data' => $this->profile(PurchaseOrderImportProfile::findOrFail($request->route('profile')))]),
            'profile-export' => response()->json(app(\App\Modules\Storage\Actions\ExportSupplierOrderProfile::class)->handle(PurchaseOrderImportProfile::findOrFail($request->route('profile')))),
            'control-slip' => $this->controlSlip($request),
            'receiving-context' => $this->receivingContext($request),
            'docs' => $this->docs($request),
            'operation' => $this->operation($request),
            'preview' => $this->preview($request),
            default => abort(404),
        };
    }

    public function context(Request $request)
    {
        $actor = $request->user();
        $permissions = [];
        foreach (['view', 'item_manage', 'stock_adjust', 'pick', 'manage_settings', 'purchase_view', 'purchase_manage',
            'purchase_receive', 'purchase_receive_overage', 'purchase_reverse', 'purchase_import_view',
            'purchase_import_resolve', 'purchase_import_execute', 'purchase_import_profile_manage', 'purchase_import_policy_manage'] as $permission) {
            $permissions['storage.'.$permission] = StorageApiAccess::allows($actor, 'storage.'.$permission);
        }

        return response()->json(['data' => [
            'permissions' => $permissions,
            'default_warehouse_id' => app(StorageInventoryDefaults::class)->configuredDefaultWarehouse()?->id,
            'default_vat_rate' => \App\Modules\Economy\Models\EconomySetting::query()->value('default_vat_rate'),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'boxes' => Box::where('is_active', true)->orderBy('code_human')->get(['id', 'warehouse_id', 'code_human', 'name']),
            'purchase_statuses' => PurchaseOrder::statuses(), 'import_statuses' => PurchaseOrderImport::statuses(),
            'import_stages' => PurchaseOrderImport::stages(),
            'item_lookup_url' => route('api.v1.storage.lookups.items'),
            'inventory_sorts' => StorageIndexQuery::SORTABLE_COLUMNS, 'picking_sorts' => PickingListQuery::SORTABLE_COLUMNS,
            'supplier_order_scopes' => SupplierOrderWorkspaceQuery::SCOPES,
            'api_automation_enabled' => (bool) config('storage_api.automation_enabled'),
            'api_ai_repair_enabled' => (bool) config('storage_api.ai_repair_enabled'),
        ]]);
    }

    private function itemLookup(Request $request)
    {
        $request->validate(['q' => 'sometimes|string|max:255', 'warehouse_id' => 'sometimes|integer|min:1']);
        $priced = StorageApiAccess::allows($request->user(), 'storage.view')
            || StorageApiAccess::allows($request->user(), 'storage.item_manage')
            || StorageApiAccess::allows($request->user(), 'storage.purchase_manage');

        return response()->json(Item::where('status', 'active')->where('can_be_ordered', true)
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($inner) => $inner->where('sku', 'like', '%'.$request->string('q').'%')
                ->orWhere('name', 'like', '%'.$request->string('q').'%')))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->integer('warehouse_id')))
            ->orderBy('sku')->orderBy('id')->paginate(StorageApiInput::page($request))->through(fn ($item) => $item->only(
                $priced ? ['id', 'sku', 'name', 'warehouse_id', 'primary_vendor_id', 'purchase_price', 'vat_rate', 'status', 'can_be_ordered']
                    : ['id', 'sku', 'name', 'warehouse_id'])));
    }

    private function itemEditContext(Request $request)
    {
        $item = Item::with(['warehouse', 'box', 'primaryVendor', 'manufacturerVendor', 'itemVendors.vendor'])
            ->findOrFail($request->route('item'));
        app(StorageIndexQuery::class)->withIncoming($item);

        return response()->json(['data' => [
            'item' => (new \App\Modules\Storage\Resources\Api\V1\StorageItemResource($item))->resolve($request),
            'tracking_configuration_locked' => $item->trackingConfigurationIsLocked(),
            'can_use_generic_adjustment' => ! $item->requiresUnitAwareInventoryMutation(),
            'warehouses' => Warehouse::where('is_active', true)->orderBy('name')->get(['id', 'name', 'code']),
            'boxes' => Box::where('is_active', true)->orderBy('code_human')->get(['id', 'warehouse_id', 'code_human', 'name']),
            'default_vat_rate' => \App\Modules\Economy\Models\EconomySetting::query()->value('default_vat_rate'),
        ]]);
    }

    private function orderEditContext(Request $request)
    {
        $order = PurchaseOrder::findOrFail($request->route('purchaseOrder'));
        abort_unless(in_array($order->status, ['draft', 'ordered'], true), 409, 'This purchase order is locked for normal editing.');
        $order->load(['lines.item', 'lines.shipmentLines', 'lines.receiptLines']);
        $form = app(PurchaseOrderFormQuery::class)->data($order);

        return response()->json(['data' => [
            'purchase_order' => (new \App\Modules\Storage\Resources\Api\V1\PurchaseOrderResource($order))->resolve($request),
            'has_operational_history' => $form['hasOperationalHistory'],
            'items' => $form['items']->map(fn ($item) => $item->only(['id', 'sku', 'name', 'warehouse_id', 'primary_vendor_id', 'purchase_price', 'vat_rate', 'status', 'can_be_ordered'])),
            'suppliers' => $form['suppliers']->map(fn ($supplier) => $this->supplier($supplier)),
            'warehouses' => $form['warehouses']->map(fn ($warehouse) => $warehouse->only(['id', 'name', 'code', 'is_active'])),
        ]]);
    }

    private function profiles(Request $request)
    {
        $request->validate(['q' => 'sometimes|string|max:255', 'vendor_id' => 'sometimes|integer|min:1',
            'state' => 'sometimes|in:draft,shadow,active,degraded,paused,retired',
            'lifecycle_state' => 'sometimes|in:draft,shadow,active,degraded,paused,retired',
            'sort' => 'sometimes|in:updated,name,state,health,priority,supplier', 'direction' => 'sometimes|in:asc,desc']);
        if ($request->has('lifecycle_state')) {
            $request->query->set('state', $request->input('lifecycle_state'));
        }

        return response()->json(app(PurchaseOrderImportProfileIndexQuery::class)
            ->paginate($request, StorageApiInput::page($request))->through(fn ($profile) => $profile->only([
                'id', 'vendor_id', 'name', 'slug', 'description', 'lifecycle_state', 'priority', 'active_version_id',
                'health_state', 'versions_count', 'fixtures_count', 'updated_at',
            ])));
    }

    private function box(Request $request)
    {
        $request->validate(['box_item_sort' => 'sometimes|in:sku,name,on_hand,reserved',
            'box_item_direction' => 'sometimes|in:asc,desc', 'box_event_sort' => 'sometimes|in:when,type,actor',
            'box_event_direction' => 'sometimes|in:asc,desc']);
        $box = Box::with(['warehouse', 'items', 'events.actor'])->withCount('items')->findOrFail($request->route('box'));
        $sorter = app(\App\Modules\Storage\Support\CollectionTableSorter::class);
        $itemColumns = [];
        foreach (['sku' => 'sku', 'name' => 'name', 'on_hand' => 'qty_on_hand', 'reserved' => 'qty_reserved'] as $key => $field) {
            $itemColumns[$key] = ['type' => in_array($key, ['on_hand', 'reserved'], true) ? $sorter::TYPE_NUMBER : $sorter::TYPE_STRING,
                'value' => fn ($item) => $item->{$field}];
        }
        $eventColumns = [
            'when' => ['type' => $sorter::TYPE_DATE, 'value' => fn ($event) => $event->created_at],
            'type' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($event) => $event->type],
            'actor' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($event) => $event->actor?->name],
        ];
        $box->setRelation('items', $sorter->sort($box->items, $request->input('box_item_sort'), $request->input('box_item_direction', 'asc'), $itemColumns));
        $box->setRelation('events', $sorter->sort($box->events, $request->input('box_event_sort'), $request->input('box_event_direction', 'desc'), $eventColumns));

        return new StorageBoxResource($box);
    }

    private function settings(Request $request)
    {
        $request->validate(['warehouse_sort' => 'sometimes|in:default,name,code,address,items,boxes,status',
            'warehouse_direction' => 'sometimes|in:asc,desc']);
        $default = app(StorageInventoryDefaults::class)->configuredDefaultWarehouse();
        $warehouses = Warehouse::withCount(['items', 'boxes'])->orderBy('name')->get();
        $sorter = app(\App\Modules\Storage\Support\CollectionTableSorter::class);
        $columns = [
            'default' => ['type' => $sorter::TYPE_BOOLEAN, 'value' => fn ($w) => $default?->is($w) ?? false],
            'status' => ['type' => $sorter::TYPE_BOOLEAN, 'value' => fn ($w) => $w->is_active],
        ];
        foreach (['name' => 'name', 'code' => 'code', 'address' => 'address', 'items' => 'items_count', 'boxes' => 'boxes_count'] as $key => $field) {
            $columns[$key] = ['type' => in_array($key, ['items', 'boxes'], true) ? $sorter::TYPE_NUMBER : $sorter::TYPE_STRING,
                'value' => fn ($w) => $w->{$field}];
        }
        $warehouses = $sorter->sort($warehouses, $request->input('warehouse_sort'), $request->input('warehouse_direction', 'asc'), $columns);

        return response()->json(['data' => ['default_warehouse_id' => $default?->id,
            'warehouses' => StorageWarehouseResource::collection($warehouses)->resolve($request)]]);
    }

    private function receivingContext(Request $request)
    {
        $data = app(PurchaseReceivingContextQuery::class)->receive($request, PurchaseOrder::findOrFail($request->route('purchaseOrder')));

        return response()->json(['data' => [
            'purchase_order' => (new \App\Modules\Storage\Resources\Api\V1\PurchaseOrderResource($data['purchaseOrder']))->resolve($request),
            'selected_shipment_id' => $data['selectedShipment']?->id,
            'rooms' => $data['rooms']->map(fn ($room) => $room->only(['id', 'warehouse_id', 'name', 'code', 'is_active'])),
            'boxes' => StorageBoxResource::collection($data['boxes'])->resolve($request),
            'idempotency_token' => $data['idempotencyToken'],
            'can_receive_overage' => StorageApiAccess::allows($request->user(), 'storage.purchase_receive_overage')
                && $request->user()->tokenCan('storage.purchase.receive_overage'),
        ]]);
    }

    private function suppliers(Request $request)
    {
        $request->validate(['q' => 'sometimes|string|max:255', 'is_active' => 'sometimes|boolean',
            'is_supplier' => 'sometimes|boolean', 'is_manufacturer' => 'sometimes|boolean']);
        $query = Vendor::query()->where('is_active', $request->boolean('is_active', true))
            ->where('is_supplier', $request->boolean('is_supplier', true));
        if ($request->has('is_manufacturer')) {
            $query->where('is_manufacturer', $request->boolean('is_manufacturer'));
        }
        if ($request->filled('q')) {
            $needle = '%'.trim($request->string('q')).'%';
            $query->where(fn ($q) => $q->where('name', 'like', $needle)->orWhere('vendor_code', 'like', $needle));
        }

        return response()->json($query->orderBy('name')->orderBy('id')->paginate(StorageApiInput::page($request))
            ->through(fn ($supplier) => $this->supplier($supplier)));
    }

    private function supplier(Vendor $supplier): array
    {
        return $supplier->only(['id', 'name', 'vendor_code', 'is_vendor', 'is_supplier', 'is_manufacturer', 'is_active']);
    }

    private function movements(Request $request)
    {
        $item = Item::findOrFail($request->route('item'));
        $request->validate(['sort' => 'sometimes|in:id,created_at,type,qty_before,qty_delta,qty_after,reason',
            'direction' => 'sometimes|in:asc,desc']);

        return response()->json($item->movements()->with('actor')
            ->orderBy($request->input('sort', 'created_at'), $request->input('direction', 'desc'))->orderBy('id')
            ->paginate(StorageApiInput::page($request))->through(fn ($m) => [
                ...$m->only(['id', 'type', 'qty_before', 'qty_delta', 'qty_after', 'reason', 'created_at']),
                'actor' => $m->actor?->only(['id', 'name']),
            ]));
    }

    private function picking(Request $request)
    {
        $request->validate(['q' => 'sometimes|string|max:255', 'status' => 'sometimes|in:ready,waiting,identified',
            'sort' => 'sometimes|in:'.implode(',', PickingListQuery::SORTABLE_COLUMNS), 'direction' => 'sometimes|in:asc,desc']);
        $query = app(PickingListQuery::class);
        $rows = $query->paginate($request->all(), StorageApiInput::page($request));
        $canViewTicket = StorageApiAccess::allows($request->user(), 'ticket.view') && $request->user()->tokenCan('tickets.read');

        return response()->json($rows->through(fn ($entry) => [
            ...$entry->only(['id', 'ticket_id', 'storage_item_id', 'storage_reservation_id', 'item_sku', 'item_name', 'quantity', 'status']),
            'ticket' => $canViewTicket ? $entry->ticket?->only(['id', 'ticket_key', 'subject']) : null,
            'item' => $entry->storageItem?->only(['id', 'sku', 'name', 'qty_on_hand', 'qty_reserved', 'warehouse_id', 'box_id']),
        ])->toArray() + ['stats' => $query->stats()]);
    }

    private function workspace(Request $request, string $operation)
    {
        $request->validate(['q' => 'sometimes|string|max:255', 'direction' => 'sometimes|in:asc,desc',
            'per_page' => 'sometimes|in:25,50,100', 'tracking_number' => 'sometimes|string|max:255', 'method' => 'sometimes|string|max:255',
            'expected_after' => 'sometimes|date_format:Y-m-d', 'expected_before' => 'sometimes|date_format:Y-m-d',
            'vendor_id' => 'sometimes|integer|min:1', 'warehouse_id' => 'sometimes|integer|min:1',
            'status' => 'sometimes|in:'.implode(',', array_unique(array_merge(PurchaseOrder::statuses(), PurchaseOrderImport::statuses()))),
            'stage' => 'sometimes|in:'.implode(',', PurchaseOrderImport::stages()),
            'scope' => 'sometimes|in:'.implode(',', array_keys(SupplierOrderWorkspaceQuery::SCOPES)),
            'sort' => 'sometimes|in:order,supplier,status,expected,progress,outstanding,updated,destination,shipments,received']);
        $orders = $operation === 'receiving'
            ? StorageApiAccess::allows($request->user(), 'storage.purchase_receive') && $request->user()->tokenCan('storage.purchase.receive')
            : $operation !== 'imports' && StorageApiAccess::allows($request->user(), 'storage.purchase_view')
                && $request->user()->tokenCan('storage.purchase.read');
        $imports = $operation !== 'receiving' && StorageApiAccess::allows($request->user(), 'storage.purchase_import_view')
            && $request->user()->tokenCan('storage.imports.read');
        $filters = $request->all();
        if ($operation === 'receiving') {
            $filters['scope'] = 'receiving';
        }
        $rows = app(SupplierOrderWorkspaceQuery::class)->paginate($filters, $orders, $imports, $operation === 'receiving');

        return response()->json($rows->through(function ($row) use ($orders, $imports) {
            $data = (array) $row;
            // Internal metadata may contain source snapshots; expose only operational queue columns.
            $allowed = ['row_type', 'row_id', 'purchase_order_id', 'import_id', 'linked_import_count',
                'vendor_id', 'supplier_name', 'destination_warehouse_id', 'destination_name',
                'order_number', 'supplier_order_number', 'status', 'stage', 'import_method',
                'reason_code', 'source_type', 'ordered_at', 'expected_at', 'qty_ordered',
                'qty_received', 'qty_cancelled', 'qty_outstanding', 'lines_count', 'shipments_count', 'updated_at'];
            $data = array_intersect_key($data, array_flip($allowed));
            if (! $imports) {
                unset($data['import_id'], $data['import_method'], $data['linked_import_count']);
            }
            if (! $orders) {
                unset($data['purchase_order_id']);
            }

            return $data;
        }));
    }

    public function import(PurchaseOrderImport $import, Request $request): array
    {
        if (! StorageApiAccess::allows($request->user(), 'storage.purchase_import_view') || ! $request->user()->tokenCan('storage.imports.read')) {
            return $import->only(['id', 'status', 'stage', 'updated_at']) + [
                'lines' => $import->lines()->get()->map(fn ($line) => $line->only(['id', 'item_id', 'mapping_status'])),
            ];
        }
        StorageApiAccess::retainResponseAuthority($request, ['storage.purchase_import_view'], ['storage.imports.read']);
        if (StorageApiAccess::allows($request->user(), 'storage.purchase_view') && $request->user()->tokenCan('storage.purchase.read')) {
            StorageApiAccess::retainResponseAuthority($request, ['storage.purchase_view'], ['storage.purchase.read']);
        }
        if (StorageApiAccess::allows($request->user(), 'storage.purchase_import_profile_manage') && $request->user()->tokenCan('storage.profiles.manage')) {
            StorageApiAccess::retainResponseAuthority($request, ['storage.purchase_import_profile_manage'], ['storage.profiles.manage']);
        }

        $import->load(['lines', 'attempts', 'repairs']);
        $data = $import->only(['id', 'vendor_id', 'profile_id', 'profile_version_id', 'purchase_order_id',
            'external_order_number', 'status', 'stage', 'reason_code', 'extraction_method', 'next_retry_at',
            'last_actor_id', 'created_at', 'updated_at']);
        if (! StorageApiAccess::allows($request->user(), 'storage.purchase_view') || ! $request->user()->tokenCan('storage.purchase.read')) {
            unset($data['purchase_order_id']);
        }
        $data['source'] = app(\App\Modules\Storage\Support\SupplierOrderSourceSnapshot::class)->sanitizeStoredSnapshot(
            (array) ($import->safe_source_snapshot ?? []), (array) ($import->trusted_auth_snapshot ?? []));
        unset($data['source']['trusted_auth'], $data['source']['account_id'], $data['source']['imap_uid'], $data['source']['message_id']);
        $data['document'] = array_intersect_key((array) $import->normalized_document, array_flip([
            'schema_version', 'document_type', 'supplier', 'supplier_name', 'external_order_number', 'ordered_at',
            'currency', 'delivery', 'delivery_method', 'destination_warehouse_id', 'lines', 'totals', 'buyer_reference', 'po_reference', 'ordered_at_provenance',
        ]));
        $data['lines'] = $import->lines->map(fn ($line) => $line->only(['id', 'position', 'supplier_sku', 'description',
            'quantity', 'unit_price', 'line_total', 'tax_rate', 'currency', 'item_id', 'mapping_status', 'resolution_method']));
        $data['attempts'] = $import->attempts->map(fn ($attempt) => $attempt->only(['id', 'attempt_number', 'stage', 'method', 'status', 'reason_code', 'actor_id', 'started_at', 'completed_at']));
        $data['repair_history'] = array_map(function ($repair) use ($request) {
            unset($repair['ai_execution_uuid'], $repair['ai_governance']);
            if (! $request->user()->tokenCan('storage.profiles.manage')) {
                unset($repair['profile_candidate']);
            }
            if (! $request->user()->tokenCan('storage.purchase.read') || ! StorageApiAccess::allows($request->user(), 'storage.purchase_view')) {
                unset($repair['purchase_order_id']);
                $repair['can_open_purchase_order'] = false;
            }

            return $repair;
        }, app(\App\Modules\Storage\Support\SupplierOrderRepairHistoryPresenter::class)->present($import, $request->user()));

        return $data;
    }

    public function profile(PurchaseOrderImportProfile $profile): array
    {
        $profile->load(['versions', 'fixtures', 'metadataAudits']);
        $data = $profile->only(['id', 'vendor_id', 'name', 'slug', 'description', 'lifecycle_state', 'priority',
            'active_version_id', 'matching_scope', 'policy_overrides', 'health_state', 'consecutive_failures',
            'last_matched_at', 'last_success_at', 'pause_reason', 'updated_at']);
        $data['versions'] = $profile->versions->map(fn ($version) => $version->only(['id', 'profile_id', 'version_number',
            'parent_version_id', 'schema_version', 'status', 'definition', 'checksum', 'source', 'test_metrics',
            'validated_at', 'activated_at', 'activation_reason']));
        $data['fixtures'] = $profile->fixtures->map(fn ($fixture) => $fixture->only(['id', 'profile_version_id', 'name',
            'fixture_type', 'is_protected', 'source_checksum', 'expected_checksum', 'last_result', 'last_tested_at']));
        $data['metadata_audits'] = $profile->metadataAudits->map(fn ($audit) => $audit->only(['id', 'profile_id', 'actor_id', 'created_at', 'reason', 'changed_fields', 'before_snapshot', 'after_snapshot']));
        $data['fixture_candidates'] = PurchaseOrderImport::where('profile_id', $profile->id)
            ->whereNotNull('normalized_document')->whereIn('status', ['needs_attention', 'imported'])
            ->where('validation_results->valid', true)->latest('id')->limit(100)
            ->get(['id', 'external_order_number', 'status'])->toArray();

        return $data;
    }

    private function policy(Request $request)
    {
        $policy = PurchaseOrderAutomationPolicy::where('is_current', true)->first();
        $fields = ['id', 'runtime_mode', 'default_warehouse_id', 'ai_agent_id', 'ai_mode', 'amount_tolerance',
            'max_lines', 'max_quantity_per_line', 'max_order_total', 'max_new_items', 'supplier_bootstrap_mode',
            'new_item_mode', 'silent_success', 'daily_digest_enabled', 'revision_number', 'updated_at'];
        $data = $policy?->only($fields) ?? ['id' => null, 'runtime_mode' => 'off', 'ai_mode' => 'off', 'revision_number' => 0];
        $data['runtime_modes'] = PurchaseOrderAutomationPolicy::runtimeModes();
        $data['storage_agents'] = app(PurchaseOrderAutomationUiQuery::class)->storageAgents()->map(fn ($agent) => $agent->only(['id', 'name']));
        $data['revisions'] = $policy ? $policy->revisions()->latest('revision_number')->limit(100)
            ->get()->map(fn ($revision) => $revision->only(['id', 'revision_number', 'checksum', 'reason', 'created_by', 'created_at'])) : [];

        return response()->json(['data' => $data]);
    }

    private function controlSlip(Request $request)
    {
        $data = app(PurchaseReceivingContextQuery::class)->controlSlip($request, PurchaseOrder::findOrFail($request->route('purchaseOrder')));

        return response()->json(['data' => [
            'purchase_order' => (new \App\Modules\Storage\Resources\Api\V1\PurchaseOrderResource($data['purchaseOrder']))->resolve($request),
            'selected_shipment_id' => $data['selectedShipment']?->id,
        ]]);
    }

    private function docs(Request $request)
    {
        $topic = $request->query('topic', 'inventory');
        $topics = ['inventory' => 'storage-inventory', 'items' => 'storage-item-fields', 'suppliers' => 'storage-vendors-suppliers',
            'picking' => 'storage-picking-list', 'purchasing' => 'storage-purchase-orders-receiving', 'automation' => 'storage-supplier-order-automation'];
        abort_unless(isset($topics[$topic]), 404);

        return response()->file(app_path('Modules/Storage/Docs/knowledge/'.$topics[$topic].'.md'), ['Content-Type' => 'text/markdown']);
    }

    private function operation(Request $request)
    {
        $actor = $request->user();
        $command = StorageApiCommand::where('actor_id', $actor->id)
            ->where('token_hash', StorageApiAccess::tokenHash($request))
            ->findOrFail($request->route('operation'));
        StorageApiAccess::authorize($actor, $command->result['permissions'] ?? [], $command->result['any_permissions'] ?? []);
        foreach ($command->result['abilities'] ?? [] as $ability) {
            abort_unless($actor->tokenCan($ability), 403, 'The original command scope is required.');
        }

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation, 'state' => $command->state,
            'result' => $command->result['body'] ?? null, 'status' => $command->result['status'] ?? null,
            'reconciliation_required' => in_array($command->state, ['running', 'unknown'], true)]]);
    }

    private function preview(Request $request)
    {
        $target = $request->route()->defaults['storage_preview_target'];
        $import = $request->route('import') ? PurchaseOrderImport::findOrFail($request->route('import')) : null;
        $profile = $request->route('profile') ? PurchaseOrderImportProfile::findOrFail($request->route('profile')) : null;

        $version = $profile && $request->route('version') ? $profile->versions()->findOrFail($request->route('version')) : null;
        $proposed = $target === 'policy'
            ? StorageApiInput::validated($request, \App\Modules\Storage\Requests\Admin\UpdatePurchaseOrderAutomationPolicyRequest::class) : null;
        $eligible = $target === 'retry'
            ? in_array($import?->status, ['needs_attention', 'failed', 'retry_scheduled'], true)
            : ($target === 'repair' ? ! in_array($import?->status, ['duplicate', 'rejected', 'cancelled'], true) : true);

        return response()->json(['data' => ['action' => $target, 'import_id' => $import?->id, 'profile_id' => $profile?->id,
            'current_status' => $import?->status ?? $profile?->lifecycle_state,
            'version_id' => $version?->id, 'version_checksum' => $version?->checksum,
            'lifecycle_eligible' => $eligible, 'requires_fresh_validation' => $version !== null,
            'proposed_policy' => $proposed ? array_intersect_key($proposed, array_flip(['runtime_mode', 'ai_mode', 'ai_agent_id', 'default_warehouse_id',
                'amount_tolerance', 'max_lines', 'max_quantity_per_line', 'max_order_total', 'max_new_items', 'supplier_bootstrap_mode', 'new_item_mode',
                'silent_success', 'daily_digest_enabled'])) : null,
            'api_enabled' => (bool) config($target === 'repair' ? 'storage_api.ai_repair_enabled' : 'storage_api.automation_enabled'),
            'confirmation_required' => true, 'idempotency_required' => true,
            'effect' => match ($target) {
                'repair' => 'Use the configured governed Storage AI workload; validate and record a repair proposal. Does not receive stock.',
                'retry' => 'Queue the existing import pipeline under its current policy; it may call the configured AI fallback and register supplier orders.',
                default => 'Apply the reviewed supplier automation configuration; future imports may be processed under the resulting active policy/profile.',
            },
            'availability' => $import ? array_intersect_key(app(PurchaseOrderAutomationUiQuery::class)->forImport($import), array_flip(['available', 'reason'])) : null,
        ]]);
    }
}
