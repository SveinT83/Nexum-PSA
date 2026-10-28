<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** This separate content scope matches browser history text without exporting raw before/after or request metadata. */
class TicketHistoryApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $run = $this->type === 'automation_run' && $request->user()->can('ticket.rule_execution_view') && $request->user()->tokenCan('tickets.rules.executions.read') ? $this->ticket_rule_run_id : null;

        return ['id' => $this->id, 'ticket_id' => $this->ticket_id, 'actor_id' => $this->actor_id, 'type' => $this->type, 'message' => $this->message,
            'created_at' => $this->created_at?->toISOString(), 'automation_run_id' => $run,
            'automation_run_url' => $run ? route('api.v1.ticket-rule-executions.show', $run) : null];
    }
}
