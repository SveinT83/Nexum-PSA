<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StoreManualTicketCostEntry
{
    public function handle(Ticket $ticket, array $data, ?User $actor = null): TicketCostEntry
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $quantity = (int) $data['quantity'];

            if ($quantity < 1) {
                throw new InvalidArgumentException('Quantity must be at least 1.');
            }

            $entry = TicketCostEntry::create([
                'ticket_id' => $ticket->id,
                'user_id' => $actor?->id,
                'storage_item_id' => null,
                'storage_reservation_id' => null,
                'quantity' => $quantity,
                'item_name' => $data['item_name'],
                'item_sku' => null,
                'unit_price_ex_vat' => $data['unit_price_ex_vat'],
                'currency' => $data['currency'] ?? 'NOK',
                'status' => 'manual',
                'billing_status' => 'pending',
                'invoice_text' => $data['invoice_text'] ?? $data['item_name'],
                'note' => $data['note'] ?? null,
            ]);

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'manual_cost_added',
                'message' => 'Manual ticket cost added.',
                'after' => [
                    'ticket_cost_entry_id' => $entry->id,
                    'quantity' => $quantity,
                    'unit_price_ex_vat' => $entry->unit_price_ex_vat,
                    'currency' => $entry->currency,
                ],
            ]);

            app(ClaimUnassignedTicket::class)->handle($ticket, $actor, 'manual_cost_added');

            return $entry;
        });
    }
}
