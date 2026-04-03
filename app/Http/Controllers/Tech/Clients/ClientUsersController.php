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
        // 1. Sjekk sesjon for aktiv site eller client
        $siteId = session('active_site_id');
        $clientId = session('active_client_id');

        // 2. Bygg spørringen basert på det mest spesifikke vi har
        if ($siteId && $targetSite = ClientSite::find($siteId)) {
            $query = $targetSite->contacts(); // Bruk contacts() relasjonen fra ClientSite
        } elseif ($clientId && $targetClient = Client::find($clientId)) {
            $query = $targetClient->contacts(); // Bruk hasManyThrough relasjonen fra Client
            $targetSite = null;
        } else {
            $query = ClientUser::query();
            $targetSite = null;
            $targetClient = null;
        }

        // 3. Legg til søk hvis det finnes
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        // 4. Hent resultater (Kjør paginate kun ÉN gang)
        $users = $query->with(['site.client', 'user'])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // 5. Returner view
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
    public function edit(User $user, Site $site, Client $client) {

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
    public function store() {

    }

    // ----------------------------------------------------------------------------------
    // UPDATE - Updates a single user for a client
    // -----------------------------------------
    public function update(User $user) {

    }

}
