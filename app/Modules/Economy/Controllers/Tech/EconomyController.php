<?php

namespace App\Modules\Economy\Controllers\Tech;

use App\Http\Controllers\Controller;
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
    public function index(EnsureEconomyDefaults $defaults): View
    {
        $defaults->handle();

        return view('economy::Tech.Orders.index', [
            'orders' => EconomyOrder::query()
                ->with(['client', 'lines'])
                ->latest()
                ->paginate(20),
            'stats' => [
                'draft' => EconomyOrder::query()->where('status', 'draft')->count(),
                'ready' => EconomyOrder::query()->where('status', 'ready')->count(),
                'approved' => EconomyOrder::query()->where('status', 'approved')->count(),
            ],
        ]);
    }

    public function show(EconomyOrder $order): View
    {
        $order->load(['client', 'lines.ticket']);

        return view('economy::Tech.Orders.show', [
            'order' => $order,
        ]);
    }

    public function settings(EnsureEconomyDefaults $defaults): View
    {
        return view('economy::Tech.Orders.settings', [
            'settings' => $defaults->handle(),
        ]);
    }

    public function updateSettings(Request $request, EnsureEconomyDefaults $defaults): RedirectResponse
    {
        $settings = $defaults->handle();
        $data = $request->validate([
            'create_orders_from_resolved_ticket_time' => 'nullable|boolean',
            'create_orders_from_closed_ticket_time' => 'nullable|boolean',
            'include_unresolved_ticket_time_in_period_close' => 'nullable|boolean',
            'create_orders_from_picked_ticket_costs' => 'nullable|boolean',
            'auto_pick_ticket_costs_on_resolved_or_closed_ticket' => 'nullable|boolean',
            'time_order_line_grouping' => 'required|string|in:per_entry',
            'order_line_text_format' => 'required|string|in:ticket_date_text',
            'order_prefix' => 'required|string|max:20',
            'default_vat_rate' => 'nullable|numeric|min:0|max:100',
        ]);

        foreach ([
            'create_orders_from_resolved_ticket_time',
            'create_orders_from_closed_ticket_time',
            'include_unresolved_ticket_time_in_period_close',
            'create_orders_from_picked_ticket_costs',
            'auto_pick_ticket_costs_on_resolved_or_closed_ticket',
        ] as $booleanField) {
            $data[$booleanField] = $request->boolean($booleanField);
        }

        $settings->update($data);

        return redirect()->route('tech.economy.settings')
            ->with('success', 'Economy settings updated.');
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
