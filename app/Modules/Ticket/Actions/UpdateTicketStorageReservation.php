<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UpdateTicketStorageReservation
{
    public function handle(Ticket $ticket, TicketCostEntry $entry, array $data, ?User $actor = null): TicketCostEntry
    {
        return DB::transaction(function () use ($ticket, $entry, $data, $actor) {
            $entry->loadMissing(['storageItem', 'reservation']);
            $item = $entry->storageItem;

            if (! $item || (int) $entry->ticket_id !== (int) $ticket->id) {
                throw new InvalidArgumentException('The selected cost entry does not belong to this ticket.');
            }

            if ($entry->status !== 'reserved') {
                throw new InvalidArgumentException('Only reserved ticket costs can be edited before they are picked.');
            }

            $newQuantity = (int) $data['quantity'];

            if ($newQuantity < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }

            $oldQuantity = $entry->quantity;
            $delta = $newQuantity - $oldQuantity;
            $availableIncludingCurrentReservation = $item->qty_available + $oldQuantity;

            if (! $item->can_be_ordered && $newQuantity > $availableIncludingCurrentReservation) {
                throw new InvalidArgumentException('This item cannot be ordered, so only available stock can be reserved.');
            }

            $item->forceFill([
                'qty_reserved' => max(0, $item->qty_reserved + $delta),
                'updated_by' => $actor?->id,
            ])->save();

            if ($entry->reservation) {
                $entry->reservation->forceFill([
                    'qty' => $newQuantity,
                    'status' => 'active',
                ])->save();
            }

            $entry->forceFill([
                'quantity' => $newQuantity,
                'invoice_text' => $data['invoice_text'] ?? $entry->invoice_text,
                'note' => $data['note'] ?? null,
                'status' => 'reserved',
            ])->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'storage_reservation_updated',
                'message' => 'Ticket storage reservation updated.',
                'before' => ['quantity' => $oldQuantity],
                'after' => [
                    'ticket_cost_entry_id' => $entry->id,
                    'quantity' => $newQuantity,
                ],
            ]);

            return $entry->refresh();
        });
    }
}
