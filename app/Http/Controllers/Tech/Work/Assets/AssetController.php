<?php

namespace App\Http\Controllers\Tech\Work\Assets;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use Illuminate\Http\Request;

/**
 * AssetController
 *
 * Håndterer visning og administrasjon av Assets (eiendeler) i systemet.
 * Støtter både global oversikt og klient-spesifikk filtrering.
 */
class AssetController extends Controller
{
    /**
     * Viser oversikt over assets.
     *
     * Kan filtreres på klient (via URL eller forespørsel), type og status.
     *
     * @param Request $request
     * @param Client|null $client Valgfri klient fra rute-parameter
     * @return \Illuminate\View\View
     */
    public function index(Request $request, $client = null)
    {
        // Handle $client being passed as an ID or as a Model
        if ($client && !($client instanceof Client)) {
            $client = Client::find($client);
        }

        // 1. Initialiser spørring med relasjoner for å unngå N+1 problemer
        $query = Asset::query()->with(['client', 'site', 'user', 'vendorRelation']);

        // 2. Filtrering basert på rute-parameter (klient-sidevisning)
        if ($client && $client->exists) {
            $query->where('client_id', $client->id);
        }

        // 3. Dynamisk filtrering fra filter-skjemaet
        if ($request->filled('client_id')) {
            $query->where('client_id', $request->client_id);
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('has_alerts')) {
            $query->whereHas('alerts', function($q) {
                $q->where('status', 'active');
            });
        }

        // 4. Hent data med paginering
        $assets = $query->latest()->paginate(25);
        $clients = Client::orderBy('name')->get();

        return view('tech.assets.index', compact('assets', 'clients', 'client'));
    }

    /**
     * Viser skjema for å opprette en ny asset.
     * Bruker Livewire-komponenten AssetForm for selve logikken.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        $clientId = $request->get('client_id');
        $siteId = $request->get('site_id');

        return view('tech.assets.create', compact('clientId', 'siteId'));
    }

    /**
     * Lagrer en ny asset.
     * Merk: Vanligvis håndtert av Livewire, men beholdt som fallback.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'user_id' => 'nullable|exists:client_users,id',
            'vendor_id' => 'nullable|exists:vendors,id',
            'name' => 'required|string|max:255',
            'type' => 'required|in:server,pc,laptop,switch,ap,firewall,other',
            'vendor' => 'nullable|string|max:255',
            'model' => 'nullable|string|max:255',
            'serial_number' => 'nullable|string|max:255',
            'mac_address' => 'nullable|string|max:255',
            'ip_address' => 'nullable|ip',
            'ip_type' => 'required|in:dhcp,fixed',
            'hostname' => 'nullable|string|max:255',
        ]);

        $asset = Asset::create($validated);

        return redirect()->route('tech.assets.show', $asset->id)
            ->with('success', 'Asset created successfully.');
    }

    /**
     * Viser detaljer om en spesifikk asset.
     *
     * @param Asset $asset
     * @return \Illuminate\View\View
     */
    public function show(Asset $asset, $tab = 'summary')
    {
        // Last inn nødvendige relasjoner for detaljvisning, inkludert alerts
        $asset->load(['client', 'site', 'user', 'vendorRelation', 'alerts']);

        // Hent alle alerts knyttet til denne asseten
        // Vi bruker relasjonen 'alerts' som er definert i Asset-modellen
        $outages = $asset->alerts;

        return view('tech.assets.show', compact('asset', 'tab', 'outages'));
    }

    /**
     * Serverer dokumentasjonsfilen for Assets.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function docs()
    {
        $path = resource_path('views/tech/assets/assets.md');

        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'text/markdown',
        ]);
    }

    /**
     * Viser skjema for redigering av en asset.
     * Bruker Livewire-komponenten AssetForm.
     *
     * @param Asset $asset
     * @return \Illuminate\View\View
     */
    public function edit(Asset $asset)
    {
        return view('tech.assets.edit', compact('asset'));
    }

    /**
     * Oppdaterer en eksisterende asset.
     * Logikken her håndteres primært av Livewire-komponenten.
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Asset $asset)
    {
        // Fallback ruting hvis Livewire ikke brukes
        return redirect()->route('tech.assets.show', $asset->id);
    }
}
