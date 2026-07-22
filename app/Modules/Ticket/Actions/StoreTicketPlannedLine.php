<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketPlannedLine;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StoreTicketPlannedLine
{
    public function __construct(private readonly TicketActionGuard $guard) {}

    public function handle(Ticket $ticket, array $data, User $actor): TicketPlannedLine
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::ADD_PLANNED_COST, $actor)) {
            throw ValidationException::withMessages(['planned_line' => $reason]);
        }

        $line = DB::transaction(function () use ($ticket, $data, $actor): TicketPlannedLine {
            $item = ! empty($data['storage_item_id']) ? Item::query()->findOrFail((int) $data['storage_item_id']) : null;

            $line = $ticket->plannedLines()->create([
                'line_type' => $item ? 'equipment' : ($data['line_type'] ?? 'custom'),
                'source_type' => $item ? 'storage_item' : ($data['source_type'] ?? 'custom'),
                'source_id' => $item?->id ?? ($data['source_id'] ?? null),
                'storage_item_id' => $item?->id,
                'section' => $data['section'] ?? ($item ? 'equipment' : 'one_time_costs'),
                'downstream_type' => $data['downstream_type'] ?? ($item ? 'equipment' : 'one_time_order'),
                'sku' => $item?->sku ?? ($data['sku'] ?? null),
                'name' => $data['name'] ?? $item?->name,
                'description' => $data['description'] ?? $item?->short_description,
                'quantity' => $data['quantity'] ?? 1,
                'unit' => $data['unit'] ?? ($item ? 'pcs' : null),
                'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $item?->purchase_price ?? 0,
                'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $item?->sale_price ?? 0,
                'vat_rate' => $data['vat_rate'] ?? $item?->vat_rate ?? 25,
                'status' => 'planned',
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
                'snapshot' => $item?->only(['id', 'sku', 'name', 'purchase_price', 'sale_price', 'vat_rate', 'can_be_ordered']),
                'metadata' => $data['metadata'] ?? [],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'planned_cost_added',
                'message' => 'Planned cost added without reserving or billing it.',
                'after' => ['planned_line_id' => $line->id, 'name' => $line->name, 'quantity' => $line->quantity],
            ]);

            app(InvalidateTicketWorkflowReviews::class)->handle($ticket, 'Planned commercial scope changed.', $actor);

            return $line;
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::ADD_PLANNED_COST, $actor);

        return $line;
    }
}
