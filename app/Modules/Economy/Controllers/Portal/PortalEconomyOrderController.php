<?php

namespace App\Modules\Economy\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Support\PortalEconomyAccess;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PortalEconomyOrderController extends Controller
{
    public function index(Request $request, PortalEconomyAccess $access, CalculateOrderTotals $totals): View
    {
        $context = $this->context($request);

        $orders = $access->visibleOrders($context)
            ->with(['lines'])
            ->latest('period_end')
            ->paginate(15);

        return view('economy::Portal.orders.index', [
            'context' => $context,
            'orders' => $orders,
            'orderTotals' => $totals->forOrders($orders->getCollection()),
            'access' => $access,
        ]);
    }

    public function show(Request $request, EconomyOrder $order, PortalEconomyAccess $access, CalculateOrderTotals $totals): View
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $order), 404);

        $order->load(['lines.ticket']);

        return view('economy::Portal.orders.show', [
            'context' => $context,
            'order' => $order,
            'orderTotals' => $totals->forOrder($order),
            'access' => $access,
        ]);
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
