<?php

namespace App\Modules\Economy\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class EconomyController extends Controller
{
    public function index(EnsureEconomyDefaults $defaults, CalculateOrderTotals $totals): View
    {
        $defaults->effective();
        $orders = \App\Modules\Economy\Queries\EconomyOrderQuery::make([])
            ->with(['client', 'lines'])
            ->latest()
            ->paginate(20);

        return view('economy::Tech.Orders.index', [
            'orders' => $orders,
            'orderTotals' => $totals->forOrders($orders->getCollection()),
            'stats' => \App\Modules\Economy\Queries\EconomyOrderQuery::stats(\App\Modules\Economy\Queries\EconomyOrderQuery::make([])),
        ]);
    }

    public function show(EconomyOrder $order, CalculateOrderTotals $totals): View
    {
        $order->load(['client', 'lines.ticket', 'updatedBy']);

        return view('economy::Tech.Orders.show', [
            'order' => $order,
            'orderTotals' => $totals->forOrder($order),
        ]);
    }

    public function generate(Request $request, GenerateOrders $generateOrders): RedirectResponse
    {
        $data = $request->validate([
            'period_start' => 'nullable|date',
            'period_end' => 'nullable|date|after_or_equal:period_start',
        ]);

        $summary = $generateOrders->handle(
            filled($data['period_start'] ?? null) ? Carbon::parse($data['period_start']) : null,
            filled($data['period_end'] ?? null) ? Carbon::parse($data['period_end']) : null,
            $request->user()
        );

        return redirect()->route('tech.economy.orders.index')
            ->with('success', 'Orders generated. Lines created: '.$summary['lines_created'].'. Contract time waiting: '.$summary['time_entries_waiting_for_contract'].'.');
    }

    public function export(
        Request $request,
        EnsureDataExchangeProfileTemplates $templates,
        RunDataExchangeExport $export,
    ): RedirectResponse {
        abort_unless($request->user()?->can('data_exchange.run') && $request->user()?->can('economy.view'), 403);

        $run = app(\App\Modules\Economy\Actions\EconomyExportWorkflow::class)->handle($request->user(), 'economy_button');

        return redirect()->route('tech.admin.system.data-exchange.runs.show', $run)
            ->with('success', 'Economy Orders export generated through Data Exchange.');
    }

    public function markReady(Request $request, EconomyOrder $order): RedirectResponse
    {
        app(\App\Modules\Economy\Actions\EconomyOrderWorkflow::class)->handle($order->id, 'ready', $request->user(), request: $request);

        return redirect()->route('tech.economy.orders.show', $order)->with('success', 'Order marked ready for billing.');
    }

    public function markDraft(Request $request, EconomyOrder $order): RedirectResponse
    {
        app(\App\Modules\Economy\Actions\EconomyOrderWorkflow::class)->handle($order->id, 'draft', $request->user(), request: $request);

        return redirect()->route('tech.economy.orders.show', $order)->with('success', 'Order moved back to draft.');
    }

    public function markInvoiced(Request $request, EconomyOrder $order): RedirectResponse
    {
        app(\App\Modules\Economy\Actions\EconomyOrderWorkflow::class)->handle($order->id, 'manual_invoiced', $request->user(), request: $request);

        return redirect()->route('tech.economy.orders.show', $order)->with('success', 'Order marked manually invoiced. No external export was sent.');
    }

    public function updatePortalVisibility(Request $request, EconomyOrder $order): RedirectResponse
    {
        $data = $request->validate(['portal_visible' => ['required', 'boolean']]);
        app(\App\Modules\Economy\Actions\EconomyOrderWorkflow::class)->handle($order->id, 'visibility', $request->user(), (bool) $data['portal_visible'], $request);

        return redirect()->route('tech.economy.orders.show', $order)->with('success', $data['portal_visible'] ? 'Order is visible in the customer portal.' : 'Order is hidden from the customer portal.');
    }

    public function destroyOrder(EconomyOrder $order): RedirectResponse
    {
        app(\App\Modules\Economy\Actions\DeleteEconomyOrder::class)->handle($order->id);

        return redirect()->route('tech.economy.orders.index')->with('success', 'Empty order deleted.');
    }

    public function destroyLine(EconomyOrder $order, EconomyOrderLine $line): RedirectResponse
    {
        $result = app(\App\Modules\Economy\Actions\DeleteEconomyOrder::class)->handle($order->id, $line->id);
        if ($result['deleted_order']) {
            return redirect()->route('tech.economy.orders.index')->with('success', 'Order line deleted. The empty draft order was removed.');
        }

        return redirect()->route('tech.economy.orders.show', $order)->with('success', 'Order line deleted and source record unlocked for recalculation.');
    }
}
