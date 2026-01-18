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
    public function index(Request $request, $client = null)
    {
        // 1. Finn ut om vi har en spesifikk klient eller om vi skal vise alt
        // Vi støtter både null og strengen 'all' fra meny-lenker
        $targetClient = ($client && $client !== 'all') ? Client::find($client) : null;

        // 2. Bygg spørringen basert på om vi har en klient eller ikke
        $query = $targetClient
            ? $targetClient->sites()
            : ClientSite::query();

        // 3. Legg til søk hvis det finnes
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
    public function show(ClientSite $site) {

        // -----------------------------------------
        // Array of sidebar menu items
        // -----------------------------------------
        $sidebarMenuItems = (new ClientsMenu())->ClientsMenu(null);

        // Eager load relasjoner for bedre ytelse
        $site->load(['client', 'contacts']);

        //Return view
        return view('tech.clients.sites.show', [
            'site' => $site,
            'client' => $site->client,
            'users' => $site->contacts,
            'sidebarMenuItems' => $sidebarMenuItems
        ]);
    }

    // -----------------------------------------
    // EDIT - Edit a single site for a client
    // -----------------------------------------
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
    public function destroy(ClientSite $site)
    {
        $site->delete();

        return redirect()->back()->with('status', 'Site deleted successfully.');
    }
}
