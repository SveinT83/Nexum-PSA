<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestTicketPurchase
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    public function handle(Ticket $ticket, TicketPlannedLine $line, User $actor): PurchaseOrderLine
    {
        abort_unless((int) $line->ticket_id === (int) $ticket->id, 404);

        if ($reason = $this->guard->reason($ticket, TicketAction::REQUEST_PURCHASE, $actor)) {
            throw ValidationException::withMessages(['planned_line' => $reason]);
        }

        $line->loadMissing('storageItem.primaryVendor');
        if ($line->status !== 'approved' || ! $line->approved_quote_version_id) {
            throw ValidationException::withMessages(['planned_line' => 'Customer approval is required before creating a purchase need.']);
        }
        if (! $line->storageItem?->can_be_ordered) {
            throw ValidationException::withMessages(['planned_line' => 'This Storage item is not marked as orderable.']);
        }
        if (! $line->storageItem->primary_vendor_id || ! $line->storageItem->warehouse_id) {
            throw ValidationException::withMessages(['planned_line' => 'The Storage item needs a primary vendor and warehouse.']);
        }

        $created = false;
        $purchaseLine = DB::transaction(function () use ($ticket, $line, $actor, &$created): PurchaseOrderLine {
            $existing = PurchaseOrderLine::query()->where('ticket_planned_line_id', $line->id)->first();
            if ($existing) {
                return $existing;
            }

            $order = PurchaseOrder::query()->firstOrCreate([
                'vendor_id' => $line->storageItem->primary_vendor_id,
                'deliver_to_warehouse_id' => $line->storageItem->warehouse_id,
                'status' => 'draft',
                'notes' => 'Draft purchase needs created from Tickets. No vendor order has been sent.',
            ], [
                'po_number' => 'DRAFT-'.now()->format('Ymd-His').'-'.Str::upper(Str::random(6)),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $created = true;
            $purchaseLine = $order->lines()->create([
                'item_id' => $line->storage_item_id,
                'ticket_id' => $ticket->id,
                'ticket_planned_line_id' => $line->id,
                'qty_ordered' => (int) ceil((float) $line->quantity),
                'qty_received' => 0,
                'unit_cost' => $line->unit_cost_ex_vat,
                'tax_rate' => $line->vat_rate,
                'expected_at' => null,
                'metadata' => [
                    'kind' => 'ticket_purchase_need',
                    'ticket_key' => $ticket->ticket_key,
                    'approved_quote_version_id' => $line->approved_quote_version_id,
                    'vendor_order_sent' => false,
                ],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'purchase_need_created',
                'message' => 'A draft purchase need was created. No vendor order was sent.',
                'after' => ['purchase_order_id' => $order->id, 'purchase_order_line_id' => $purchaseLine->id, 'planned_line_id' => $line->id],
            ]);

            return $purchaseLine;
        });

        if ($created) {
            app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::REQUEST_PURCHASE, $actor);
        }

        return $purchaseLine;
    }
}
