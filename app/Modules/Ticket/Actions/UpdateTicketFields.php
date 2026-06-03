<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;

class UpdateTicketFields
{
    /*
    |--------------------------------------------------------------------------
    | Editable ticket fields
    |--------------------------------------------------------------------------
    |
     | This action captures the editable ticket fields technicians can change
     | before workflows exist. Status has a dedicated action because it drives
     | lifecycle timestamps. Asset is included here because it is ticket context,
     | not a workflow transition.
    |
    */
    public function handle(Ticket $ticket, array $data, ?User $actor = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $actor) {
            $fields = ['subject', 'description', 'queue_id', 'priority_id', 'category_id', 'client_id', 'site_id', 'contact_id', 'asset_id', 'owner_id'];
            $updates = array_intersect_key($data, array_flip($fields));
            $before = [];
            $after = [];

            foreach ($updates as $field => $value) {
                $normalizedValue = $value === '' ? null : $value;

                if ((string) $ticket->{$field} !== (string) $normalizedValue) {
                    $before[$field] = $ticket->{$field};
                    $after[$field] = $normalizedValue;
                }
            }

            if ($after === []) {
                return $ticket;
            }

            $ticket->forceFill(array_merge($after, [
                'updated_by' => $actor?->id,
            ]))->save();

            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'fields_updated',
                'before' => $before,
                'after' => $after,
                'message' => 'Ticket fields updated.',
            ]);

            return $ticket->refresh();
        });
    }
}
