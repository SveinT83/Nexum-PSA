<?php

namespace App\Http\Controllers\Tech\Clients;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Http\Requests\Tech\Clients\SiteRequest;
use App\Service\SideBarMenus\ClientsMenu;
use Illuminate\Http\Request;

class ClientSiteController extends Controller
{

    // -----------------------------------------
    // INDEX - List all sites for a client
    // -----------------------------------------
    /**
     * Display a paginated list of sites.
     *
     * Sites can be filtered by the active client in the session.
     * If no client is active, it displays all sites.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request)
    {
        // 1. URL overstyrer alt. Hvis URL er tom, sjekk sesjon.
        $clientId = session('active_client_id');

        // 2. Finn kunden
        $targetClient = $clientId ? Client::find($clientId) : null;

        // 3. Bygg spørring (viser alt hvis $targetClient er null)
        $query = $targetClient ? $targetClient->sites() : ClientSite::query();

        // 4. Legg til søk hvis det finnes
        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%$search%");
        }

        $sites = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('tech.clients.sites.index', [
            'sites' => $sites,
            'client' => $targetClient, // Dette er nå enten Client-objektet eller null
            'search' => $search,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($targetClient)
        ]);
    }

    // -----------------------------------------
    // SHOW - Show a single site for a client
    // -----------------------------------------
    /**
     * Display the details of a specific site.
     *
     * Sets the site as the "active site" in the session and eager loads
     * relations for better performance.
     *
     * @param ClientSite $site
     * @return View
     */
    public function show(ClientSite $site) {

        // -----------------------------------------
        // Set the active site ID in session
        // -----------------------------------------
        session(['active_site_id' => $site->id]);

        // Eager load relasjoner for bedre ytelse
        $site->load(['client', 'contacts']);

        //Return view
        return view('tech.clients.sites.show', [
            'site' => $site,
            'client' => $site->client,
            'users' => $site->contacts,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($site->client),
        ]);
    }

    // -----------------------------------------
    // EDIT - Edit a single site for a client
    // -----------------------------------------
    /**
     * Show the form for editing an existing site.
     *
     * @param ClientSite $site
     * @return View
     */
    public function edit(ClientSite $site) {

        // -----------------------------------------
        // Load data we need
        // -----------------------------------------
        $site->load(['client']);

        return view('tech.clients.sites.form', [
            'site' => $site,
            'client' => $site->client,
            'allClients' => collect(),
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($site->client),
        ]);
    }

    // -----------------------------------------
    // CREATE - Create a new site for a client
    // -----------------------------------------
    /**
     * Show the form for creating a new site.
     *
     * If a client ID is provided, the site will be pre-linked to that client.
     * Otherwise, the user will be presented with a list of all clients to choose from.
     *
     * @param int|null $client
     * @return View
     */
    public function create($client = null) {

        // -----------------------------------------
        // Try to find the Client
        // -----------------------------------------
        $targetClient = $client ? Client::find($client) : null;

        // -----------------------------------------
        // If no Client, then vi pass them all for an selection in  the form.
        // -----------------------------------------
        $allClients = !$targetClient ? Client::orderBy('name')->get() : collect();

        return view('tech.clients.sites.form', [
            'site' => new ClientSite(),
            'client' => $targetClient,
            'allClients' => $allClients,
            'sidebarMenuItems' => (new ClientsMenu())->ClientsMenu($targetClient),
        ]);
    }

    // -----------------------------------------
    // STORE - Store a new sites for a client
    // -----------------------------------------
    /**
     * Store a newly created site in the database.
     *
     * @param SiteRequest $request
     * @param int|null $client
     * @return RedirectResponse
     */
    public function store(SiteRequest $request, $client = null) {

        // -----------------------------------------
        // Validate request via FormRequest
        // -----------------------------------------
        $data = $request->validated();

        // -----------------------------------------
        // Get Client from URL or from request
        // -----------------------------------------
        $clientId = $client ?: $request->input('client_id');

        $targetClient = Client::findOrFail($clientId);

        // -----------------------------------------
        //Save the form to DB
        // -----------------------------------------
        $site = $targetClient->sites()->create($data);

        // -----------------------------------------
        //Redirect wiew whit message
        // -----------------------------------------
        return redirect()->route('tech.clients.sites.show', $site)
            ->with('success', 'Site created successfully.');
    }

    // -----------------------------------------
    // UPDATE - Updates a single sites for a client
    // -----------------------------------------
    /**
     * Update an existing site in the database.
     *
     * @param ClientSite $site
     * @param SiteRequest $request
     * @return RedirectResponse
     */
    public function update(ClientSite $site, SiteRequest $request) {

        // -----------------------------------------
        // Validate request via FormRequest
        // -----------------------------------------
        $data = $request->validated();

        // -----------------------------------------
        //Update the site in the database
        // -----------------------------------------
        $site->update($data);

        // -----------------------------------------
        //Redirect with message
        // -----------------------------------------
        return redirect()->route('tech.clients.sites.show', $site)
            ->with('success', 'Site updated successfully.');
    }

    // -----------------------------------------
    // Destroy - Delete a sites
    // -----------------------------------------
    /**
     * Remove a site from the database.
     *
     * @param ClientSite $site
     * @return RedirectResponse
     */
    public function destroy(ClientSite $site)
    {
        $site->delete();

        return redirect()->back()->with('status', 'Site deleted successfully.');
    }
}
