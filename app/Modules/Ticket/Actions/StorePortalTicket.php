<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\PortalTicketAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Existing portal creation, public first message, audit and customer notification share one transaction. */
class StorePortalTicket
{
    public function handle(Request $request, CustomerPortalContext $context, array $validated): Ticket
    {
        $storeTicket = app(StoreTicket::class);
        $access = app(PortalTicketAccess::class);
        $audit = app(RecordCustomerPortalAudit::class);
        $portalNotifications = app(SendCustomerPortalNotification::class);

        return DB::transaction(function () use ($request, $storeTicket, $access, $audit, $portalNotifications, $context, $validated): Ticket {
            $site = $access->resolveSite($context, filled($validated['site_id'] ?? null) ? (int) $validated['site_id'] : null);
            $clientUser = $access->clientUserFor($context, $site);
            $ticket = $storeTicket->handle([
                'channel' => 'customer_portal',
                'client_id' => $context->client->id,
                'site_id' => $site?->id,
                'contact_id' => $clientUser?->id,
                'subject' => $validated['subject'],
                'description' => null,
            ], $request->user());

            $ticket->forceFill([
                'portal_visible_at' => now(),
                'portal_visible_by' => $request->user()?->id,
                'is_unread' => true,
                'metadata' => array_merge($ticket->metadata ?? [], [
                    'customer_portal' => [
                        'created_by_account_id' => $context->account->id,
                        'created_by_contact_id' => $context->contact->id,
                    ],
                ]),
            ])->save();

            $message = app(AddPortalTicketMessage::class)->handle(
                $ticket,
                $request,
                $context,
                $validated['description'],
                triggerWorkflow: false,
                sourceAction: 'PortalTicketController.store',
            );

            $audit->handle('portal_ticket_created', $context->account, $request->user(), $context->contact, $context->client, $site, [
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'message_id' => $message->id,
            ], $request);

            $portalNotifications->handle(
                type: 'portal_ticket_created',
                clientId: (int) $context->client->id,
                siteId: $site?->id,
                title: 'Ticket '.$ticket->ticket_key.' was created',
                body: $ticket->subject,
                url: route('customer-portal.tickets.show', $ticket),
                sourceType: Ticket::class,
                sourceId: $ticket->id,
                metadata: [
                    'ticket_key' => $ticket->ticket_key,
                    'message_id' => $message->id,
                ],
            );

            return $ticket;
        });

    }
}
