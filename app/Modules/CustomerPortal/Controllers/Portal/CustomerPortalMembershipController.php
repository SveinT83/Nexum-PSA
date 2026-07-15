<?php

namespace App\Modules\CustomerPortal\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class CustomerPortalMembershipController extends Controller
{
    public function switch(Request $request, CustomerPortalMembership $membership, RecordCustomerPortalAudit $audit): RedirectResponse
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        abort_unless((int) $membership->customer_portal_account_id === (int) $context->account->id, 403);
        abort_unless($membership->isActive(), 403);

        $membership->load(['client', 'site']);
        abort_unless($membership->client && $membership->client->active, 403);

        if ($membership->site) {
            abort_unless((int) $membership->site->client_id === (int) $membership->client_id, 403);
        }

        $request->session()->put('customer_portal_membership_id', $membership->id);

        $audit->handle('portal_membership_switched', $context->account, $request->user(), $context->contact, $membership->client, $membership->site, [
            'membership_id' => $membership->id,
        ], $request);

        return redirect()->route('customer-portal.dashboard');
    }
}
