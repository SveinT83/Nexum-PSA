<?php

namespace App\Modules\Ticket\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Email\Models\EmailLog;
use App\Modules\Knowledge\Queries\ArticleQuery;
use App\Modules\Contact\Models\Contact;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\ChangeTicketStatus;
use App\Modules\Ticket\Actions\CloseTicket;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\MarkTicketMessageSolution;
use App\Modules\Ticket\Actions\MarkTicketRead;
use App\Modules\Ticket\Actions\MarkTicketAsNotTicket;
use App\Modules\Ticket\Actions\MergeTickets;
use App\Modules\Ticket\Actions\PickTicketStorageReservation;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\StoreManualTicketCostEntry;
use App\Modules\Ticket\Actions\RegisterTicketTimeEntry;
use App\Modules\Ticket\Actions\ReserveTicketStorageItem;
use App\Modules\Ticket\Actions\UpdateTicketStorageReservation;
use App\Modules\Ticket\Actions\UpdateTicketTimeEntry;
use App\Modules\Ticket\Actions\UpdateTicketFields;
use App\Modules\Storage\Models\Item as StorageItem;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAttachment;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketMergeSuggestionDismissal;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketWorkflowTransition;
use App\Modules\Ticket\Queries\TicketIndexQuery;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\Ticket\Services\TicketAssignmentEngine;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketMergeSuggestionService;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use App\Modules\Ticket\Services\TicketWorkflowRuntime;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Illuminate\View\View;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketController extends Controller
{
    public function index(
        Request $request,
        TicketIndexQuery $query,
        EnsureTicketDefaults $defaults,
        TicketMergeSuggestionService $mergeSuggestionService
    ): View
    {
        $defaults->handle();

        $filters = $request->only([
            'q',
            'status_id',
            'queue_id',
            'priority_id',
            'category_id',
            'lifecycle',
            'unread',
            'unassigned',
            'ownership',
            'client_id',
            'sort',
            'direction',
        ]);

        return view('ticket::Tech.Tickets.index', [
            'tickets' => $query->paginate($filters),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'categories' => $this->ticketCategories(),
            'clients' => Client::where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'filters' => $filters,
            'mergeSuggestionSettings' => $mergeSuggestionService->settings(),
            'mergeSuggestions' => $mergeSuggestionService->suggestionsForIndex(),
            'stats' => [
                'open' => Ticket::whereHas('status', fn ($query) => $query->where('is_closed', false))->count(),
                'mine' => Ticket::where('owner_id', $request->user()?->id)->count(),
                'unread' => Ticket::where('is_unread', true)->count(),
                'unassigned' => Ticket::whereNull('owner_id')
                    ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                    ->count(),
            ],
        ]);
    }

    public function create(Request $request, EnsureTicketDefaults $defaults): View
    {
        $defaults->handle();

        $selectedClientId = $request->integer('client_id') ?: null;
        $selectedClient = $selectedClientId
            ? Client::where('active', true)->find($selectedClientId)
            : null;
        $selectedContactId = $request->integer('contact_id') ?: null;
        $selectedSiteId = $request->integer('site_id') ?: null;
        $selectedContact = $selectedContactId && $selectedClient
            ? ClientUser::whereKey($selectedContactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $selectedClient->id))
                ->where('active', true)
                ->first()
            : null;
        $selectedSite = $selectedContact?->site
            ?? ($selectedSiteId && $selectedClient
                ? ClientSite::where('client_id', $selectedClient->id)->find($selectedSiteId)
                : null);

        return view('ticket::Tech.Tickets.create', [
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'types' => TicketType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'categories' => $this->ticketCategories(),
            'tags' => $this->activeTags(),
            'clients' => Client::where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'sites' => $selectedClient
                ? ClientSite::where('client_id', $selectedClient->id)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])
                : collect(),
            'contacts' => $selectedClient
                ? ClientUser::whereHas('site', fn ($query) => $query->where('client_id', $selectedClient->id))
                    ->where('active', true)
                    ->orderByDesc('is_default_for_client')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email', 'phone'])
                : collect(),
            'assetOptions' => $this->assetOptions($selectedClient?->id, $selectedContact?->id, $selectedSite?->id),
            'technicians' => $this->technicians(),
            'selectedClient' => $selectedClient,
            'selectedContact' => $selectedContact,
            'selectedSite' => $selectedSite,
            'openClientTickets' => $selectedClient
                ? Ticket::with(['status', 'priority'])
                    ->where('client_id', $selectedClient->id)
                    ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                    ->latest('updated_at')
                    ->limit(8)
                    ->get()
                : collect(),
        ]);
    }

    public function createContext(Request $request): JsonResponse
    {
        $clientId = $request->integer('client_id') ?: null;
        $contactId = $request->integer('contact_id') ?: null;
        $siteId = $request->integer('site_id') ?: null;

        if (! $clientId) {
            return response()->json([
                'sites' => [],
                'contacts' => [],
                'assets' => [],
                'openTickets' => [],
            ]);
        }

        $client = Client::query()
            ->where('active', true)
            ->findOrFail($clientId);

        $contact = $contactId
            ? ClientUser::query()
                ->whereKey($contactId)
                ->where('active', true)
                ->whereHas('site', fn ($query) => $query->where('client_id', $client->id))
                ->first()
            : null;

        $resolvedSiteId = $contact?->client_site_id ?: $siteId;

        return response()->json([
            'sites' => ClientSite::query()
                ->where('client_id', $client->id)
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (ClientSite $site) => [
                    'id' => $site->id,
                    'name' => $site->name,
                ])
                ->values(),
            'contacts' => ClientUser::query()
                ->whereHas('site', fn ($query) => $query->where('client_id', $client->id))
                ->where('active', true)
                ->orderByDesc('is_default_for_client')
                ->orderBy('name')
                ->get(['id', 'name', 'email', 'phone'])
                ->map(fn (ClientUser $contact) => [
                    'id' => $contact->id,
                    'label' => $contact->name . ($contact->email ? ' - ' . $contact->email : ''),
                ])
                ->values(),
            'assets' => $this->assetOptions($client->id, $contact?->id, $resolvedSiteId)
                ->map(fn (array $asset) => [
                    'id' => $asset['id'],
                    'group' => $asset['group'],
                    'label' => $asset['label'],
                ])
                ->values(),
            'openTickets' => Ticket::query()
                ->with(['status', 'priority'])
                ->where('client_id', $client->id)
                ->whereHas('status', fn ($query) => $query->where('is_closed', false))
                ->latest('updated_at')
                ->limit(8)
                ->get()
                ->map(fn (Ticket $ticket) => [
                    'key' => $ticket->ticket_key,
                    'url' => route('tech.tickets.show', $ticket),
                    'subject' => $ticket->subject,
                    'updated' => $ticket->updated_at?->diffForHumans(),
                    'status' => $ticket->status?->name,
                    'priority' => $ticket->priority
                        ? 'P' . $ticket->priority->level . ' ' . $ticket->priority->name
                        : null,
                ])
                ->values(),
        ]);
    }

    public function store(Request $request, StoreTicket $storeTicket): RedirectResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'queue_id' => 'nullable|exists:ticket_queues,id',
            'status_id' => 'nullable|exists:ticket_statuses,id',
            'priority_id' => 'nullable|exists:ticket_priorities,id',
            'category_id' => ['nullable', $this->ticketCategoryRule()],
            'ticket_type_id' => 'nullable|exists:ticket_types,id',
            'client_id' => 'nullable|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'contact_id' => 'nullable|exists:client_users,id',
            'asset_id' => 'nullable|exists:assets,id',
            'owner_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')],
            'tag_names' => 'nullable|array',
            'tag_names.*' => 'nullable|string|max:80',
            'type' => 'nullable|string|max:50',
            'impact' => 'nullable|integer|min:1|max:5',
            'urgency' => 'nullable|integer|min:1|max:5',
        ]);

        if (! empty($data['contact_id']) && empty($data['client_id'])) {
            return back()
                ->withErrors(['client_id' => 'Select a client before selecting a contact.'])
                ->withInput();
        }

        if (! empty($data['contact_id']) && ! empty($data['client_id'])) {
            $contactBelongsToClient = ClientUser::whereKey($data['contact_id'])
                ->whereHas('site', fn ($query) => $query->where('client_id', $data['client_id']))
                ->exists();

            if (! $contactBelongsToClient) {
                return back()
                    ->withErrors(['contact_id' => 'The selected contact does not belong to the selected client.'])
                    ->withInput();
            }
        }

        $data['site_id'] = $this->resolveSiteId(
            $data['client_id'] ?? null,
            $data['site_id'] ?? null,
            $data['contact_id'] ?? null,
            $data['asset_id'] ?? null
        );

        if (! empty($data['category_id'])) {
            $data['category_id'] = $this->ticketCategoryId((int) $data['category_id']);
        }

        $data['tag_ids'] = $this->resolveTagIds($data['tag_names'] ?? []);

        if (! empty($data['ticket_type_id'])) {
            $type = TicketType::where('is_active', true)->findOrFail($data['ticket_type_id']);
            $data['ticket_type_id'] = $type->id;
            $data['type'] = $type->slug;
        }

        if (! empty($data['asset_id'])) {
            $this->validateAssetScope($data['asset_id'], $data['client_id'] ?? null, $data['contact_id'] ?? null, $data['site_id'] ?? null);
        }

        $data['channel'] = 'manual';

        $ticket = $storeTicket->handle($data, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Ticket ' . $ticket->ticket_key . ' created.');
    }

    public function show(Ticket $ticket, ArticleQuery $articleQuery, TicketActionGuard $actionGuard, TicketWorkflowRuntime $workflowRuntime, TicketTimeRateOptions $timeRateOptions, TicketSolutionPolicy $solutionPolicy): View|RedirectResponse
    {
        app(EnsureTicketDefaults::class)->handle();

        if ($ticket->trashed() && $ticket->merged_into_ticket_id) {
            return redirect()->route('tech.tickets.show', $ticket->mergedInto)
                ->with('warning', 'Ticket '.$ticket->ticket_key.' was merged into '.$ticket->mergedInto?->ticket_key.'.');
        }

        abort_if($ticket->trashed(), 404);

        $ticket->load(['queue', 'status', 'priority', 'sla', 'workflow', 'category', 'client', 'site', 'contact.site', 'owner', 'asset', 'tags', 'messages.author', 'messages.fileAttachments', 'events', 'timeEntries.user', 'costEntries.user', 'costEntries.storageItem', 'tasks.status', 'tasks.assignee', 'tasks.checklistItems', 'tasks.timeEntries']);
        $messageIds = $ticket->messages->pluck('id')->all();

        $workflowTransitions = $workflowRuntime->availableTransitionsWithRequirements($ticket);
        $closeTransition = $workflowTransitions->first(fn (TicketWorkflowTransition $transition) => (bool) $transition->toStatus?->is_closed);

        return view('ticket::Tech.Tickets.show', [
            'ticket' => $ticket,
            'ticketActions' => $actionGuard->map($ticket, request()->user()),
            'solutionPolicy' => $solutionPolicy->settings(),
            'workflowTransitions' => $workflowTransitions,
            'closeTransition' => $closeTransition,
            'latestAssignmentEvent' => $ticket->events
                ->where('type', 'assigned')
                ->sortByDesc('created_at')
                ->first(),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'types' => TicketType::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'categories' => $this->ticketCategories(),
            'technicians' => $this->technicians(),
            'replyContacts' => $this->replyContactOptions($ticket),
            'ccContactSuggestions' => $this->ccContactSuggestions($ticket),
            'timeRateOptions' => $timeRateOptions->forTicket($ticket),
            'storageItems' => $this->storageItemOptions(),
            'knowledgeSuggestions' => $articleQuery->relevantForTicket($ticket, 3),
            'emailLogsByMessageId' => EmailLog::query()
                ->where('direction', 'outbound')
                ->where('scope', 'tickets')
                ->whereIn('context_json->ticket_message_id', $messageIds)
                ->latest()
                ->get()
                ->groupBy(fn (EmailLog $log) => (int) ($log->context_json['ticket_message_id'] ?? 0)),
        ]);
    }

    public function edit(Ticket $ticket): View
    {
        app(EnsureTicketDefaults::class)->handle();

        return view('ticket::Tech.Tickets.edit', [
            'ticket' => $ticket->load(['queue', 'status', 'priority', 'category', 'client', 'site', 'contact.site', 'owner', 'asset', 'tags']),
            'queues' => TicketQueue::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'statuses' => TicketStatus::where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(),
            'priorities' => TicketPriority::where('is_active', true)->orderBy('level')->get(),
            'categories' => $this->ticketCategories(),
            'tags' => $this->activeTags(),
            'clients' => Client::where('active', true)->orderBy('name')->get(['id', 'name', 'client_number']),
            'contacts' => ClientUser::query()
                ->with('site.client:id,name')
                ->where('active', true)
                ->whereHas('site.client', fn ($query) => $query->where('active', true))
                ->orderBy('name')
                ->get(['id', 'client_site_id', 'name', 'email']),
            'sites' => $ticket->client_id
                ? ClientSite::where('client_id', $ticket->client_id)->orderByDesc('is_default')->orderBy('name')->get(['id', 'name'])
                : collect(),
            'assetOptions' => $this->assetOptions($ticket->client_id, $ticket->contact_id, $ticket->site_id),
            'technicians' => $this->technicians(),
        ]);
    }

    public function addMessage(Request $request, Ticket $ticket, AddTicketMessage $addTicketMessage, TicketActionGuard $actionGuard, TicketSolutionPolicy $solutionPolicy): RedirectResponse
    {
        $data = $request->validate([
            'body' => 'required|string',
            'type' => 'required|string|in:internal_note,customer_reply,internal_solution',
            'reply_intent' => 'nullable|string|in:customer_update,request_customer_input,send_solution',
            'reply_contact_id' => 'nullable|integer|exists:client_users,id',
            'cc' => 'nullable|string|max:1000',
            'notify_user_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')->where('status', User::STATUS_ACTIVE)],
            'visibility' => 'required|string|in:internal,public',
            'attachments' => 'nullable|array|max:5',
            'attachments.*' => 'file|max:20480',
        ]);

        $isInternalSolution = $data['type'] === 'internal_solution';

        if ($isInternalSolution) {
            if (! $solutionPolicy->allowsInternalSolutionNotes()) {
                return back()
                    ->withErrors(['type' => 'Internal solution notes are disabled by Ticket solution policy.'])
                    ->withInput();
            }

            $data['type'] = 'internal_note';
            $data['reply_intent'] = TicketAction::SEND_SOLUTION;
        }

        $data['visibility'] = $data['type'] === 'internal_note' ? 'internal' : 'public';
        if (! $isInternalSolution) {
            $data['reply_intent'] = $data['type'] === 'customer_reply'
                ? ($data['reply_intent'] ?? TicketAction::CUSTOMER_UPDATE)
                : null;
        }
        $action = $data['type'] === 'customer_reply' ? TicketAction::CUSTOMER_REPLY : TicketAction::ADD_INTERNAL_NOTE;

        if ($reason = $actionGuard->reason($ticket, $action, $request->user())) {
            return back()->withErrors(['type' => $reason])->withInput();
        }

        if ($data['type'] === 'customer_reply') {
            $replyContactId = $data['reply_contact_id'] ?? $ticket->contact_id;
            $replyContact = $this->replyContactOptions($ticket)->firstWhere('id', (int) $replyContactId);

            if (! $replyContact || blank($replyContact->email)) {
                return back()->withErrors(['reply_contact_id' => 'Select an active client contact with an email address.'])->withInput();
            }

            $data['reply_contact_id'] = $replyContact->id;
        } else {
            $data['reply_contact_id'] = null;
            $data['cc'] = null;
        }

        $addTicketMessage->handle($ticket, $data, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Message added.');
    }

    public function storeTimeEntry(Request $request, Ticket $ticket, TicketTimeRateOptions $timeRateOptions, RegisterTicketTimeEntry $registerTimeEntry): RedirectResponse
    {
        $data = $request->validate([
            'work_date' => 'required|date',
            'minutes' => 'required|integer|min:1|max:1440',
            'rate_key' => 'required|string|max:100',
            'invoice_text' => 'required|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);

        $rateOption = $timeRateOptions->findForTicket($ticket, $data['rate_key']);

        if (! $rateOption) {
            return back()
                ->withErrors(['rate_key' => 'Select an available time rate for this ticket.'])
                ->withInput();
        }

        $registerTimeEntry->handle($ticket, $data, $rateOption, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Time entry added.');
    }

    public function storeCostEntry(Request $request, Ticket $ticket, ReserveTicketStorageItem $reserveItem, StoreManualTicketCostEntry $storeManualCost): RedirectResponse
    {
        $data = $request->validate([
            'cost_mode' => 'nullable|string|in:storage,manual',
            'storage_item_id' => 'nullable|required_if:cost_mode,storage|integer|exists:storage_items,id',
            'item_name' => 'nullable|required_if:cost_mode,manual|string|max:255',
            'quantity' => 'required|integer|min:1|max:100000',
            'unit_price_ex_vat' => 'nullable|required_if:cost_mode,manual|numeric|min:0|max:9999999999.99',
            'currency' => 'nullable|string|size:3',
            'invoice_text' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);

        if (($data['cost_mode'] ?? 'storage') === 'manual') {
            $storeManualCost->handle($ticket, [
                'item_name' => $data['item_name'],
                'quantity' => $data['quantity'],
                'unit_price_ex_vat' => $data['unit_price_ex_vat'],
                'currency' => Str::upper($data['currency'] ?? 'NOK'),
                'invoice_text' => $data['invoice_text'] ?? null,
                'note' => $data['note'] ?? null,
            ], $request->user());

            return redirect()->route('tech.tickets.show', $ticket)
                ->with('success', 'Manual cost added.');
        }

        $item = StorageItem::where('status', 'active')->findOrFail($data['storage_item_id']);

        try {
            $reserveItem->handle($ticket, $item, $data, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()
                ->withErrors(['storage_item_id' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Storage item reserved.');
    }

    public function updateTimeEntry(Request $request, Ticket $ticket, TicketTimeEntry $timeEntry, TicketTimeRateOptions $timeRateOptions, UpdateTicketTimeEntry $updateTimeEntry): RedirectResponse
    {
        abort_unless((int) $timeEntry->ticket_id === (int) $ticket->id, 404);

        $data = $request->validate([
            'work_date' => 'required|date',
            'minutes' => 'required|integer|min:1|max:1440',
            'rate_key' => 'required|string|max:100',
            'invoice_text' => 'required|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);
        $rateOption = $timeRateOptions->findForTicket($ticket, $data['rate_key']);

        if (! $rateOption) {
            return back()
                ->withErrors(['rate_key' => 'Select an available time rate for this ticket.'])
                ->withInput();
        }

        $updateTimeEntry->handle($ticket, $timeEntry, $data, $rateOption, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Time entry updated.');
    }

    public function updateCostEntry(Request $request, Ticket $ticket, TicketCostEntry $costEntry, UpdateTicketStorageReservation $updateReservation): RedirectResponse
    {
        abort_unless((int) $costEntry->ticket_id === (int) $ticket->id, 404);

        $data = $request->validate([
            'quantity' => 'required|integer|min:1|max:100000',
            'invoice_text' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
        ]);

        try {
            $updateReservation->handle($ticket, $costEntry, $data, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()
                ->withErrors(['cost_entry' => $exception->getMessage()])
                ->withInput();
        }

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Storage reservation updated.');
    }

    public function pickCostEntry(Request $request, Ticket $ticket, TicketCostEntry $costEntry, PickTicketStorageReservation $pickReservation): RedirectResponse
    {
        try {
            $pickReservation->handle($ticket, $costEntry, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['cost_entry' => $exception->getMessage()]);
        }

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Storage item picked and sent to Economy.');
    }

    public function draftTimeEntryInvoiceText(Request $request, Ticket $ticket, AiAgentResolver $agentResolver, AiChatResponder $responder, TicketTimeRateOptions $timeRateOptions): JsonResponse
    {
        $data = $request->validate([
            'existing_text' => 'nullable|string|max:2000',
            'rate_key' => 'nullable|string|max:100',
        ]);
        $user = $request->user();
        $agent = $user ? $agentResolver->defaultAgent($user, 'tickets') : null;

        if (! $agent) {
            return response()->json([
                'message' => 'No active AI agent is available for tickets.',
            ], 422);
        }

        $rateOption = filled($data['rate_key'] ?? null)
            ? $timeRateOptions->findForTicket($ticket, $data['rate_key'])
            : null;

        $ticket->loadMissing(['status', 'client', 'contact', 'messages.author', 'timeEntries.user']);
        $prompt = $this->timeInvoiceDraftPrompt($ticket, $data['existing_text'] ?? null, $rateOption);
        $chat = AiChat::create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Ticket time invoice draft '.$ticket->ticket_key,
            'status' => 'closed',
            'metadata' => [
                'source' => 'ticket_time_invoice_draft',
                'ticket_id' => $ticket->id,
            ],
            'last_message_at' => now(),
        ]);

        $chat->messages()->create([
            'user_id' => $user->id,
            'role' => 'user',
            'body' => $prompt,
        ]);
        $pending = $chat->messages()->create([
            'role' => 'assistant',
            'body' => 'AI is thinking...',
            'metadata' => ['status' => 'pending'],
        ]);

        $responder->respond($chat, $pending->id);
        $reply = trim((string) $pending->refresh()->body);

        if (Str::startsWith($reply, 'AI provider error:')) {
            return response()->json(['message' => $reply], 422);
        }

        return response()->json([
            'text' => Str::limit($reply, 2000, ''),
        ]);
    }

    public function downloadAttachment(Ticket $ticket, TicketAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $attachment->ticket_id === (int) $ticket->id, 404);

        $disk = $attachment->disk ?: 'local';

        abort_unless($attachment->path && Storage::disk($disk)->exists($attachment->path), 404);

        return Storage::disk($disk)->download($attachment->path, $attachment->filename);
    }

    public function update(Request $request, Ticket $ticket, UpdateTicketFields $updateTicketFields, ChangeTicketStatus $changeTicketStatus, TicketActionGuard $actionGuard): RedirectResponse
    {
        if ($reason = $actionGuard->reason($ticket, TicketAction::UPDATE_FIELDS, $request->user())) {
            return back()->withErrors(['ticket' => $reason])->withInput();
        }

        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'description' => 'nullable|string',
            'queue_id' => 'required|exists:ticket_queues,id',
            'status_id' => 'required|exists:ticket_statuses,id',
            'priority_id' => 'required|exists:ticket_priorities,id',
            'category_id' => ['nullable', $this->ticketCategoryRule()],
            'client_id' => 'nullable|exists:clients,id',
            'contact_id' => 'nullable|exists:client_users,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'asset_id' => 'nullable|exists:assets,id',
            'owner_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')],
            'tag_names' => 'nullable|array',
            'tag_names.*' => 'nullable|string|max:80',
        ]);

        $queue = TicketQueue::where('is_active', true)->findOrFail($data['queue_id']);
        $status = TicketStatus::where('is_active', true)->findOrFail($data['status_id']);
        $priority = TicketPriority::where('is_active', true)->findOrFail($data['priority_id']);
        $categoryId = empty($data['category_id'])
            ? null
            : $this->ticketCategoryId((int) $data['category_id']);
        $clientId = $data['client_id'] ?? null;
        $contactId = $data['contact_id'] ?? null;

        if ($contactId && ! $clientId) {
            return back()
                ->withErrors(['client_id' => 'Select a client before selecting a contact.'])
                ->withInput();
        }

        if ($contactId && $clientId && ! $this->contactBelongsToClient((int) $contactId, (int) $clientId)) {
            return back()
                ->withErrors(['contact_id' => 'The selected contact does not belong to the selected client.'])
                ->withInput();
        }

        $siteId = $this->resolveSiteId($clientId, $data['site_id'] ?? null, $contactId, $data['asset_id'] ?? null);
        $assetId = empty($data['asset_id'])
            ? null
            : $this->validateAssetScope($data['asset_id'], $clientId, $contactId, $siteId)->id;

        $updateTicketFields->handle($ticket, [
            'subject' => $data['subject'],
            'description' => $data['description'] ?? null,
            'queue_id' => $queue->id,
            'priority_id' => $priority->id,
            'category_id' => $categoryId,
            'client_id' => $clientId,
            'contact_id' => $contactId,
            'owner_id' => $data['owner_id'] ?? null,
            'site_id' => $siteId,
            'asset_id' => $assetId,
        ], $request->user());

        $changeTicketStatus->handle($ticket->refresh(), $status, $request->user());
        $ticket->tags()->syncWithPivotValues($this->resolveTagIds($data['tag_names'] ?? []), ['module' => 'ticket']);

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Ticket updated.');
    }

    public function close(Request $request, Ticket $ticket, CloseTicket $closeTicket, TicketActionGuard $actionGuard): RedirectResponse
    {
        if ($reason = $actionGuard->reason($ticket, TicketAction::CLOSE, $request->user())) {
            return back()->withErrors(['ticket' => $reason]);
        }

        $closeTicket->handle($ticket, $request->user());

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Ticket closed.');
    }

    public function transition(Request $request, Ticket $ticket, TicketWorkflowTransition $transition, ChangeTicketStatus $changeTicketStatus, TicketWorkflowRuntime $workflowRuntime): RedirectResponse
    {
        abort_unless((int) $transition->from_status_id === (int) $ticket->status_id, 404);

        $transition->loadMissing('toStatus');

        if ($reason = $workflowRuntime->manualBlockedReason($ticket, $transition)) {
            return back()->withErrors(['status_id' => $reason]);
        }

        $changeTicketStatus->handle($ticket, $transition->toStatus, $request->user());

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Workflow transition completed.');
    }

    public function requestDocumentation(Request $request, Ticket $ticket): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        TicketEvent::query()->create([
            'ticket_id' => $ticket->id,
            'actor_id' => $request->user()?->id,
            'type' => 'documentation_requested',
            'message' => $data['reason'] ?? 'Documentation follow-up requested from ticket.',
            'metadata' => [
                'ticket_key' => $ticket->ticket_key,
                'category_id' => $ticket->category_id,
                'client_id' => $ticket->client_id,
                'source' => 'ticket_show',
            ],
        ]);

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Documentation follow-up was created.');
    }

    public function markRead(Request $request, Ticket $ticket, MarkTicketRead $markTicketRead): RedirectResponse
    {
        $markTicketRead->handle($ticket, $request->user());

        return redirect()->route('tech.tickets.show', $ticket)
            ->with('success', 'Ticket marked as read.');
    }

    public function markMessageRead(Request $request, Ticket $ticket, TicketMessage $message): RedirectResponse
    {
        abort_unless((int) $message->ticket_id === (int) $ticket->id, 404);

        DB::transaction(function () use ($ticket, $message, $request) {
            $wasUnread = blank($message->read_at);

            if ($wasUnread) {
                $message->forceFill(['read_at' => now()])->save();
            }

            // Keep the ticket unread until every customer/contact reply has been handled.
            $hasUnreadCustomerReplies = $ticket->messages()
                ->where('author_type', 'contact')
                ->whereNull('read_at')
                ->exists();

            $ticket->forceFill([
                'is_unread' => $hasUnreadCustomerReplies,
                'updated_by' => $request->user()?->id,
            ])->save();

            if ($wasUnread) {
                TicketEvent::create([
                    'ticket_id' => $ticket->id,
                    'actor_id' => $request->user()?->id,
                    'type' => 'message_marked_read',
                    'message' => 'Ticket reply marked as read.',
                    'after' => [
                        'message_id' => $message->id,
                        'is_unread' => $hasUnreadCustomerReplies,
                    ],
                ]);
            }
        });

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Reply marked as read.');
    }

    public function markMessageSolution(Request $request, Ticket $ticket, TicketMessage $message, MarkTicketMessageSolution $markSolution): RedirectResponse
    {
        $markSolution->handle($ticket, $message, $request->user());

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', 'Response marked as solution.');
    }

    public function assign(Request $request, Ticket $ticket, TicketAssignmentEngine $assignmentEngine, TicketActionGuard $actionGuard): RedirectResponse
    {
        if ($reason = $actionGuard->reason($ticket, TicketAction::ASSIGN_OWNER, $request->user())) {
            return back()->withErrors(['assignment' => $reason]);
        }

        $previousOwnerId = $ticket->owner_id;
        $ownerId = $assignmentEngine->assign($ticket, force: true);

        if (! $ownerId) {
            return redirect()->route('tech.tickets.show', $ticket->refresh())
                ->withErrors(['assignment' => 'No matching assignment rule or available ticket assignment setting was found.']);
        }

        $message = $previousOwnerId === $ownerId
            ? 'Ticket assignment already matched the current owner.'
            : 'Ticket assignment updated.';

        return redirect()->route('tech.tickets.show', $ticket->refresh())
            ->with('success', $message);
    }

    public function mergeSelected(Request $request, MergeTickets $mergeTickets): RedirectResponse
    {
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:2',
            'ticket_ids.*' => 'integer|exists:tickets,id',
            'target_ticket_id' => 'required|integer|exists:tickets,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        $ticketIds = collect($data['ticket_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $targetId = (int) $data['target_ticket_id'];

        if (! $ticketIds->contains($targetId)) {
            return back()->withErrors(['target_ticket_id' => 'The primary ticket must be one of the selected tickets.']);
        }

        if ($ticketIds->count() < 2) {
            return back()->withErrors(['ticket_ids' => 'Select at least two tickets to merge.']);
        }

        $tickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->whereNull('merged_into_ticket_id')
            ->get()
            ->keyBy('id');

        if ($tickets->count() !== $ticketIds->count()) {
            return back()->withErrors(['ticket_ids' => 'One or more selected tickets are no longer available for merging.']);
        }

        $target = $tickets->get($targetId);

        if (! $target) {
            return back()->withErrors(['target_ticket_id' => 'The primary ticket was not found.']);
        }

        try {
            foreach ($tickets->except($target->id) as $source) {
                $target = $mergeTickets->handle($source, $target, $request->user(), $data['reason'] ?? null);
            }
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['ticket_ids' => $exception->getMessage()]);
        }

        return redirect()->route('tech.tickets.show', $target)
            ->with('success', 'Merged '.($tickets->count() - 1).' ticket(s) into '.$target->ticket_key.'.');
    }

    public function dismissMergeSuggestion(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ticket_ids' => 'required|array|min:2',
            'ticket_ids.*' => 'integer|exists:tickets,id',
            'reason' => 'nullable|string|max:255',
        ]);

        $ticketIds = collect($data['ticket_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $tickets = Ticket::query()
            ->whereIn('id', $ticketIds)
            ->get();

        if ($tickets->count() !== $ticketIds->count()) {
            return back()->withErrors(['ticket_ids' => 'One or more tickets could not be found.']);
        }

        for ($left = 0; $left < $tickets->count(); $left++) {
            for ($right = $left + 1; $right < $tickets->count(); $right++) {
                TicketMergeSuggestionDismissal::updateOrCreate(
                    TicketMergeSuggestionDismissal::pairIds($tickets[$left], $tickets[$right]),
                    [
                        'dismissed_by' => $request->user()?->id,
                        'reason' => $data['reason'] ?? 'Dismissed from ticket list.',
                    ]
                );
            }
        }

        return back()->with('success', 'Merge suggestion dismissed.');
    }

    public function markNotTicket(Request $request, Ticket $ticket, MarkTicketAsNotTicket $markTicketAsNotTicket): RedirectResponse
    {
        try {
            $emailCount = $markTicketAsNotTicket->handle($ticket, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return redirect()->route('tech.tickets.show', $ticket)
                ->withErrors(['not_ticket' => $exception->getMessage()]);
        }

        return redirect()->route('tech.tickets.index')
            ->with('success', 'Ticket '.$ticket->ticket_key.' returned to Inbox. '.$emailCount.' email(s) tagged as not-ticket.');
    }

    public function destroy(Request $request, Ticket $ticket): RedirectResponse
    {
        DB::transaction(function () use ($ticket, $request) {
            TicketEvent::create([
                'ticket_id' => $ticket->id,
                'actor_id' => $request->user()?->id,
                'type' => 'deleted',
                'message' => 'Ticket deleted from the ticket list.',
                'before' => [
                    'ticket_key' => $ticket->ticket_key,
                    'subject' => $ticket->subject,
                ],
            ]);

            $ticket->forceFill([
                'updated_by' => $request->user()?->id,
            ])->save();

            $ticket->delete();
        });

        return redirect()->route('tech.tickets.index')
            ->with('success', 'Ticket '.$ticket->ticket_key.' deleted.');
    }

    private function technicians()
    {
        $requestedRoles = ['ticket.admin', 'ticket.agent', 'ticket.view', 'tech.admin', 'Superuser', 'Admin', 'Tech'];
        $existingRoles = Role::whereIn('name', $requestedRoles)->pluck('name')->all();

        $query = ! empty($existingRoles)
            ? User::role($existingRoles)
            : User::query();

        $technicians = $query
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $currentUser = auth()->user();

        if ($currentUser?->isActive() && ! $technicians->contains('id', $currentUser->id)) {
            $technicians->push($currentUser);
        }

        return $technicians->sortBy('name')->values();
    }

    private function storageItemOptions(): Collection
    {
        return StorageItem::query()
            ->with(['warehouse', 'box'])
            ->where('status', 'active')
            ->orderBy('name')
            ->get()
            ->map(fn (StorageItem $item) => [
                'id' => $item->id,
                'label' => $item->name . ($item->sku ? ' (' . $item->sku . ')' : ''),
                'available' => $item->qty_available,
                'sale_price' => $item->sale_price,
                'short_description' => $item->short_description,
                'location' => trim(($item->warehouse?->name ?? 'No warehouse') . ($item->box ? ' / ' . $item->box->code_human : '')),
            ]);
    }

    private function timeInvoiceDraftPrompt(Ticket $ticket, ?string $existingText = null, ?array $rateOption = null): string
    {
        $messages = $ticket->messages
            ->sortByDesc('created_at')
            ->take(10)
            ->reverse()
            ->map(function (TicketMessage $message) {
                $author = $message->author_type === 'contact'
                    ? 'Customer'
                    : ($message->author?->name ?? 'Technician');

                return '['.$message->type.' / '.$message->visibility.'] '.$author.': '.Str::limit(trim((string) $message->body), 1200);
            })
            ->implode("\n");
        $previousTimeTexts = $ticket->timeEntries
            ->sortByDesc('created_at')
            ->take(8)
            ->map(function ($entry) {
                return '- '.$entry->minutes.' min / '.($entry->rate_name ?: 'unknown rate').': '.Str::limit(trim((string) ($entry->invoice_text ?: $entry->note)), 500);
            })
            ->filter(fn (string $line) => filled(trim($line, "- \t\n\r\0\x0B")))
            ->implode("\n");

        $rateText = strtolower(implode(' ', array_filter([
            $rateOption['label'] ?? null,
            $rateOption['rate_name'] ?? null,
            $rateOption['rate_code'] ?? null,
            $rateOption['rate_type'] ?? null,
        ])));
        $isTravelRate = Str::contains($rateText, ['driving', 'drive', 'travel', 'kjøring', 'kjoring', 'reise']);

        return trim(implode("\n\n", array_filter([
            'Write one short invoice/work description for registered ticket time.',
            'Return only the concrete work performed. No greeting, no markdown, no bullet list.',
            'Do not include ticket numbers, customer names, "billing", "invoice", or phrases like "registered time".',
            'Prefer a compact noun phrase such as "oppdatering av skjermkortdriver og Steam-konfigurasjon".',
            'Do not copy a previous time entry unless the selected rate and current draft clearly describe the same work.',
            'If an existing draft is provided, improve or normalize that draft instead of ignoring it.',
            $isTravelRate ? 'CRITICAL: The selected rate is driving/travel. Return a driving/travel description only. Do not mention technical work such as drivers, Steam, troubleshooting, repair, configuration, or updates.' : null,
            $isTravelRate ? 'Good examples for this selected rate: "kjøring til og fra kunde", "reise i forbindelse med supportoppdrag", "kjøring til kunde".' : null,
            ! $isTravelRate ? 'If the selected rate is technical labor, describe the technical work performed.' : null,
            'Use the same language as the technician work when obvious.',
            $rateOption ? 'Selected time rate: '.$rateOption['label'].' (type: '.($rateOption['rate_type'] ?? 'unknown').', unit: '.($rateOption['rate_unit'] ?? 'unknown').')' : null,
            'Ticket: '.$ticket->ticket_key,
            'Subject: '.$ticket->subject,
            'Client: '.($ticket->client?->name ?? 'Unknown'),
            'Contact: '.($ticket->contact?->name ?? 'Unknown'),
            'Description: '.Str::limit(trim((string) $ticket->description), 1500),
            filled($existingText) ? 'Existing draft: '.$existingText : null,
            filled($previousTimeTexts) ? "Previous registered time texts on this ticket:\n".$previousTimeTexts : null,
            filled($messages) ? "Recent replies and notes:\n".$messages : null,
        ])));
    }

    private function replyContactOptions(Ticket $ticket): Collection
    {
        if (! $ticket->client_id) {
            return $ticket->contact ? collect([$ticket->contact]) : collect();
        }

        return ClientUser::query()
            ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
            ->where('active', true)
            ->whereNotNull('email')
            ->orderByDesc('is_default_for_client')
            ->orderBy('name')
            ->get(['id', 'client_site_id', 'name', 'email']);
    }

    private function ccContactSuggestions(Ticket $ticket): Collection
    {
        $suggestions = collect();
        $seenEmails = [];

        $addSuggestion = function (?string $email, ?string $name, string $group, ?string $site = null) use (&$suggestions, &$seenEmails): void {
            $email = trim((string) $email);

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return;
            }

            $key = Str::lower($email);

            if (isset($seenEmails[$key])) {
                return;
            }

            $seenEmails[$key] = true;
            $suggestions->push([
                'email' => $email,
                'name' => filled($name) ? $name : $email,
                'group' => $group,
                'site' => $site,
            ]);
        };

        if ($ticket->client_id) {
            ClientUser::query()
                ->with('site:id,name,client_id')
                ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
                ->where('active', true)
                ->whereNotNull('email')
                ->get(['id', 'client_site_id', 'name', 'email'])
                ->sortBy(fn (ClientUser $contact): string => Str::lower(($contact->site?->name ?? '').'|'.$contact->name.'|'.$contact->email))
                ->each(fn (ClientUser $contact) => $addSuggestion($contact->email, $contact->name, 'Client contacts', $contact->site?->name));

            $this->clientContactSuggestions($ticket)
                ->each(function (Contact $contact) use ($addSuggestion): void {
                    $contact->emails
                        ->sortByDesc('is_primary')
                        ->each(fn ($email) => $addSuggestion($email->email, $contact->display_name, 'Client contacts', $contact->metadata['site_name'] ?? null));
                });
        }

        Contact::query()
            ->with(['emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('email')])
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('do_not_email', false)->orWhereNull('do_not_email'))
            ->whereHas('emails')
            ->orderBy('display_name')
            ->limit(100)
            ->get()
            ->each(function (Contact $contact) use ($addSuggestion): void {
                $contact->emails
                    ->sortByDesc('is_primary')
                    ->each(fn ($email) => $addSuggestion($email->email, $contact->display_name, 'Global contacts'));
            });

        return $suggestions->take(80)->values();
    }

    private function clientContactSuggestions(Ticket $ticket): Collection
    {
        if (! $ticket->client_id) {
            return collect();
        }

        $clientType = (new Client())->getMorphClass();
        $siteType = (new ClientSite())->getMorphClass();
        $siteNames = ClientSite::query()
            ->where('client_id', $ticket->client_id)
            ->pluck('name', 'id');

        return Contact::query()
            ->with([
                'emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('email'),
                'relations',
            ])
            ->where('status', 'active')
            ->where(fn ($query) => $query->where('do_not_email', false)->orWhereNull('do_not_email'))
            ->whereHas('emails')
            ->whereHas('relations', function ($query) use ($ticket, $clientType, $siteType, $siteNames): void {
                $query->where(function ($nested) use ($ticket, $clientType, $siteType, $siteNames): void {
                    $nested->where(function ($clientQuery) use ($ticket, $clientType): void {
                        $clientQuery->where('related_type', $clientType)
                            ->where('related_id', $ticket->client_id);
                    })->orWhere(function ($siteQuery) use ($siteType, $siteNames): void {
                        $siteQuery->where('related_type', $siteType)
                            ->whereIn('related_id', $siteNames->keys());
                    });
                });
            })
            ->orderBy('display_name')
            ->limit(100)
            ->get()
            ->map(function (Contact $contact) use ($siteType, $siteNames): Contact {
                $siteRelation = $contact->relations
                    ->where('related_type', $siteType)
                    ->first();

                $metadata = $contact->metadata ?? [];
                $metadata['site_name'] = $siteRelation ? $siteNames->get($siteRelation->related_id) : null;
                $contact->metadata = $metadata;

                return $contact;
            });
    }

    private function contactBelongsToClient(int $contactId, int $clientId): bool
    {
        return ClientUser::whereKey($contactId)
            ->whereHas('site', fn ($query) => $query->where('client_id', $clientId))
            ->exists();
    }

    private function ticketCategories(): Collection
    {
        return Category::query()
            ->active()
            ->where(fn ($query) => $query
                ->where('type', Category::TYPE_TICKET)
                ->orWhereNull('type')
            )
            ->orderBy('name')
            ->get();
    }

    private function ticketCategoryId(int $categoryId): int
    {
        return $this->ticketCategories()
            ->firstWhere('id', $categoryId)
            ?->id
            ?? abort(422, 'Invalid ticket category.');
    }

    private function activeTags(): Collection
    {
        return Tag::where('active', true)->orderBy('name')->get();
    }

    private function resolveTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->map(fn (?string $name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => Str::lower($name))
            ->map(function (string $name) {
                return Tag::query()->firstOrCreate(
                    ['slug' => Str::slug($name)],
                    [
                        'name' => $name,
                        'active' => true,
                    ],
                )->id;
            })
            ->values()
            ->all();
    }

    private function ticketCategoryRule(): Exists
    {
        return Rule::exists('categories', 'id')
            ->where(fn ($query) => $query
                ->where('type', Category::TYPE_TICKET)
                ->orWhereNull('type')
            )
            ->where('is_active', true);
    }

    private function assetOptions(?int $clientId, ?int $contactId = null, ?int $siteId = null)
    {
        if (! $clientId) {
            return collect();
        }

        if ($contactId) {
            $contact = ClientUser::with('site')->find($contactId);

            if (! $contact || (int) $contact->site?->client_id !== (int) $clientId) {
                return collect();
            }

            $contactAssets = Asset::query()
                ->where('client_id', $clientId)
                ->where('user_id', $contact->id)
                ->orderBy('name')
                ->get()
                ->map(fn (Asset $asset) => $this->assetOption($asset, 'Contact assets'));

            $siteAssets = Asset::query()
                ->where('client_id', $clientId)
                ->where('site_id', $contact->client_site_id)
                ->when($contactAssets->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $contactAssets->pluck('id')))
                ->orderBy('name')
                ->get()
                ->map(fn (Asset $asset) => $this->assetOption($asset, 'Site assets'));

            return $contactAssets->concat($siteAssets)->values();
        }

        return Asset::query()
            ->where('client_id', $clientId)
            ->when($siteId, fn ($query) => $query->where('site_id', $siteId))
            ->orderBy('name')
            ->get()
            ->map(fn (Asset $asset) => $this->assetOption($asset, $siteId ? 'Site assets' : 'Client assets'))
            ->values();
    }

    private function assetOption(Asset $asset, string $group): array
    {
        $details = array_filter([$asset->hostname, $asset->serial_number, $asset->ip_address]);

        return [
            'id' => $asset->id,
            'group' => $group,
            'label' => $asset->name . ($details ? ' - ' . implode(' / ', $details) : ''),
        ];
    }

    private function validateAssetScope(int $assetId, ?int $clientId, ?int $contactId = null, ?int $siteId = null): Asset
    {
        $asset = Asset::where('client_id', $clientId)->findOrFail($assetId);

        if ($contactId) {
            $contact = ClientUser::with('site')->findOrFail($contactId);

            abort_unless(
                $asset->user_id === $contact->id || $asset->site_id === $contact->client_site_id,
                422,
                'The selected asset is not linked to the selected contact or site.'
            );
        }

        if ($siteId) {
            abort_unless(
                ($contactId && (int) $asset->user_id === (int) $contactId) || (int) $asset->site_id === (int) $siteId,
                422,
                'The selected asset is not linked to the selected site.'
            );
        }

        return $asset;
    }

    private function resolveSiteId(?int $clientId, ?int $siteId = null, ?int $contactId = null, ?int $assetId = null): ?int
    {
        if (! $clientId) {
            return null;
        }

        if ($contactId) {
            return ClientUser::whereKey($contactId)
                ->whereHas('site', fn ($query) => $query->where('client_id', $clientId))
                ->value('client_site_id');
        }

        if ($siteId) {
            return ClientSite::where('client_id', $clientId)->findOrFail($siteId)->id;
        }

        if ($assetId) {
            return Asset::where('client_id', $clientId)->findOrFail($assetId)->site_id;
        }

        $sites = ClientSite::where('client_id', $clientId)->get(['id', 'is_default']);

        if ($sites->count() === 1) {
            return $sites->first()->id;
        }

        return $sites->firstWhere('is_default', true)?->id;
    }
}
