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
use App\Modules\Task\Models\Task;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    /**
     * Display a paginated list of clients.
     *
     * This method resets any active client or site context in the session
     * and allows searching for clients by name, organization number, or email.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        // -----------------------------------------
        // Reset active client ID in session
        // -----------------------------------------
        session()->forget(['active_client_id', 'active_site_id']);

        $query = Client::query()->with(['clientFormat', 'riskAssessments.items']);

        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('clients.name', 'like', "%$search%");
                $q->orWhere('clients.org_no', 'like', "%$search%");
                $q->orWhere('clients.billing_email', 'like', "%$search%");
            });
        }

        $sort = $request->get('sort', 'name');
        $direction = $request->get('direction', 'asc') === 'desc' ? 'desc' : 'asc';
        $sortableColumns = [
            'name' => 'clients.name',
            'org_no' => 'clients.org_no',
            'format' => 'client_formats.code',
            'billing_email' => 'clients.billing_email',
            'status' => 'clients.active',
        ];

        if (! array_key_exists($sort, $sortableColumns)) {
            $sort = 'name';
        }

        if ($sort === 'format') {
            $query->leftJoin('client_formats', 'client_formats.id', '=', 'clients.client_format_id')
                ->select('clients.*');
        }

        // -----------------------------------------
        // Get Clients
        // -----------------------------------------
        $clients = $query
            ->orderBy($sortableColumns[$sort], $direction)
            ->orderBy('clients.name')
            ->paginate(25)
            ->withQueryString();

        // -----------------------------------------
        // Retun View:
        // -----------------------------------------
        return view('clients::Tech.index', [
            'clients' => $clients,
            'search' => $search,
            'sort' => $sort,
            'direction' => $direction,
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
    public function show(Client $client)
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
