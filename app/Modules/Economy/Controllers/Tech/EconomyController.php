<?php

namespace App\Modules\Economy\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Actions\DeleteOrderLine;
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
        $defaults->handle();
        $orders = EconomyOrder::query()
            ->with(['client', 'lines'])
            ->latest()
            ->paginate(20);

        return view('economy::Tech.Orders.index', [
            'orders' => $orders,
            'orderTotals' => $totals->forOrders($orders->getCollection()),
            'stats' => [
                'draft' => EconomyOrder::query()->where('status', 'draft')->count(),
                'ready' => EconomyOrder::query()->where('status', 'ready')->count(),
                'approved' => EconomyOrder::query()->where('status', 'approved')->count(),
                'manual_invoiced' => EconomyOrder::query()->where('status', 'manual_invoiced')->count(),
            ],
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
            ->with('success', 'Orders generated. Lines created: ' . $summary['lines_created'] . '. Contract time waiting: ' . $summary['time_entries_waiting_for_contract'] . '.');
    }

    public function markReady(EconomyOrder $order): RedirectResponse
    {
        abort_unless($order->status === 'draft', 422);

        $order->forceFill([
            'status' => 'ready',
            'ready_at' => now(),
        ])->save();

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order marked ready for billing.');
    }

    public function markDraft(EconomyOrder $order): RedirectResponse
    {
        abort_unless($order->status === 'ready', 422);

        $order->forceFill([
            'status' => 'draft',
            'ready_at' => null,
        ])->save();

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order moved back to draft.');
    }

    public function markInvoiced(Request $request, EconomyOrder $order): RedirectResponse
    {
        abort_unless(in_array($order->status, ['ready', 'approved'], true), 422);

        $order->forceFill([
            'status' => 'manual_invoiced',
            'updated_by' => $request->user()?->id,
        ])->save();

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order marked manually invoiced. No external export was sent.');
    }

    public function destroyOrder(EconomyOrder $order): RedirectResponse
    {
        abort_unless(in_array($order->status, ['draft', 'ready'], true), 422);
        abort_unless(! $order->lines()->exists(), 422);

        $order->delete();

        return redirect()->route('tech.economy.orders.index')
            ->with('success', 'Empty order deleted.');
    }

    public function destroyLine(EconomyOrder $order, EconomyOrderLine $line, DeleteOrderLine $deleteOrderLine): RedirectResponse
    {
        $deleteOrderLine->handle($order, $line);

        if (! $order->refresh()->lines()->exists()) {
            $order->delete();

            return redirect()->route('tech.economy.orders.index')
                ->with('success', 'Order line deleted. The empty draft order was removed.');
        }

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order line deleted and source record unlocked for recalculation.');
    }
}
