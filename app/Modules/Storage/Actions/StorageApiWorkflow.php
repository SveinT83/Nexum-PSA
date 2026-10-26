<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\StorageApiQuery;
use App\Modules\Storage\Requests\Admin\ImportPurchaseOrderProfileRequest;
use App\Modules\Storage\Requests\Admin\PurchaseOrderImportProfileReasonRequest;
use App\Modules\Storage\Requests\Admin\StoreProtectedSupplierOrderProfileFixtureRequest;
use App\Modules\Storage\Requests\Admin\StorePurchaseOrderImportProfileRequest;
use App\Modules\Storage\Requests\Admin\StorePurchaseOrderImportProfileVersionRequest;
use App\Modules\Storage\Requests\Admin\UpdatePurchaseOrderAutomationPolicyRequest;
use App\Modules\Storage\Requests\Admin\UpdatePurchaseOrderImportProfileMetadataRequest;
use App\Modules\Storage\Requests\Tech\CreatePurchaseOrderImportItemRequest;
use App\Modules\Storage\Requests\Tech\ManuallyCorrectPurchaseOrderImportRequest;
use App\Modules\Storage\Requests\Tech\MapPurchaseOrderImportLineRequest;
use App\Modules\Storage\Requests\Tech\RejectPurchaseOrderImportRequest;
use App\Modules\Storage\Support\StorageApiInput;
use App\Modules\Storage\Support\StorageInventoryDefaults;
use App\Modules\Ticket\Actions\PickTicketStorageReservation;
use App\Modules\Ticket\Models\TicketCostEntry;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** API orchestration calls the browser's authoritative actions and validators. */
class StorageApiWorkflow
{
    public function handle(Request $request)
    {
        try {
            return $this->execute($request);
        } catch (\InvalidArgumentException|\DomainException $error) {
            throw \Illuminate\Validation\ValidationException::withMessages(['storage' => $error->getMessage()]);
        }
    }

    private function execute(Request $request)
    {
        StorageApiInput::allowed($request, $request->route()->defaults['storage_fields'] ?? []);
        $operation = $request->route()->defaults['storage_operation'];
        $actor = $request->user();
        if ($operation === 'pick') {
            $entry = TicketCostEntry::with('ticket')->findOrFail($request->route('costEntry'));
            abort_unless($entry->ticket, 404);
            $entry = app(PickTicketStorageReservation::class)->handle($entry->ticket, $entry, $actor);

            return response()->json(['data' => $entry->only(['id', 'ticket_id', 'storage_item_id', 'storage_reservation_id', 'quantity', 'status'])]);
        }
        if ($operation === 'settings-update') {
            $data = $request->validate(['default_warehouse_id' => ['required', 'integer',
                Rule::exists('storage_warehouses', 'id')->where('is_active', true)->whereNull('deleted_at')]]);
            app(StorageInventoryDefaults::class)->setDefaultWarehouse(Warehouse::findOrFail($data['default_warehouse_id']));

            return response()->json(['data' => $data]);
        }
        if ($operation === 'policy-update') {
            if ($request->input('runtime_mode') !== 'off' || $request->input('ai_mode') !== 'off') {
                $this->confirmAutomation($request);
            }
            $data = StorageApiInput::validated($request, UpdatePurchaseOrderAutomationPolicyRequest::class);
            $policy = app(UpdatePurchaseOrderAutomationPolicy::class)->handle($data, $actor);

            return response()->json(['data' => $policy->only(['id', 'runtime_mode', 'ai_mode', 'revision_number', 'default_warehouse_id', 'ai_agent_id'])]);
        }
        if (str_starts_with($operation, 'import-')) {
            $import = PurchaseOrderImport::findOrFail($request->route('import'));
            $line = $request->route('line') ? $import->lines()->findOrFail($request->route('line')) : null;
            switch ($operation) {
                case 'import-map':
                    $data = StorageApiInput::validated($request, MapPurchaseOrderImportLineRequest::class);
                    app(MapSupplierOrderImportLine::class)->handle($line, Item::findOrFail($data['item_id']), $actor);
                    break;
                case 'import-create-item':
                    $data = StorageApiInput::validated($request, CreatePurchaseOrderImportItemRequest::class);
                    app(CreateItemForPurchaseOrderImportLine::class)->handle($line, $actor, $data['mode']);
                    break;
                case 'import-correct':
                    $data = StorageApiInput::validated($request, ManuallyCorrectPurchaseOrderImportRequest::class);
                    app(ManuallyCorrectPurchaseOrderImport::class)->handle($import, $data['correction'], $actor);
                    break;
                case 'import-retry':
                    $this->confirmAutomation($request);
                    app(RetryPurchaseOrderImport::class)->handle($import, $actor);
                    break;
                case 'import-finalize':
                    $order = app(ManuallyFinalizePurchaseOrderImport::class)->handle($import, $actor);

                    $canReadOrder = \App\Modules\Storage\Support\StorageApiAccess::allows($actor, 'storage.purchase_view')
                        && $actor->tokenCan('storage.purchase.read');
                    if ($canReadOrder) {
                        \App\Modules\Storage\Support\StorageApiAccess::retainResponseAuthority($request, ['storage.purchase_view'], ['storage.purchase.read']);
                    }

                    return response()->json(['data' => ['import' => app(StorageApiQuery::class)->import($import->fresh(), $request),
                        'purchase_order' => $canReadOrder
                            ? (new \App\Modules\Storage\Resources\Api\V1\PurchaseOrderResource($order))->resolve($request)
                            : $order->only(['id', 'po_number', 'status'])]]);
                case 'import-reject':
                    $data = StorageApiInput::validated($request, RejectPurchaseOrderImportRequest::class);
                    app(RejectPurchaseOrderImport::class)->handle($import, $actor, $data['reason']);
                    break;
                case 'import-repair':
                    $availability = app(\App\Modules\Storage\Queries\PurchaseOrderAutomationUiQuery::class)->forImport($import);
                    abort_unless($availability['available'], 409, $availability['reason']);
                    abort_if(in_array($import->status, ['duplicate', 'rejected', 'cancelled'], true), 409, 'A terminal import cannot be repaired.');
                    try {
                        app(RepairPurchaseOrderImportWithAi::class)->handle($import, $actor);
                    } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                        throw $error;
                    } catch (\Throwable) {
                        // Preserve the committed claim and reveal no remote response, token or stack.
                        return response()->json(['state' => 'unknown', 'reconciliation_required' => true], 202);
                    }
                    break;
                default:
                    abort(404);
            }

            return response()->json(['data' => app(StorageApiQuery::class)->import($import->fresh(), $request)], $operation === 'import-retry' ? 202 : 200);
        }
        if ($operation === 'profile-create') {
            $data = StorageApiInput::validated($request, StorePurchaseOrderImportProfileRequest::class);
            $profile = app(CreateSupplierOrderProfile::class)->handle($data, $actor);

            return response()->json(['data' => app(StorageApiQuery::class)->profile($profile)], 201);
        }
        if ($operation === 'profile-import') {
            $data = StorageApiInput::validated($request, ImportPurchaseOrderProfileRequest::class);
            $result = app(ImportSupplierOrderProfile::class)->handle($data['export'], $actor, $data['slug']);

            return response()->json(['data' => app(StorageApiQuery::class)->profile($result['profile'])], 201);
        }
        $profile = PurchaseOrderImportProfile::findOrFail($request->route('profile'));
        $version = $request->route('version') ? $profile->versions()->findOrFail($request->route('version')) : null;
        switch ($operation) {
            case 'profile-update':
                $data = StorageApiInput::validated($request, UpdatePurchaseOrderImportProfileMetadataRequest::class);
                app(UpdateSupplierOrderProfileMetadata::class)->handle($profile, $data, $actor);
                break;
            case 'profile-fixture':
                $data = StorageApiInput::validated($request, StoreProtectedSupplierOrderProfileFixtureRequest::class);
                $version = $profile->versions()->findOrFail($data['profile_version_id']);
                $import = PurchaseOrderImport::findOrFail($data['purchase_order_import_id']);
                $result = app(CreateProtectedSupplierOrderProfileFixtureFromImport::class)->handle($profile, $version, $import, $data['fixture_name'], $actor);

                return response()->json(['data' => ['profile' => app(StorageApiQuery::class)->profile($profile->fresh()),
                    'protected_replay_passed' => $result['replay']->protectedPassed()]], 201);
            case 'profile-version':
                $data = StorageApiInput::validated($request, StorePurchaseOrderImportProfileVersionRequest::class);
                $parent = ! empty($data['parent_version_id']) ? $profile->versions()->findOrFail($data['parent_version_id']) : null;
                $parent
                    ? app(CloneSupplierOrderProfileVersion::class)->handle($parent, $data['definition'], $actor)
                    : app(CreateSupplierOrderProfileVersion::class)->handle($profile, $data['definition'], 'manual', $actor);
                break;
            case 'profile-test':
                $result = app(ValidateSupplierOrderProfileVersion::class)->handle($version);

                return response()->json(['data' => ['profile' => app(StorageApiQuery::class)->profile($profile->fresh()), 'validation' => $result->toArray()]]);
            case 'profile-activate':
            case 'profile-rollback':
            case 'profile-pause':
            case 'profile-retire':
                $data = StorageApiInput::validated($request, PurchaseOrderImportProfileReasonRequest::class);
                if (in_array($operation, ['profile-activate', 'profile-rollback'], true)) {
                    $this->confirmAutomation($request);
                }
                match ($operation) {
                    'profile-activate' => app(ActivateSupplierOrderProfileVersion::class)->handle($version, $actor, $data['reason']),
                    'profile-rollback' => app(RollbackSupplierOrderProfileVersion::class)->handle($profile, $version, $actor, $data['reason']),
                    'profile-pause' => app(PauseSupplierOrderProfile::class)->handle($profile, $data['reason'], $actor),
                    'profile-retire' => app(RetireSupplierOrderProfile::class)->handle($profile, $data['reason'], $actor),
                };
                break;
            default:
                abort(404);
        }

        return response()->json(['data' => app(StorageApiQuery::class)->profile($profile->fresh())],
            $operation === 'profile-version' ? 201 : 200);
    }

    private function confirmAutomation(Request $request): void
    {
        abort_unless(config('storage_api.automation_enabled'), 409, 'Storage API automation execution is disabled.');
        abort_unless($request->boolean('confirm'), 422, 'Explicit confirmation of automation effects is required.');
    }
}
