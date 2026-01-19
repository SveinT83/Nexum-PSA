<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\User;
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
    public function index(Request $request, $client = null, $site = null)
    {
        // -----------------------------------------
        // 1. Determine target scope - client, site, or all users
        // Support both null and 'all' string from menu links
        // -----------------------------------------
        $targetClient = ($client && $client !== 'all') ? Client::find($client) : null;
        $targetSite = ($site && $site !== 'all') ? ClientSite::find($site) : null;

        // -----------------------------------------
        // 2. Build query based on scope hierarchy
        // Priority: Site > Client > All
        // -----------------------------------------
        if ($targetSite) {
            // Scope to specific site's users
            $query = $targetSite->contacts();
        } elseif ($targetClient) {
            // Scope to all users across client's sites via hasManyThrough
            $query = $targetClient->contacts();
        } else {
            // Show all users across all clients
            $query = ClientUser::query();
        }

        // -----------------------------------------
        // 3. Apply search filter if provided
        // -----------------------------------------
        if ($search = $request->get('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%$search%")
                    ->orWhere('email', 'like', "%$search%");
            });
        }

        // -----------------------------------------
        // 4. Fetch paginated results with relationships
        // -----------------------------------------
        $users = $query->with(['site.client', 'user'])
            ->orderBy('name')
            ->paginate(25)
            ->withQueryString();

        // -----------------------------------------
        // Return view with user data and context
        // -----------------------------------------
        return view('tech.clients.users.index', [
            'users' => $users,
            'client' => $targetClient,
            'site' => $targetSite,
            'search' => $search,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($targetClient),
        ]);
    }

    // ----------------------------------------------------------------------------------
    // SHOW - Show a single user for a client
    // ----------------------------------------------------------------------------------
    public function show(User $user) {

    }

    // ----------------------------------------------------------------------------------
    // EDIT - Edit a single user for a client
    // ----------------------------------------------------------------------------------
    public function edit(User $user, Site $site, Client $client) {

    }

    // ----------------------------------------------------------------------------------
    // CREATE - Create a new user for a client
    // ----------------------------------------------------------------------------------
    public function create(Client $client) {

        return view('tech.clients.users.form', [
            'client' => $client,
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
