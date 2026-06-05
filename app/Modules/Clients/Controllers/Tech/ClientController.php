<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\Clients\ClientRequest;
use App\Models\Clients\Client;
use App\Models\Clients\ClientFormat;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Actions\CreateClientWithDefaults;
use App\Modules\Clients\Actions\SuggestClientNumber;
use App\Modules\Clients\Menus\SideBar\ClientsMenu;
use App\Models\Core\User;
use App\Modules\CustomField\Support\CustomFieldPresenter;
use App\Modules\Task\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    private const CLIENT_FILTER_SESSION_KEY = 'clients.index.filters';
    private const CLIENT_FILTER_TTL_MINUTES = 120;

    /**
     * Display a paginated list of clients.
     *
     * This method resets any active client or site context in the session
     * and allows searching for clients by name, organization number, or email.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View|RedirectResponse
    {
        // -----------------------------------------
        // Reset active client ID in session
        // -----------------------------------------
        session()->forget(['active_client_id', 'active_site_id']);

        if ($request->boolean('clear_filters')) {
            session()->forget(self::CLIENT_FILTER_SESSION_KEY);

            return redirect()->route('tech.clients.index');
        }

        $filterKeys = ['search', 'status', 'format_id', 'contract_filter', 'rmm_filter'];
        $hasIncomingFilters = collect($filterKeys)->contains(fn (string $key): bool => $request->has($key));
        $storedFilters = session(self::CLIENT_FILTER_SESSION_KEY);

        if (! $hasIncomingFilters && is_array($storedFilters ?? null)) {
            $storedAt = $storedFilters['stored_at'] ?? null;
            $isExpired = ! $storedAt || now()->diffInMinutes($storedAt) > self::CLIENT_FILTER_TTL_MINUTES;

            if ($isExpired) {
                session()->forget(self::CLIENT_FILTER_SESSION_KEY);
                $filters = array_fill_keys($filterKeys, null);
            } else {
                $filters = array_merge(array_fill_keys($filterKeys, null), $storedFilters['filters'] ?? []);
            }
        } else {
            $filters = array_merge(array_fill_keys($filterKeys, null), $request->only($filterKeys));
            $filters = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $filters);

            session([
                self::CLIENT_FILTER_SESSION_KEY => [
                    'filters' => $filters,
                    'stored_at' => now(),
                ],
            ]);
        }

        $query = Client::query()
            ->select('clients.*')
            ->with(['clientFormat', 'riskAssessments.items'])
            ->withCount(['sites', 'contacts', 'contracts']);

        if ($search = $filters['search']) {
            $query->where(function($q) use ($search) {
                $q->where('clients.name', 'like', "%$search%");
                $q->orWhere('clients.client_number', 'like', "%$search%");
                $q->orWhere('clients.org_no', 'like', "%$search%");
                $q->orWhere('clients.billing_email', 'like', "%$search%");
            });
        }

        if (($filters['status'] ?? '') === 'active') {
            $query->where('clients.active', true);
        } elseif (($filters['status'] ?? '') === 'inactive') {
            $query->where('clients.active', false);
        }

        if (filled($filters['format_id'] ?? null)) {
            $query->where('clients.client_format_id', (int) $filters['format_id']);
        }

        if (($filters['contract_filter'] ?? '') === 'without_contract') {
            $query->doesntHave('contracts');
        } elseif (($filters['contract_filter'] ?? '') === 'with_contract') {
            $query->has('contracts');
        } elseif (($filters['contract_filter'] ?? '') === 'won_contract') {
            $query->whereHas('contracts', fn ($contracts) => $contracts->where('approval_status', 'won'));
        }

        $rmmIntegration = Integration::query()->where('type', 'rmm')->first();
        if ($rmmIntegration && ($filters['rmm_filter'] ?? '') === 'linked') {
            $query->whereHas('rmmLinks', fn ($links) => $links->where('integration_id', $rmmIntegration->id));
        } elseif ($rmmIntegration && ($filters['rmm_filter'] ?? '') === 'unlinked') {
            $query->whereDoesntHave('rmmLinks', fn ($links) => $links->where('integration_id', $rmmIntegration->id));
        }

        $sort = $request->get('sort', 'name');
        $direction = $request->get('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $sortableColumns = [
            'name' => 'clients.name',
            'client_number' => 'clients.client_number',
            'org_no' => 'clients.org_no',
            'format' => 'client_formats.code',
            'billing_email' => 'clients.billing_email',
            'status' => 'clients.active',
            'contracts' => 'contracts_count',
        ];

        if (! array_key_exists($sort, $sortableColumns)) {
            $sort = 'name';
        }

        if ($sort === 'format') {
            $query->leftJoin('client_formats', 'client_formats.id', '=', 'clients.client_format_id');
        }

        // -----------------------------------------
        // Get Clients
        // -----------------------------------------
        $clients = $query
            ->orderBy($sortableColumns[$sort], $direction)
            ->orderBy('clients.name')
            ->paginate(25)
            ->withQueryString();

        $activeFilterCount = collect($filters)
            ->filter(fn ($value): bool => filled($value))
            ->count();

        // -----------------------------------------
        // Retun View:
        // -----------------------------------------
        return view('clients::Tech.index', [
            'clients' => $clients,
            'filters' => $filters,
            'search' => $filters['search'],
            'sort' => $sort,
            'direction' => $direction,
            'clientFormats' => ClientFormat::activeOptions(),
            'activeFilterCount' => $activeFilterCount,
            'rmmIntegration' => $rmmIntegration,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu(null),
        ]);
    }

    /**
     * Display the details of a specific client.
     *
     * This method sets the client as the "active client" in the session,
     * eager-loads risk assessment data for the rightbar widget,
     * and prepares the sidebar menu based on this client context.
     *
     * @param Client $client
     * @return View
     */
    public function show(Client $client, Request $request, CustomFieldPresenter $customFields)
    {
        // -----------------------------------------
        // Set the active client ID in session
        // -----------------------------------------
        session(['active_client_id' => $client->id]);

        // -----------------------------------------
        // Client Contract (s)
        // -----------------------------------------
        $contracts = $client->contracts()->with('items')->latest('updated_at')->get();

        // -----------------------------------------
        // Eager load risk assessments and items
        // to avoid N+1 queries in the rightbar widget.
        // -----------------------------------------
        $client->load(['clientFormat', 'sites', 'riskAssessments.items', 'tasks.status', 'tasks.assignee', 'tasks.checklistItems', 'tasks.timeEntries']);

        $clientTaskOwners = [
            [$client->getMorphClass(), [$client->id]],
        ];

        $ticketIds = \App\Modules\Ticket\Models\Ticket::query()
            ->where('client_id', $client->id)
            ->pluck('id');

        if ($ticketIds->isNotEmpty()) {
            $ticket = new \App\Modules\Ticket\Models\Ticket();
            $clientTaskOwners[] = [$ticket->getMorphClass(), $ticketIds->all()];
        }

        $clientTasks = Task::query()
            ->with(['status', 'assignee', 'checklistItems', 'timeEntries'])
            ->where(function ($query) use ($clientTaskOwners) {
                foreach ($clientTaskOwners as [$ownerType, $ownerIds]) {
                    $query->orWhere(fn ($ownerQuery) => $ownerQuery
                        ->where('owner_type', $ownerType)
                        ->whereIn('owner_id', $ownerIds));
                }
            })
            ->where(function ($query) {
                $query->whereNull('completed_at')
                    ->whereDoesntHave('status', fn ($status) => $status
                        ->where('is_done', true)
                        ->orWhere('is_cancelled', true));
            })
            ->latest('updated_at')
            ->get();

        // -----------------------------------------
        // Array of sidebar menu items
        // -----------------------------------------
        $sidebarMenuItems = (new ClientsMenu())->ClientsMenu($client);

        return view('clients::Tech.show', [
            'client' => $client,
            'sidebarMenuItems' => $sidebarMenuItems,
            'contracts' => $contracts,
            'technicians' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(),
            'clientTasks' => $clientTasks,
            'customFields' => $customFields->visibleFor($client, $request->user()),

        ]);
    }

    /**
     * Show the form for creating a new client.
     *
     * Provides suggested client numbers and default configuration options.
     *
     * @return View
     */
    public function create(SuggestClientNumber $suggestClientNumber): View
    {
        // Simple lookup lists (can be moved to config or DB later)
        $roles = ['Daglig leder', 'Innehaver', 'IT-kontakt', 'Økonomi', 'Annet'];
        $countries = ['NO' => 'Norway', 'SE' => 'Sweden', 'DK' => 'Denmark'];

        // Check if RMM is active
        $rmmIntegration = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->first();
        $nableActive = $rmmIntegration !== null;

        return view('clients::Tech.create', [
            'suggestedClientNumber' => $suggestClientNumber->handle(),
            'clientFormats' => ClientFormat::activeOptions(),
            'roles' => $roles,
            'countries' => $countries,
            'nableActive' => $nableActive,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu(null),
        ]);
    }

    /**
     * Store a newly created client in the database.
     *
     * This operation is wrapped in a transaction to ensure that the client,
     * its default site, and its default user are all created successfully.
     *
     * @param ClientRequest $request
     * @return RedirectResponse
     */
    public function store(ClientRequest $request, CreateClientWithDefaults $createClient): RedirectResponse
    {
        $result = $createClient->handle($request->validated());

        $response = redirect()->route('tech.clients.index')->with('status', 'Client created');
        if ($result['warning']) {
            $response->with('warning', $result['warning']);
        }

        return $response;
    }
}
