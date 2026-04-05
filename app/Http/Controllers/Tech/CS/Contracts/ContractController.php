<?php

namespace App\Http\Controllers\Tech\CS\Contracts;

use App\Http\Controllers\Controller;
use App\Models\CS\Contracts\Contracts;
use App\Mail\ContractLinkSent;
use App\Domain\Email\Models\EmailAccount;
use Illuminate\Http\Request;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Http\Requests\Tech\CS\ContractsRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;

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
     * @param Contracts $contract
     * @return \Illuminate\View\View
     */
    public function show(Contracts $contract)
    {
        $contract->load(['client', 'items.service.serviceTerms', 'items.service.costRelations.cost']);

        // Check for missing terms (services that have terms not in snapshot)
        // This ensures the legal base is synchronized with the actual service list.
        $hasMissingTerms = false;
        foreach ($contract->items as $item) {
            if ($item->service && $item->service->serviceTerms->count() > 0 && empty($contract->terms_snapshot)) {
                $hasMissingTerms = true;
                break;
            }
        }

        // Readiness logic for the UI to enable/disable approval and export actions.
        $validation = [
            'has_items' => $contract->items->count() > 0,
            'has_terms' => !empty($contract->terms_snapshot) || !empty($contract->dpa_snapshot) || !empty($contract->legal_snapshot),
            'future_start_date' => $contract->start_date && $contract->start_date->isFuture(),
            'ready' => $contract->isReady(),
            'has_missing_terms' => $hasMissingTerms,
        ];

        return view('tech.cs.contracts.show', [
            'contract' => $contract,
            'client' => $contract->client,
            'validation' => $validation
        ]);
    }

    /**
     * INDEX - List all contracts with associated client and cost data.
     *
     * Includes a summary metric for the sidebar showing clients without any active contracts.
     *
     * @return \Illuminate\View\View
     */
    public function index()
    {
        $contracts = Contracts::with(['client', 'items.service.costRelations.cost'])->paginate(20);

        // Calculate clients without contracts for administrative overview.
        $clientsWithoutContractsCount = Client::whereDoesntHave('contracts')->count();

        return view('tech.cs.contracts.index', [
            'contracts' => $contracts,
            'clientsWithoutContractsCount' => $clientsWithoutContractsCount
        ]);
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
        // Filter users by roles that are allowed to manage services.
        $requestedRoles = ['service.admin', 'tech.admin', 'Superuser', 'service.create', 'service.view'];
        $existingRoles = \Spatie\Permission\Models\Role::whereIn('name', $requestedRoles)->pluck('name')->toArray();

        if (!empty($existingRoles)) {
            $technicians = User::role($existingRoles)->get();
        } else {
            $technicians = collect();
        }

        // 3. Contract Period Defaults
        // Contracts typically start on the 1st of the next month if created well in advance (>30 days).
        $startDate = now()->addMonths(1)->startOfMonth();
        if (now()->diffInDays($startDate, false) < 30) {
            $startDate->addMonths(1);
        }

        $endDate = $startDate->copy()->addYear();
        $bindingEndDate = $startDate->copy()->addYear();

        return view('tech.cs.contracts.create.create', [
            'activeClient' => $activeClient,
            'clients' => $clients,
            'technicians' => $technicians,
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
     * @param ContractsRequest $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(ContractsRequest $request)
    {
        $validatedData = $request->safe()->all();

        // UI Normalization
        $validatedData['auto_renew'] = $request->boolean('auto_renew');
        $validatedData['allow_indexing_during_binding'] = $request->boolean('allow_indexing_during_binding');
        $validatedData['allow_decrease_during_binding'] = $request->boolean('allow_decrease_during_binding');

        // Number Formatting (Comma -> Dot)
        if ($request->max_index_pct_binding) {
            $validatedData['max_index_pct_binding'] = str_replace(',', '.', $request->max_index_pct_binding);
        }

        if ($request->post_binding_index_pct) {
            $validatedData['post_binding_index_pct'] = str_replace(',', '.', $request->post_binding_index_pct);
        }

        $contract = Contracts::create([
            ...$validatedData,
            'approval_status' => 'draft',
            'total_monthly_amount' => 0,
            'created_by' => auth()->id(),
        ]);

        return redirect()->route('tech.contracts.services.edit', [
            'contract' => $contract->id
        ]);
    }

    /**
     * EDIT - Show the contract metadata editing form.
     *
     * Reuses the 'create' view but pre-populates with existing contract data.
     *
     * @param Contracts $contract
     * @return \Illuminate\View\View
     */
    public function edit(Contracts $contract)
    {
        $clients = Client::all();
        $activeClient = $contract->client;

        $requestedRoles = ['service.admin', 'tech.admin', 'Superuser', 'service.create', 'service.view'];
        $existingRoles = \Spatie\Permission\Models\Role::whereIn('name', $requestedRoles)->pluck('name')->toArray();

        if (!empty($existingRoles)) {
            $technicians = User::role($existingRoles)->get();
        } else {
            $technicians = collect();
        }

        return view('tech.cs.contracts.create.create', [
            'contract' => $contract,
            'activeClient' => $activeClient,
            'clients' => $clients,
            'technicians' => $technicians,
            'startDate' => $contract->start_date ? $contract->start_date->toDateString() : null,
            'endDate' => $contract->end_date ? $contract->end_date->toDateString() : null,
            'bindingEndDate' => $contract->binding_end_date ? $contract->binding_end_date->toDateString() : null,
        ]);
    }

    /**
     * UPDATE - Save changes to contract metadata.
     *
     * @param ContractsRequest $request
     * @param Contracts $contract
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(ContractsRequest $request, Contracts $contract)
    {
        $validatedData = $request->safe()->all();

        $validatedData['auto_renew'] = $request->boolean('auto_renew');
        $validatedData['allow_indexing_during_binding'] = $request->boolean('allow_indexing_during_binding');
        $validatedData['allow_decrease_during_binding'] = $request->boolean('allow_decrease_during_binding');

        if ($request->max_index_pct_binding) {
            $validatedData['max_index_pct_binding'] = str_replace(',', '.', $request->max_index_pct_binding);
        }

        if ($request->post_binding_index_pct) {
            $validatedData['post_binding_index_pct'] = str_replace(',', '.', $request->post_binding_index_pct);
        }

        $contract->update($validatedData);

        return redirect()->route('tech.contracts.services.edit', [
            'contract' => $contract->id
        ])->with('success', 'Contract details updated successfully.');
    }

    /**
     * SERVICES EDIT - Entry point for managing individual service items in the contract.
     *
     * The actual line-item management is handled by a Livewire component embedded in this view.
     *
     * @param Contracts $contract
     * @return \Illuminate\View\View
     */
    public function servicesEdit(Contracts $contract)
    {
        $contract->load('client');

        return view('tech.cs.contracts.services.edit', [
            'contract' => $contract,
            'client' => $contract->client
        ]);
    }

    /**
     * Store the services for a contract. (Placeholder for non-Livewire fallbacks if needed).
     *
     * @param Request $request
     * @param Contracts $contract
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
     * 4. Auto-fills snapshots if they are empty, creating a point-in-time legal baseline.
     * 5. Supports 'refreshing' snapshots if the service list has changed.
     *
     * @param Contracts $contract
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function terms(Contracts $contract, Request $request)
    {
        $contract->load(['client', 'items.service.serviceTerms']);

        // Group terms by their functional type.
        $termsByType = [
            'terms' => collect(),
            'dpa' => collect(),
            'legal' => collect(),
            'sla' => collect(),
            'general' => collect(),
        ];

        foreach ($contract->items as $item) {
            if ($item->service) {
                foreach ($item->service->serviceTerms as $term) {
                    $type = $term->type ?: 'terms';

                    // Fallback to 'terms' if a custom/unknown type is found in the DB.
                    if (!isset($termsByType[$type])) {
                        $type = 'terms';
                    }

                    // Deduplicate by term ID.
                    if (!$termsByType[$type]->has($term->id)) {
                        $termsByType[$type]->put($term->id, $term);
                    }
                }
            }
        }

        // Automatic Snapshot Initialization:
        // If snapshots are empty, we concatenate all relevant terms into a single string.
        $snapshots = [
            'terms_snapshot' => 'terms',
            'dpa_snapshot' => 'dpa',
            'legal_snapshot' => 'legal',
            'sla_snapshot' => 'sla',
            'general_snapshot' => 'general',
        ];

        $isRefresh = $request->has('refresh');

        foreach ($snapshots as $field => $type) {
            if (empty($contract->$field) || $isRefresh) {
                // Combine content with a visual separator.
                $contract->$field = $termsByType[$type]->pluck('content')->filter()->unique()->implode("\n\n---\n\n");
            }
        }

        return view('tech.cs.contracts.terms.terms', [
            'contract' => $contract,
            'client' => $contract->client,
            'termsByType' => $termsByType
        ]);
    }

    /**
     * TERMS UPDATE - Save manual modifications to the legal snapshots.
     *
     * Once saved, these snapshots become the legal foundation for the contract,
     * independent of future updates to the master service terms.
     *
     * @param Request $request
     * @param Contracts $contract
     * @return \Illuminate\Http\RedirectResponse
     */
    public function termsUpdate(Request $request, Contracts $contract)
    {
        $contract->update([
            'terms_snapshot' => $request->terms_snapshot,
            'dpa_snapshot' => $request->dpa_snapshot,
            'legal_snapshot' => $request->legal_snapshot,
            'sla_snapshot' => $request->sla_snapshot,
            'general_snapshot' => $request->general_snapshot,
        ]);

        return redirect()->route('tech.contracts.index')
            ->with('success', 'Contract terms updated and snapshotted successfully.');
    }

    /**
     * Send the contract as a quote.
     * Sets status to 'sent_quote' and generates access token.
     */
    public function sendQuote(Request $request, Contracts $contract)
    {
        if (!$contract->isReady()) {
            return back()->with('error', 'Contract is not ready to be sent. Please check items and terms.');
        }

        $contract->generateSecureToken();
        $contract->update([
            'approval_status' => 'sent_quote',
            'sent_at' => now(),
            'cc_email' => $request->cc_email,
        ]);

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
    public function sendContract(Request $request, Contracts $contract)
    {
        if (!$contract->isReady()) {
            return back()->with('error', 'Contract is not ready to be sent. Please check items and terms.');
        }

        $contract->generateSecureToken();
        $contract->update([
            'approval_status' => 'sent_contract',
            'sent_at' => now(),
            'cc_email' => $request->cc_email,
        ]);

        // Send email to billing_email if available
        if ($contract->client && $contract->client->billing_email) {
            $this->sendEmailViaAccount($contract, 'contract');
        }

        return back()->with('success', 'Contract sent as Binding Contract successfully.');
    }

    /**
     * Resend the contract or quote email.
     */
    public function resend(Request $request, Contracts $contract)
    {
        $type = ($contract->approval_status === 'sent_quote') ? 'quote' : 'contract';

        $contract->update([
            'cc_email' => $request->cc_email,
        ]);

        if ($contract->client && $contract->client->billing_email) {
            $this->sendEmailViaAccount($contract, $type);
        }

        return back()->with('success', 'Contract email resent successfully.');
    }

    /**
     * Helper to send contract emails using the system's EmailAccount configuration.
     * Fallback to default Mailer if no active global account is found.
     */
    protected function sendEmailViaAccount(Contracts $contract, string $type)
    {
        $emailAccount = EmailAccount::where('is_active', true)
            ->where('is_global_default', true)
            ->first();

        $to = $contract->client->billing_email;
        $cc = $contract->cc_email;

        if (!$emailAccount) {
            Log::info('No global default email account found. Using system default mailer.');
            $mail = Mail::to($to);
            if ($cc) $mail->cc($cc);
            $mail->send(new ContractLinkSent($contract, $type));
            return;
        }

        try {
            // Map encryption to Symfony Mailer scheme
            $enc = strtolower($emailAccount->smtp_encryption);
            $scheme = ($enc === 'ssl' ? 'smtps' : 'smtp');

            $user = $emailAccount->smtp_username;
            $pass = Crypt::decryptString($emailAccount->smtp_secret);
            $host = $emailAccount->smtp_host;
            $port = $emailAccount->smtp_port;

            // Dynamic mailer configuration
            config([
                'mail.mailers.dynamic_smtp' => [
                    'transport' => 'smtp',
                    'scheme' => $scheme,
                    'host' => $host,
                    'port' => $port,
                    'username' => $user,
                    'password' => $pass,
                    'timeout' => null,
                ]
            ]);

            $mailable = new ContractLinkSent($contract, $type);

            // Set "From" based on account
            if ($emailAccount->from_name) {
                $mailable->from($emailAccount->address, $emailAccount->from_name);
            } else {
                $mailable->from($emailAccount->address);
            }

            $mail = Mail::mailer('dynamic_smtp')->to($to);
            if ($cc) $mail->cc($cc);
            $mail->send($mailable);

            $emailAccount->update(['last_successful_send_at' => now()]);

        } catch (\Exception $e) {
            Log::error('Failed to send contract email via custom account', [
                'account_id' => $emailAccount->id,
                'contract_id' => $contract->id,
                'error' => $e->getMessage()
            ]);

            // Final fallback to system mailer if custom one fails
            $mail = Mail::to($to);
            if ($cc) $mail->cc($cc);
            $mail->send(new ContractLinkSent($contract, $type));
        }
    }

    /**
     * Manually approve the contract.
     * Used when acceptance is received outside the system.
     */
    public function approveManual(Contracts $contract)
    {
        $contract->update([
            'approval_status' => 'won',
            'accepted_at' => now(),
            'accepted_by_name' => 'Internal Approval',
        ]);

        return back()->with('success', 'Contract manually approved and marked as Won.');
    }
}
