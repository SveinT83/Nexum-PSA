<?php

namespace App\Modules\Ticket\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\PortalTicketAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalTicketController extends Controller
{
    public function __construct(
        private readonly AddTicketMessage $addTicketMessage,
    ) {}

    public function index(Request $request, PortalTicketAccess $access): View
    {
        $context = $this->context($request);

        $tickets = $access->visibleTickets($context)
            ->with(['status', 'priority', 'site'])
            ->latest('updated_at')
            ->paginate(15);

        return view('ticket::Portal.tickets.index', [
            'context' => $context,
            'tickets' => $tickets,
            'access' => $access,
        ]);
    }

    public function create(Request $request, PortalTicketAccess $access): View
    {
        $context = $this->context($request);

        return view('ticket::Portal.tickets.create', [
            'context' => $context,
            'sites' => $access->availableSites($context),
        ]);
    }

    public function store(Request $request, StoreTicket $storeTicket, PortalTicketAccess $access, RecordCustomerPortalAudit $audit, SendCustomerPortalNotification $portalNotifications): RedirectResponse
    {
        $context = $this->context($request);
        $validated = $request->validate([
            'site_id' => ['nullable', 'integer'],
            'subject' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
        ]);

        $ticket = DB::transaction(function () use ($request, $storeTicket, $access, $audit, $portalNotifications, $context, $validated): Ticket {
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

            $message = $this->createPortalMessage(
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

        return redirect()->route('customer-portal.tickets.show', $ticket)
            ->with('success', 'Ticket '.$ticket->ticket_key.' was created.');
    }

    public function show(Request $request, Ticket $ticket, PortalTicketAccess $access): View
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $ticket), 404);

        $ticket->load(['status', 'priority', 'site', 'client']);
        $messages = $ticket->messages()
            ->with(['author', 'fileAttachments'])
            ->where('visibility', 'public')
            ->orderBy('created_at')
            ->get();

        return view('ticket::Portal.tickets.show', [
            'context' => $context,
            'ticket' => $ticket,
            'messages' => $messages,
            'access' => $access,
        ]);
    }

    public function reply(Request $request, Ticket $ticket, PortalTicketAccess $access, RecordCustomerPortalAudit $audit): RedirectResponse
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $ticket), 404);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        DB::transaction(function () use ($request, $ticket, $context, $validated, $audit): void {
            $message = $this->createPortalMessage(
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
        });

        return redirect()->route('customer-portal.tickets.show', $ticket)
            ->with('success', 'Reply added.');
    }

    public function downloadAttachment(Request $request, Ticket $ticket, TicketAttachment $attachment, PortalTicketAccess $access): StreamedResponse
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $ticket), 404);
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id, 404);

        $attachment->loadMissing('message');
        abort_unless($attachment->message && $attachment->message->visibility === 'public', 404);

        $disk = $attachment->disk ?: 'local';
        abort_unless($attachment->path && Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->filename);
    }

    private function createPortalMessage(
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

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
