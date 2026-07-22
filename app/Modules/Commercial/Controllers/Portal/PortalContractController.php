<?php

namespace App\Modules\Commercial\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Actions\RecordLegalAcceptance;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\PortalContractAccess;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PortalContractController extends Controller
{
    public function index(Request $request, PortalContractAccess $access): View
    {
        $context = $this->context($request);

        $contracts = $access->visibleContracts($context)
            ->with(['sla', 'items'])
            ->latest('start_date')
            ->paginate(15);

        return view('commercial::Portal.contracts.index', [
            'context' => $context,
            'contracts' => $contracts,
            'access' => $access,
        ]);
    }

    public function show(Request $request, Contracts $contract, PortalContractAccess $access): View
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $contract), 404);

        $contract->load(['client', 'sla', 'items.slaPolicy', 'items.timeRates', 'termSnapshots']);

        return view('commercial::Portal.contracts.show', [
            'context' => $context,
            'contract' => $contract,
            'access' => $access,
        ]);
    }

    public function accept(Request $request, Contracts $contract, PortalContractAccess $access, RecordCustomerPortalAudit $audit, SendCustomerPortalNotification $portalNotifications, RecordLegalAcceptance $legalAcceptance): RedirectResponse
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $contract), 404);

        if (! $access->canAccept($contract)) {
            return back()->with('error', 'This contract cannot be accepted in its current status.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ]);

        DB::transaction(function () use ($request, $contract, $context, $audit, $portalNotifications, $legalAcceptance, $data): void {
            $contract->forceFill([
                'approval_status' => 'won',
                'accepted_at' => now(),
                'accepted_by_name' => $data['name'],
                'accepted_ip' => $request->ip(),
                'accepted_ua' => $request->userAgent(),
                'portal_accepted_account_id' => $context->account->id,
                'portal_accepted_membership_id' => $context->membership->id,
                'portal_accepted_contact_id' => $context->contact->id,
            ])->save();

            $legalAcceptance->forContract($request, $context, $contract, $data['name']);

            $audit->handle(
                'portal_contract_accepted',
                $context->account,
                $request->user(),
                $context->contact,
                $context->client,
                $context->site,
                [
                    'contract_id' => $contract->id,
                    'approval_status' => 'won',
                    'accepted_by_name' => $data['name'],
                ],
                $request,
            );

            $portalNotifications->handle(
                type: 'portal_contract_accepted',
                clientId: (int) $context->client->id,
                siteId: null,
                title: 'Contract accepted',
                body: $contract->description.' was accepted by '.$data['name'].'.',
                url: route('customer-portal.contracts.show', $contract),
                sourceType: Contracts::class,
                sourceId: $contract->id,
                metadata: [
                    'contract_id' => $contract->id,
                    'accepted_by_name' => $data['name'],
                ],
            );
        });

        return redirect()->route('customer-portal.contracts.show', $contract->refresh())
            ->with('success', 'Contract accepted. Thank you.');
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
