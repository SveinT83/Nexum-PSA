<?php

namespace App\Modules\CustomerPortal\Actions;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Contact\Models\ContactRelation;
use App\Modules\CustomerPortal\Jobs\SendCustomerPortalInvitationEmail;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCustomerPortalInvitation
{
    public function __construct(private readonly RecordCustomerPortalAudit $audit)
    {
    }

    public function handle(User $actor, Contact $contact, Client $client, ?ClientSite $site, string $role, ?string $email = null): CustomerPortalInvitation
    {
        return DB::transaction(function () use ($actor, $contact, $client, $site, $role, $email): CustomerPortalInvitation {
            $this->validateScope($contact, $client, $site);

            if (! array_key_exists($role, CustomerPortalMembership::roleOptions())) {
                throw ValidationException::withMessages(['role' => 'The selected portal role is invalid.']);
            }

            $email = trim((string) ($email ?: $this->primaryEmailFor($contact)));

            if ($email === '') {
                throw ValidationException::withMessages(['email' => 'The contact needs an email address before a portal invitation can be sent.']);
            }

            $existingUser = User::query()->where('email', $email)->first();

            if ($existingUser && $existingUser->contact_id && (int) $existingUser->contact_id !== (int) $contact->id) {
                throw ValidationException::withMessages(['email' => 'This email belongs to a user linked to another Contact.']);
            }

            if ($this->activeMembershipExists($contact, $client, $site)) {
                throw ValidationException::withMessages(['contact_id' => 'This contact already has active portal access for the selected scope.']);
            }

            $this->revokePendingInvitations($contact, $client, $site);

            $rawToken = Str::random(64);
            $invitation = CustomerPortalInvitation::query()->create([
                'contact_id' => $contact->id,
                'client_id' => $client->id,
                'site_id' => $site?->id,
                'user_id' => $existingUser?->id,
                'email' => $email,
                'role' => $role,
                'token_hash' => CustomerPortalInvitation::hashToken($rawToken),
                'expires_at' => now()->addHours((int) config('auth.invite_expire_hours', 72)),
                'created_by' => $actor->id,
                'metadata' => [
                    'created_from' => 'customer_portal_admin',
                ],
            ]);

            $this->audit->handle('portal_invitation_created', user: $actor, contact: $contact, client: $client, site: $site, metadata: [
                'invitation_id' => $invitation->id,
                'email' => $email,
                'role' => $role,
            ]);

            SendCustomerPortalInvitationEmail::dispatch($invitation->id, $rawToken)->afterCommit();

            return $invitation;
        });
    }

    private function validateScope(Contact $contact, Client $client, ?ClientSite $site): void
    {
        if (! $client->active) {
            throw ValidationException::withMessages(['client_id' => 'Portal access can only be granted for active clients.']);
        }

        if ($site && (int) $site->client_id !== (int) $client->id) {
            throw ValidationException::withMessages(['site_id' => 'The selected site does not belong to the selected client.']);
        }

        if (($contact->status ?? 'active') !== 'active') {
            throw ValidationException::withMessages(['contact_id' => 'Portal access can only be granted to active contacts.']);
        }

        $hasClientScope = $this->hasContactClientScope($contact, $client);
        $hasSiteScope = ! $site || $this->hasContactSiteScope($contact, $site);

        if (! $hasClientScope || ! $hasSiteScope) {
            throw ValidationException::withMessages([
                'contact_id' => 'The contact must be related to the selected client and site before portal access can be granted.',
            ]);
        }
    }

    private function hasContactClientScope(Contact $contact, Client $client): bool
    {
        $clientMorph = $client->getMorphClass();

        return ContactRelation::query()
            ->where('contact_id', $contact->id)
            ->where('related_type', $clientMorph)
            ->where('related_id', $client->id)
            ->exists()
            || ClientUser::query()
                ->where('contact_id', $contact->id)
                ->whereHas('site', fn ($query) => $query->where('client_id', $client->id))
                ->exists();
    }

    private function hasContactSiteScope(Contact $contact, ClientSite $site): bool
    {
        $siteMorph = $site->getMorphClass();

        return ContactRelation::query()
            ->where('contact_id', $contact->id)
            ->where('related_type', $siteMorph)
            ->where('related_id', $site->id)
            ->exists()
            || ClientUser::query()
                ->where('contact_id', $contact->id)
                ->where('client_site_id', $site->id)
                ->exists();
    }

    private function primaryEmailFor(Contact $contact): ?string
    {
        return $contact->emails()
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->value('email');
    }

    private function activeMembershipExists(Contact $contact, Client $client, ?ClientSite $site): bool
    {
        return CustomerPortalMembership::query()
            ->where('client_id', $client->id)
            ->when($site, fn ($query) => $query->where('site_id', $site->id), fn ($query) => $query->whereNull('site_id'))
            ->where('status', CustomerPortalMembership::STATUS_ACTIVE)
            ->whereHas('account', fn ($query) => $query->where('contact_id', $contact->id))
            ->exists();
    }

    private function revokePendingInvitations(Contact $contact, Client $client, ?ClientSite $site): void
    {
        CustomerPortalInvitation::query()
            ->where('contact_id', $contact->id)
            ->where('client_id', $client->id)
            ->when($site, fn ($query) => $query->where('site_id', $site->id), fn ($query) => $query->whereNull('site_id'))
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->update(['revoked_at' => now()]);
    }
}
