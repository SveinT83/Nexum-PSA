<?php

namespace App\Modules\Commercial\Controllers\Tech\Contracts;

use App\Http\Controllers\Controller;
use App\Mail\ContractLinkSent;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\AttestLegacyContractCustomerDocument;
use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Commercial\Requests\ContractsRequest;
use App\Modules\Commercial\Support\ContractCustomerDocument;
use App\Modules\Commercial\Support\ContractDocumentReadiness;
use App\Modules\Commercial\Support\ContractTermSnapshotReadiness;
use App\Modules\Email\Services\BodyNormalizer;
use App\Modules\Email\Services\DefaultEmailAccountResolver;
use App\Modules\Email\Services\EmailProviderBindingSnapshot;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\System\Support\CompanyProfileSettings;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use UnexpectedValueException;

/**
 * Class ContractController
 *
 * Manages the full lifecycle of Customer Service (CS) Contracts.
 * Key responsibilities:
 * - Listing and showing contracts.
 * - Multi-step contract creation (Basic info -> Services -> Terms).
 * - Real-time validation and snapshotting of legal terms (GDPR, DPA, SLA).
 * - Contextual data gathering (Client metrics, technician roles).
 */
class ContractController extends Controller
{
    /**
     * SHOW - Display a detailed preview of a specific Contract.
     *
     * This view serves as a pre-approval checkpoint. It performs:
     * 1. Eager loading of client data, contract items, associated services, and their costs/terms.
     * 2. Validation of readiness for approval based on:
     *    - Presence of at least one service item.
     *    - Existence of a non-empty terms/legal snapshot.
     *    - A future-dated start date.
     * 3. Detection of "Stale Terms": Checks if services with terms were added after the last snapshot was taken.
     *
     * @return \Illuminate\View\View
     */
    public function show(Request $request, Contracts $contract)
    {
        $contract->load([
            'client',
            'termSnapshots.termVersion',
            'sla',
            'items.slaPolicy',
            'items.timeRates',
            'items.service.serviceTerms',
            'items.service.costRelations.cost',
        ]);

        // A reviewed fingerprint keeps appendix text aligned with the exact
        // Service term versions that will be named in the frozen document.
        $termReadiness = app(ContractTermSnapshotReadiness::class);
        $hasMissingTerms = $contract->isEditable() && ! $termReadiness->isCurrent($contract);
        $customerDocuments = app(ContractCustomerDocument::class);
        $documentEvidenceBlockers = [];
        $requiredLegacyDocumentType = match ((string) $contract->approval_status) {
            'sent_quote' => 'Tilbud',
            'sent_contract' => 'Avtale',
            default => null,
        };
        $requestedLegacyDocumentType = $request->query('legacy_document_type');
        $legacyDocumentType = $requiredLegacyDocumentType
            ?? (in_array($requestedLegacyDocumentType, ['Tilbud', 'Avtale'], true)
                ? $requestedLegacyDocumentType
                : null);
        $legacyDocumentTypeAmbiguous = $requiredLegacyDocumentType === null;

        try {
            $customerDocument = $customerDocuments->resolve($contract);
        } catch (DomainException $exception) {
            $documentEvidenceBlockers[] = $exception->getMessage();
            $customerDocument = $customerDocuments->previewForLegacyAttestation(
                $contract,
                null,
                $legacyDocumentType,
            );
        } catch (UnexpectedValueException) {
            return redirect()->route('tech.contracts.index')
                ->with('error', 'Kundedokumentets lagrede format kan ikke leses. Ingen live fallback ble brukt.');
        }

        // Readiness logic for the UI to enable/disable approval and export actions.
        $documentReadiness = app(ContractDocumentReadiness::class);
        $missingLegalIdentity = $documentReadiness->missingLegalIdentity($contract);
        $hasCapturedCustomerDocument = $customerDocuments->hasStoredSnapshot($contract);
        $legacyAttestationAvailable = ! $contract->isEditable()
            && ! $hasCapturedCustomerDocument
            && in_array($contract->approval_status, ['sent_quote', 'sent_contract', 'approved', 'won'], true);
        $identityRequiredForNextTransition = $contract->isEditable() || ! $hasCapturedCustomerDocument;
        $blockingLegalIdentity = $identityRequiredForNextTransition ? $missingLegalIdentity : [];
        $legacyAttestationFingerprint = null;
        if ($legacyAttestationAvailable
            && $blockingLegalIdentity === []
            && $legacyDocumentType !== null) {
            try {
                $legacyAttestationFingerprint = $customerDocuments->fingerprint($customerDocument);
            } catch (UnexpectedValueException) {
                // The marked internal aid may still be inspected, but an
                // incomplete v1 document cannot be attested or released.
            }
        }
        $validation = [
            'has_items' => $contract->items->count() > 0,
            'has_terms' => ! empty($contract->terms_snapshot) || ! empty($contract->dpa_snapshot) || ! empty($contract->legal_snapshot),
            'future_start_date' => $contract->start_date && $contract->start_date->isFuture(),
            'valid_contract_period' => $contract->hasValidContractPeriod(),
            'ready' => $contract->isReady() && ! $hasMissingTerms && $blockingLegalIdentity === [],
            'has_missing_terms' => $hasMissingTerms,
            'show_readiness_status' => ! in_array($contract->approval_status, ['approved', 'won'], true),
            'pdf_available' => $this->canDownloadPdf($contract)
                && $documentEvidenceBlockers === []
                && ($hasCapturedCustomerDocument || $missingLegalIdentity === []),
            'customer_access_available' => $documentEvidenceBlockers === []
                && ($hasCapturedCustomerDocument || $missingLegalIdentity === [])
                && filled($contract->secure_token),
            'customer_document_missing_identity' => $blockingLegalIdentity,
            'customer_document_evidence_blockers' => $documentEvidenceBlockers,
            'legacy_attestation_available' => $legacyAttestationAvailable,
            'legacy_attestation_fingerprint' => $legacyAttestationFingerprint,
            'legacy_attestation_document_type' => $legacyDocumentType,
            'legacy_attestation_document_type_ambiguous' => $legacyAttestationAvailable
                && $legacyDocumentTypeAmbiguous,
            'legacy_attestation_preview_available' => ! ($legacyAttestationAvailable
                && $legacyDocumentTypeAmbiguous
                && $legacyDocumentType === null),
        ];

        return view('commercial::Tech.cs.contracts.show', [
            'contract' => $contract,
            'client' => $contract->client,
            'validation' => $validation,
            'defaultSla' => Sla::query()->where('is_default', true)->orderBy('name')->first(),
            'customerDocument' => $customerDocument,
        ]);
    }

    public function pdf(Contracts $contract, CompanyProfileSettings $companyProfile)
    {
        try {
            $artifact = app(\App\Modules\Commercial\Actions\BuildContractPdf::class)->handle($contract);
        } catch (DomainException|UnexpectedValueException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return response($artifact['bytes'], 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.$artifact['filename'].'"']);
    }

    /**
     * INDEX - List all contracts with associated client and cost data.
     *
     * Includes a summary metric for the sidebar showing clients without any active contracts.
     *
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        return view('commercial::Tech.cs.contracts.index', app(\App\Modules\Commercial\Queries\CommercialContractQuery::class)->handle($request));
    }

    /**
     * Build the SQL expression used for sorting by yearly profit without loading every contract first.
     */
    private function yearlyProfitSortExpression(): string
    {
        return <<<SQL
            (
                SELECT COALESCE(SUM(
                    (
                        {$this->contractItemLineTotalExpression('contract_items')}
                        - (
                            COALESCE(
                                contract_items.cost_unit_price,
                                (
                                    SELECT COALESCE(SUM(costs.cost), 0)
                                    FROM cost_relations
                                    INNER JOIN costs ON costs.id = cost_relations.costId
                                    WHERE cost_relations.serviceId = contract_items.service_id
                                )
                            ) * contract_items.quantity
                        )
                    )
                    * CASE contract_items.billing_interval
                        WHEN 'monthly' THEN 12
                        WHEN 'quarterly' THEN 4
                        WHEN 'yearly' THEN 1
                        ELSE 0
                    END
                ), 0)
                FROM contract_items
                WHERE contract_items.contract_id = contracts.id
            )
        SQL;
    }

    /**
     * Mirror ContractItem::line_total in SQL so financial sort links match the displayed values.
     */
    private function contractItemLineTotalExpression(string $table): string
    {
        return <<<SQL
            (
                ({$table}.unit_price * {$table}.quantity)
                - CASE
                    WHEN {$table}.discount_value IS NOT NULL AND {$table}.discount_type = 'percent'
                        THEN ({$table}.unit_price * {$table}.quantity) * ({$table}.discount_value / 100)
                    WHEN {$table}.discount_value IS NOT NULL AND {$table}.discount_type = 'amount'
                        THEN CASE
                            WHEN {$table}.discount_value > ({$table}.unit_price * {$table}.quantity)
                                THEN ({$table}.unit_price * {$table}.quantity)
                            ELSE {$table}.discount_value
                        END
                    ELSE 0
                END
            )
        SQL;
    }

    /**
     * CREATE - Display the initial contract creation form.
     *
     * Gathers contextual data needed to initialize a contract:
     * - Active client (from session or list).
     * - Eligible technicians (filtered by specific service roles).
     * - Default contract dates (next month start, 1-year duration).
     *
     * @return \Illuminate\View\View
     */
    public function create()
    {
        // 1. Client Context
        $clientId = session('active_client_id');
        $activeClient = null;
        if ($clientId) {
            $activeClient = Client::find($clientId);
        }
        $clients = Client::all();

        // 2. Technicians / Responsible Personnel
        // Filter user_management by roles that are allowed to manage services.
        $technicians = app(\App\Modules\Commercial\Queries\CommercialContractFormContext::class)->technicians()->get();

        // 3. Contract Period Defaults
        // Contracts typically start on the 1st of the next month if created well in advance (>30 days).
        $defaults = app(\App\Modules\Commercial\Queries\CommercialContractFormContext::class)->defaults();
        $startDate = \Carbon\Carbon::parse($defaults['start_date']);
        $endDate = \Carbon\Carbon::parse($defaults['end_date']);
        $bindingEndDate = \Carbon\Carbon::parse($defaults['binding_end_date']);

        return view('commercial::Tech.cs.contracts.create.create', [
            'activeClient' => $activeClient,
            'clients' => $clients,
            'technicians' => $technicians,
            'slas' => Sla::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'startDate' => $startDate->toDateString(),
            'endDate' => $endDate->toDateString(),
            'bindingEndDate' => $bindingEndDate->toDateString(),
        ]);
    }

    /**
     * STORE - Persist a new contract draft.
     *
     * Handles normalization of UI-specific data:
     * - Casts checkboxes to booleans.
     * - Converts localized decimal strings (comma) to DB-friendly floats (dot).
     * - Sets initial 'draft' status.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(ContractsRequest $request)
    {
        $contract = app(\App\Modules\Commercial\Actions\ContractMetadataWorkflow::class)->store($request);

        return redirect()->route('tech.contracts.services.edit', [
            'contract' => $contract->id,
        ]);
    }

    /**
     * EDIT - Show the contract metadata editing form.
     *
     * Reuses the 'create' view but pre-populates with existing contract data.
     *
     * @return \Illuminate\View\View
     */
    public function edit(Contracts $contract)
    {
        $clients = Client::all();
        $activeClient = $contract->client;

        $technicians = app(\App\Modules\Commercial\Queries\CommercialContractFormContext::class)->technicians()->get();

        return view('commercial::Tech.cs.contracts.create.create', [
            'contract' => $contract,
            'activeClient' => $activeClient,
            'clients' => $clients,
            'technicians' => $technicians,
            'slas' => Sla::query()->orderByDesc('is_default')->orderBy('name')->get(),
            'startDate' => $contract->start_date ? $contract->start_date->toDateString() : null,
            'endDate' => $contract->end_date ? $contract->end_date->toDateString() : null,
            'bindingEndDate' => $contract->binding_end_date ? $contract->binding_end_date->toDateString() : null,
        ]);
    }

    /**
     * UPDATE - Save changes to contract metadata.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ContractsRequest $request, Contracts $contract)
    {
        $updated = app(\App\Modules\Commercial\Actions\ContractMetadataWorkflow::class)->update($request, $contract);

        if (! $updated) {
            return back()->with('error', 'Only editable contract drafts can be changed.');
        }

        return redirect()->route('tech.contracts.services.edit', [
            'contract' => $contract->id,
        ])->with('success', 'Contract details updated successfully.');
    }

    /**
     * SERVICES EDIT - Entry point for managing individual service items in the contract.
     *
     * The actual line-item management is handled by a Livewire component embedded in this view.
     *
     * @return \Illuminate\View\View
     */
    public function servicesEdit(Contracts $contract)
    {
        $contract->load('client');

        return view('commercial::Tech.cs.contracts.services.edit', [
            'contract' => $contract,
            'client' => $contract->client,
        ]);
    }

    /**
     * Store the services for a contract. (Placeholder for non-Livewire fallbacks if needed).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function servicesUpdate(Request $request, Contracts $contract)
    {
        return redirect()->route('tech.contracts.index')
            ->with('success', 'Contract services updated successfully.');
    }

    /**
     * TERMS - Manage and snapshot legal terms associated with the contract's services.
     *
     * CRITICAL LOGIC:
     * 1. Aggregates all legal/terms/SLA/DPA entries from every service attached to the contract.
     * 2. Categorizes them into distinct buckets (terms, dpa, legal, sla, general).
     * 3. Deduplicates identical terms across multiple services to prevent redundant clauses.
     * 4. Previews generated text for empty fields without mutating the contract on GET.
     *
     * @return \Illuminate\View\View
     */
    public function terms(Contracts $contract)
    {
        if (! $contract->isEditable()) {
            return redirect()->route('tech.contracts.show', $contract)->with('error', 'Accepted and sent contract terms are immutable.');
        }

        $contract->load(['client', 'items.service.serviceTerms']);
        $builder = app(BuildContractTermSnapshots::class);
        $generatedSnapshots = $builder->handle($contract);
        $hasGeneratedPreview = false;

        // Empty fields show a generated preview, but only an explicit CSRF-protected POST
        // may persist legal text or attest that a person reviewed it.
        foreach ($generatedSnapshots as $field => $content) {
            if (blank($contract->{$field}) && $content !== '') {
                $contract->setAttribute($field, $content);
                $hasGeneratedPreview = true;
            }
        }

        return view('commercial::Tech.cs.contracts.terms.terms', [
            'contract' => $contract,
            'client' => $contract->client,
            'termsByType' => $builder->groupTermsByType($contract),
            'hasGeneratedPreview' => $hasGeneratedPreview,
        ]);
    }

    /**
     * Replace draft term snapshots from the current Service sources after an explicit review action.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function termsRefresh(Contracts $contract)
    {
        $updated = app(\App\Modules\Commercial\Actions\ContractLifecycleWorkflow::class)->refreshTerms($contract, (int) auth()->id());

        if (! $updated) {
            return redirect()->route('tech.contracts.show', $contract)->with('error', 'Accepted and sent contract terms are immutable.');
        }

        return redirect()->route('tech.contracts.terms', $contract)
            ->with('success', 'Contract terms refreshed and snapshotted successfully.');
    }

    /**
     * TERMS UPDATE - Save manual modifications to the legal snapshots.
     *
     * Once saved, these snapshots become the legal foundation for the contract,
     * independent of future updates to the master service terms.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function termsUpdate(Request $request, Contracts $contract)
    {
        $updates = [
            'terms_snapshot' => $request->terms_snapshot,
            'dpa_snapshot' => $request->dpa_snapshot,
            'legal_snapshot' => $request->legal_snapshot,
            'sla_snapshot' => $request->sla_snapshot,
            'general_snapshot' => $request->general_snapshot,
        ];
        $updated = app(\App\Modules\Commercial\Actions\ContractLifecycleWorkflow::class)->saveTerms($contract, $updates, (int) auth()->id());

        if (! $updated) {
            return redirect()->route('tech.contracts.show', $contract)->with('error', 'Accepted and sent contract terms are immutable.');
        }

        return redirect()->route('tech.contracts.index')
            ->with('success', 'Contract terms updated and snapshotted successfully.');
    }

    /**
     * Send the contract as a quote.
     * Sets status to 'sent_quote' and generates access token.
     */
    public function sendQuote(Request $request, Contracts $contract, SendCustomerPortalNotification $portalNotifications)
    {
        if (config('commercial.durable_delivery_enabled', false)) {
            return $this->durableDelivery($request, $contract, 'quote');
        }

        $transition = $this->transitionForSending($contract, 'sent_quote', $request->cc_email);
        if (isset($transition['error'])) {
            return back()->with('error', $transition['error']);
        }

        $contract = $transition['contract'];
        $previousStatus = $transition['previous_status'];

        if ($previousStatus !== 'sent_quote' && $contract->client_id) {
            $portalNotifications->handle(
                type: 'portal_contract_sent',
                clientId: (int) $contract->client_id,
                siteId: null,
                title: 'New contract quote available',
                body: $contract->description,
                url: route('customer-portal.contracts.show', $contract),
                sourceType: Contracts::class,
                sourceId: $contract->id,
                metadata: [
                    'contract_id' => $contract->id,
                    'approval_status' => 'sent_quote',
                ],
            );
        }

        // Send email to billing_email if available
        if ($contract->client && $contract->client->billing_email) {
            $this->sendEmailViaAccount($contract, 'quote');
        }

        return back()->with('success', 'Contract sent as Quote successfully.');
    }

    /**
     * Send the contract as a binding contract.
     * Sets status to 'sent_contract' and generates access token.
     */
    public function sendContract(Request $request, Contracts $contract, SendCustomerPortalNotification $portalNotifications)
    {
        if (config('commercial.durable_delivery_enabled', false)) {
            return $this->durableDelivery($request, $contract, 'contract');
        }

        $transition = $this->transitionForSending($contract, 'sent_contract', $request->cc_email);
        if (isset($transition['error'])) {
            return back()->with('error', $transition['error']);
        }

        $contract = $transition['contract'];
        $previousStatus = $transition['previous_status'];

        if ($previousStatus !== 'sent_contract' && $contract->client_id) {
            $portalNotifications->handle(
                type: 'portal_contract_sent',
                clientId: (int) $contract->client_id,
                siteId: null,
                title: 'New contract available',
                body: $contract->description,
                url: route('customer-portal.contracts.show', $contract),
                sourceType: Contracts::class,
                sourceId: $contract->id,
                metadata: [
                    'contract_id' => $contract->id,
                    'approval_status' => 'sent_contract',
                ],
            );
        }

        // Send email to billing_email if available
        if ($contract->client && $contract->client->billing_email) {
            $this->sendEmailViaAccount($contract, 'contract');
        }

        return back()->with('success', 'Contract sent as Binding Contract successfully.');
    }

    /**
     * Atomically freeze terms, customer economics and the sent status while
     * holding the same parent-row lock used by the contract item editor.
     *
     * @return array{contract?: Contracts, previous_status?: string, error?: string}
     */
    private function transitionForSending(Contracts $contract, string $status, ?string $ccEmail): array
    {
        return app(\App\Modules\Commercial\Actions\ContractLifecycleWorkflow::class)->transitionForSending($contract, $status, $ccEmail);
    }

    /**
     * Resend the contract or quote email.
     */
    public function resend(Request $request, Contracts $contract)
    {
        if (config('commercial.durable_delivery_enabled', false)) {
            return $this->durableDelivery($request, $contract, 'resend');
        }

        try {
            $result = DB::transaction(function () use ($request, $contract): array {
                $locked = Contracts::query()
                    ->with('client')
                    ->lockForUpdate()
                    ->findOrFail($contract->getKey());

                if (! in_array($locked->approval_status, ['sent_quote', 'sent_contract'], true)) {
                    return ['error' => 'Only a sent quote or agreement can be resent.'];
                }

                if (blank($locked->secure_token)) {
                    return [
                        'error' => 'Kundelenke mangler for dette historiske dokumentet. Ny offentlig tilgang må opprettes gjennom en separat, kontrollert utsending.',
                    ];
                }

                // A resend may expose the public customer link. Require the
                // same complete immutable evidence as the customer surfaces.
                app(ContractCustomerDocument::class)->resolve($locked);

                $billingEmail = trim((string) $locked->client?->billing_email);
                if (Validator::make(
                    ['billing_email' => $billingEmail],
                    ['billing_email' => ['required', 'email']],
                )->fails()) {
                    return [
                        'error' => 'Kundens faktura-e-post mangler eller er ugyldig. Ingen e-post ble sendt, og kopi-adressen ble ikke endret.',
                    ];
                }

                $locked->forceFill(['cc_email' => $request->cc_email])->save();

                return [
                    'contract' => $locked,
                    'type' => $locked->approval_status === 'sent_quote' ? 'quote' : 'contract',
                ];
            });
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (UnexpectedValueException) {
            return back()->with(
                'error',
                'Kundedokumentets lagrede format kan ikke behandles automatisk. Ingen live fallback ble brukt.'
            );
        }

        if (isset($result['error'])) {
            return back()->with('error', $result['error']);
        }

        $resolvedContract = $result['contract'];
        $this->sendEmailViaAccount($resolvedContract, $result['type']);

        return back()->with('success', 'Contract email resent successfully.');
    }

    /**
     * Send through the Integration-backed system Email provider only. A
     * missing or stale provider is visible to the operator; it never falls
     * through to a separate system mail transport.
     */
    protected function sendEmailViaAccount(Contracts $contract, string $type)
    {
        $emailAccount = app(DefaultEmailAccountResolver::class)->forScope('system');

        $to = $contract->client->billing_email;
        $cc = $contract->cc_email;

        if (! $emailAccount) {
            throw new \RuntimeException('No ready system Email provider is configured for contract delivery.');
        }

        try {
            $mailable = new ContractLinkSent($contract, $type);
            $html = $mailable->render();
            $snapshot = app(EmailProviderBindingSnapshot::class)->captureAccount($emailAccount);
            $emailAccount = app(EmailProviderBindingSnapshot::class)->resolveAccount(
                $emailAccount,
                $snapshot['account_id'],
                $snapshot['provider_binding_version'],
            );
            app(SmtpAccountMailer::class)->send(
                $emailAccount,
                $to,
                $contract->client?->name,
                (string) $mailable->envelope()->subject,
                $html,
                BodyNormalizer::toText($html),
                [],
                $cc && filter_var($cc, FILTER_VALIDATE_EMAIL)
                    ? [['email' => $cc, 'name' => '']]
                    : [],
                ['provider_binding_version' => $snapshot['provider_binding_version']],
            );
        } catch (\Throwable $exception) {
            Log::error('Contract Email provider delivery failed safely.', [
                'account_id' => $emailAccount->id,
                'contract_id' => $contract->id,
                'reason' => 'contract_email_provider_failed',
                'exception' => $exception::class,
            ]);

            throw new \RuntimeException('The contract Email provider could not confirm delivery.');
        }
    }

    /**
     * Freeze a manually verified reconstruction for a historical contract
     * that predates immutable customer-document snapshots.
     */
    public function attestLegacyCustomerDocument(
        Request $request,
        Contracts $contract,
        AttestLegacyContractCustomerDocument $attestation,
    ) {
        $validated = $request->validate([
            'attestation_note' => ['required', 'string', 'min:20', 'max:2000'],
            'confirm_legacy_attestation' => ['accepted'],
            'legacy_attestation_fingerprint' => ['required', 'string', 'size:64', 'regex:/\A[0-9a-f]{64}\z/D'],
            'legacy_attestation_document_type' => ['required', 'string', 'in:Tilbud,Avtale'],
        ], [
            'attestation_note.required' => 'Beskriv hvilket originalt underlag som er kontrollert.',
            'attestation_note.min' => 'Attestasjonen må være minst 20 tegn.',
            'confirm_legacy_attestation.accepted' => 'Du må bekrefte den manuelle kontrollen.',
            'legacy_attestation_fingerprint.required' => 'Rekonstruksjonsgrunnlaget mangler. Last siden på nytt og kontroller dokumentet igjen.',
            'legacy_attestation_fingerprint.size' => 'Rekonstruksjonsgrunnlaget er ugyldig. Last siden på nytt.',
            'legacy_attestation_fingerprint.regex' => 'Rekonstruksjonsgrunnlaget er ugyldig. Last siden på nytt.',
            'legacy_attestation_document_type.required' => 'Velg og kontroller den historiske dokumenttypen.',
            'legacy_attestation_document_type.in' => 'Historisk dokumenttype må være Tilbud eller Avtale.',
        ]);

        /** @var User $attestedBy */
        $attestedBy = $request->user();

        try {
            $attestation->handle(
                $contract,
                $attestedBy,
                $validated['attestation_note'],
                $request->boolean('confirm_legacy_attestation'),
                $validated['legacy_attestation_fingerprint'],
                $validated['legacy_attestation_document_type'],
            );
        } catch (DomainException $exception) {
            return back()->withInput()->with('error', $exception->getMessage());
        } catch (UnexpectedValueException) {
            return back()->withInput()->with(
                'error',
                'Rekonstruksjonen er ikke et komplett støttet kundedokument og ble ikke lagret.'
            );
        }

        return back()->with(
            'success',
            'Det historiske kundedokumentet er attestert og lagret som et uforanderlig snapshot.'
        );
    }

    /**
     * Manually approve the contract.
     * Used when acceptance is received outside the system.
     */
    public function approveManual(Contracts $contract)
    {
        $approvedBy = auth()->user();

        try {
            $approval = app(\App\Modules\Commercial\Actions\ContractLifecycleWorkflow::class)->approve($contract, $approvedBy);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (UnexpectedValueException) {
            return back()->with(
                'error',
                'Kundedokumentets lagrede format kan ikke behandles automatisk. Ingen live fallback ble brukt.'
            );
        }

        if (isset($approval['error'])) {
            return back()->with('error', $approval['error']);
        }

        return back()->with('success', 'Contract manually approved and marked as Won.');
    }

    private function canDownloadPdf(Contracts $contract): bool
    {
        return $contract->isReady()
            || in_array($contract->approval_status, ['approved', 'sent_quote', 'sent_contract', 'won'], true);
    }

    private function pdfFileName(Contracts $contract): string
    {
        $clientSlug = Str::slug($contract->client?->name ?: 'client');

        return 'contract-'.$contract->id.'-'.$clientSlug.'.pdf';
    }

    /**
     * Delete the contract.
     */
    public function destroy(Contracts $contract)
    {
        $result = DB::transaction(function () use ($contract): ?string {
            $locked = Contracts::query()->lockForUpdate()->findOrFail($contract->getKey());

            if ($locked->approval_status !== 'draft') {
                return 'Only draft contracts can be deleted.';
            }

            if ($locked->end_date && $locked->end_date->isPast()) {
                return 'Cannot delete a contract that has already ended.';
            }

            $locked->delete();

            return null;
        });

        if ($result !== null) {
            return back()->with('error', $result);
        }

        return redirect()->route('tech.contracts.index')->with('success', 'Contract deleted successfully.');
    }

    /** Roll out only after the additive receipt/delivery schema and workers are verified. */
    private function durableDelivery(Request $request, Contracts $contract, string $kind)
    {
        $request->validate(['cc_email' => 'nullable|email|max:255']);
        try {
            $attempt = app(\App\Modules\Commercial\Services\CommercialDelivery::class)->browser($contract, $request->user(), $kind, $request->input('cc_email'));
            $message = $attempt->state === 'no_recipient'
                ? 'The customer document is available in the portal. No billing recipient is configured; no email was submitted.'
                : 'The contract email is queued. Provider acceptance and inbox delivery are separate checks.';

            return back()->with('success', $message);
        } catch (\DomainException|\UnexpectedValueException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return back()->with('error', $e->getMessage());
        }
    }
}
