<?php

namespace App\Modules\Storage\Queries;

use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Support\CollectionTableSorter;
use App\Modules\Storage\Support\StorageApiAccess;
use Illuminate\Http\Request;

/** Browser-equivalent detail sorting over the authoritative loaded order graph. */
class PurchaseOrderApiDetailQuery
{
    public function sort(PurchaseOrder $order, Request $request): PurchaseOrder
    {
        $request->validate([
            'order_line_sort' => 'sometimes|in:item,supplier_sku,ordered,received,cancelled,outstanding,expected,source',
            'order_line_direction' => 'sometimes|in:asc,desc',
            'shipment_line_sort' => 'sometimes|in:item,allocated,accepted,rejected,cancelled,outstanding',
            'shipment_line_direction' => 'sometimes|in:asc,desc',
            'receipt_sort' => 'sometimes|in:receipt,received,shipment,accepted,rejected,status,actor',
            'receipt_direction' => 'sometimes|in:asc,desc',
        ]);
        $sorter = app(CollectionTableSorter::class);
        $columns = [
            'item' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($line) => $line->sku_snapshot ?: $line->item?->sku],
            'supplier_sku' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($line) => $line->supplier_sku_snapshot],
            'expected' => ['type' => $sorter::TYPE_DATE, 'value' => fn ($line) => $line->expected_at],
            'source' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($line) => ! $line->ticket_id ? 'Manual'
                : (StorageApiAccess::allows($request->user(), 'ticket.view') && $request->user()->tokenCan('tickets.read') ? $line->ticket?->ticket_key : 'Linked')],
        ];
        foreach (['ordered', 'received', 'cancelled', 'outstanding'] as $quantity) {
            $columns[$quantity] = ['type' => $sorter::TYPE_NUMBER, 'value' => fn ($line) => $line->{'qty_'.$quantity}];
        }
        $order->setRelation('lines', $sorter->sort($order->lines, $request->input('order_line_sort'), $request->input('order_line_direction', 'asc'), $columns));
        $shipmentColumns = ['item' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($line) => $line->purchaseOrderLine?->sku_snapshot]];
        foreach (['allocated' => 'allocated', 'accepted' => 'received', 'rejected' => 'rejected', 'cancelled' => 'cancelled', 'outstanding' => 'outstanding'] as $key => $quantity) {
            $shipmentColumns[$key] = ['type' => $sorter::TYPE_NUMBER, 'value' => fn ($line) => $line->{'qty_'.$quantity}];
        }
        foreach ($order->shipments as $shipment) {
            $shipment->setRelation('lines', $sorter->sort($shipment->lines, $request->input('shipment_line_sort'),
                $request->input('shipment_line_direction', 'asc'), $shipmentColumns));
        }
        $receipts = [
            'receipt' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($r) => $r->receipt_number],
            'received' => ['type' => $sorter::TYPE_DATE, 'value' => fn ($r) => $r->received_at],
            'shipment' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($r) => $r->shipment?->reference],
            'status' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($r) => $r->status],
            'actor' => ['type' => $sorter::TYPE_STRING, 'value' => fn ($r) => $r->creator?->name],
        ];
        foreach (['accepted', 'rejected'] as $quantity) {
            $receipts[$quantity] = ['type' => $sorter::TYPE_NUMBER, 'value' => fn ($r) => ($r->receipt_type === 'reversal' ? -1 : 1) * $r->lines->sum('qty_'.$quantity)];
        }
        $order->setRelation('receipts', $sorter->sort($order->receipts, $request->input('receipt_sort'),
            $request->input('receipt_direction', 'desc'), $receipts));

        return $order;
    }
}
