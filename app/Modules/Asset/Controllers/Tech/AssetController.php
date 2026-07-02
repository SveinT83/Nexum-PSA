<?php

namespace App\Modules\Asset\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Actions\StoreAsset;
use App\Modules\Asset\Actions\UpdateAsset;
use App\Modules\Asset\Queries\AssetQuery;
use Illuminate\Http\Request;

/**
 * Tech-facing controller for the Asset domain.
 *
 * The module deliberately keeps the existing public route names
 * (`tech.assets.*` and `tech.clients.assets.index`) while moving the
 * implementation into `app/Modules/Asset`. That lets menus, links, and saved
 * bookmarks continue to work during the domain migration.
 */
class AssetController extends Controller
{
    /**
     * Display the global or client-scoped asset list.
     *
     * `$client` may arrive as a route-bound model from `/clients/{client}/assets`
     * or as an ID from the legacy `/clients/assets/{client?}` route. Supporting
     * both shapes prevents the module move from changing existing URLs abruptly.
     *
     * @param Request $request
     * @param Client|null $client Valgfri klient fra rute-parameter
     * @return \Illuminate\View\View
     */
    public function index(Request $request, $client = null)
    {
        // Normalize the optional client context before building the list query.
        if ($client && !($client instanceof Client)) {
            $client = Client::find($client);
        }

        $assets = app(AssetQuery::class)->paginateForTechIndex($request, $client);
        $clients = Client::orderBy('name')->get();

        return view('asset::Tech.index', compact('assets', 'clients', 'client'));
    }

    /**
     * Show the create screen.
     *
     * The form is handled by the module Livewire component. Query parameters are
     * preserved so Client and Site pages can preselect their context.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        $clientId = $request->get('client_id');
        $siteId = $request->get('site_id');

        return view('asset::Tech.create', compact('clientId', 'siteId'));
    }

    /**
     * Persist a new asset through the non-Livewire fallback route.
     *
     * Livewire is the primary UX path, but keeping this route functional gives
     * the module a plain HTTP fallback and keeps feature tests simple.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $asset = app(StoreAsset::class)->handle($request);

        return redirect()->route('tech.assets.show', $asset->id)
            ->with('success', 'Asset created successfully.');
    }

    /**
     * Display a single asset and the selected detail tab.
     *
     * @param Asset $asset
     * @return \Illuminate\View\View
     */
    public function show(Asset $asset, $tab = 'summary')
    {
        // Load the relations needed by summary, ownership, vendor, and alert tabs.
        $asset->load(['client', 'workContext', 'site', 'user', 'vendorRelation', 'alerts']);

        // Existing view code expects this variable name for alert/outage output.
        $outages = $asset->alerts;

        return view('asset::Tech.show', compact('asset', 'tab', 'outages'));
    }

    /**
     * Serve the module README-style Markdown used by the in-app documentation tab.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function docs()
    {
        $path = app_path('Modules/Asset/Docs/legacy-view-specs/Tech/assets.md');

        if (!file_exists($path)) {
            abort(404);
        }

        return response()->file($path, [
            'Content-Type' => 'text/markdown',
        ]);
    }

    /**
     * Show the edit screen. The save behavior is handled by AssetForm.
     *
     * @param Asset $asset
     * @return \Illuminate\View\View
     */
    public function edit(Asset $asset)
    {
        return view('asset::Tech.edit', compact('asset'));
    }

    /**
     * Non-Livewire update fallback.
     *
     * The full update path lives in the Livewire component. This method keeps the
     * route contract valid if a plain form ever posts to it.
     *
     * @param Request $request
     * @param Asset $asset
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, Asset $asset)
    {
        app(UpdateAsset::class)->handle($request, $asset);

        return redirect()->route('tech.assets.show', $asset->id);
    }
}
