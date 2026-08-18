<?php

namespace App\Modules\Sales\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Clients\Actions\CreateClientWithDefaults;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Commercial\Models\Packages\Package;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\EnsureSalesQuoteDraft;
use App\Modules\Sales\Actions\EvaluateSalesQuoteApproval;
use App\Modules\Sales\Actions\ApplySalesQuoteTemplate;
use App\Modules\Sales\Actions\MarkSalesOpportunityLost;
use App\Modules\Sales\Actions\RecalculateSalesQuoteVersion;
use App\Modules\Sales\Actions\ReopenSalesOpportunity;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use App\Modules\Sales\Actions\SyncOpportunityFollowUpCalendar;
use App\Modules\Sales\Jobs\SendSalesActivityEmail;
use App\Modules\Sales\Jobs\SendSalesInternalNotificationEmail;
use App\Modules\Sales\Jobs\SendSalesQuoteEmail;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesOpportunityStakeholder;
use App\Modules\Sales\Models\SalesQuoteAcknowledgement;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Sales\Models\SalesQuoteConversionPlan;
use App\Modules\Sales\Models\SalesQuoteLine;
use App\Modules\Sales\Models\SalesQuoteOptionGroup;
use App\Modules\Sales\Models\SalesQuoteTemplate;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use App\Modules\Sales\Support\SalesQuotePresentation;
use App\Modules\Storage\Models\Item as StorageItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'client_number' => 'nullable|string|regex:/^\d{5}$/|unique:clients,client_number',
            'org_no' => 'nullable|string|max:50',
            'client_format_id' => 'nullable|exists:client_formats,id',
            'website' => 'nullable|url|max:255',
            'billing_email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
            'active' => 'sometimes|boolean',
            'site_name' => 'required|string|max:255',
            'user_name' => 'required|string|max:255',
            'user_email' => 'required|email|max:255',
            'user_phone' => 'nullable|string|max:50',
            'user_role' => 'nullable|string|max:100',
        ]);

        $result = $createClient->handle(array_merge($data, ['active' => true]));
        $client = $result['client']->fresh(['sites', 'contacts']);

        return response()->json([
            'client' => [
                'id' => $client->id,
                'name' => $client->name,
                'client_number' => $client->client_number,
                'label' => trim($client->name.' '.($client->client_number ? '('.$client->client_number.')' : '')),
            ],
            'sites' => $this->sitePayload($client),
            'contacts' => $this->contactPayload($client),
            'warning' => $result['warning'],
        ], 201);
    }

    public function quickStoreContact(Request $request, Client $client): JsonResponse
    {
        $data = $request->validate([
            'client_site_id' => 'nullable|exists:client_sites,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:50',
            'role' => 'nullable|string|max:100',
        ]);

        $site = $client->sites()
            ->when(! empty($data['client_site_id']), fn ($query) => $query->whereKey($data['client_site_id']))
            ->first();

        if (! $site) {
            $site = $client->sites()->where('is_default', true)->first()
                ?: $client->sites()->first()
                ?: ClientSite::query()->create([
                    'client_id' => $client->id,
                    'name' => 'General sites',
                    'is_default' => true,
                ]);
        }

        $contact = ClientUser::query()->create([
            'client_site_id' => $site->id,
            'user_id' => null,
            'role' => $data['role'] ?? null,
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'is_default_for_site' => false,
            'is_default_for_client' => false,
            'active' => true,
        ]);

        return response()->json([
            'contact' => [
                'id' => $contact->id,
                'name' => $contact->name,
                'email' => $contact->email,
                'phone' => $contact->phone,
                'role' => $contact->role,
                'label' => $this->contactLabel($contact),
            ],
            'site' => [
                'id' => $site->id,
                'name' => $site->name,
            ],
        ], 201);
    }

    public function store(Request $request, StoreSalesOpportunity $storeOpportunity): RedirectResponse
    {
        $data = $this->opportunityData($request);
        $this->assertPrimaryContactBelongsToClient($data['primary_contact_id'] ?? null, (int) $data['client_id']);

        $opportunity = $storeOpportunity->handle($data, $request->user());

        return redirect()->route('tech.sales.show', $opportunity)
            ->with('success', 'Sales opportunity created.');
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
        $data = $this->opportunityData($request, false);
        $this->assertLostWorkflowIsNotBypassed($sale, $request->input('status'));
        $clientId = (int) ($data['client_id'] ?? $sale->client_id);
        $this->assertPrimaryContactBelongsToClient($data['primary_contact_id'] ?? null, $clientId);

        $probability = $data['probability_percent'] ?? (EnsureSalesDefaults::STATUSES[$data['status'] ?? $sale->status]['probability'] ?? $sale->probability_percent);
        $estimated = (float) ($data['estimated_value_ex_vat'] ?? $sale->estimated_value_ex_vat);

        $sale->fill(array_merge($data, [
            'probability_percent' => $probability,
            'weighted_value_ex_vat' => round($estimated * ($probability / 100), 2),
            'updated_by' => $request->user()->id,
        ]))->save();

        $syncCalendar->handle($sale, $request->user());

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
        $data = $request->validate([
            'type' => 'required|string|in:journal,internal_note,email_in,email_out',
            'subject' => 'nullable|string|max:255',
            'body' => 'required|string|max:10000',
            'recipient_contact_id' => 'nullable|exists:client_users,id',
            'to_email' => 'nullable|email|max:255',
            'cc' => 'nullable|string|max:1000',
            'notify_user_id' => ['nullable', Rule::exists((new User)->getTable(), 'id')],
        ]);

        $metadata = [];
        if ($data['type'] === 'email_out') {
            $recipient = $this->salesRecipient($sale, $data);
            $metadata = [
                'recipient_contact_id' => $recipient['contact_id'] ?? null,
                'to_email' => $recipient['email'],
                'to_name' => $recipient['name'] ?? '',
                'cc' => $this->ccRecipientsFromString($data['cc'] ?? ''),
            ];
        }

        if ($data['type'] === 'internal_note' && ! empty($data['notify_user_id'])) {
            $metadata['notify_user_id'] = (int) $data['notify_user_id'];
        }

        $activity = SalesActivity::query()->create([
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => $data['type'],
            'direction' => in_array($data['type'], ['email_in', 'email_out'], true) ? ($data['type'] === 'email_in' ? 'inbound' : 'outbound') : null,
            'subject' => $data['subject'] ?? null,
            'body' => $data['body'],
            'is_unread' => $data['type'] === 'email_in',
            'read_at' => $data['type'] === 'email_in' ? null : now(),
            'metadata' => $metadata,
        ]);

        if ($activity->is_unread) {
            $sale->forceFill(['is_unread' => true])->save();
        }

        if ($activity->type === 'email_out') {
            SendSalesActivityEmail::dispatch($activity->id);
        }

        if ($activity->type === 'internal_note' && ! empty($metadata['notify_user_id'])) {
            SendSalesInternalNotificationEmail::dispatch($activity->id);
        }

        return back()->with('success', 'Sales activity added.');
    }

    public function markRead(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        DB::transaction(function () use ($sale): void {
            SalesActivity::query()
                ->where('opportunity_id', $sale->id)
                ->where('is_unread', true)
                ->update([
                    'is_unread' => false,
                    'read_at' => now(),
                ]);

            $sale->forceFill(['is_unread' => false])->save();
        });

        return back()->with('success', 'Sales activity marked as read.');
    }

    public function markActivityRead(Request $request, SalesOpportunity $sale, SalesActivity $activity): RedirectResponse
    {
        abort_unless((int) $activity->opportunity_id === (int) $sale->id, 404);

        DB::transaction(function () use ($sale, $activity): void {
            if ($activity->is_unread) {
                $activity->forceFill([
                    'is_unread' => false,
                    'read_at' => now(),
                ])->save();
            }

            $hasUnreadActivity = SalesActivity::query()
                ->where('opportunity_id', $sale->id)
                ->where('is_unread', true)
                ->exists();

            $sale->forceFill(['is_unread' => $hasUnreadActivity])->save();
        });

        return back()->with('success', 'Sales reply marked as read.');
    }

    public function storeStakeholder(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $data = $request->validate([
            'client_user_id' => 'required|exists:client_users,id',
            'role' => 'required|string|max:100',
            'is_primary' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',
        ]);

        $contact = ClientUser::query()->whereHas('site', fn ($query) => $query->where('client_id', $sale->client_id))->findOrFail($data['client_user_id']);

        if ($request->boolean('is_primary')) {
            SalesOpportunityStakeholder::query()->where('opportunity_id', $sale->id)->update(['is_primary' => false]);
            $sale->forceFill(['primary_contact_id' => $contact->id])->save();
        }

        SalesOpportunityStakeholder::query()->updateOrCreate(
            ['opportunity_id' => $sale->id, 'client_user_id' => $contact->id],
            [
                'role' => $data['role'],
                'is_primary' => $request->boolean('is_primary'),
                'notes' => $data['notes'] ?? null,
            ]
        );

        return back()->with('success', 'Stakeholder added.');
    }

    public function ensureQuote(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, EnsureSalesQuoteDraft $ensureDraft): RedirectResponse
    {
        $version = $ensureDraft->handle($sale, $request->user());
        $recalculate->handle($version);

        return back()->with('success', 'Quote draft ready.');
    }

    public function updateQuoteDetails(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $version = $sale->currentQuoteVersion()->firstOrFail();
        abort_unless($version->isEditable(), 422, 'Only a draft quote can be edited.');

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
            'intro_text' => ['nullable', 'string', 'max:20000'],
            'scope_text' => ['nullable', 'string', 'max:20000'],
            'assumptions_text' => ['nullable', 'string', 'max:20000'],
            'exclusions_text' => ['nullable', 'string', 'max:20000'],
            'next_steps_text' => ['nullable', 'string', 'max:20000'],
            'acknowledgement_title' => ['nullable', 'string', 'max:255'],
            'acknowledgement_body' => ['nullable', 'string', 'max:20000'],
            'acknowledgement_required' => ['nullable', 'boolean'],
        ]);

        $version->fill(array_merge(collect($data)->except(['acknowledgement_title', 'acknowledgement_body', 'acknowledgement_required'])->all(), [
            'updated_by' => $request->user()->id,
        ]))->save();
        $this->saveQuoteAcknowledgement($version, $data);
        $this->resetDraftApproval($version, $request->user(), 'Quote presentation changed.');

        return back()
            ->with('success', 'Quote presentation updated.')
            ->with('open_quote_modal', true);
    }

    public function addQuoteLine(
        Request $request,
        SalesOpportunity $sale,
        RecalculateSalesQuoteVersion $recalculate,
        EnsureSalesQuoteDraft $ensureDraft,
        SalesQuotePresentation $quotePresentation
    ): RedirectResponse {
        $version = $ensureDraft->handle($sale, $request->user());

        abort_unless($version->isEditable(), 422);

        $data = $request->validate([
            'source_type' => 'required|string|in:custom,service,package,time_rate,storage_item',
            'source_id' => 'required_unless:source_type,custom|nullable|integer',
            'section' => 'required|string|max:100',
            'downstream_type' => 'required|string|max:100',
            'billing_cadence' => ['nullable', Rule::in(array_keys(SalesQuotePresentation::CADENCES))],
            'name' => 'nullable|string|max:255',
            'description' => 'nullable|string|max:4000',
            'quantity' => 'required|numeric|min:0.01|max:100000',
            'unit_price_ex_vat' => 'nullable|numeric|min:0|max:9999999',
            'unit_cost_ex_vat' => 'nullable|numeric|min:0|max:9999999',
            'discount_value' => 'nullable|numeric|min:0|max:9999999',
            'discount_type' => 'required|string|in:amount,percent',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'is_optional' => 'nullable|boolean',
            'is_required' => 'nullable|boolean',
            'is_recommended' => 'nullable|boolean',
            'customer_selected_by_default' => 'nullable|boolean',
            'customer_quantity_editable' => 'nullable|boolean',
            'min_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
            'max_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
            'customer_label' => 'nullable|string|max:255',
            'option_group_name' => 'nullable|string|max:255',
            'option_group_type' => ['nullable', Rule::in(array_keys(SalesQuoteOptionGroup::TYPES))],
            'option_group_description' => 'nullable|string|max:2000',
            'option_group_min_select' => 'nullable|integer|min:0|max:100',
            'option_group_max_select' => 'nullable|integer|min:1|max:100',
            'line_acknowledgement_title' => 'nullable|string|max:255',
            'line_acknowledgement_body' => 'nullable|string|max:20000',
            'line_acknowledgement_required' => 'nullable|boolean',
        ]);

        $lineData = $this->lineDataFromSource($data);
        $optionGroup = $this->resolveOptionGroup($version, $data);
        $cpqData = $this->lineCpqData($request, $data);

        $line = SalesQuoteLine::query()->create(array_merge($lineData, $cpqData, [
            'quote_version_id' => $version->id,
            'option_group_id' => $optionGroup?->id,
            'section' => $data['section'],
            'downstream_type' => $data['downstream_type'],
            'billing_cadence' => $quotePresentation->normalizeCadence(
                $data['billing_cadence'] ?? null,
                $data['section'],
                $data['downstream_type']
            ),
            'quantity' => $data['quantity'],
            'unit_price_ex_vat' => $data['unit_price_ex_vat'] ?? $lineData['unit_price_ex_vat'],
            'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? $lineData['unit_cost_ex_vat'],
            'discount_value' => $data['discount_value'] ?? 0,
            'discount_type' => $data['discount_type'],
            'vat_rate' => $data['vat_rate'] ?? $lineData['vat_rate'],
            'is_optional' => ! $cpqData['is_required'],
            'description' => $data['description'] ?? $lineData['description'],
        ]));
        $this->saveLineAcknowledgement($version, $line, $data);
        $this->resetDraftApproval($version, $request->user(), 'Quote line added.');

        $recalculate->handle($version);

        return back()
            ->with('success', 'Quote line added.')
            ->with('open_quote_modal', true);
    }

    public function deleteQuoteLine(Request $request, SalesOpportunity $sale, SalesQuoteLine $line, RecalculateSalesQuoteVersion $recalculate): RedirectResponse
    {
        abort_unless((int) $line->quoteVersion->quote->opportunity_id === (int) $sale->id, 404);
        abort_unless($line->quoteVersion->isEditable(), 422);

        $version = $line->quoteVersion;
        $line->delete();
        $this->resetDraftApproval($version, $request->user(), 'Quote line removed.');
        $recalculate->handle($version);

        return back()
            ->with('success', 'Quote line removed.')
            ->with('open_quote_modal', true);
    }

    public function updateQuoteLine(
        Request $request,
        SalesOpportunity $sale,
        SalesQuoteLine $line,
        RecalculateSalesQuoteVersion $recalculate,
        SalesQuotePresentation $quotePresentation
    ): RedirectResponse {
        abort_unless((int) $line->quoteVersion->quote->opportunity_id === (int) $sale->id, 404);
        abort_unless($line->quoteVersion->isEditable(), 422);

        $data = $request->validate([
            'section' => 'required|string|max:100',
            'downstream_type' => 'required|string|max:100',
            'billing_cadence' => ['nullable', Rule::in(array_keys(SalesQuotePresentation::CADENCES))],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:4000',
            'quantity' => 'required|numeric|min:0.01|max:100000',
            'unit_price_ex_vat' => 'required|numeric|min:0|max:9999999',
            'unit_cost_ex_vat' => 'nullable|numeric|min:0|max:9999999',
            'discount_value' => 'nullable|numeric|min:0|max:9999999',
            'discount_type' => 'required|string|in:amount,percent',
            'vat_rate' => 'nullable|numeric|min:0|max:100',
            'is_optional' => 'nullable|boolean',
            'is_required' => 'nullable|boolean',
            'is_recommended' => 'nullable|boolean',
            'customer_selected_by_default' => 'nullable|boolean',
            'customer_quantity_editable' => 'nullable|boolean',
            'min_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
            'max_customer_quantity' => 'nullable|numeric|min:0.01|max:100000',
            'customer_label' => 'nullable|string|max:255',
            'option_group_name' => 'nullable|string|max:255',
            'option_group_type' => ['nullable', Rule::in(array_keys(SalesQuoteOptionGroup::TYPES))],
            'option_group_description' => 'nullable|string|max:2000',
            'option_group_min_select' => 'nullable|integer|min:0|max:100',
            'option_group_max_select' => 'nullable|integer|min:1|max:100',
            'line_acknowledgement_title' => 'nullable|string|max:255',
            'line_acknowledgement_body' => 'nullable|string|max:20000',
            'line_acknowledgement_required' => 'nullable|boolean',
        ]);
        $optionGroup = $this->resolveOptionGroup($line->quoteVersion, $data);
        $cpqData = $this->lineCpqData($request, $data, $line);

        $line->fill(array_merge(collect($data)->except([
            'option_group_name',
            'option_group_type',
            'option_group_description',
            'option_group_min_select',
            'option_group_max_select',
            'line_acknowledgement_title',
            'line_acknowledgement_body',
            'line_acknowledgement_required',
        ])->all(), $cpqData, [
            'option_group_id' => $optionGroup?->id,
            'billing_cadence' => $quotePresentation->normalizeCadence(
                $data['billing_cadence'] ?? null,
                $data['section'],
                $data['downstream_type']
            ),
            'unit_cost_ex_vat' => $data['unit_cost_ex_vat'] ?? 0,
            'discount_value' => $data['discount_value'] ?? 0,
            'vat_rate' => $data['vat_rate'] ?? 25,
            'is_optional' => ! $cpqData['is_required'],
        ]))->save();
        $this->saveLineAcknowledgement($line->quoteVersion, $line, $data);
        $this->resetDraftApproval($line->quoteVersion, $request->user(), 'Quote line changed.');

        $recalculate->handle($line->quoteVersion);

        return back()
            ->with('success', 'Quote line updated.')
            ->with('open_quote_modal', true);
    }

    public function reviseQuote(Request $request, SalesOpportunity $sale, EnsureSalesQuoteDraft $ensureDraft, RecalculateSalesQuoteVersion $recalculate): RedirectResponse
    {
        $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();

        if ($sale->status !== 'negotiation' || $version->status !== 'sent') {
            return back()->with('warning', 'Only sent quotes in negotiation can be revised.');
        }

        $newVersion = $ensureDraft->handle($sale, $request->user());
        $recalculate->handle($newVersion);

        SalesActivity::query()->create([
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => 'quote_revised',
            'subject' => 'Quote moved back to draft',
            'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' remains immutable. Version '.$newVersion->version_number.' was created as a new draft.',
            'is_unread' => false,
            'read_at' => now(),
            'metadata' => ['previous_quote_version_id' => $version->id, 'quote_version_id' => $newVersion->id],
        ]);

        return back()
            ->with('success', 'A new draft quote version was created. The sent version remains unchanged.')
            ->with('open_quote_modal', true);
    }

    public function requestQuoteApproval(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, EvaluateSalesQuoteApproval $approval): RedirectResponse
    {
        $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();
        abort_unless($version->isEditable(), 422, 'Only draft quotes can be submitted for approval.');

        $recalculate->handle($version);
        $result = $approval->handle($version);

        $version->forceFill([
            'approval_status' => $result['required'] ? 'pending' : 'not_required',
            'approval_required_reasons' => $result['reasons'],
            'approval_policy_snapshot' => $result['policy'],
            'approval_requested_at' => $result['required'] ? now() : null,
            'approval_requested_by' => $result['required'] ? $request->user()->id : null,
            'approval_decided_at' => null,
            'approval_decided_by' => null,
            'approval_decision_note' => null,
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => $result['required'] ? 'quote_approval_requested' : 'quote_approval_not_required',
            'subject' => $result['required'] ? 'Quote approval requested' : 'Quote approval not required',
            'body' => $result['required'] ? implode("\n", $result['reasons']) : 'Approval policy did not require internal approval for this quote.',
            'metadata' => ['quote_version_id' => $version->id, 'reasons' => $result['reasons']],
        ]);

        return back()
            ->with($result['required'] ? 'warning' : 'success', $result['required'] ? 'Quote sent for internal approval.' : 'Approval is not required for this quote.')
            ->with('open_quote_modal', true);
    }

    public function approveQuote(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $this->assertQuoteApprover($request);

        return $this->decideQuoteApproval($request, $sale, 'approved', 'Quote approved.');
    }

    public function rejectQuote(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $this->assertQuoteApprover($request);

        return $this->decideQuoteApproval($request, $sale, 'rejected', 'Quote approval rejected.');
    }

    public function requestQuoteChanges(Request $request, SalesOpportunity $sale): RedirectResponse
    {
        $this->assertQuoteApprover($request);

        return $this->decideQuoteApproval($request, $sale, 'changes_requested', 'Quote changes requested.');
    }

    public function applyQuoteTemplate(Request $request, SalesOpportunity $sale, ApplySalesQuoteTemplate $applyTemplate): RedirectResponse
    {
        $data = $request->validate([
            'template_id' => 'required|exists:sales_quote_templates,id',
            'replace_existing' => 'nullable|boolean',
        ]);

        $template = SalesQuoteTemplate::query()->where('is_active', true)->findOrFail($data['template_id']);
        $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();

        $applyTemplate->handle($version, $template, $request->user(), (bool) ($data['replace_existing'] ?? false));

        return redirect()->route('tech.sales.show', $sale)
            ->with('success', 'Quote template applied.')
            ->with('open_quote_modal', true);
    }

    public function updateConversionPlan(Request $request, SalesOpportunity $sale, SalesQuoteConversionPlan $plan): RedirectResponse
    {
        $plan->loadMissing('quoteVersion.quote');
        abort_unless((int) $plan->quoteVersion->quote->opportunity_id === (int) $sale->id, 404);

        $data = $request->validate([
            'status' => ['required', Rule::in(['pending', 'in_progress', 'completed', 'deferred', 'blocked', 'not_applicable'])],
            'target_reference' => ['nullable', 'string', 'max:255'],
            'operator_note' => ['nullable', 'string', 'max:4000'],
        ]);

        $terminalStatuses = ['completed', 'deferred', 'blocked', 'not_applicable'];
        $plan->forceFill([
            'status' => $data['status'],
            'target_reference' => $data['target_reference'] ?? null,
            'operator_note' => $data['operator_note'] ?? null,
            'processed_at' => in_array($data['status'], $terminalStatuses, true) ? now() : null,
            'processed_by' => in_array($data['status'], $terminalStatuses, true) ? $request->user()->id : null,
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => 'quote_conversion_plan_updated',
            'subject' => 'Quote conversion plan updated',
            'body' => trim(str_replace('_', ' ', $data['status'])."\n".($data['target_reference'] ?? '')."\n".($data['operator_note'] ?? '')),
            'metadata' => [
                'quote_version_id' => $plan->quote_version_id,
                'conversion_plan_id' => $plan->id,
                'status' => $data['status'],
                'target_reference' => $data['target_reference'] ?? null,
            ],
        ]);

        return back()->with('success', 'Conversion plan updated.');
    }

    public function sendQuote(Request $request, SalesOpportunity $sale, RecalculateSalesQuoteVersion $recalculate, SendCustomerPortalNotification $portalNotifications, EvaluateSalesQuoteApproval $approval): RedirectResponse
    {
        $version = $sale->currentQuoteVersion()->with('quote')->firstOrFail();

        if (! in_array($version->status, ['draft', 'sent'], true)) {
            return back()->with('warning', 'Only draft or sent quotes can be emailed.');
        }

        $recalculate->handle($version);

        if ($version->lines()->count() < 1) {
            return back()->with('warning', 'Add at least one quote line before sending.');
        }

        $approvalResult = $approval->handle($version);
        if ($approvalResult['required'] && $version->approval_status !== 'approved') {
            $version->forceFill([
                'approval_status' => 'pending',
                'approval_required_reasons' => $approvalResult['reasons'],
                'approval_policy_snapshot' => $approvalResult['policy'],
                'approval_requested_at' => $version->approval_requested_at ?: now(),
                'approval_requested_by' => $version->approval_requested_by ?: $request->user()->id,
                'approval_decided_at' => null,
                'approval_decided_by' => null,
                'approval_decision_note' => null,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $sale->id,
                'actor_id' => $request->user()->id,
                'type' => 'quote_approval_requested',
                'subject' => 'Quote approval required',
                'body' => implode("\n", $approvalResult['reasons']),
                'metadata' => ['quote_version_id' => $version->id, 'reasons' => $approvalResult['reasons']],
            ]);

            return back()
                ->with('warning', 'Quote requires internal approval before sending.')
                ->with('open_quote_modal', true);
        }

        if (! $approvalResult['required'] && $version->approval_status !== 'not_required') {
            $version->forceFill([
                'approval_status' => 'not_required',
                'approval_required_reasons' => [],
                'approval_policy_snapshot' => $approvalResult['policy'],
                'approval_requested_at' => null,
                'approval_requested_by' => null,
                'approval_decided_at' => null,
                'approval_decided_by' => null,
                'approval_decision_note' => null,
            ])->save();
        }

        $wasDraft = $version->status === 'draft';

        if ($wasDraft) {
            $version->forceFill([
                'status' => 'sent',
                'sent_at' => now(),
                'updated_by' => $request->user()->id,
            ])->save();
            $version->quote->forceFill(['status' => 'sent', 'current_version_id' => $version->id])->save();
            $sale->forceFill([
                'status' => 'quote_sent',
                'probability_percent' => 50,
                'weighted_value_ex_vat' => round((float) $version->total_ex_vat * 0.5, 2),
                'current_quote_version_id' => $version->id,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $sale->id,
                'actor_id' => $request->user()->id,
                'type' => 'quote_sent',
                'subject' => 'Quote sent',
                'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' was marked as sent.',
                'metadata' => ['quote_version_id' => $version->id],
            ]);

            if ($sale->client_id) {
                $portalNotifications->handle(
                    type: 'portal_quote_sent',
                    clientId: (int) $sale->client_id,
                    siteId: null,
                    title: 'New quote available',
                    body: $version->title ?: $sale->title,
                    url: route('customer-portal.quotes.show', $version),
                    sourceType: SalesQuoteVersion::class,
                    sourceId: $version->id,
                    metadata: [
                        'quote_key' => $version->quote->quote_key,
                        'version_number' => $version->version_number,
                        'opportunity_id' => $sale->id,
                    ],
                );
            }
        }

        $sale->loadMissing('primaryContact');

        if ($sale->primaryContact?->email) {
            SendSalesQuoteEmail::dispatch($version->id);

            SalesActivity::query()->create([
                'opportunity_id' => $sale->id,
                'actor_id' => $request->user()->id,
                'type' => 'quote_email_queued',
                'subject' => 'Quote email queued',
                'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' was queued for email delivery to '.$sale->primaryContact->email.'.',
                'is_unread' => false,
                'read_at' => now(),
                'metadata' => ['quote_version_id' => $version->id, 'to_email' => $sale->primaryContact->email],
            ]);

            return back()->with('success', $wasDraft ? 'Quote marked as sent and queued for email delivery.' : 'Quote email queued for delivery.');
        }

        return back()->with('warning', 'Quote marked as sent, but no primary contact email is available. Public link: '.route('sales.quotes.public.view', $version->secure_token));
    }

    private function decideQuoteApproval(Request $request, SalesOpportunity $sale, string $status, string $message): RedirectResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:4000'],
        ]);

        $version = $sale->currentQuoteVersion()->firstOrFail();
        abort_unless($version->isEditable(), 422, 'Only draft quotes can receive an internal approval decision.');

        $version->forceFill([
            'approval_status' => $status,
            'approval_decided_at' => now(),
            'approval_decided_by' => $request->user()->id,
            'approval_decision_note' => $data['note'] ?? null,
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $sale->id,
            'actor_id' => $request->user()->id,
            'type' => 'quote_approval_'.$status,
            'subject' => $message,
            'body' => $data['note'] ?? $message,
            'metadata' => ['quote_version_id' => $version->id, 'approval_status' => $status],
        ]);

        return back()
            ->with($status === 'approved' ? 'success' : 'warning', $message)
            ->with('open_quote_modal', true);
    }

    private function assertQuoteApprover(Request $request): void
    {
        abort_unless($request->user()?->can('sales.quote.approve'), 403);
    }

    private function resolveOptionGroup(SalesQuoteVersion $version, array $data): ?SalesQuoteOptionGroup
    {
        if (! filled($data['option_group_name'] ?? null)) {
            return null;
        }

        $maxSelect = $data['option_group_max_select'] ?? null;
        $type = $data['option_group_type'] ?? 'optional';
        if ($maxSelect === null && in_array($type, ['alternative', 'good_better_best'], true)) {
            $maxSelect = 1;
        }

        if ($maxSelect !== null && (int) $maxSelect < (int) ($data['option_group_min_select'] ?? 0)) {
            throw ValidationException::withMessages(['option_group_max_select' => 'Maximum selections must be greater than or equal to minimum selections.']);
        }

        return SalesQuoteOptionGroup::query()->updateOrCreate(
            [
                'quote_version_id' => $version->id,
                'name' => trim((string) $data['option_group_name']),
            ],
            [
                'type' => $type,
                'description' => $data['option_group_description'] ?? null,
                'min_select' => (int) ($data['option_group_min_select'] ?? 0),
                'max_select' => $maxSelect !== null ? (int) $maxSelect : null,
                'sort_order' => $version->optionGroups()->count() * 10 + 10,
            ]
        );
    }

    private function lineCpqData(Request $request, array $data, ?SalesQuoteLine $line = null): array
    {
        $quantity = (float) ($data['quantity'] ?? $line?->quantity ?? 1);
        $customerQuantityEditable = $request->boolean('customer_quantity_editable');
        $minQuantity = (float) ($data['min_customer_quantity'] ?? ($customerQuantityEditable ? 1 : $quantity));
        $maxQuantity = array_key_exists('max_customer_quantity', $data) && $data['max_customer_quantity'] !== null
            ? (float) $data['max_customer_quantity']
            : ($customerQuantityEditable ? null : $quantity);

        if ($maxQuantity !== null && $maxQuantity < $minQuantity) {
            throw ValidationException::withMessages(['max_customer_quantity' => 'Maximum customer quantity must be greater than or equal to minimum quantity.']);
        }

        $isOptional = $request->boolean('is_optional');
        $isRequired = $request->has('is_required') ? $request->boolean('is_required') : ! $isOptional;
        $selectedByDefault = $request->has('customer_selected_by_default')
            ? $request->boolean('customer_selected_by_default')
            : true;

        return [
            'is_required' => $isRequired,
            'is_recommended' => $request->boolean('is_recommended'),
            'customer_selected_by_default' => $isRequired || $selectedByDefault,
            'customer_quantity_editable' => $customerQuantityEditable,
            'min_customer_quantity' => $minQuantity,
            'max_customer_quantity' => $maxQuantity,
            'customer_label' => $data['customer_label'] ?? null,
        ];
    }

    private function saveQuoteAcknowledgement(SalesQuoteVersion $version, array $data): void
    {
        $body = trim((string) ($data['acknowledgement_body'] ?? ''));
        $existing = SalesQuoteAcknowledgement::query()
            ->where('quote_version_id', $version->id)
            ->whereNull('quote_line_id')
            ->where('source_type', 'manual_quote_details')
            ->first();

        if ($body === '') {
            $existing?->delete();

            return;
        }

        SalesQuoteAcknowledgement::query()->updateOrCreate(
            [
                'quote_version_id' => $version->id,
                'quote_line_id' => null,
                'source_type' => 'manual_quote_details',
            ],
            [
                'title' => $data['acknowledgement_title'] ?: 'Important information',
                'body' => $body,
                'is_required' => array_key_exists('acknowledgement_required', $data) ? (bool) $data['acknowledgement_required'] : true,
                'sort_order' => $existing?->sort_order ?? $version->acknowledgements()->count() * 10 + 10,
            ]
        );
    }

    private function saveLineAcknowledgement(SalesQuoteVersion $version, SalesQuoteLine $line, array $data): void
    {
        $body = trim((string) ($data['line_acknowledgement_body'] ?? ''));
        $existing = SalesQuoteAcknowledgement::query()
            ->where('quote_version_id', $version->id)
            ->where('quote_line_id', $line->id)
            ->where('source_type', 'manual_line')
            ->first();

        if ($body === '') {
            $existing?->delete();

            return;
        }

        SalesQuoteAcknowledgement::query()->updateOrCreate(
            [
                'quote_version_id' => $version->id,
                'quote_line_id' => $line->id,
                'source_type' => 'manual_line',
            ],
            [
                'title' => $data['line_acknowledgement_title'] ?: 'Important information for '.$line->name,
                'body' => $body,
                'is_required' => array_key_exists('line_acknowledgement_required', $data) ? (bool) $data['line_acknowledgement_required'] : true,
                'sort_order' => $existing?->sort_order ?? $version->acknowledgements()->count() * 10 + 10,
            ]
        );
    }

    private function resetDraftApproval(SalesQuoteVersion $version, User $actor, string $reason): void
    {
        if (! $version->isEditable() || ! in_array($version->approval_status, ['pending', 'approved', 'rejected', 'changes_requested'], true)) {
            return;
        }

        $version->forceFill([
            'approval_status' => 'not_required',
            'approval_required_reasons' => [],
            'approval_requested_at' => null,
            'approval_requested_by' => null,
            'approval_decided_at' => null,
            'approval_decided_by' => null,
            'approval_decision_note' => null,
        ])->save();

        SalesActivity::query()->create([
            'opportunity_id' => $version->quote->opportunity_id,
            'actor_id' => $actor->id,
            'type' => 'quote_approval_invalidated',
            'subject' => 'Quote approval reset',
            'body' => $reason,
            'metadata' => ['quote_version_id' => $version->id],
        ]);
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

    private function salesRecipient(SalesOpportunity $sale, array $data): array
    {
        $contactId = $data['recipient_contact_id'] ?? null;

        if ($contactId) {
            $contact = ClientUser::query()
                ->whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $sale->client_id))
                ->where('active', true)
                ->firstOrFail();

            return [
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'name' => $contact->name,
            ];
        }

        $email = $data['to_email'] ?? null;
        abort_unless($email && filter_var($email, FILTER_VALIDATE_EMAIL), 422, 'Customer email requires a recipient.');

        return [
            'contact_id' => null,
            'email' => $email,
            'name' => '',
        ];
    }

    private function ccRecipientsFromString(?string $cc): array
    {
        return collect(preg_split('/[,;\s]+/', (string) $cc))
            ->map(fn ($email) => trim($email))
            ->filter(fn ($email) => filter_var($email, FILTER_VALIDATE_EMAIL))
            ->unique()
            ->map(fn ($email) => ['email' => $email, 'name' => ''])
            ->values()
            ->all();
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

    private function lineDataFromSource(array $data): array
    {
        $base = [
            'source_type' => $data['source_type'],
            'source_id' => $data['source_id'] ?? null,
            'sku' => null,
            'name' => ($data['name'] ?? null) ?: 'Custom line',
            'description' => $data['description'] ?? null,
            'unit' => null,
            'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? 0),
            'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? 0),
            'vat_rate' => $data['vat_rate'] ?? 25,
            'snapshot' => null,
        ];

        if ($data['source_type'] === 'service' && ! empty($data['source_id'])) {
            $service = Services::with(['serviceTerms', 'sla', 'costRelations.cost'])->findOrFail($data['source_id']);
            $cost = $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0));

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $service->name,
                'sku' => $service->sku,
                'description' => $data['description'] ?? $service->short_description,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $service->price_ex_vat ?? 0),
                'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? $cost),
                'vat_rate' => $data['vat_rate'] ?? 25,
                'snapshot' => array_merge($service->only(['id', 'name', 'sku', 'price_ex_vat', 'billing_cycle']), [
                    'cost_ex_vat' => $cost,
                ]),
            ]);
        }

        if ($data['source_type'] === 'package' && ! empty($data['source_id'])) {
            $package = Package::with(['terms', 'services.costRelations.cost'])->findOrFail($data['source_id']);
            $cost = $package->services->sum(
                fn ($service) => $service->costRelations->sum(fn ($relation) => (float) ($relation->cost?->cost ?? 0))
            );

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $package->name,
                'description' => $data['description'] ?? $package->description,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $package->sales_price_client ?? 0),
                'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? $cost),
                'snapshot' => array_merge($package->only(['id', 'name', 'description']), [
                    'cost_ex_vat' => $cost,
                ]),
            ]);
        }

        if ($data['source_type'] === 'time_rate' && ! empty($data['source_id'])) {
            $rate = TimeRate::findOrFail($data['source_id']);

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $rate->name,
                'description' => $data['description'] ?? $rate->description,
                'unit' => $rate->unit,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $rate->amount_ex_vat ?? 0),
                'snapshot' => $rate->only(['id', 'name', 'code', 'amount_ex_vat', 'unit']),
            ]);
        }

        if ($data['source_type'] === 'storage_item' && ! empty($data['source_id'])) {
            $item = StorageItem::findOrFail($data['source_id']);

            return array_merge($base, [
                'name' => ($data['name'] ?? null) ?: $item->name,
                'sku' => $item->sku,
                'description' => $data['description'] ?? $item->short_description,
                'unit_price_ex_vat' => (float) ($data['unit_price_ex_vat'] ?? $item->sale_price ?? 0),
                'unit_cost_ex_vat' => (float) ($data['unit_cost_ex_vat'] ?? $item->purchase_price ?? 0),
                'vat_rate' => $data['vat_rate'] ?? $item->vat_rate ?? 25,
                'snapshot' => $item->only(['id', 'sku', 'name', 'sale_price', 'purchase_price', 'vat_rate']),
            ]);
        }

        return $base;
    }
}
