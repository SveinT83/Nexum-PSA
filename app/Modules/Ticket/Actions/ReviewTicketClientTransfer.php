<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication as Outbound;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Validation\ValidationException;

/** Resolve the retained or explicitly replaced children against the destination before writing. */
final class ReviewTicketClientTransfer
{
    public function handle(Ticket $ticket, array $fields, ?User $actor): array
    {
        abort_unless($actor?->isActive() && $actor->can('ticket.update') && $actor->can('client.view'), 403);
        if (request()->is('api/*')) {
            abort_unless($actor->tokenCan('clients.read'), 403, 'Client read scope is required to move a Ticket.');
        }
        app(TicketMutationScopeGuard::class)->assert($ticket);
        $clientId = $fields['client_id'];
        if ($clientId !== null) {
            abort_unless(Client::whereKey($clientId)->where('active', true)->exists(), 422, 'Select an active destination Client.');
        }
        $context = array_replace($ticket->only(['site_id', 'contact_id', 'asset_id']), array_intersect_key($fields, array_flip(['site_id', 'contact_id', 'asset_id'])));
        $site = $context['site_id'];
        if ($site && ! ClientSite::whereKey($site)->where('client_id', $clientId)->exists()) {
            throw ValidationException::withMessages(['site_id' => 'Clear the previous site or select a destination site.']);
        }
        if ($context['contact_id'] && ! ClientUser::whereKey($context['contact_id'])
            ->whereHas('site', fn ($q) => $q->where('client_id', $clientId))
            ->when($site, fn ($q) => $q->where('client_site_id', $site))->exists()) {
            throw ValidationException::withMessages(['contact_id' => 'Clear the previous contact or select a contact at the destination.']);
        }
        if ($context['asset_id'] && ! Asset::whereKey($context['asset_id'])->where('client_id', $clientId)
            ->when($site, fn ($q) => $q->where(fn ($q) => $q->whereNull('site_id')->orWhere('site_id', $site)))->exists()) {
            throw ValidationException::withMessages(['asset_id' => 'Clear the previous asset or select one belonging to the destination.']);
        }
        abort_if(Outbound::where('ticket_id', $ticket->id)->whereIn('state', [Outbound::STATE_DRAFT, Outbound::STATE_RESERVED, Outbound::STATE_ACCEPTED, Outbound::STATE_UNRESOLVED])->exists(),
            409, 'Resolve the pending outbound email before transferring this Ticket.');

        return $context;
    }
}
