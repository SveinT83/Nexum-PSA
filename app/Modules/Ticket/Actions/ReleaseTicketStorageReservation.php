<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Reservation;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReleaseTicketStorageReservation
{
    /*
    |--------------------------------------------------------------------------
    | Release reserved Ticket stock
    |--------------------------------------------------------------------------
    |
    | Removing a reservation is a lifecycle transition, not a hard delete.
    | Stock availability, the picking queue, approved planned scope, and the
    | Ticket audit trail must move together inside the same transaction.
    |
    */
    public function handle(Ticket $ticket, TicketCostEntry $entry, ?User $actor = null): TicketCostEntry
    {
        return DB::transaction(function () use ($ticket, $entry, $actor): TicketCostEntry {
            $lockedEntry = TicketCostEntry::query()
                ->lockForUpdate()
                ->findOrFail($entry->id);

            abort_unless((int) $lockedEntry->ticket_id === (int) $ticket->id, 404);

            if ($lockedEntry->status !== 'reserved' || $lockedEntry->billing_status !== 'pending') {
                throw new InvalidArgumentException('Only pending reserved ticket costs can be removed.');
            }

            $item = Item::withTrashed()
                ->lockForUpdate()
                ->find($lockedEntry->storage_item_id);

            if (! $item) {
                throw new InvalidArgumentException('The reserved storage item no longer exists.');
            }

            $reservation = $lockedEntry->storage_reservation_id
                ? Reservation::query()->lockForUpdate()->find($lockedEntry->storage_reservation_id)
                : null;

            if ($reservation && $reservation->status !== 'active') {
                throw new InvalidArgumentException('Only active storage reservations can be removed.');
            }

            $before = [
                'ticket_cost_entry_id' => $lockedEntry->id,
                'storage_item_id' => $item->id,
                'storage_reservation_id' => $reservation?->id,
                'item_name' => $lockedEntry->item_name,
                'item_sku' => $lockedEntry->item_sku,
                'quantity' => $lockedEntry->quantity,
                'invoice_text' => $lockedEntry->invoice_text,
                'note' => $lockedEntry->note,
                'cost_status' => $lockedEntry->status,
                'billing_status' => $lockedEntry->billing_status,
                'reservation_status' => $reservation?->status,
                'item_qty_reserved' => $item->qty_reserved,
            ];

            $item->forceFill([
                'qty_reserved' => max(0, $item->qty_reserved - $lockedEntry->quantity),
                'updated_by' => $actor?->id,
            ])->save();

            if ($reservation) {
                $reservation->forceFill(['status' => 'released'])->save();
            }

            TicketPlannedLine::query()
                ->where('converted_cost_entry_id', $lockedEntry->id)
                ->lockForUpdate()
                ->get()
                ->each(function (TicketPlannedLine $line) use ($actor): void {
                    $line->forceFill([
                        'status' => 'approved',
                        'converted_cost_entry_id' => null,
                        'updated_by' => $actor?->id,
                    ])->save();
                });

            $lockedEntry->forceFill([
                'status' => 'released',
                'billing_status' => 'cancelled',
            ])->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'storage_reservation_released',
                'message' => 'Storage reservation removed from Ticket and released.',
                'before' => $before,
                'after' => [
                    'ticket_cost_entry_id' => $lockedEntry->id,
                    'storage_item_id' => $item->id,
                    'storage_reservation_id' => $reservation?->id,
                    'cost_status' => 'released',
                    'billing_status' => 'cancelled',
                    'reservation_status' => $reservation ? 'released' : null,
                    'item_qty_reserved' => $item->qty_reserved,
                ],
            ]);

            return $lockedEntry->refresh();
        });
    }
}
