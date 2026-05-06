<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tech\Clients\RedirectResponse;
use App\Http\Controllers\Tech\Clients\View;
use App\Http\Requests\Tech\Clients\SiteRequest;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\ClientRmmLink;
use App\Models\System\Integrations\Integration;
use App\Modules\Clients\Menus\SideBar\ClientsMenu;
use App\Services\Integrations\NAbleRmm\NAbleRmmClient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
     * @param int|null $client
     * @return View
     */
    public function index(Request $request, $client = null)
    {
        // 1. URL overstyrer alt. Hvis URL er tom, sjekk sesjon.
        $clientId = $client ?: session('active_client_id');

        // 2. Finn kunden
        $targetClient = $clientId ? Client::find($clientId) : null;

        // 3. Update session if we have a client from URL
        if ($client && $targetClient) {
            session(['active_client_id' => $targetClient->id]);
        }

        // 3. Bygg spørring (viser alt hvis $targetClient er null)
        $query = $targetClient ? $targetClient->sites() : ClientSite::query();

        // 4. Legg til søk hvis det finnes
        if ($search = $request->get('search')) {
            $query->where('name', 'like', "%$search%");
        }

        $sites = $query->orderBy('name')->paginate(25)->withQueryString();

        return view('Tech.Sites.index', [
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
        return view('Tech.Sites.show', [
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

        return view('Tech.Sites.form', [
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

        // Check if N-able RMM is active
        $nableActive = \App\Models\System\Integrations\Integration::where('type', 'rmm')->where('status', 'active')->exists();

        return view('Tech.Sites.form', [
            'site' => new ClientSite(),
            'client' => $targetClient,
            'allClients' => $allClients,
            'nableActive' => $nableActive,
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

        $warning = null;

        // -----------------------------------------
        //Save the form to DB
        // -----------------------------------------
        $site = DB::transaction(function () use ($targetClient, $data, &$warning) {
            $site = $targetClient->sites()->create($data);

            // Handle N-able RMM creation if requested
            if (!empty($data['create_in_rmm'])) {
                $integration = Integration::where('type', 'rmm')->where('status', 'active')->first();
                $clientLink = $targetClient->rmmLinks()->where('integration_id', $integration?->id)->first();

                if ($integration && $clientLink) {
                    $rmmClient = new NAbleRmmClient($integration);
                    $result = $rmmClient->addSite($clientLink->external_id, $site->name);
                    $status = $result['success'] ? 'success' : 'error';

                    if ($status === 'success' && !empty($result['siteid'])) {
                        // Create RMM Link
                        ClientRmmLink::create([
                            'integration_id' => $integration->id,
                            'external_id' => $result['siteid'],
                            'linkable_type' => ClientSite::class,
                            'linkable_id' => $site->id,
                        ]);
                    } else {
                        $warning = "Sites created locally, but failed to create in N-able RMM: " . ($result['error'] ?? 'Unknown error');
                    }
                }
            }

            return $site;
        });

        // -----------------------------------------
        //Redirect wiew whit message
        // -----------------------------------------
        $response = redirect()->route('tech.clients.sites.show', $site)
            ->with('success', 'Sites created successfully.');

        if ($warning) {
            $response->with('warning', $warning);
        }

        return $response;
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
            ->with('success', 'Sites updated successfully.');
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

        return redirect()->back()->with('status', 'Sites deleted successfully.');
    }
}
