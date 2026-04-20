<?php

namespace App\Http\Controllers\Tech\Risk;

use App\Http\Controllers\Controller;
use App\Models\Risk\RiskAssessment;
use Illuminate\Http\Request;
use App\Models\Clients\Client;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use App\Models\System\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Dompdf\Dompdf;
use Dompdf\Options;

class RiskController extends Controller
{
    /**
     * Display a list of risk assessments.
     *
     * The list is filtered based on the current session context:
     * - Only internal: returns assessments where client_id is NULL.
     * - Specific client: returns assessments for the active_client_id.
     * - Default (none of above): returns all assessments.
     *
     * @param Request $request
     * @return View
     */
    public function index(Request $request): View
    {
        // Get all active clients to populate the context selector dropdown
        $clients = Client::where('active', true)->orderBy('name')->get();

        // Apply filters based on session context (similar to DocController)
        $query = RiskAssessment::query();

        if (session('only_internal')) {
            $query->whereNull('client_id');
        } elseif (session('active_client_id')) {
            $query->where('client_id', session('active_client_id'));
        }

        $assessments = $query->with('items')->orderBy('created_at', 'desc')->paginate(20);

        return view('tech.Risk.index', compact('clients', 'assessments'));
    }

    /**
     * Show the form for creating a new risk assessment.
     *
     * Provides a list of active clients for selection if the assessment is not internal.
     *
     * @return View
     */
    public function create(): View
    {
        $clients = Client::where('active', true)->orderBy('name')->get();

        return view('tech.Risk.create', compact('clients'));
    }

    /**
     * Store a newly created risk assessment in storage.
     *
     * Assessments can be scoped as 'internal' or linked to a specific 'client'.
     * Default status is set to 'new'.
     *
     * @param Request $request
     * @return RedirectResponse
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'client_id' => 'nullable|exists:clients,id',
            'scope' => 'required|in:internal,client',
        ]);

        $assessment = new RiskAssessment();
        $assessment->title = $validated['title'];
        $assessment->description = $validated['description'];
        $assessment->status = 'new'; // Default status

        // Handle client vs internal scope mapping
        if ($validated['scope'] === 'internal') {
            $assessment->client_id = null;
        } else {
            $assessment->client_id = $validated['client_id'];
        }

        $assessment->save();

        return redirect()->route('tech.risk.show', $assessment)
            ->with('success', 'Risk assessment created successfully.');
    }

    /**
     * Display the specified risk assessment.
     *
     * Eager loads related items and client details for efficient rendering.
     *
     * @param RiskAssessment $risk
     * @return View
     */
    public function show(RiskAssessment $risk): View
    {
        $risk->load(['items.category', 'client']);

        // Group items by category. Items without category go to 'Uncategorized'
        $groupedItems = $risk->items->groupBy(function($item) {
            return $item->category ? $item->category->name : 'Uncategorized';
        });

        $categories = Category::orderBy('name')->get();

        return view('tech.Risk.show', compact('risk', 'groupedItems', 'categories'));
    }

    /**
     * Store a new risk item for the specified risk assessment.
     *
     * This creates an initial risk entry. It also automatically transitions
     * the parent assessment status from 'new' to 'in_progress'.
     *
     * @param Request $request
     * @param RiskAssessment $risk
     * @return RedirectResponse
     */
    public function storeItem(Request $request, RiskAssessment $risk): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'recommended_actions' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'likelihood' => 'required|integer|min:1|max:5',
            'impact' => 'required|integer|min:1|max:5',
            'status' => 'required|string|max:255',
        ]);

        $item = DB::transaction(function () use ($validated, $risk) {
            $item = new \App\Models\Risk\RiskItem($validated);
            $item->risk_assessment_id = $risk->id;
            $item->save();

            // Create the history record
            $update = new RiskItemUpdate([
                'risk_item_id' => $item->id,
                'created_by' => Auth::id(),
                'note' => 'Initial risk identified',
                'status' => $item->status,
                'likelihood' => $item->likelihood,
                'impact' => $item->impact,
            ]);
            $update->save();

            return $item;
        });

        // Update assessment status to in_progress if it was still in 'new' state
        if ($risk->status === 'new') {
            $risk->status = 'in_progress';
            $risk->save();
        }

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Risk item added successfully.',
                'item' => $item
            ]);
        }

        return redirect()->back()
            ->with('success', 'Risk item added successfully.');
    }

    /**
     * Display the specified risk item detail page.
     *
     * Shows current state, update history, and linked entities.
     *
     * @param RiskItem $item
     * @return View
     */
    public function showItem(RiskItem $item): View
    {
        $item->load(['assessment.client', 'updates.creator', 'links']);
        $categories = \App\Models\System\Category::where('type', 'risk_item')->orderBy('name')->get();
        if ($categories->isEmpty()) {
            $categories = \App\Models\System\Category::orderBy('name')->get();
        }
        return view('tech.Risk.items.show', compact('item', 'categories'));
    }

    /**
     * Store a new update for the specified risk item.
     *
     * This method handles the 'living document' workflow:
     * 1. Creates a RiskItemUpdate record for historical auditing.
     * 2. Updates the main RiskItem with the latest likelihood, impact, and status.
     * 3. Recalculates risk score (via RiskItem boot method).
     * 4. Updates parent assessment status to 'in_progress' if needed.
     *
     * All changes are wrapped in a DB transaction to ensure consistency between
     * history and the current state snapshot.
     *
     * @param Request $request
     * @param RiskItem $item
     * @return RedirectResponse
     */
    public function storeItemUpdate(Request $request, RiskItem $item): RedirectResponse
    {
        $validated = $request->validate([
            'note' => 'required|string',
            'status' => 'required|string|in:open,mitigated,accepted',
            'likelihood' => 'nullable|integer|min:1|max:5',
            'impact' => 'nullable|integer|min:1|max:5',
            'next_review_at' => 'nullable|date',
        ]);

        DB::transaction(function () use ($validated, $item) {
            // Create the history record
            $update = new RiskItemUpdate([
                'risk_item_id' => $item->id,
                'created_by' => Auth::id(),
                'note' => $validated['note'],
                'status' => $validated['status'],
                'likelihood' => $validated['likelihood'] ?? $item->likelihood,
                'impact' => $validated['impact'] ?? $item->impact,
            ]);
            $update->save();

            // Update the main risk item with current state for easier access/listing
            $item->status = $update->status;
            $item->likelihood = $update->likelihood;
            $item->impact = $update->impact;
            // Note: score is automatically calculated in the boot method of RiskItem model

            if (isset($validated['next_review_at'])) {
                $item->next_review_at = $validated['next_review_at'];
            }

            $item->save();

            // Ensure the assessment reflects that work is being done
            $assessment = $item->assessment;
            if ($assessment->status === 'new' || $assessment->status === 'approved') {
                $assessment->status = 'in_progress';
                $assessment->save();
            }
        });

        return redirect()->back()
            ->with('success', 'Risk item update added successfully.');
    }

    /**
     * Delete the specified risk item update.
     *
     * Business Rule: Only a Superuser or the creator of the update can delete it.
     * When an update is deleted, we must sync the RiskItem's current state with
     * the remaining latest update.
     *
     * @param RiskItemUpdate $update
     * @return RedirectResponse
     */
    public function destroyUpdate(RiskItemUpdate $update): RedirectResponse
    {
        if (!Auth::user()->hasRole('Superuser') && Auth::id() !== $update->created_by) {
            return redirect()->back()->with('error', 'You are not authorized to delete this update.');
        }

        $item = $update->riskItem;

        DB::transaction(function () use ($update, $item) {
            $update->delete();

            // Sync RiskItem with the NEW latest update
            $latest = $item->updates()->latest()->first();
            if ($latest) {
                $item->status = $latest->status;
                $item->likelihood = $latest->likelihood;
                $item->impact = $latest->impact;
                $item->save();
            } else {
                // If NO updates left, we might want to keep the current values
                // or reset them. Given our workflow, there should usually be
                // at least the initial creation state.
            }
        });

        return redirect()->back()
            ->with('success', 'Risk update deleted successfully.');
    }

    /**
     * Approve the specified risk assessment.
     *
     * Business Rule: An assessment can ONLY be approved if all its risk items
     * are addressed (status 'mitigated' or 'accepted'). This is checked via
     * the $risk->is_approvable attribute.
     *
     * @param RiskAssessment $risk
     * @return RedirectResponse
     */
    public function approve(RiskAssessment $risk): RedirectResponse
    {
        if (!$risk->is_approvable) {
            return redirect()->back()
                ->with('error', 'Assessment cannot be approved until all risk items are addressed.');
        }

        $risk->status = 'approved';
        $risk->approved_at = now();
        $risk->approved_by = Auth::id();
        $risk->save();

        return redirect()->back()
            ->with('success', 'Risk assessment has been approved.');
    }

    /**
     * Export the specified risk assessment as a PDF.
     *
     * @param RiskAssessment $risk
     * @return Response
     */
    public function exportPdf(RiskAssessment $risk): Response
    {
        $risk->load(['items.category', 'items.updates.creator', 'client']);

        // Group items by category for the PDF report. Items without category go to 'Uncategorized'
        $groupedItems = $risk->items->groupBy(function($item) {
            return $item->category ? $item->category->name : 'Uncategorized';
        })->sortKeys();

        // Calculate summary data
        $summary = [
            'total' => $risk->items->count(),
            'mitigated' => $risk->items->whereIn('status', ['mitigated', 'accepted'])->count(),
            'open' => $risk->items->where('status', 'open')->count(),
            'critical_areas' => $risk->items->where('score', '>=', 16)->pluck('category.name')->unique()->filter()->values()->all()
        ];

        // Overall Risk Level
        $avgScore = $risk->items->avg('score') ?? 0;
        if ($avgScore >= 16) $summary['level'] = 'Critical';
        elseif ($avgScore >= 10) $summary['level'] = 'High';
        elseif ($avgScore >= 5) $summary['level'] = 'Medium';
        else $summary['level'] = 'Low';

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $html = view('tech.Risk.pdf', compact('risk', 'groupedItems', 'summary'))->render();
        $dompdf->loadHtml($html);

        // (Optional) Setup the paper size and orientation
        $dompdf->setPaper('A4', 'portrait');

        // Render the HTML as PDF
        $dompdf->render();

        $filename = 'Risikoanalyse_' . str_replace(' ', '_', $risk->title) . '_' . now()->format('Y-m-d') . '.pdf';

        return response($dompdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="' . $filename . '"');
    }

    /**
     * Update the specified risk item.
     *
     * @param Request $request
     * @param RiskItem $item
     * @return RedirectResponse
     */
    public function updateItem(Request $request, RiskItem $item): RedirectResponse
    {
        $creatorId = $item->original_state?->created_by;
        $isOwner = $creatorId && Auth::id() === $creatorId;

        // Authorization check: Superuser or owner
        if (!Auth::user()->hasRole('Superuser') && !$isOwner) {
            return redirect()->back()
                ->with('error', 'You do not have permission to edit this risk item.');
        }

        $hasUpdates = $item->updates()->count() > 0;

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'recommended_actions' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'likelihood' => $hasUpdates ? 'nullable|integer' : 'required|integer|min:1|max:5',
            'impact' => $hasUpdates ? 'nullable|integer' : 'required|integer|min:1|max:5',
            'status' => $hasUpdates ? 'nullable|string' : 'required|string|in:open,mitigated,accepted',
        ]);

        // If it has updates, we don't allow changing likelihood, impact, and status via this method
        if ($hasUpdates) {
            unset($validated['likelihood'], $validated['impact'], $validated['status']);
        }

        $oldValues = $item->only(['title', 'description', 'recommended_actions', 'conclusion', 'category_id']);
        $item->update($validated);

        // Check if anything significant changed to log it
        $changes = [];
        if ($oldValues['title'] !== $item->title) $changes[] = "Title";
        if ($oldValues['description'] !== $item->description) $changes[] = "Description";
        if ($oldValues['recommended_actions'] !== $item->recommended_actions) $changes[] = "Recommended Actions";
        if ($oldValues['conclusion'] !== $item->conclusion) $changes[] = "Conclusion";
        if ($oldValues['category_id'] != $item->category_id) $changes[] = "Category";

        if (!empty($changes)) {
            $item->updates()->create([
                'created_by' => Auth::id(),
                'note' => 'Risk item details updated: ' . implode(', ', $changes),
                'likelihood' => $item->likelihood,
                'impact' => $item->impact,
                'status' => $item->status,
            ]);
        }

        return redirect()->back()
            ->with('success', 'Risk item updated successfully.');
    }

    /**
     * Delete the specified risk item.
     *
     * @param RiskItem $item
     * @return RedirectResponse
     */
    public function destroyItem(RiskItem $item): RedirectResponse
    {
        // Authorization check: Superuser or category admin (if applicable)
        if (!Auth::user()->hasRole('Superuser')) {
             return redirect()->back()
                ->with('error', 'You do not have permission to delete this risk item.');
        }

        $assessment = $item->assessment;
        $item->delete();

        return redirect()->route('tech.risk.show', $assessment)
            ->with('success', 'Risk item deleted successfully.');
    }
}
