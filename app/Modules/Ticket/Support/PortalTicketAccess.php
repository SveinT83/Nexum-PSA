<?php

namespace App\Modules\Ticket\Support;

use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PortalTicketAccess
{
    public function visibleTickets(CustomerPortalContext $context): Builder
    {
        return Ticket::query()
            ->whereNotNull('portal_visible_at')
            ->where('client_id', $context->client->id)
            ->when($context->site, fn (Builder $query) => $query->where('site_id', $context->site->id));
    }

    public function canView(CustomerPortalContext $context, Ticket $ticket): bool
    {
        if (! $ticket->isPortalVisible()) {
            return false;
        }

        if ((int) $ticket->client_id !== (int) $context->client->id) {
            return false;
        }

        if ($context->site && (int) $ticket->site_id !== (int) $context->site->id) {
            return false;
        }

        return true;
    }

    /**
     * @return Collection<int, ClientSite>
     */
    public function availableSites(CustomerPortalContext $context): Collection
    {
        if ($context->site) {
            return collect([$context->site]);
        }

        return $context->client->sites()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function resolveSite(CustomerPortalContext $context, ?int $siteId = null): ?ClientSite
    {
        if ($context->site) {
            return $context->site;
        }

        if ($siteId) {
            return ClientSite::query()
                ->where('client_id', $context->client->id)
                ->findOrFail($siteId);
        }

        return $context->client->sites()
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }

    public function clientUserFor(CustomerPortalContext $context, ?ClientSite $site): ?ClientUser
    {
        if (! $site) {
            return null;
        }

        $email = $context->contact->emails()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->value('email')
            ?: $context->account->user?->email;

        $clientUser = ClientUser::query()
            ->where('contact_id', $context->contact->id)
            ->where('client_site_id', $site->id)
            ->first();

        if (! $clientUser && $email) {
            $clientUser = ClientUser::query()
                ->where('client_site_id', $site->id)
                ->where('email', $email)
                ->first();
        }

        if ($clientUser) {
            $clientUser->forceFill([
                'contact_id' => $clientUser->contact_id ?: $context->contact->id,
                'user_id' => $clientUser->user_id ?: $context->account->user_id,
                'name' => $clientUser->name ?: $context->contact->display_name,
                'email' => $clientUser->email ?: $email,
                'active' => true,
            ])->save();

            return $clientUser;
        }

        return ClientUser::query()->create([
            'contact_id' => $context->contact->id,
            'client_site_id' => $site->id,
            'user_id' => $context->account->user_id,
            'role' => 'Portal contact',
            'name' => $context->contact->display_name,
            'email' => $email,
            'active' => true,
        ]);
    }

    public function publicStatusLabel(Ticket $ticket): string
    {
        $status = $ticket->status;

        if (! $status) {
            return 'Open';
        }

        if ($status->is_closed) {
            return 'Closed';
        }

        return match ($status->state) {
            'waiting' => 'Waiting for customer',
            'resolved' => 'Resolved',
            default => 'Open',
        };
    }
}
