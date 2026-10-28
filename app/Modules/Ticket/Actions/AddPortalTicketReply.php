<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The same customer-authored reply/audit path serves both interfaces. */
class AddPortalTicketReply
{
    public function handle(Request $request, Ticket $ticket, CustomerPortalContext $context, string $body): TicketMessage
    {
        $validated = ['body' => $body];
        $audit = app(RecordCustomerPortalAudit::class);

        return DB::transaction(function () use ($request, $ticket, $context, $validated, $audit): TicketMessage {
            $message = app(AddPortalTicketMessage::class)->handle(
                $ticket,
                $request,
                $context,
                $validated['body'],
                triggerWorkflow: true,
                sourceAction: 'PortalTicketController.reply',
            );

            $audit->handle('portal_ticket_reply_created', $context->account, $request->user(), $context->contact, $context->client, $context->site, [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'message_id' => $message->id,
            ], $request);

            return $message;
        });
    }
}
