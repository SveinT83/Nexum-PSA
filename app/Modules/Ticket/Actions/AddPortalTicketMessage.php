<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Http\Request;

/** The public customer author and suppression flags are common to browser and API. */
class AddPortalTicketMessage
{
    public function __construct(private readonly AddTicketMessage $addTicketMessage) {}

    public function handle(
        Ticket $ticket,
        Request $request,
        CustomerPortalContext $context,
        string $body,
        bool $triggerWorkflow,
        string $sourceAction,
    ): TicketMessage {
        return $this->addTicketMessage->handle($ticket, [
            '_author_type' => 'portal_user',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => $ticket->subject,
            'body' => $body,
            'metadata' => [
                'source' => 'customer_portal',
                'customer_portal_account_id' => $context->account->id,
                'contact_id' => $context->contact->id,
            ],
            'suppress_notifications' => true,
            'suppress_workflow_trigger' => ! $triggerWorkflow,
            '_suppress_reply_delivery' => true,
            '_suppress_assignment_claim' => true,
            '_event_source_channel' => 'customer_portal',
            '_event_source_action' => $sourceAction,
        ], $request->user());
    }
}
