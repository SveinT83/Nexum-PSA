<?php

namespace App\Modules\Documentation\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Documentation\Menus\SideBar\DocumentationsMenu;
use App\Modules\Documentation\Models\Documentation;
use App\Modules\Documentation\Models\DocumentationTemplate;
use Illuminate\Http\Request;

class DocumentationController extends Controller
{
    /**
     * Display a listing of documentations.
     *
     * Fetches documentations with filters for category (via 'cat' query param)
     * and global context (active client, active site, or internal-only scope)
     * stored in the session.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function index(Request $request)
    {
        // Fetch sidebar navigation items based on documentation categories
        $sidebarMenuItems = (new DocumentationsMenu())->DocumentationsMenu();

        // Get all active clients to populate the context selector dropdown
        $clients = Client::where('active', true)->orderBy('name')->get();

        // Initialize query with common relationships
        $query = Documentation::with(['category', 'client', 'site', 'template']);

        // Filter by category if specified. 'cat' can be an ID or a Slug for flexibility.
        $selectedCategory = null;
        if ($cat = $request->get('cat')) {
            if (in_array($cat, ['vendors', 'suppliers'], true)) {
                return app(VendorController::class)->index($request, $cat);
            }

            if ($cat !== 'all') {
                $selectedCategory = Category::where(function ($q) use ($cat) {
                    $q->where('id', $cat)->orWhere('slug', $cat);
                })->first();

                if ($selectedCategory && in_array($selectedCategory->slug, ['vendors', 'suppliers'], true)) {
                    return app(VendorController::class)->index($request, $selectedCategory->slug);
                }

                if ($selectedCategory) {
                    $query->where('category_id', $selectedCategory->id);
                }
            }
        }

        // Apply global context filters from session:
        // 1. Specific Client (and optionally Sites)
        // 2. Or "Internal Only" records
        // 3. Otherwise, show everything (if none of the above are set)
        if ($activeClientId = session('active_client_id')) {
            $query->where('client_id', $activeClientId);

            if ($activeSiteId = session('active_site_id')) {
                $query->where('site_id', $activeSiteId);
            }
        } elseif (session('only_internal')) {
            $query->where('scope_type', 'internal');
        }

        // If 'exclude_internal' is passed, ensure internal documents are hidden.
        // This is typically used when navigating from the Clients context.
        if ($request->has('exclude_internal')) {
            $query->where('scope_type', '!=', 'internal');
        }

        $search = trim((string) $request->input('q', ''));
        if ($search !== '') {
            $query->where(function ($query) use ($search): void {
                $query->where('title', 'like', "%{$search}%")
                    ->orWhere('scope_type', 'like', "%{$search}%")
                    ->orWhereHas('category', fn ($categoryQuery) => $categoryQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('site', fn ($siteQuery) => $siteQuery->where('name', 'like', "%{$search}%"))
                    ->orWhereHas('template', fn ($templateQuery) => $templateQuery->where('name', 'like', "%{$search}%"));
            });
        }

        $documentations = $query->orderBy('updated_at', 'desc')->paginate(20)->withQueryString();

        return view('documentation::Tech.index', compact('sidebarMenuItems', 'clients', 'documentations', 'selectedCategory', 'search'));
    }

    /**
     * Set the global context (active client/site) and persist it in the session.
     *
     * This method handles POST requests from the context selector component.
     * After updating the session, it redirects back to the previous URL while
     * carefully preserving existing query parameters (like 'cat') to maintain
     * the user's navigational state.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function setContext(Request $request)
    {
        $clientId = $request->get('active_client_id');

        // Update Client/Internal session state
        if ($clientId) {
            if ($clientId == 'none') {
                // Clear all filters
                session()->forget(['active_client_id', 'active_site_id', 'only_internal']);
            } elseif ($clientId == 'internal') {
                // Filter to internal documentation only
                session(['only_internal' => true]);
                session()->forget(['active_client_id', 'active_site_id']);
            } else {
                // Filter to a specific client
                session(['active_client_id' => $clientId]);
                session()->forget(['active_site_id', 'only_internal']);
            }
        }

        // Update Sites session state (if applicable)
        if ($siteId = $request->get('active_site_id')) {
            if ($siteId == 'none') {
                session()->forget('active_site_id');
            } else {
                session(['active_site_id' => $siteId]);
                session()->forget('only_internal');
            }
        }

        session()->save();

        // Reconstruct the previous URL and preserve query parameters (especially 'cat')
        $previousUrl = url()->previous();
        $query = parse_url($previousUrl, PHP_URL_QUERY);
        parse_str($query, $params);

        // If 'cat' was passed in the request, prioritize it over the previous URL's 'cat'
        if ($request->has('cat')) {
            $params['cat'] = $request->get('cat');
        }

        // Clean base URL and re-attach query string
        $redirectUrl = strtok($previousUrl, '?');
        if (!empty($params)) {
            $redirectUrl .= '?' . http_build_query($params);
        }

        return redirect($redirectUrl);
    }

    /**
     * Show the form for creating a new documentation record.
     *
     * Automatically fetches the appropriate template fields if a category is selected.
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function create(Request $request)
    {
        // Default placeholders
        $fields = [];
        $formView = null;

        $categories = Category::where('is_active', true)
            ->whereHas('templates', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('name')
            ->get();
        $clients = Client::where('active', true)->orderBy('name')->get();

        // Populate sites if a client is already active in the session
        $sites = [];
        if (session('active_client_id')) {
            $sites = \App\Models\Clients\ClientSite::where('client_id', session('active_client_id'))->orderBy('name')->get();
        }

        $cat = $request->cat;

        // Load the category and its active template to determine which fields to render
        $selectedCategory = Category::where('is_active', true)
            ->where(function ($query) use ($cat) {
                $query->where('id', $cat)
                    ->orWhere('slug', $cat);
            })->first();

        if ($selectedCategory) {
            $cat = $selectedCategory->id;

            // Documentation templatesManagement define the dynamic fields (stored as JSON in the database)
            $template = DocumentationTemplate::where('category_id', $selectedCategory->id)
                ->where('is_active', true)
                ->first();

            if ($template) {
                $fields = $template->fields; // Automatically cast from JSON
                $formView = "Template: " . $template->name;
            }
        }

        $sidebarMenuItems = (new DocumentationsMenu())->DocumentationsMenu();

        return view('documentation::Tech.create', [
            'sidebarMenuItems' => $sidebarMenuItems,
            'cat' => $cat,
            'categories' => $categories,
            'clients' => $clients,
            'sites' => $sites,
            'active_client_id' => session('active_client_id'),
            'active_site_id' => session('active_site_id'),
            'formView' => $formView,
            'fields' => $fields,
        ]);
    }

    /**
     * Store a newly created documentation record in storage.
     *
     * Captures a snapshot of the template fields at the moment of creation.
     * Dynamic data from the form is stored in a JSON column.
     *
     * @param Request $request
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        $request->validate([
            'category_id' => 'required|exists:categories,id',
            'client_id' => 'nullable|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'title' => 'required|string|max:255',
        ]);

        // Fetch the template to snapshot its current field definitions
        $template = DocumentationTemplate::where('category_id', $request->category_id)
            ->where('is_active', true)
            ->firstOrFail();

        $templateSnapshot = $template->fields;

        // Capture all form inputs as document data, excluding metadata fields
        $data = $request->except(['_token', 'category_id', 'client_id', 'site_id', 'title', 'scope_type']);

        // Determine the visibility scope based on which fields are populated
        $scopeType = 'internal';
        if ($request->site_id) {
            $scopeType = 'site';
        } elseif ($request->client_id) {
            $scopeType = 'client';
        }

        $documentation = Documentation::create([
            'template_id' => $template->id,
            'category_id' => $request->category_id,
            'client_id' => $request->client_id,
            'site_id' => $request->site_id,
            'title' => $request->title,
            'scope_type' => $scopeType,
            'template_snapshot_json' => $templateSnapshot, // Essential for rendering if the original template changes later
            'data_json' => $data,
        ]);

        return redirect()->route('tech.documentations.show', $documentation->id)
            ->with('success', 'Documentation saved successfully.');
    }

    /**
     * Show the form for editing the specified documentation record.
     *
     * Uses the captured 'template_snapshot_json' to render the form,
     * ensuring historical data remains editable even if the parent template evolves.
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function edit($id)
    {
        $documentation = Documentation::with(['category', 'client', 'site', 'template'])->findOrFail($id);
        $sidebarMenuItems = (new DocumentationsMenu())->DocumentationsMenu();

        $categories = Category::where('is_active', true)
            ->whereHas('templates', function ($query) {
                $query->where('is_active', true);
            })
            ->orderBy('name')
            ->get();
        $clients = Client::where('active', true)->orderBy('name')->get();

        $sites = [];
        if ($documentation->client_id) {
            $sites = \App\Models\Clients\ClientSite::where('client_id', $documentation->client_id)->orderBy('name')->get();
        }

        // Use snapshot to ensure form matches the data structure saved during creation/previous update
        $fields = $documentation->template_snapshot_json ?? [];
        $data = $documentation->data_json ?? [];

        return view('documentation::Tech.edit', [
            'sidebarMenuItems' => $sidebarMenuItems,
            'documentation' => $documentation,
            'categories' => $categories,
            'clients' => $clients,
            'sites' => $sites,
            'fields' => $fields,
            'data' => $data,
        ]);
    }

    /**
     * Update the specified documentation record in storage.
     *
     * Note: This only updates metadata and data fields.
     * The template structure (snapshot) is preserved as it was at creation.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function update(Request $request, $id)
    {
        $documentation = Documentation::findOrFail($id);

        $request->validate([
            'client_id' => 'nullable|exists:clients,id',
            'site_id' => 'nullable|exists:client_sites,id',
            'title' => 'required|string|max:255',
        ]);

        // Capture all dynamic form inputs as updated data
        $data = $request->except(['_token', '_method', 'category_id', 'client_id', 'site_id', 'title', 'scope_type']);

        // Recalculate scope if client/site changed
        $scopeType = 'internal';
        if ($request->site_id) {
            $scopeType = 'site';
        } elseif ($request->client_id) {
            $scopeType = 'client';
        }

        $documentation->update([
            'client_id' => $request->client_id,
            'site_id' => $request->site_id,
            'title' => $request->title,
            'scope_type' => $scopeType,
            'data_json' => $data,
        ]);

        return redirect()->route('tech.documentations.show', $documentation->id)
            ->with('success', 'Documentation updated successfully.');
    }

    /**
     * Display the specified documentation record.
     *
     * Uses a dedicated "rendered" view that maps data_json to labels in template_snapshot_json.
     *
     * @param int $id
     * @return \Illuminate\View\View
     */
    public function show($id)
    {
        $documentation = Documentation::with(['category', 'client', 'site', 'template'])->findOrFail($id);
        $sidebarMenuItems = (new DocumentationsMenu())->DocumentationsMenu();

        return view('documentation::Tech.show', [
            'sidebarMenuItems' => $sidebarMenuItems,
            'documentation' => $documentation,
        ]);
    }

    /**
     * Remove the specified documentation record from storage.
     *
     * @param int $id
     * @return \Illuminate\Http\RedirectResponse
     */
    public function destroy($id)
    {
        $documentation = Documentation::findOrFail($id);
        $documentation->delete();

        return redirect()->route('tech.documentations.index')
            ->with('success', 'Documentation deleted successfully.');
    }
}
