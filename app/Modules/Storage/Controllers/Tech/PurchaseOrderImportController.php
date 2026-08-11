<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\CreateItemForPurchaseOrderImportLine;
use App\Modules\Storage\Actions\ManuallyCorrectPurchaseOrderImport;
use App\Modules\Storage\Actions\ManuallyFinalizePurchaseOrderImport;
use App\Modules\Storage\Actions\MapSupplierOrderImportLine;
use App\Modules\Storage\Actions\RejectPurchaseOrderImport;
use App\Modules\Storage\Actions\RepairPurchaseOrderImportWithAi;
use App\Modules\Storage\Actions\RetryPurchaseOrderImport;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\PurchaseOrderAutomationUiQuery;
use App\Modules\Storage\Queries\SupplierOrderWorkspaceQuery;
use App\Modules\Storage\Requests\Tech\CreatePurchaseOrderImportItemRequest;
use App\Modules\Storage\Requests\Tech\ManuallyCorrectPurchaseOrderImportRequest;
use App\Modules\Storage\Requests\Tech\MapPurchaseOrderImportLineRequest;
use App\Modules\Storage\Requests\Tech\RejectPurchaseOrderImportRequest;
use App\Modules\Storage\Support\SupplierOrderRepairHistoryPresenter;
use App\Modules\Storage\Support\SupplierOrderSourceSnapshot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PurchaseOrderImportController extends Controller
{
    public function index(Request $request, SupplierOrderWorkspaceQuery $query): View
    {
        return view(
            'storage::Tech.Storage.purchase-orders.index',
            $query->viewData(
                $request,
                includePurchaseOrders: $request->user()->can('storage.purchase_view'),
                includeImports: true,
            ),
        );
    }

    public function show(
        Request $request,
        PurchaseOrderImport $purchaseOrderImport,
        PurchaseOrderAutomationUiQuery $automationUi,
        SupplierOrderSourceSnapshot $sourceSnapshot,
        SupplierOrderRepairHistoryPresenter $repairHistory,
    ): View {
        $purchaseOrderImport->load([
            'vendor',
            'profile',
            'profileVersion',
            'policyRevision',
            'purchaseOrder',
            'revisionOf',
            'lines.item',
            'lines.resolver',
            'attempts.actor',
            'repairs.actor',
            'repairs.profileCandidateVersion.profile',
            'requester',
            'lastActor',
        ]);

        $canOpenInbox = $request->user()->can('email.inbox_view')
            && $purchaseOrderImport->email_message_id !== null;
        if ($canOpenInbox) {
            $purchaseOrderImport->load('emailMessage');
        }

        $canResolve = $request->user()->can('storage.purchase_import_resolve');
        $mappableItems = $canResolve
            ? Item::query()
                ->where('status', 'active')
                ->where('can_be_ordered', true)
                ->orderBy('sku')
                ->limit(500)
                ->get(['id', 'sku', 'name', 'warehouse_id'])
            : collect();
        $warehouses = $canResolve
            ? Warehouse::query()
                ->where('is_active', true)
                ->orderBy('name')
                ->get(['id', 'name', 'code'])
            : collect();

        return view('storage::Tech.Storage.purchase-order-imports.show', [
            'import' => $purchaseOrderImport,
            'safeSource' => $sourceSnapshot->sanitizeStoredSnapshot(
                (array) ($purchaseOrderImport->safe_source_snapshot ?? []),
                (array) ($purchaseOrderImport->trusted_auth_snapshot ?? []),
            ),
            'normalizedDocument' => (array) ($purchaseOrderImport->normalized_document ?? []),
            'mappableItems' => $mappableItems,
            'warehouses' => $warehouses,
            'aiAvailability' => $automationUi->forImport($purchaseOrderImport),
            'canOpenInbox' => $canOpenInbox && $purchaseOrderImport->emailMessage !== null,
            'repairHistory' => $repairHistory->present($purchaseOrderImport, $request->user()),
        ]);
    }

    public function mapLine(
        MapPurchaseOrderImportLineRequest $request,
        PurchaseOrderImport $purchaseOrderImport,
        PurchaseOrderImportLine $importLine,
        MapSupplierOrderImportLine $mapLine
    ): RedirectResponse {
        $this->ensureLineBelongsToImport($purchaseOrderImport, $importLine);
        $item = Item::query()->findOrFail($request->integer('item_id'));
        $mapLine->handle($importLine, $item, $request->user());

        return back()->with('success', 'Supplier-order line mapped to '.$item->sku.'.');
    }

    public function createItem(
        CreatePurchaseOrderImportItemRequest $request,
        PurchaseOrderImport $purchaseOrderImport,
        PurchaseOrderImportLine $importLine,
        CreateItemForPurchaseOrderImportLine $createItem
    ): RedirectResponse {
        $this->ensureLineBelongsToImport($purchaseOrderImport, $importLine);
        $item = $createItem->handle(
            $importLine,
            $request->user(),
            $request->validated('mode')
        );

        return back()->with('success', 'Distinct Item '.$item->sku.' created and mapped.');
    }

    public function retry(
        Request $request,
        PurchaseOrderImport $purchaseOrderImport,
        RetryPurchaseOrderImport $retry
    ): RedirectResponse {
        $retry->handle($purchaseOrderImport, $request->user());

        return back()->with('success', 'Supplier-order import queued for retry.');
    }

    public function finalize(
        Request $request,
        PurchaseOrderImport $purchaseOrderImport,
        ManuallyFinalizePurchaseOrderImport $finalize
    ): RedirectResponse {
        $purchaseOrder = $finalize->handle($purchaseOrderImport, $request->user());

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Purchase order finalized from the reviewed supplier import.');
    }

    public function reject(
        RejectPurchaseOrderImportRequest $request,
        PurchaseOrderImport $purchaseOrderImport,
        RejectPurchaseOrderImport $reject
    ): RedirectResponse {
        $reject->handle(
            $purchaseOrderImport,
            $request->user(),
            $request->validated('reason')
        );

        return back()->with('success', 'Supplier-order import rejected with an audit reason.');
    }

    public function correctManually(
        ManuallyCorrectPurchaseOrderImportRequest $request,
        PurchaseOrderImport $purchaseOrderImport,
        ManuallyCorrectPurchaseOrderImport $correctImport
    ): RedirectResponse {
        $correctImport->handle(
            $purchaseOrderImport,
            $request->validated('correction'),
            $request->user(),
        );

        return back()->with('success', 'Manual correction recorded and ready for controlled reprocessing.');
    }

    public function repair(
        Request $request,
        PurchaseOrderImport $purchaseOrderImport,
        PurchaseOrderAutomationUiQuery $automationUi,
        RepairPurchaseOrderImportWithAi $repair
    ): RedirectResponse {
        abort_unless(
            $request->user()->can('storage.purchase_import_profile_manage'),
            403
        );

        if (in_array($purchaseOrderImport->status, [
            PurchaseOrderImport::STATUS_DUPLICATE,
            PurchaseOrderImport::STATUS_REJECTED,
            PurchaseOrderImport::STATUS_CANCELLED,
        ], true)) {
            throw ValidationException::withMessages(['ai' => 'A terminal import cannot be repaired.']);
        }

        $availability = $automationUi->forImport($purchaseOrderImport);
        if (! $availability['available']) {
            throw ValidationException::withMessages([
                'ai' => $availability['reason'],
            ]);
        }

        $repair->handle($purchaseOrderImport, $request->user());

        return back()->with('success', 'AI-assisted repair completed through the governed workload.');
    }

    private function ensureLineBelongsToImport(
        PurchaseOrderImport $purchaseOrderImport,
        PurchaseOrderImportLine $importLine
    ): void {
        abort_unless(
            (int) $importLine->import_id === (int) $purchaseOrderImport->id,
            404
        );
    }
}
