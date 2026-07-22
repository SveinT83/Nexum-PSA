<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Reservation;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class ReserveTicketStorageItem
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    /*
    |--------------------------------------------------------------------------
    | Ticket storage reservation
    |--------------------------------------------------------------------------
    |
    | Reserving an item only marks stock as held for this ticket. It does not
    | consume stock permanently or decide invoice handling. Billing can later
    | settle the pending cost entry and Storage can later convert the
    | reservation into a final stock movement.
    |
    */
    public function handle(Ticket $ticket, Item $item, array $data, ?User $actor = null): TicketCostEntry
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::RESERVE_ITEM, $actor)) {
            throw ValidationException::withMessages(['storage_item' => $reason]);
        }

        $entry = DB::transaction(function () use ($ticket, $item, $data, $actor) {
            $item->refresh();
            $quantity = (int) $data['quantity'];

            if ($quantity < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }

            if (! $item->can_be_ordered && $item->qty_available < $quantity) {
                throw new InvalidArgumentException('This item cannot be ordered, so only available stock can be reserved.');
            }

            $reservation = Reservation::create([
                'item_id' => $item->id,
                'warehouse_id' => $item->warehouse_id,
                'box_id' => $item->box_id,
                'qty' => $quantity,
                'source_type' => 'ticket',
                'source_id' => (string) $ticket->id,
                'strength' => 'hard',
                'status' => 'active',
                'created_by' => $actor?->id,
            ]);

            $item->forceFill([
                'qty_reserved' => $item->qty_reserved + $quantity,
                'updated_by' => $actor?->id,
            ])->save();

            $entry = TicketCostEntry::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor?->id,
                'storage_item_id' => $item->id,
                'storage_reservation_id' => $reservation->id,
                'quantity' => $quantity,
                'item_name' => $item->name,
                'item_sku' => $item->sku,
                'unit_price_ex_vat' => $item->sale_price,
                'currency' => 'NOK',
                'status' => 'reserved',
                'billing_status' => 'pending',
                'invoice_text' => $data['invoice_text'] ?? ($item->short_description ?: $item->name),
                'note' => $data['note'] ?? null,
            ]);

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'storage_item_reserved',
                'message' => 'Storage item reserved for ticket.',
                'after' => [
                    'ticket_cost_entry_id' => $entry->id,
                    'storage_item_id' => $item->id,
                    'reservation_id' => $reservation->id,
                    'quantity' => $quantity,
                ],
            ]);

            app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'storage_item_reserved');

            return $entry;
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::RESERVE_ITEM, $actor);

        return $entry;
    }
}
