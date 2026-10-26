<?php

namespace App\Modules\Storage\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Storage\Actions\PostPurchaseReceipt;
use App\Modules\Storage\Actions\ReversePurchaseReceipt;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Queries\SupplierOrderWorkspaceQuery;
use App\Modules\Storage\Requests\Tech\PostPurchaseReceiptRequest;
use App\Modules\Storage\Requests\Tech\ReversePurchaseReceiptRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        return view('storage::Tech.Storage.receiving.create',
            app(\App\Modules\Storage\Queries\PurchaseReceivingContextQuery::class)->receive($request, $purchaseOrder));
    }

    public function controlSlip(Request $request, PurchaseOrder $purchaseOrder): View
    {
        return view('storage::Tech.Storage.receiving.control-slip',
            app(\App\Modules\Storage\Queries\PurchaseReceivingContextQuery::class)->controlSlip($request, $purchaseOrder));
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
}
