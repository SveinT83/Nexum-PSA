<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Http\Requests\Tech\Clients\ClientRequest;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Service\SideBarMenus\ClientsMenu;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
    public function index(Request $request)
    {
        // -----------------------------------------
        // Reset active client ID in session
        // -----------------------------------------
        session()->forget(['active_client_id', 'active_site_id']);

        $query = Client::query()->with(['riskAssessments.items']);

        if ($search = $request->get('search')) {
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%$search%");
                $q->orWhere('org_no', 'like', "%$search%");
                $q->orWhere('billing_email', 'like', "%$search%");
            });
        }

        $clients = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('tech.clients.index', [
            'clients' => $clients,
            'search' => $search,
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
        // Eager load risk assessments and items
        // to avoid N+1 queries in the rightbar widget.
        // -----------------------------------------
        $client->load(['riskAssessments.items']);

        // -----------------------------------------
        // Array of sidebar menu items
        // -----------------------------------------
        $sidebarMenuItems = (new ClientsMenu())->ClientsMenu($client);

        return view('tech.clients.show', [
            'client' => $client,
            'sidebarMenuItems' => $sidebarMenuItems
        ]);
    }

    /**
     * Show the form for creating a new client.
     *
     * Provides suggested client numbers and default configuration options.
     *
     * @return View
     */
    public function create(): View
    {
        // Suggest the next client number (5 digits)
        $maxNumber = Client::query()->max('client_number');
        $suggestedClientNumber = str_pad((string) (((int) $maxNumber) + 1), 5, '0', STR_PAD_LEFT);

        // Simple lookup lists (can be moved to config or DB later)
        $roles = ['Daglig leder', 'Innehaver', 'IT-kontakt', 'Økonomi', 'Annet'];
        $countries = ['NO' => 'Norway', 'SE' => 'Sweden', 'DK' => 'Denmark'];

        return view('tech.clients.create', [
            'suggestedClientNumber' => $suggestedClientNumber,
            'roles' => $roles,
            'countries' => $countries,
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
    public function store(ClientRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data): void {
            // Create client
            $client = Client::query()->create([
                'name' => $data['name'],
                'client_number' => $data['client_number'],
                'org_no' => $data['org_no'] ?? null,
                'billing_email' => $data['billing_email'] ?? null,
                'notes' => $data['notes'] ?? null,
                'active' => $data['active'] ?? true,
            ]);

            // Create default sites
            $site = ClientSite::query()->create([
                'client_id' => $client->id,
                'name' => $data['site_name'],
                'is_default' => true,
            ]);

            // Create default sites user
            ClientUser::query()->create([
                'client_site_id' => $site->id,
                'user_id' => null,
                'role' => $data['user_role'] ?? null,
                'name' => $data['user_name'],
                'email' => $data['user_email'] ?? null,
                'phone' => $data['user_phone'] ?? null,
                'is_default_for_site' => true,
                'is_default_for_client' => true,
                'active' => true,
            ]);
        });

        return redirect()->route('tech.clients.index')->with('status', 'Client created');
    }
}
