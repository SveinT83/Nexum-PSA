<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Models\Clients\ClientUser;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Service\SideBarMenus\ClientsMenu;
use Illuminate\Http\Request;
//Users

class ClientUsersController extends Controller
{
    // ----------------------------------------------------------------------------------
    // INDEX - List all users for a client or a site or all
    // ----------------------------------------------------------------------------------
    /**
     * Display a paginated list of client users (contacts).
     *
     * The list is filtered based on the active site or client in the session.
     * If neither is set, it returns all client users.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request)
    {
        // 1. Check session for active site or client
        $siteId = session('active_site_id');
        $clientId = session('active_client_id');

        // 2. Build the query based on the most specific context we have
        if ($siteId && $targetSite = ClientSite::find($siteId)) {
            $query = $targetSite->contacts(); // Use contacts() relation from ClientSite
        } elseif ($clientId && $targetClient = Client::find($clientId)) {
            $query = $targetClient->contacts(); // Use hasManyThrough relation from Client
            $targetSite = null;
        } else {
            $query = ClientUser::query();
            $targetSite = null;
            $targetClient = null;
        }

        // 3. Add search if it exists
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        // 4. Get results (Run paginate only ONCE)
        $users = $query->with(['site.client', 'user'])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // 5. Return view
        return view('tech.clients.users.index', [
            'users' => $users,
            'site' => $targetSite,
            'client' => $targetClient ?? ($targetSite ? $targetSite->client : null),
            'search' => $search,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($targetClient ?? ($targetSite ? $targetSite->client : null)),
        ]);
    }

    // ----------------------------------------------------------------------------------
    // SHOW - Show a single user for a client
    // ----------------------------------------------------------------------------------
    /**
     * Display the details of a specific client user.
     *
     * @param ClientUser $ClientUser
     * @return View
     */
    public function show(ClientUser $ClientUser) {

        // -----------------------------------------
        // Get client data from user's site
        // -----------------------------------------
        $targetClient = $ClientUser->site->client ?? null;

        // -----------------------------------------
        // Return view with user data and context
        // -----------------------------------------
        return view('tech.clients.users.show', [
            'user' => $ClientUser,
            'client' => $targetClient,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($targetClient),
        ]);
    }

    // ----------------------------------------------------------------------------------
    // EDIT - Edit a single user for a client
    // ----------------------------------------------------------------------------------
    /**
     * Show the form for editing an existing client user.
     *
     * @param ClientUser $ClientUser
     * @return View
     */
    public function edit(ClientUser $ClientUser) {

        // -----------------------------------------
        // Get client data from user's site
        // -----------------------------------------
        $client = $ClientUser->site->client ?? null;

        // -----------------------------------------
        // Get all sites for client
        // -----------------------------------------
        $sites = $client ? $client->sites : null;

        return view('tech.clients.users.form', [
            'user' => $ClientUser,
            'client' => $client,
            'sites' => $sites,
            'activeSite' => $ClientUser->site,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($client),
        ]);
    }

    // ----------------------------------------------------------------------------------
    // CREATE - Create a new user for a client
    // ----------------------------------------------------------------------------------
    /**
     * Show the form for creating a new user for a specific client.
     *
     * @param Client $client
     * @return View
     */
    public function create(Client $client) {

        // -----------------------------------------
        // Get active site from session if available
        // -----------------------------------------
        $ActiveSite = session('active_site_id');

        // -----------------------------------------
        // Get active site data
        // -----------------------------------------
        $ActiveSite = $ActiveSite ? ClientSite::find($ActiveSite) : null;

        // -----------------------------------------
        // Get all sites for client
        // -----------------------------------------
        $sites = $client ? $client->sites : null;

        return view('tech.clients.users.form', [
            'client' => $client,
            'sites' => $sites,
            'activeSite' => $ActiveSite,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($client),
        ]);
    }

    // ----------------------------------------------------------------------------------
    // STORE - Store a new user for a client
    // ----------------------------------------------------------------------------------
    /**
     * Store a newly created client user in storage.
     *
     * @param Request $request
     * @param Client $client
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, Client $client)
    {
        // 1. Validate the request
        $validated = $request->validate([
            'client_site_id' => 'required|exists:client_sites,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'co_address' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'county' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
        ]);

        // 2. Create the ClientUser
        // Note: user_id is nullable in the migration, we don't handle linked system users here yet
        $clientUser = new ClientUser($validated);
        $clientUser->save();

        // 3. Redirect back to the user view with success message
        return redirect()->route('tech.clients.user.show', $clientUser->id)
            ->with('success', 'User created successfully.');
    }

    // ----------------------------------------------------------------------------------
    // UPDATE - Updates a single user for a client
    // -----------------------------------------
    /**
     * Update the specified client user in storage.
     *
     * @param Request $request
     * @param ClientUser $ClientUser
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, ClientUser $ClientUser) {
        // 1. Validate the request
        $validated = $request->validate([
            'client_site_id' => 'required|exists:client_sites,id',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'phone' => 'nullable|string|max:20',
            'role' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:255',
            'co_address' => 'nullable|string|max:255',
            'zip' => 'nullable|string|max:20',
            'city' => 'nullable|string|max:100',
            'county' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:100',
            'language' => 'nullable|string|max:10',
        ]);

        // 2. Update the ClientUser
        $ClientUser->update($validated);

        // 3. Redirect back to the user view with success message
        return redirect()->route('tech.clients.user.show', $ClientUser->id)
            ->with('success', 'User updated successfully.');
    }

}
