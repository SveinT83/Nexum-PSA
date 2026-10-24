<?php

namespace App\Modules\Sales\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Clients\Actions\CreateClientWithDefaults;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Sales\Actions\ApplySalesQuoteTemplate;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\EnsureSalesQuoteDraft;
use App\Modules\Sales\Actions\EvaluateSalesQuoteApproval;
use App\Modules\Sales\Actions\MarkSalesOpportunityLost;
use App\Modules\Sales\Actions\RecalculateSalesQuoteVersion;
use App\Modules\Sales\Actions\ReopenSalesOpportunity;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use App\Modules\Sales\Actions\SyncOpportunityFollowUpCalendar;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Support\SalesQuotePresentation;
use App\Modules\Storage\Models\Item as StorageItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SalesController extends Controller
{
    public function index(Request $request, EnsureSalesDefaults $defaults): View
    {
        $defaults->handle();

        $opportunities = SalesOpportunity::query()
            ->with(['client', 'owner', 'currentQuoteVersion'])
            ->when(! $request->filled('status') && ! $request->filled('q'), fn ($query) => $query->where('status', '!=', 'lost'))
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')))
            ->when($request->filled('owner'), fn ($query) => $query->where('owner_id', $request->integer('owner')))
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';
                $query->where(function ($query) use ($search): void {
                    $query->where('title', 'like', $search)
                        ->orWhere('opportunity_key', 'like', $search)
                        ->orWhereHas('client', fn ($query) => $query->where('name', 'like', $search));
                });
            })
            ->orderByDesc('is_unread')
            ->orderByRaw('CASE WHEN next_follow_up_at IS NOT NULL AND next_follow_up_at < ? THEN 0 ELSE 1 END', [now()])
            ->latest('updated_at')
            ->paginate(20)
            ->withQueryString();

        return view('sales::Tech.Sales.index', [
            'opportunities' => $opportunities,
            'statuses' => EnsureSalesDefaults::STATUSES,
            'types' => EnsureSalesDefaults::TYPES,
            'nextActions' => EnsureSalesDefaults::NEXT_ACTIONS,
            'owners' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']),
            'filters' => $request->only(['status', 'owner', 'q']),
            'stats' => [
                'open' => SalesOpportunity::query()->whereNotIn('status', ['won', 'lost', 'not_qualified'])->count(),
                'won' => SalesOpportunity::query()->where('status', 'won')->count(),
                'unread' => SalesOpportunity::query()->where('is_unread', true)->count(),
                'due' => SalesOpportunity::query()->whereNotNull('next_follow_up_at')->where('next_follow_up_at', '<', now())->count(),
                'weighted' => SalesOpportunity::query()->whereNotIn('status', ['lost', 'not_qualified'])->sum('weighted_value_ex_vat'),
            ],
        ]);
    }

    public function create(EnsureSalesDefaults $defaults, SuggestClientNumber $suggestClientNumber): View
    {
        $defaults->handle();

        return view('sales::Tech.Sales.create', [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name', 'client_number']),
            'clientContactData' => $this->clientContactData(Client::query()->with(['sites.contacts'])->orderBy('name')->get()),
            'owners' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']),
            'types' => EnsureSalesDefaults::TYPES,
            'statuses' => EnsureSalesDefaults::STATUSES,
            'nextActions' => EnsureSalesDefaults::NEXT_ACTIONS,
            'suggestedClientNumber' => $suggestClientNumber->handle(),
            'clientFormats' => ClientFormat::activeOptions(),
            'clientContactRoles' => ['Daglig leder', 'Innehaver', 'IT-kontakt', 'Økonomi', 'Annet'],
        ]);
    }

    public function quickStoreClient(Request $request, CreateClientWithDefaults $createClient): JsonResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesQuickCreate::class)->client($request);
        $client = $result['client']->fresh(['sites', 'contacts']);

        return response()->json(['client' => $client->only(['id', 'name', 'client_number']) + ['label' => $client->name.' ('.$client->client_number.')'], 'sites' => $this->sitePayload($client), 'contacts' => $this->contactPayload($client), 'warning' => $result['warning']], 201);
    }

    public function quickStoreContact(Request $request, Client $client): JsonResponse
    {
        return response()->json(app(\App\Modules\Sales\Actions\SalesQuickCreate::class)->contact($request, $client), 201);
    }

    public function store(Request $request, StoreSalesOpportunity $storeOpportunity): RedirectResponse
    {
        $opportunity = app(\App\Modules\Sales\Actions\SalesOpportunityWorkflow::class)->store($request);

        return redirect()->route('tech.sales.show', $opportunity)->with('success', 'Sales opportunity created.');
    }

    public function show(
        SalesOpportunity $sale,
        EnsureSalesDefaults $defaults,
        SalesQuotePresentation $quotePresentation
    ): View {
        $defaults->handle();
        $sale->load([
            'client.contacts',
            'client.sites.contacts',
            'primaryContact',
            'owner',
            'activities.actor',
            'stakeholders.clientUser',
            'quotes.currentVersion.lines.optionGroup',
            'currentQuoteVersion.lines.optionGroup',
            'currentQuoteVersion.optionGroups.lines',
            'currentQuoteVersion.acknowledgements',
            'currentQuoteVersion.acceptanceSnapshot',
            'currentQuoteVersion.conversionPlans',
        ]);

        return view('sales::Tech.Sales.show', [
            'sale' => $sale,
            'statuses' => EnsureSalesDefaults::STATUSES,
            'reopenStatuses' => ReopenSalesOpportunity::REOPEN_STATUSES,
            'quotePresentation' => $sale->currentQuoteVersion
                ? $quotePresentation->forVersion($sale->currentQuoteVersion)
                : null,
            'quoteCadences' => SalesQuotePresentation::CADENCES,
            'types' => EnsureSalesDefaults::TYPES,
            'owners' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']),
            'nextActions' => EnsureSalesDefaults::NEXT_ACTIONS,
            'services' => Services::query()
                ->with(['costRelations.cost'])
                ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))
                ->orderBy('name')
                ->get(),
            'quoteTemplates' => SalesQuoteTemplate::query()
                ->where('is_active', true)
                ->withCount('lines')
                ->orderBy('name')
                ->get(),
            'packages' => Package::query()
                ->with(['services.costRelations.cost'])
                ->where(fn ($query) => $query->where('status', 'active')->orWhereNull('status'))
                ->orderBy('name')
                ->get(),
            'rates' => TimeRate::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'storageItems' => StorageItem::query()->where('status', 'active')->orderBy('name')->limit(200)->get(),
            'clientContactData' => $this->clientContactData(collect([$sale->client])->filter()),
            'clientContactRoles' => ['Daglig leder', 'Innehaver', 'IT-kontakt', 'Økonomi', 'Annet'],
        ]);
    }

    public function update(Request $request, SalesOpportunity $sale, SyncOpportunityFollowUpCalendar $syncCalendar): RedirectResponse
    {
        app(\App\Modules\Sales\Actions\SalesOpportunityWorkflow::class)->update($request, $sale);

        return back()->with('success', 'Opportunity updated.');
    }

    public function markLost(Request $request, SalesOpportunity $sale, MarkSalesOpportunityLost $markLost): RedirectResponse
    {
        $data = $request->validate([
            'lost_reason' => ['required', 'string', 'max:4000'],
            'internal_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $markLost->handle(
            $sale,
            $data['lost_reason'],
            $data['internal_note'] ?? null,
            $request->user()
        );

        return back()->with('success', 'Opportunity marked as lost.');
    }

    public function reopen(Request $request, SalesOpportunity $sale, ReopenSalesOpportunity $reopen): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(ReopenSalesOpportunity::REOPEN_STATUSES)],
        ]);

        $reopen->handle($sale, $data['status'], $request->user());

        return back()->with('success', 'Opportunity reopened. Add a new follow-up when needed.');
    }

    public function storeActivity(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->storeActivity($request, $sale);
        $response = back()->with($result->level, $result->message);

        return $response;
    }

    public function markRead(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->markRead($request, $sale);
        $response = back()->with($result->level, $result->message);

        return $response;
    }

    public function markActivityRead(Request $request, SalesOpportunity $sale, SalesActivity $activity): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->markActivityRead($request, $sale, $activity);
        $response = back()->with($result->level, $result->message);

        return $response;
    }

    public function storeStakeholder(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->storeStakeholder($request, $sale);
        $response = back()->with($result->level, $result->message);

        return $response;
    }

    public function ensureQuote(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, EnsureSalesQuoteDraft $ensureDraft): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->ensureQuote($request, $sale, $recalculate, $ensureDraft);
        $response = back()->with($result->level, $result->message);

        return $response;
    }

    public function updateQuoteDetails(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->updateQuoteDetails($request, $sale);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function addQuoteLine(
        Request $request,
        SalesOpportunity $sale,
        RecalculateSalesQuoteVersion $recalculate,
        EnsureSalesQuoteDraft $ensureDraft,
        SalesQuotePresentation $quotePresentation
    ): RedirectResponse {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->addQuoteLine($request, $sale, $recalculate, $ensureDraft, $quotePresentation);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function deleteQuoteLine(Request $request, SalesOpportunity $sale, SalesQuoteLine $line, RecalculateSalesQuoteVersion $recalculate): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->deleteQuoteLine($request, $sale, $line, $recalculate);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function updateQuoteLine(
        Request $request,
        SalesOpportunity $sale,
        SalesQuoteLine $line,
        RecalculateSalesQuoteVersion $recalculate,
        SalesQuotePresentation $quotePresentation
    ): RedirectResponse {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->updateQuoteLine($request, $sale, $line, $recalculate, $quotePresentation);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function reviseQuote(Request $request, SalesOpportunity $sale, EnsureSalesQuoteDraft $ensureDraft, RecalculateSalesQuoteVersion $recalculate): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->reviseQuote($request, $sale, $ensureDraft, $recalculate);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function requestQuoteApproval(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, EvaluateSalesQuoteApproval $approval): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->requestQuoteApproval($request, $sale, $recalculate, $approval);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function approveQuote(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->approveQuote($request, $sale);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function rejectQuote(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->rejectQuote($request, $sale);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function requestQuoteChanges(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->requestQuoteChanges($request, $sale);
        $response = back()->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function applyQuoteTemplate(Request $request, SalesOpportunity $sale, ApplySalesQuoteTemplate $applyTemplate): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->applyQuoteTemplate($request, $sale, $applyTemplate);
        $response = redirect()->route('tech.sales.show', $sale)->with($result->level, $result->message);
        $response->with('open_quote_modal', true);

        return $response;
    }

    public function updateConversionPlan(Request $request, SalesOpportunity $sale, SalesQuoteConversionPlan $plan): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->updateConversionPlan($request, $sale, $plan);
        $response = back()->with($result->level, $result->message);

        return $response;
    }

    public function sendQuote(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, SendCustomerPortalNotification $portalNotifications, EvaluateSalesQuoteApproval $approval): RedirectResponse
    {
        $result = app(\App\Modules\Sales\Actions\SalesWorkflow::class)->sendQuote($request, $sale, $recalculate, $portalNotifications, $approval);
        $message = $result->message;
        if (str_contains($message, 'no primary contact email')) {
            $message .= ' Public link: '.route('sales.quotes.public.view', $sale->fresh()->currentQuoteVersion->secure_token);
        }
        $response = back()->with($result->level, $message);

        return $response;
    }

    private function opportunityData(Request $request, bool $create = true): array
    {
        if ($request->has('next_follow_up_type')) {
            $request->merge([
                'next_follow_up_type' => EnsureSalesDefaults::normalizeNextAction($request->input('next_follow_up_type')),
            ]);
        }

        return $request->validate([
            'client_id' => [$create ? 'required' : 'sometimes', 'exists:clients,id'],
            'primary_contact_id' => 'nullable|exists:client_users,id',
            'owner_id' => ['nullable', Rule::exists((new User)->getTable(), 'id')],
            'title' => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'type' => [$create ? 'required' : 'sometimes', 'string', 'max:100'],
            'status' => 'nullable|string|max:100',
            'summary' => 'nullable|string|max:4000',
            'needs' => 'nullable|string|max:4000',
            'employee_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'user_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'workstation_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'server_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'site_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'estimated_value_ex_vat' => 'nullable|numeric|min:0|max:999999999',
            'probability_percent' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'next_follow_up_at' => 'nullable|date',
            'next_follow_up_type' => ['nullable', Rule::in(array_keys(EnsureSalesDefaults::NEXT_ACTIONS))],
            'next_follow_up_note' => 'nullable|string|max:2000',
        ]);
    }

    private function assertLostWorkflowIsNotBypassed(SalesOpportunity $opportunity, mixed $requestedStatus): void
    {
        if ($requestedStatus === 'lost' && $opportunity->status !== 'lost') {
            throw ValidationException::withMessages([
                'status' => 'Use Mark as lost so the reason and follow-up cleanup are recorded.',
            ]);
        }

        if ($opportunity->status === 'lost' && filled($requestedStatus) && $requestedStatus !== 'lost') {
            throw ValidationException::withMessages([
                'status' => 'Use Reopen so the lost transition is reversed safely.',
            ]);
        }
    }

    private function assertPrimaryContactBelongsToClient(?int $contactId, int $clientId): void
    {
        if (! $contactId) {
            return;
        }

        abort_unless(
            ClientUser::query()
                ->whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $clientId))
                ->exists(),
            422,
            'Sales contact must belong to the selected client.'
        );
    }

    private function clientContactData($clients): array
    {
        return collect($clients)
            ->mapWithKeys(fn (Client $client) => [
                $client->id => [
                    'sites' => $this->sitePayload($client),
                    'contacts' => $this->contactPayload($client),
                ],
            ])
            ->all();
    }

    private function sitePayload(Client $client): array
    {
        return $client->sites
            ->map(fn ($site) => [
                'id' => $site->id,
                'name' => $site->name,
            ])
            ->values()
            ->all();
    }

    private function contactPayload(Client $client): array
    {
        return $client->contacts
            ->filter(fn (ClientUser $contact) => $contact->active && $contact->email)
            ->map(fn (ClientUser $contact) => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'role' => $contact->role,
                'label' => $this->contactLabel($contact),
            ])
            ->values()
            ->all();
    }

    private function contactLabel(ClientUser $contact): string
    {
        $parts = array_filter([$contact->name, $contact->role]);

        return trim(implode(' / ', $parts).' <'.$contact->email.'>');
    }

    private function ensureDraftQuoteVersion(SalesOpportunity $opportunity, User $actor): SalesQuoteVersion
    {
        if ($opportunity->currentQuoteVersion && $opportunity->currentQuoteVersion->isEditable()) {
            return $opportunity->currentQuoteVersion;
        }

        $quote = $opportunity->quotes()->first() ?: SalesQuote::query()->create([
            'opportunity_id' => $opportunity->id,
            'quote_key' => 'Q-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
            'status' => 'draft',
        ]);
        $nextVersion = ((int) $quote->versions()->max('version_number')) + 1;

        $version = SalesQuoteVersion::query()->create([
            'quote_id' => $quote->id,
            'version_number' => $nextVersion,
            'status' => 'draft',
            'secure_token' => Str::random(64),
            'title' => $opportunity->title,
            'intro_text' => 'Thank you for the opportunity to provide this quote.',
            'scope_text' => $opportunity->needs,
            'assumptions_text' => 'Prices are shown excluding VAT unless otherwise stated.',
            'next_steps_text' => 'Please accept the quote or ask a question if anything should be clarified.',
            'expires_at' => now()->addDays((int) SalesSetting::get('quote_expiry_days', 30))->toDateString(),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);

        $quote->forceFill(['current_version_id' => $version->id, 'status' => 'draft'])->save();
        $opportunity->forceFill(['current_quote_version_id' => $version->id])->save();

        return $version;
    }
}
