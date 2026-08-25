<?php

namespace App\Modules\Commercial\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Actions\CaptureContractCustomerDocument;
use App\Modules\Commercial\Actions\RecordLegalAcceptance;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\PortalContractAccess;
use App\Modules\CustomerPortal\Actions\RecordCustomerPortalAudit;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use UnexpectedValueException;

class PortalContractController extends Controller
{
    public function index(
        Request $request,
        PortalContractAccess $access,
        ContractCustomerDocument $documents,
    ): View {
        $context = $this->context($request);

        $contracts = $access->visibleContracts($context)
            ->with(['client', 'items.timeRates', 'termSnapshots.termVersion'])
            ->latest('start_date')
            ->paginate(15);
        $customerDocuments = [];
        $customerDocumentReadiness = [];
        $contracts->getCollection()
            ->each(function (Contracts $contract) use ($documents, &$customerDocuments, &$customerDocumentReadiness): void {
                try {
                    $customerDocuments[(int) $contract->id] = $documents->resolve($contract);
                    $customerDocumentReadiness[(int) $contract->id] = [
                        'ready' => true,
                        'message' => null,
                    ];
                } catch (DomainException|UnexpectedValueException) {
                    // Preserve one authorized row per paginator result. The
                    // document, amount and link remain unavailable until the
                    // immutable evidence has been manually verified.
                    $customerDocuments[(int) $contract->id] = null;
                    $customerDocumentReadiness[(int) $contract->id] = [
                        'ready' => false,
                        'message' => 'Kundedokumentet er sperret i påvente av manuell verifisering.',
                    ];
                }
            });

        return view('commercial::Portal.contracts.index', [
            'context' => $context,
            'contracts' => $contracts,
            'customerDocuments' => $customerDocuments,
            'customerDocumentReadiness' => $customerDocumentReadiness,
        ]);
    }

    public function show(
        Request $request,
        Contracts $contract,
        PortalContractAccess $access,
        ContractCustomerDocument $documents,
    ): View {
        $context = $this->context($request);
        abort_unless($access->canView($context, $contract), 404);

        $contract->load(['client', 'sla', 'items.slaPolicy', 'items.timeRates', 'termSnapshots.termVersion']);
        try {
            $customerDocument = $documents->resolve($contract);
        } catch (DomainException|UnexpectedValueException) {
            abort(409, 'Kundedokumentet er sperret i påvente av manuell verifisering.');
        }

        return view('commercial::Portal.contracts.show', [
            'context' => $context,
            'contract' => $contract,
            'access' => $access,
            'customerDocument' => $customerDocument,
        ]);
    }

    public function accept(Request $request, Contracts $contract, PortalContractAccess $access, RecordCustomerPortalAudit $audit, SendCustomerPortalNotification $portalNotifications, RecordLegalAcceptance $legalAcceptance, CaptureContractCustomerDocument $customerDocuments): RedirectResponse
    {
        $context = $this->context($request);
        abort_unless($access->canView($context, $contract), 404);

        if (! $access->canAccept($contract)) {
            return back()->with('error', 'Avtalen kan ikke godtas med nåværende status.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'confirm' => ['required', 'accepted'],
        ], [
            'name.required' => 'Navn må fylles ut.',
            'name.string' => 'Navnet må være tekst.',
            'name.max' => 'Navnet kan ikke være lengre enn 255 tegn.',
            'confirm.required' => 'Du må bekrefte at du godtar avtalen.',
            'confirm.accepted' => 'Du må bekrefte at du godtar avtalen.',
        ]);

        try {
            $acceptedContract = DB::transaction(function () use ($request, $contract, $context, $access, $audit, $legalAcceptance, $customerDocuments, $data): ?Contracts {
                $locked = Contracts::query()
                    ->lockForUpdate()
                    ->findOrFail($contract->getKey());

                if (! $access->canAccept($locked)) {
                    return null;
                }

                $customerDocuments->handle($locked, 'won');

                $locked->forceFill([
                    'approval_status' => 'won',
                    'accepted_at' => now(),
                    'accepted_by_name' => $data['name'],
                    'accepted_ip' => $request->ip(),
                    'accepted_ua' => $request->userAgent(),
                    'portal_accepted_account_id' => $context->account->id,
                    'portal_accepted_membership_id' => $context->membership->id,
                    'portal_accepted_contact_id' => $context->contact->id,
                ])->save();

                $legalAcceptance->forContract($request, $context, $locked, $data['name']);

                $audit->handle(
                    'portal_contract_accepted',
                    $context->account,
                    $request->user(),
                    $context->contact,
                    $context->client,
                    $context->site,
                    [
                        'contract_id' => $locked->id,
                        'approval_status' => 'won',
                        'accepted_by_name' => $data['name'],
                    ],
                    $request,
                );

                return $locked;
            });
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (UnexpectedValueException) {
            return back()->with('error', 'Dokumentversjonen kan ikke behandles automatisk. Kontakt leverandøren.');
        }

        if (! $acceptedContract) {
            return back()->with('error', 'Avtalen kan ikke godtas med nåværende status.');
        }

        $portalNotifications->handle(
            type: 'portal_contract_accepted',
            clientId: (int) $context->client->id,
            siteId: null,
            title: 'Avtale godtatt',
            body: $acceptedContract->description.' ble godtatt av '.$data['name'].'.',
            url: route('customer-portal.contracts.show', $acceptedContract),
            sourceType: Contracts::class,
            sourceId: $acceptedContract->id,
            metadata: [
                'contract_id' => $acceptedContract->id,
                'accepted_by_name' => $data['name'],
            ],
        );

        return redirect()->route('customer-portal.contracts.show', $acceptedContract->refresh())
            ->with('success', 'Avtalen er godtatt. Takk.');
    }

    private function context(Request $request): CustomerPortalContext
    {
        /** @var CustomerPortalContext $context */
        $context = $request->attributes->get('customerPortalContext');

        return $context;
    }
}
