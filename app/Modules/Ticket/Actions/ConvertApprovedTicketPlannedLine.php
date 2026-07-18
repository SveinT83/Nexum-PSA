<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConvertApprovedTicketPlannedLine
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly ReserveTicketStorageItem $reserve,
        private readonly StoreManualTicketCostEntry $manualCost,
    ) {}

    public function handle(Ticket $ticket, TicketPlannedLine $line, User $actor): TicketCostEntry
    {
        abort_unless((int) $line->ticket_id === (int) $ticket->id, 404);

        $action = $line->storage_item_id ? TicketAction::RESERVE_ITEM : TicketAction::ADD_ACTUAL_COST;
        if ($reason = $this->guard->reason($ticket, $action, $actor)) {
            throw ValidationException::withMessages(['planned_line' => $reason]);
        }

        if ($line->status !== 'approved' || ! $line->approved_quote_version_id) {
            throw ValidationException::withMessages(['planned_line' => 'Only a line from the accepted quote can be converted.']);
        }

        if ($line->converted_cost_entry_id) {
            return $line->convertedCostEntry()->firstOrFail();
        }

        return DB::transaction(function () use ($ticket, $line, $actor): TicketCostEntry {
            $locked = TicketPlannedLine::query()->lockForUpdate()->findOrFail($line->id);
            if ($locked->converted_cost_entry_id) {
                return $locked->convertedCostEntry()->firstOrFail();
            }

            $entry = $locked->storageItem
                ? $this->reserve->handle($ticket, $locked->storageItem, [
                    'quantity' => (int) ceil((float) $locked->quantity),
                    'invoice_text' => $locked->description ?: $locked->name,
                    'note' => 'Converted from approved planned line #'.$locked->id.'.',
                ], $actor)
                : $this->manualCost->handle($ticket, [
                    'quantity' => (int) ceil((float) $locked->quantity),
                    'item_name' => $locked->name,
                    'unit_price_ex_vat' => $locked->unit_price_ex_vat,
                    'currency' => 'NOK',
                    'invoice_text' => $locked->description ?: $locked->name,
                    'note' => 'Converted from approved planned line #'.$locked->id.'.',
                ], $actor);

            $locked->forceFill([
                'status' => 'converted',
                'converted_cost_entry_id' => $entry->id,
                'updated_by' => $actor->id,
            ])->save();

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'approved_scope_converted',
                'message' => 'Approved planned line converted to an actual Ticket cost.',
                'after' => ['planned_line_id' => $locked->id, 'ticket_cost_entry_id' => $entry->id],
            ]);

            return $entry;
        });
    }
}
