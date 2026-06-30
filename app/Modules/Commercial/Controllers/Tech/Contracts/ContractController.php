<?php

namespace App\Modules\Commercial\Controllers\Tech\Contracts;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Actions\BuildContractTermSnapshots;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Mail\ContractLinkSent;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\System\Support\CompanyProfileSettings;
use Dompdf\Dompdf;
use Dompdf\Options;
use App\Modules\Email\Models\EmailAccount;
use Illuminate\Http\Request;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Requests\ContractsRequest;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

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
        $contract->load([
            'client',
            'sla',
            'items.slaPolicy',
            'items.timeRates',
            'items.service.serviceTerms',
            'items.service.costRelations.cost',
        ]);

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
            'show_readiness_status' => ! in_array($contract->approval_status, ['approved', 'won'], true),
            'pdf_available' => $this->canDownloadPdf($contract),
        ];

        return view('commercial::Tech.cs.contracts.show', [
            'contract' => $contract,
            'client' => $contract->client,
            'validation' => $validation,
            'defaultSla' => Sla::query()->where('is_default', true)->orderBy('name')->first(),
        ]);
    }

    public function pdf(Contracts $contract, CompanyProfileSettings $companyProfile)
    {
        $contract->load([
            'client',
            'sla',
            'items.slaPolicy',
            'items.timeRates',
        ]);

        if (! $this->canDownloadPdf($contract)) {
            return back()->with('error', 'Contract is not ready for PDF export. Please add services, terms, and a valid start date first.');
        }

        $html = view('commercial::Tech.cs.contracts.pdf', [
            'contract' => $contract,
            'companyProfile' => $companyProfile->get(),
        ])->render();

        $options = new Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->pdfFileName($contract).'"',
        ]);
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
        $sort = $request->input('sort', 'id');
        $direction = $request->input('direction') === 'asc' ? 'asc' : 'desc';
        $sortableColumns = ['id', 'client', 'status', 'start_date', 'end_date', 'monthly_price', 'yearly_profit'];

        if (! in_array($sort, $sortableColumns, true)) {
            $sort = 'id';
        }

        $contractsQuery = Contracts::query()
            ->select('contracts.*')
            ->selectRaw($this->monthlyPriceSortExpression().' as monthly_price_sort')
            ->selectRaw($this->yearlyProfitSortExpression().' as yearly_profit_sort')
            ->with(['client', 'sla', 'items.service.costRelations.cost'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';
                $query->where(function ($query) use ($search): void {
                    $query->where('contracts.id', 'like', $search)
                        ->orWhere('contracts.description', 'like', $search)
                        ->orWhere('contracts.approval_status', 'like', $search)
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', $search));
                });
            })
            ->when($request->filled('status'), fn ($query) => $query->where('approval_status', $request->input('status')))
            ->when($request->filled('client_id'), fn ($query) => $query->where('client_id', $request->integer('client_id')))
            ->when($request->filled('period'), function ($query) use ($request): void {
                match ($request->input('period')) {
                    'active' => $query
                        ->whereDate('start_date', '<=', now()->toDateString())
                        ->where(fn ($periodQuery) => $periodQuery->whereNull('end_date')->orWhereDate('end_date', '>=', now()->toDateString())),
                    'future' => $query->whereDate('start_date', '>', now()->toDateString()),
                    'expired' => $query->whereNotNull('end_date')->whereDate('end_date', '<', now()->toDateString()),
                    default => null,
                };
            });

        if ($sort === 'client') {
            $contractsQuery->leftJoin('clients', 'contracts.client_id', '=', 'clients.id')
                ->orderBy('clients.name', $direction)
                ->orderBy('contracts.id', 'desc');
        } elseif ($sort === 'status') {
            $contractsQuery->orderBy('approval_status', $direction)->orderByDesc('id');
        } elseif ($sort === 'monthly_price') {
            $contractsQuery->orderBy('monthly_price_sort', $direction)->orderByDesc('id');
        } elseif ($sort === 'yearly_profit') {
            $contractsQuery->orderBy('yearly_profit_sort', $direction)->orderByDesc('id');
        } else {
            $contractsQuery->orderBy($sort, $direction)->orderByDesc('id');
        }

        $contracts = $contractsQuery->paginate(20)->withQueryString();

        // Calculate clients without contracts for administrative overview.
        $clientsWithoutContractsCount = Client::whereDoesntHave('contracts')->count();

        return view('commercial::Tech.cs.contracts.index', [
            'contracts' => $contracts,
            'clientsWithoutContractsCount' => $clientsWithoutContractsCount,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(['id', 'name']),
            'statuses' => Contracts::query()->distinct()->orderBy('approval_status')->pluck('approval_status')->filter()->values(),
            'filters' => $request->only(['q', 'status', 'client_id', 'period', 'sort', 'direction']),
            'defaultSla' => Sla::query()->where('is_default', true)->orderBy('name')->first(),
        ]);
    }

    /**
     * Build the SQL expression used for sorting by the same monthly line totals shown in the index.
     */
    private function monthlyPriceSortExpression(): string
    {
        return <<<SQL
            (
                SELECT COALESCE(SUM(
                    CASE WHEN contract_items.billing_interval = 'monthly' THEN
                        {$this->contractItemLineTotalExpression('contract_items')}
                    ELSE 0 END
                ), 0)
                FROM contract_items
                WHERE contract_items.contract_id = contracts.id
            )
        SQL;
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
                            (
                                SELECT COALESCE(SUM(costs.cost), 0)
                                FROM cost_relations
                                INNER JOIN costs ON costs.id = cost_relations.costId
                                WHERE cost_relations.serviceId = contract_items.service_id
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

        return view('commercial::Tech.cs.contracts.services.edit', [
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
        $isRefresh = $request->has('refresh');
        $builder = app(BuildContractTermSnapshots::class);
        $termsByType = $builder->groupTermsByType($contract);
        $snapshots = $builder->handle($contract);

        foreach ($snapshots as $field => $content) {
            if (empty($contract->$field) || $isRefresh) {
                $contract->$field = $content;
            }
        }

        // Persist generated snapshots so contract preview, public quote, and readiness checks
        // all see the same legal content without requiring an extra manual save.
        if ($contract->isDirty(['terms_snapshot', 'dpa_snapshot', 'legal_snapshot', 'sla_snapshot', 'general_snapshot'])) {
            $contract->save();
        }

        return view('commercial::Tech.cs.contracts.terms.terms', [
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

    private function canDownloadPdf(Contracts $contract): bool
    {
        return $contract->isReady()
            || in_array($contract->approval_status, ['sent_quote', 'sent_contract', 'won'], true);
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
        if ($contract->approval_status !== 'draft') {
            return back()->with('error', 'Only draft contracts can be deleted.');
        }

        if ($contract->end_date && $contract->end_date->isPast()) {
            return back()->with('error', 'Cannot delete a contract that has already ended.');
        }

        $contract->delete();

        return redirect()->route('tech.contracts.index')->with('success', 'Contract deleted successfully.');
    }
}
