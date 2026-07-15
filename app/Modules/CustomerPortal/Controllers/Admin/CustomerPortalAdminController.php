<?php

namespace App\Modules\CustomerPortal\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Actions\CreateCustomerPortalInvitation;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalInvitation;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CustomerPortalAdminController extends Controller
{
    public function index(): View
    {
        return view('customerportal::Admin.index', [
            'accounts' => CustomerPortalAccount::query()
                ->with(['user', 'contact', 'memberships.client', 'memberships.site'])
                ->latest()
                ->limit(50)
                ->get(),
            'invitations' => CustomerPortalInvitation::query()
                ->with(['contact', 'client', 'site', 'creator'])
                ->latest()
                ->limit(50)
                ->get(),
            'contacts' => Contact::query()
                ->with('emails')
                ->where('status', 'active')
                ->orderBy('display_name')
                ->limit(250)
                ->get(),
            'clients' => Client::query()
                ->with(['sites' => fn ($query) => $query->orderBy('name')])
                ->where('active', true)
                ->orderBy('name')
                ->get(),
            'sites' => ClientSite::query()
                ->with('client')
                ->whereHas('client', fn ($query) => $query->where('active', true))
                ->orderBy('name')
                ->get(),
            'roles' => CustomerPortalMembership::roleOptions(),
            'stats' => [
                'accounts' => CustomerPortalAccount::query()->count(),
                'active_memberships' => CustomerPortalMembership::query()->where('status', CustomerPortalMembership::STATUS_ACTIVE)->count(),
                'pending_invitations' => CustomerPortalInvitation::query()->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            ],
        ]);
    }

    public function storeInvitation(Request $request, CreateCustomerPortalInvitation $createInvitation): RedirectResponse
    {
        $validated = $request->validate([
            'contact_id' => ['required', Rule::exists('contacts', 'id')],
            'client_id' => ['required', Rule::exists('clients', 'id')],
            'site_id' => ['nullable', Rule::exists('client_sites', 'id')],
            'role' => ['required', Rule::in(array_keys(CustomerPortalMembership::roleOptions()))],
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $invitation = $createInvitation->handle(
            $request->user(),
            Contact::query()->findOrFail($validated['contact_id']),
            Client::query()->findOrFail($validated['client_id']),
            filled($validated['site_id'] ?? null) ? ClientSite::query()->findOrFail($validated['site_id']) : null,
            $validated['role'],
            $validated['email'] ?? null,
        );

        return redirect()->route('tech.admin.system.customer-portal.index')
            ->with('success', 'Portal invitation sent to '.$invitation->email.'.');
    }

    public function disableMembership(Request $request, CustomerPortalMembership $membership, RecordCustomerPortalAudit $audit): RedirectResponse
    {
        $membership->load(['account', 'client', 'site']);
        $membership->forceFill([
            'status' => CustomerPortalMembership::STATUS_DISABLED,
            'disabled_at' => now(),
        ])->save();

        $audit->handle('portal_membership_disabled', $membership->account, $request->user(), client: $membership->client, site: $membership->site, metadata: [
            'membership_id' => $membership->id,
        ], request: $request);

        return redirect()->route('tech.admin.system.customer-portal.index')
            ->with('success', 'Portal membership disabled.');
    }

    public function revokeInvitation(Request $request, CustomerPortalInvitation $invitation, RecordCustomerPortalAudit $audit): RedirectResponse
    {
        if (! $invitation->accepted_at && ! $invitation->revoked_at) {
            $invitation->forceFill(['revoked_at' => now()])->save();
            $audit->handle('portal_invitation_revoked', user: $request->user(), contact: $invitation->contact, client: $invitation->client, site: $invitation->site, metadata: [
                'invitation_id' => $invitation->id,
            ], request: $request);
        }

        return redirect()->route('tech.admin.system.customer-portal.index')
            ->with('success', 'Portal invitation revoked.');
    }
}
