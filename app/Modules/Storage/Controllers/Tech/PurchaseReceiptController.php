<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\PostPurchaseReceipt;
use App\Modules\Storage\Actions\ReversePurchaseReceipt;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\Room;
use App\Modules\Storage\Queries\SupplierOrderWorkspaceQuery;
use App\Modules\Storage\Requests\Tech\PostPurchaseReceiptRequest;
use App\Modules\Storage\Requests\Tech\ReversePurchaseReceiptRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PurchaseReceiptController extends Controller
{
    public function index(Request $request, SupplierOrderWorkspaceQuery $query): View
    {
        $canViewPurchaseOrders = $request->user()->can('storage.purchase_view');

        return view(
            'storage::Tech.Storage.purchase-orders.index',
            $query->viewData(
                $request,
                includePurchaseOrders: true,
                includeImports: $request->user()->can('storage.purchase_import_view'),
                receivingOnly: ! $canViewPurchaseOrders,
            ),
        );
    }

    public function create(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $this->assertReceivable($purchaseOrder);

        $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'lines.item',
            'shipments.lines',
            'shipments.trackings',
        ]);

        $selectedShipment = $this->selectedShipment($request, $purchaseOrder);

        return view('storage::Tech.Storage.receiving.create', [
            'purchaseOrder' => $purchaseOrder,
            'selectedShipment' => $selectedShipment,
            'rooms' => Room::query()
                ->where('warehouse_id', $purchaseOrder->deliver_to_warehouse_id)
                ->where('is_active', true)
                ->orderBy('name')
                ->get(),
            'boxes' => Box::query()
                ->where('warehouse_id', $purchaseOrder->deliver_to_warehouse_id)
                ->where('is_active', true)
                ->orderBy('code_human')
                ->get(),
            'idempotencyToken' => (string) Str::uuid(),
            'canReceiveOverage' => $request->user()->can('storage.purchase_receive_overage'),
        ]);
    }

    public function controlSlip(Request $request, PurchaseOrder $purchaseOrder): View
    {
        $purchaseOrder->load([
            'vendor',
            'deliverToWarehouse',
            'lines.item',
            'shipments.lines',
            'shipments.trackings',
        ]);

        return view('storage::Tech.Storage.receiving.control-slip', [
            'purchaseOrder' => $purchaseOrder,
            'selectedShipment' => $this->selectedShipment($request, $purchaseOrder),
        ]);
    }

    public function store(
        PostPurchaseReceiptRequest $request,
        PurchaseOrder $purchaseOrder,
        PostPurchaseReceipt $postPurchaseReceipt
    ): RedirectResponse {
        try {
            $data = $request->validated();
            $data['lines'] = collect($data['lines'])
                ->filter(
                    fn (array $line): bool => (int) $line['qty_accepted'] > 0
                        || (int) $line['qty_rejected'] > 0
                )
                ->all();

            $receipt = $postPurchaseReceipt->handle(
                $purchaseOrder,
                $data,
                $request->user(),
                $request->user()->can('storage.purchase_receive_overage')
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return back()
                ->withInput()
                ->withErrors(['receipt' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.storage.purchase-orders.show', $purchaseOrder)
            ->with('success', 'Receipt '.$receipt->receipt_number.' posted and inventory updated.');
    }

    public function reverse(
        ReversePurchaseReceiptRequest $request,
        PurchaseReceipt $receipt,
        ReversePurchaseReceipt $reversePurchaseReceipt
    ): RedirectResponse {
        $receipt->loadMissing('purchaseOrder');

        try {
            $reversal = $reversePurchaseReceipt->handle(
                $receipt,
                $request->validated(),
                $request->user()
            );
        } catch (\InvalidArgumentException|\DomainException $exception) {
            return back()->withErrors(['reversal' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.storage.purchase-orders.show', $receipt->purchaseOrder)
            ->with('success', 'Receipt reversed by '.$reversal->receipt_number.'.');
    }

    private function assertReceivable(PurchaseOrder $purchaseOrder): void
    {
        abort_unless(in_array($purchaseOrder->status, [
            PurchaseOrder::STATUS_ORDERED,
            PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
        ], true), 409, 'This purchase order is not open for receiving.');

        abort_unless(
            $purchaseOrder->lines()->get()->sum('qty_outstanding') > 0,
            409,
            'This purchase order has no outstanding quantity.'
        );
    }

    private function selectedShipment(Request $request, PurchaseOrder $purchaseOrder)
    {
        if (! $request->filled('shipment_id')) {
            return null;
        }

        $shipment = $purchaseOrder->shipments
            ->firstWhere('id', $request->integer('shipment_id'));

        abort_unless($shipment, 404);

        abort_if(
            $shipment->status === PurchaseShipment::STATUS_CANCELLED,
            409,
            'Cancelled shipments cannot be selected for receiving.'
        );

        return $shipment;
    }
}
