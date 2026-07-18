<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Storage\Models\Movement;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class PickTicketStorageReservation
{
    public function __construct(
        private readonly GenerateOrders $generateOrders,
        private readonly TicketActionGuard $guard,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Pick reserved ticket stock
    |--------------------------------------------------------------------------
    |
    | Picking is the point where Storage permanently consumes stock and Economy
    | can turn the cost entry into an order line. Reserving only holds the item.
    |
    */
    public function handle(Ticket $ticket, TicketCostEntry $costEntry, ?User $actor = null): TicketCostEntry
    {
        abort_unless((int) $costEntry->ticket_id === (int) $ticket->id, 404);

        if ($reason = $this->guard->reason($ticket, TicketAction::PICK_ITEM, $actor)) {
            throw ValidationException::withMessages(['storage_item' => $reason]);
        }

        $entry = DB::transaction(function () use ($ticket, $costEntry, $actor): TicketCostEntry {
            $costEntry->loadMissing(['storageItem', 'reservation']);

            if ($costEntry->status !== 'reserved') {
                throw new InvalidArgumentException('Only reserved ticket costs can be picked.');
            }

            $item = $costEntry->storageItem;

            if (! $item) {
                throw new InvalidArgumentException('The reserved storage item no longer exists.');
            }

            $item->refresh();

            if ($item->qty_on_hand < $costEntry->quantity) {
                throw new InvalidArgumentException('Not enough on-hand stock to pick this item.');
            }

            $before = $item->qty_on_hand;
            $after = $before - $costEntry->quantity;

            $item->forceFill([
                'qty_on_hand' => $after,
                'qty_reserved' => max(0, $item->qty_reserved - $costEntry->quantity),
                'updated_by' => $actor?->id,
            ])->save();

            if ($costEntry->reservation) {
                $costEntry->reservation->forceFill(['status' => 'fulfilled'])->save();
            }

            Movement::create([
                'item_id' => $item->id,
                'actor_id' => $actor?->id,
                'type' => 'ticket_pick',
                'qty_before' => $before,
                'qty_delta' => -1 * $costEntry->quantity,
                'qty_after' => $after,
                'from_warehouse_id' => $item->warehouse_id,
                'to_warehouse_id' => $item->warehouse_id,
                'from_room_id' => $item->room_id,
                'to_room_id' => $item->room_id,
                'from_box_id' => $item->box_id,
                'to_box_id' => $item->box_id,
                'source_type' => $costEntry->getMorphClass(),
                'source_id' => (string) $costEntry->id,
                'reason' => 'Ticket cost picked',
                'note' => $ticket->ticket_key,
                'metadata' => [
                    'ticket_id' => $ticket->id,
                    'ticket_key' => $ticket->ticket_key,
                    'ticket_cost_entry_id' => $costEntry->id,
                ],
            ]);

            $costEntry->forceFill(['status' => 'picked'])->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'storage_item_picked',
                'message' => 'Reserved storage item picked for ticket.',
                'after' => [
                    'ticket_cost_entry_id' => $costEntry->id,
                    'storage_item_id' => $item->id,
                    'quantity' => $costEntry->quantity,
                ],
            ]);

            $this->generateOrders->handle($costEntry->created_at?->copy()->startOfMonth(), $costEntry->created_at?->copy()->endOfMonth(), $actor);

            return $costEntry->refresh();
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::PICK_ITEM, $actor);

        return $entry;
    }
}
