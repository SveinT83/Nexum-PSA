<?php

namespace App\Modules\Economy\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\DataExchange\Actions\EnsureDataExchangeProfileTemplates;
use App\Modules\DataExchange\Actions\RunDataExchangeExport;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Actions\DeleteOrderLine;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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

    public function export(
        Request $request,
        EnsureDataExchangeProfileTemplates $templates,
        RunDataExchangeExport $export,
    ): RedirectResponse {
        abort_unless($request->user()?->can('data_exchange.run') && $request->user()?->can('economy.view'), 403);

        $profile = $templates->economyOrdersExportProfile($request->user());
        $run = $export->handle($profile, $request->user(), 'economy_button');

        return redirect()->route('tech.admin.system.data-exchange.runs.show', $run)
            ->with('success', 'Economy Orders export generated through Data Exchange.');
    }

    public function markReady(EconomyOrder $order, SendCustomerPortalNotification $portalNotifications): RedirectResponse
    {
        abort_unless($order->status === 'draft', 422);

        $order->forceFill([
            'status' => 'ready',
            'ready_at' => now(),
        ])->save();

        $this->notifyPortalOrderStatus($order, 'Ready for billing', $portalNotifications);

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order marked ready for billing.');
    }

    public function markDraft(EconomyOrder $order, SendCustomerPortalNotification $portalNotifications): RedirectResponse
    {
        abort_unless($order->status === 'ready', 422);

        $order->forceFill([
            'status' => 'draft',
            'ready_at' => null,
        ])->save();

        $this->notifyPortalOrderStatus($order, 'Moved back to draft', $portalNotifications);

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order moved back to draft.');
    }

    public function markInvoiced(Request $request, EconomyOrder $order, SendCustomerPortalNotification $portalNotifications): RedirectResponse
    {
        abort_unless(in_array($order->status, ['ready', 'approved'], true), 422);

        $order->forceFill([
            'status' => 'manual_invoiced',
            'updated_by' => $request->user()?->id,
        ])->save();

        $this->notifyPortalOrderStatus($order, 'Marked as invoiced', $portalNotifications);

        return redirect()->route('tech.economy.orders.show', $order)
            ->with('success', 'Order marked manually invoiced. No external export was sent.');
    }

    public function updatePortalVisibility(Request $request, EconomyOrder $order, RecordCustomerPortalAudit $audit, SendCustomerPortalNotification $portalNotifications): RedirectResponse
    {
        $request->validate([
            'portal_visible' => ['required', 'boolean'],
        ]);

        if ($request->boolean('portal_visible') && ! $order->client_id) {
            return back()->withErrors(['portal_visible' => 'Only client-scoped orders can be shown in the customer portal.']);
        }

        DB::transaction(function () use ($request, $order, $audit, $portalNotifications): void {
            $wasVisible = $order->isPortalVisible();
            $isVisible = $request->boolean('portal_visible');

            $order->forceFill([
                'portal_visible_at' => $isVisible ? ($order->portal_visible_at ?: now()) : null,
                'portal_visible_by' => $isVisible ? $request->user()?->id : null,
            ])->save();

            $audit->handle(
                $isVisible ? 'portal_economy_order_visibility_enabled' : 'portal_economy_order_visibility_disabled',
                null,
                $request->user(),
                null,
                $order->client,
                null,
                [
                    'economy_order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                ],
                $request,
            );

            if (! $wasVisible && $isVisible && $order->client_id) {
                $portalNotifications->handle(
                    type: 'portal_order_published',
                    clientId: (int) $order->client_id,
                    siteId: null,
                    title: 'New order available',
                    body: $order->order_number,
                    url: route('customer-portal.orders.show', $order),
                    sourceType: EconomyOrder::class,
                    sourceId: $order->id,
                    metadata: [
                        'order_number' => $order->order_number,
                        'status' => $order->status,
                    ],
                );
            }
        });

        return redirect()->route('tech.economy.orders.show', $order->refresh())
            ->with('success', $order->isPortalVisible() ? 'Order is visible in the customer portal.' : 'Order is hidden from the customer portal.');
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

    private function notifyPortalOrderStatus(EconomyOrder $order, string $statusLabel, SendCustomerPortalNotification $portalNotifications): void
    {
        if (! $order->isPortalVisible() || ! $order->client_id) {
            return;
        }

        $portalNotifications->handle(
            type: 'portal_order_status_changed',
            clientId: (int) $order->client_id,
            siteId: null,
            title: 'Order '.$order->order_number.' status changed',
            body: $statusLabel,
            url: route('customer-portal.orders.show', $order),
            sourceType: EconomyOrder::class,
            sourceId: $order->id,
            metadata: [
                'order_number' => $order->order_number,
                'status' => $order->status,
            ],
        );
    }
}
