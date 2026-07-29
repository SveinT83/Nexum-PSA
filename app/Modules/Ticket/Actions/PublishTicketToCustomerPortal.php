<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class PublishTicketToCustomerPortal
{
    public function __construct(
        private readonly SendCustomerPortalNotification $portalNotifications,
    ) {}

    /** @return array{ticket: Ticket, published_now: bool} */
    public function handle(Ticket $ticket, ?User $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($ticket, $actor): array {
            /** @var Ticket $lockedTicket */
            $lockedTicket = Ticket::query()
                ->whereKey($ticket->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedTicket->client_id) {
                throw ValidationException::withMessages([
                    'portal_visible' => 'Only client-scoped tickets can be shown in the customer portal.',
                ]);
            }

            if ($lockedTicket->isPortalVisible()) {
                return ['ticket' => $lockedTicket, 'published_now' => false];
            }

            $lockedTicket->forceFill([
                'portal_visible_at' => now(),
                'portal_visible_by' => $actor?->id,
            ])->save();

            TicketEvent::query()->create([
                'ticket_id' => $lockedTicket->id,
                'actor_id' => $actor?->id,
                'type' => 'portal_visibility_enabled',
                'message' => 'Ticket published in customer portal.',
                'after' => ['portal_visible' => true],
            ]);

            $this->portalNotifications->handle(
                type: 'portal_ticket_created',
                clientId: (int) $lockedTicket->client_id,
                siteId: $lockedTicket->site_id ? (int) $lockedTicket->site_id : null,
                title: 'Ticket '.$lockedTicket->ticket_key.' is available',
                body: $lockedTicket->subject,
                url: route('customer-portal.tickets.show', $lockedTicket),
                sourceType: Ticket::class,
                sourceId: $lockedTicket->id,
                metadata: ['ticket_key' => $lockedTicket->ticket_key],
            );

            return ['ticket' => $lockedTicket->refresh(), 'published_now' => true];
        });
    }

    public function authorize(?User $actor): void
    {
        if (! $actor || ! $actor->isActive()) {
            throw new AuthorizationException('An active user is required to publish a Ticket.');
        }

        if (Permission::query()->where('name', 'ticket.update')->where('guard_name', 'web')->exists()
            && ! $actor->can('ticket.update')) {
            throw new AuthorizationException('Missing permission: ticket.update.');
        }
    }
}
