<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Actions\CreateTicketFromMailPlacement;
use App\Modules\Email\Actions\LinkMailPlacementToTicket;
use App\Modules\Email\Actions\SuppressEmailConversationTicketCorrelation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Email\Support\EmailApiAccess;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Http\Request;

/** Mail keeps the provider source while Ticket owns the linked case and its audience/work context. */
final class MailboxTicketApiController extends Controller
{
    public function context(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access)
    {
        $placement = $access->placement($request, $placement);
        $access->ordinary($request, $placement->account, MailboxAccess::VIEW);
        $links = app(\App\Modules\Email\Queries\EmailPlacementTicketLinks::class)->forPlacement($placement);

        return response()->json(['data' => [
            'links' => $links->map(fn ($link) => $link->only(['id', 'ticket_id', 'relationship_role', 'audience', 'status', 'linked_at'])
                + ['ticket_key' => $link->ticket?->ticket_key]),
            'can_create' => $request->user()->can('ticket.create') && $placement->message->ticket_id === null
                && app(MailboxAccess::class)->canAccessAccount($request->user(), $placement->account, MailboxAccess::ORGANIZE),
            'can_link' => $request->user()->can('ticket.update')
                && app(MailboxAccess::class)->canAccessAccount($request->user(), $placement->account, MailboxAccess::ORGANIZE),
        ]]);
    }

    public function create(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, CreateTicketFromMailPlacement $create)
    {
        $placement = $access->placement($request, $placement);
        $result = $create->handle($placement, $request->user());

        return response()->json(['data' => ['ticket_id' => $result['ticket']->id,
            'ticket_key' => $result['ticket']->ticket_key, 'link_id' => $result['link']->id]], 201);
    }

    public function link(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, LinkMailPlacementToTicket $link)
    {
        $data = $request->validate(['ticket_id' => ['required', 'integer', 'min:1']]);
        $placement = $access->placement($request, $placement);
        $relationship = $link->handle($placement, Ticket::query()->findOrFail($data['ticket_id']), $request->user());

        return response()->json(['data' => $relationship->only(['id', 'ticket_id', 'account_id',
            'email_conversation_id', 'relationship_role', 'audience', 'status', 'linked_at'])]);
    }

    public function suppress(Request $request, EmailMailboxPlacement $placement, EmailApiAccess $access, SuppressEmailConversationTicketCorrelation $suppress)
    {
        $placement = $access->placement($request, $placement);
        $access->ordinary($request, $placement->account, MailboxAccess::ORGANIZE);
        $suppress->handle($placement, $request->user());

        return response()->json(['data' => ['placement_id' => $placement->id, 'ticket_correlation_suppressed' => true]]);
    }
}
