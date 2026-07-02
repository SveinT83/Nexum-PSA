<?php

namespace App\Modules\Relationship\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Relationship\Actions\EscalateTicketToRelationship;
use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Support\RelationshipStatus;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TicketRelationshipController extends Controller
{
    public function __invoke(Request $request, Ticket $ticket, EscalateTicketToRelationship $escalate): RedirectResponse
    {
        $data = $request->validate([
            'relationship_id' => ['required', Rule::exists('nexum_relationships', 'id')->where('status', RelationshipStatus::ACTIVE)],
        ]);

        $relationship = NexumRelationship::query()->findOrFail($data['relationship_id']);
        $link = $escalate->handle($ticket->loadMissing(['client', 'status', 'priority']), $relationship, $request->user());

        return redirect()
            ->route('tech.tickets.show', $ticket->refresh())
            ->with($link->sync_status === 'synced' ? 'success' : 'warning', $link->sync_status === 'synced'
                ? 'Ticket escalated through Nexum relationship.'
                : 'Ticket escalation was recorded, but remote sync did not complete. Normal customer email behavior remains unchanged.');
    }
}
