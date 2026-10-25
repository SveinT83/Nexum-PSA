<?php

namespace App\Modules\Economy\Support;

use App\Http\Middleware\EnforceTechRoutePermission;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use Illuminate\Http\Request;

/** Explicit staff/customer projections keep financial display totals distinct from stored history. */
class EconomyProjection
{
    public static function order(EconomyOrder $order, Request $request, bool $lines = true, bool $customer = false): array
    {
        $order->loadMissing(['client', 'lines.ticket', 'updatedBy']);
        $totals = app(CalculateOrderTotals::class)->forOrder($order);
        $active = $customer ? $order->lines->where('status', 'active') : $order->lines;
        $data = [
            'id' => $order->id, 'order_number' => $order->order_number,
            'client_id' => $order->client_id, 'client' => ['id' => $order->client_id, 'name' => $order->client?->name],
            'period_start' => $order->period_start?->toDateString(), 'period_end' => $order->period_end?->toDateString(),
            'status' => $order->status, 'status_label' => app(PortalEconomyAccess::class)->statusLabel($order),
            'subtotal_ex_vat' => $customer ? $totals['subtotal_ex_vat'] : (float) $order->subtotal_ex_vat,
            'vat_amount' => $customer ? $totals['vat_amount'] : (float) $order->vat_amount,
            'total_inc_vat' => $customer ? $totals['total_inc_vat'] : (float) $order->total_inc_vat,
            'effective_totals' => array_intersect_key($totals, array_flip(['subtotal_ex_vat', 'vat_amount', 'total_inc_vat'])),
            'currencies' => $order->lines->where('status', 'active')->pluck('currency')->unique()->values()->all(),
            'currency_conversion' => false, 'lines_count' => $active->count(),
        ];
        if ($lines) {
            $data['lines'] = $active->take(100)->map(fn ($line) => self::line($line, $totals['lines'][$line->id] ?? null, $customer))->values()->all();
            $data['lines_truncated'] = $active->count() > 100;
            $data['lines_url'] = route($customer ? 'api.v1.economy.portal.orders.lines' : 'api.v1.economy.orders.lines', $order);
        }
        if ($customer) {
            return $data;
        }
        $data += [
            'revision' => EconomyRevision::order($order), 'portal_visible' => $order->isPortalVisible(),
            'portal_visible_at' => $order->portal_visible_at?->toIso8601String(), 'portal_visible_by' => $order->portal_visible_by,
            'created_by' => $order->created_by, 'updated_by' => $order->updated_by,
            'updated_by_name' => $order->updatedBy?->name,
            'stored_totals' => ['subtotal_ex_vat' => $order->subtotal_ex_vat, 'vat_amount' => $order->vat_amount, 'total_inc_vat' => $order->total_inc_vat],
            'links' => ['self' => route('api.v1.economy.orders.show', $order)],
        ];
        foreach (['generated_at', 'ready_at', 'approved_at', 'exported_at', 'cancelled_at', 'created_at', 'updated_at'] as $field) {
            $data[$field] = $order->{$field}?->toIso8601String();
        }
        $actor = $request->user();
        $policy = app(EnforceTechRoutePermission::class);
        $manage = $actor && $policy->allowsActor($actor, 'economy.order_manage') && $actor->tokenCan('economy.update');
        $delete = $actor && $policy->allowsActor($actor, 'economy.delete_orders') && $actor->tokenCan('economy.delete');
        $data['available_actions'] = [
            'mark_ready' => $manage && $order->status === 'draft', 'mark_draft' => $manage && $order->status === 'ready',
            'mark_invoiced' => $manage && in_array($order->status, ['ready', 'approved'], true),
            'portal_visibility' => $manage && (bool) $order->client_id,
            'delete_order' => $delete && in_array($order->status, ['draft', 'ready'], true) && $order->lines->isEmpty(),
            'delete_lines' => $delete && $order->status === 'draft',
        ];

        $data['links']['mark_ready'] = $data['available_actions']['mark_ready'] ? route('api.v1.economy.orders.ready', $order) : null;
        $data['links']['mark_draft'] = $data['available_actions']['mark_draft'] ? route('api.v1.economy.orders.draft', $order) : null;

        return $data;
    }

    public static function line(EconomyOrderLine $line, ?array $effective = null, bool $customer = false): array
    {
        $data = [
            'id' => $line->id, 'work_date' => $line->work_date?->toDateString(), 'line_type' => $line->line_type,
            'reference' => $line->ticket?->ticket_key ?? $line->line_type, 'description' => $line->description,
            'quantity' => (float) $line->quantity, 'unit' => $line->unit,
            'unit_price_ex_vat' => $line->unit_price_ex_vat === null ? null : (float) $line->unit_price_ex_vat,
            'line_total_ex_vat' => $customer && $effective ? $effective['line_total_ex_vat'] : (float) $line->line_total_ex_vat,
            'vat_rate' => $customer && $effective ? $effective['vat_rate'] : ($line->vat_rate === null ? null : (float) $line->vat_rate),
            'vat_amount' => $customer && $effective ? $effective['vat_amount'] : ($line->vat_amount === null ? null : (float) $line->vat_amount),
            'total_inc_vat' => $customer && $effective ? $effective['total_inc_vat'] : (float) $line->total_inc_vat,
            'currency' => $line->currency, 'effective_totals' => $effective,
            'decimals' => ['quantity' => $line->quantity, 'unit_price_ex_vat' => $line->unit_price_ex_vat, 'line_total_ex_vat' => $line->line_total_ex_vat, 'vat_rate' => $line->vat_rate, 'vat_amount' => $line->vat_amount, 'total_inc_vat' => $line->total_inc_vat],
        ];
        if ($customer) {
            unset($data['decimals']);

            return $data;
        }

        return $data + [
            'economy_order_id' => $line->economy_order_id, 'client_id' => $line->client_id,
            'source_type' => $line->source_type, 'source_id' => $line->source_id, 'ticket_id' => $line->ticket_id,
            'ticket' => $line->ticket ? ['id' => $line->ticket_id, 'ticket_key' => $line->ticket->ticket_key] : null,
            'status' => $line->status,
            'metadata' => array_intersect_key($line->metadata ?? [], array_flip(['ticket_key', 'rate_name', 'rate_amount_ex_vat_per_hour', 'covered_minutes', 'included_minutes', 'contract_id', 'contract_item_id', 'contract_item_name', 'included_minutes_snapshot', 'used_before_minutes_snapshot', 'registered_minutes', 'overused_minutes', 'provider_family', 'item_name', 'item_sku'])),
            'created_at' => $line->created_at?->toIso8601String(), 'updated_at' => $line->updated_at?->toIso8601String(),
        ];
    }
}
