<?php

namespace App\Modules\Risk\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Risk\RiskAssessment;
use App\Models\Risk\RiskItem;
use App\Models\Risk\RiskItemUpdate;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Risk\Actions\ApproveRiskAssessment;
use App\Modules\Risk\Actions\DeleteRiskItemUpdate;
use App\Modules\Risk\Actions\StoreRiskAssessment;
use App\Modules\Risk\Actions\StoreRiskItem;
use App\Modules\Risk\Actions\StoreRiskItemUpdate;
use App\Modules\Risk\Actions\UpdateRiskAssessment;
use App\Modules\Risk\Actions\UpdateRiskItem;
use App\Modules\Risk\Queries\RiskAssessmentQuery;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * Tech-facing HTTP controller for the Risk module.
 *
 * The controller is intentionally limited to HTTP concerns:
 * validation, authorization checks that are still local to this module,
 * selecting the correct view, and redirect/response construction.
 *
 * Business state changes are delegated to Action classes in
 * App\Modules\Risk\Actions so that the same workflows can later be reused
 * from jobs, API controllers, Livewire components, or tests without copying
 * controller code.
 */
class RiskController extends Controller
{
    /**
     * List risk assessments for the currently selected tdPSA context.
     *
     * The context filtering itself lives in RiskAssessmentQuery. The controller
     * only supplies the client list needed by the global context selector and
     * passes the paginated result to the module namespaced Blade view.
     */
    public function index(RiskAssessmentQuery $query): View
    {
        return view('risk::Tech.index', [
            'clients' => $this->activeClients(),
            'assessments' => $query->paginateForCurrentContext(),
        ]);
    }

    /**
     * Show the create form for a new assessment.
     *
     * The create and edit screens share the same form view. Passing a new
     * unsaved RiskAssessment lets the view decide whether it is in create or
     * edit mode without duplicating markup.
     */
    public function create(): View
    {
        return view('risk::Tech.form', [
            'clients' => $this->activeClients(),
            'risk' => new RiskAssessment(),
        ]);
    }

    /**
     * Persist a new assessment after validating the request.
     *
     * StoreRiskAssessment owns the mapping between UI scope values
     * ("internal" / "client") and the persisted client_id value.
     */
    public function store(Request $request, StoreRiskAssessment $action): RedirectResponse
    {
        $assessment = $action->handle($this->validateAssessment($request));

        return redirect()->route('tech.risk.show', $assessment)
            ->with('success', 'Risk assessment created successfully.');
    }

    /**
     * Show one assessment with its grouped risk items.
     *
     * Items are grouped by category name for readability in the UI. Items
     * without a category are placed in "Uncategorized" so the view can render a
     * predictable group header for every item.
     */
    public function show(RiskAssessment $risk): View
    {
        $risk->load(['items.category', 'client', 'approver']);

        return view('risk::Tech.show', [
            'risk' => $risk,
            'groupedItems' => $risk->items->groupBy(fn (RiskItem $item) => $item->category?->name ?? 'Uncategorized'),
            'categories' => $this->riskCategories(),
        ]);
    }

    /**
     * Show the edit form for an existing assessment.
     */
    public function edit(RiskAssessment $risk): View
    {
        return view('risk::Tech.form', [
            'clients' => $this->activeClients(),
            'risk' => $risk,
        ]);
    }

    /**
     * Update assessment metadata and scope.
     *
     * This endpoint does not alter the item history, approval metadata, or
     * scoring state. Those workflows have separate actions and routes.
     */
    public function update(Request $request, RiskAssessment $risk, UpdateRiskAssessment $action): RedirectResponse
    {
        $action->handle($risk, $this->validateAssessment($request));

        return redirect()->route('tech.risk.show', $risk)
            ->with('success', 'Risk assessment updated successfully.');
    }

    /**
     * Soft-delete an assessment.
     *
     * Deletion is restricted to Superuser here because the module does not yet
     * have a dedicated policy. RiskAssessment uses SoftDeletes, so records can
     * be recovered manually if needed.
     */
    public function destroy(RiskAssessment $risk): RedirectResponse
    {
        if (! Auth::user()->hasRole('Superuser')) {
            return redirect()->back()->with('error', 'You do not have permission to delete this risk assessment.');
        }

        $risk->delete();

        return redirect()->route('tech.risk.index')
            ->with('success', 'Risk assessment deleted successfully.');
    }

    /**
     * Add a new risk item to an assessment.
     *
     * StoreRiskItem creates both the current RiskItem snapshot and the initial
     * RiskItemUpdate history row in one database transaction. JSON responses are
     * supported so this route can be used by async UI later without adding a
     * second endpoint.
     */
    public function storeItem(Request $request, RiskAssessment $risk, StoreRiskItem $action): JsonResponse|RedirectResponse
    {
        $item = $action->handle($risk, $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'recommended_actions' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'likelihood' => 'required|integer|min:1|max:5',
            'impact' => 'required|integer|min:1|max:5',
            'status' => 'required|string|in:open,mitigated,accepted',
            'next_review_at' => 'nullable|date',
        ]));

        if ($request->wantsJson()) {
            return response()->json([
                'success' => true,
                'message' => 'Risk item added successfully.',
                'item' => $item,
            ]);
        }

        return redirect()->back()->with('success', 'Risk item added successfully.');
    }

    /**
     * Show the detail/history screen for one risk item.
     *
     * This view needs both the current item snapshot and its historical updates.
     * Links are eager-loaded with the polymorphic target for future linked
     * entities such as assets, documentation, or tickets.
     */
    public function showItem(RiskItem $item): View
    {
        $item->load(['assessment.client', 'updates.creator', 'links.linkable', 'category']);

        return view('risk::Tech.items.show', [
            'item' => $item,
            'categories' => $this->riskCategories(),
        ]);
    }

    /**
     * Add a historical update and synchronize the current item snapshot.
     *
     * The module treats likelihood, impact, and status as audit-sensitive
     * values. They should be changed through this endpoint rather than through
     * the descriptive edit form.
     */
    public function storeItemUpdate(Request $request, RiskItem $item, StoreRiskItemUpdate $action): RedirectResponse
    {
        $action->handle($item, $request->validate([
            'note' => 'required|string',
            'status' => 'required|string|in:open,mitigated,accepted',
            'likelihood' => 'nullable|integer|min:1|max:5',
            'impact' => 'nullable|integer|min:1|max:5',
            'next_review_at' => 'nullable|date',
        ]));

        return redirect()->back()->with('success', 'Risk item update added successfully.');
    }

    /**
     * Delete one historical item update and recalculate the current snapshot.
     *
     * Only the creator or a Superuser may delete an update. The action updates
     * the parent item from the latest remaining update so list screens keep
     * showing the correct current state after deletion.
     */
    public function destroyUpdate(RiskItemUpdate $update, DeleteRiskItemUpdate $action): RedirectResponse
    {
        if (! Auth::user()->hasRole('Superuser') && Auth::id() !== $update->created_by) {
            return redirect()->back()->with('error', 'You are not authorized to delete this update.');
        }

        $action->handle($update);

        return redirect()->back()->with('success', 'Risk update deleted successfully.');
    }

    /**
     * Approve an assessment when every risk item has been addressed.
     *
     * The approval rule is implemented on the RiskAssessment model and enforced
     * by ApproveRiskAssessment. The controller only translates the boolean
     * result into user-facing feedback.
     */
    public function approve(RiskAssessment $risk, ApproveRiskAssessment $action): RedirectResponse
    {
        if (! $action->handle($risk)) {
            return redirect()->back()
                ->with('error', 'Assessment cannot be approved until all risk items are addressed.');
        }

        return redirect()->back()->with('success', 'Risk assessment has been approved.');
    }

    /**
     * Render the assessment as an inline PDF report.
     *
     * PDF export currently lives here because the workflow is small and only
     * used from the Tech UI. If reporting expands, move the summary builder and
     * Dompdf setup into a module action or service.
     */
    public function exportPdf(RiskAssessment $risk): Response
    {
        $risk->load(['items.category', 'items.updates.creator', 'client', 'approver']);

        $groupedItems = $risk->items
            ->groupBy(fn (RiskItem $item) => $item->category?->name ?? 'Uncategorized')
            ->sortKeys();

        $summary = $this->summaryFor($risk);

        $options = new Options();
        $options->set('isHtml5ParserEnabled', true);
        $options->set('isRemoteEnabled', true);
        $options->set('isFontSubsettingEnabled', true);
        $options->set('chroot', public_path());

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('risk::Tech.pdf', compact('risk', 'groupedItems', 'summary'))->render());
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        $filename = 'Risikoanalyse_'.str_replace(' ', '_', $risk->title).'_'.now()->format('Y-m-d').'.pdf';

        return response($dompdf->output(), 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="'.$filename.'"');
    }

    /**
     * Edit descriptive risk item fields.
     *
     * Once a risk item has update history, likelihood, impact, and status are
     * locked in this endpoint and must be changed through storeItemUpdate().
     * This preserves the chronological risk history.
     */
    public function updateItem(Request $request, RiskItem $item, UpdateRiskItem $action): RedirectResponse
    {
        $creatorId = $item->original_state?->created_by;
        $isOwner = $creatorId && Auth::id() === $creatorId;

        if (! Auth::user()->hasRole('Superuser') && ! $isOwner) {
            return redirect()->back()->with('error', 'You do not have permission to edit this risk item.');
        }

        $hasUpdates = $item->updates()->count() > 0;

        $action->handle($item, $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'recommended_actions' => 'nullable|string',
            'conclusion' => 'nullable|string',
            'category_id' => 'nullable|exists:categories,id',
            'likelihood' => $hasUpdates ? 'nullable|integer' : 'required|integer|min:1|max:5',
            'impact' => $hasUpdates ? 'nullable|integer' : 'required|integer|min:1|max:5',
            'status' => $hasUpdates ? 'nullable|string' : 'required|string|in:open,mitigated,accepted',
        ]), $hasUpdates);

        return redirect()->back()->with('success', 'Risk item updated successfully.');
    }

    /**
     * Soft-delete one risk item.
     *
     * RiskItem uses SoftDeletes. The update history remains tied to the item in
     * the database and can be inspected manually if the item is restored.
     */
    public function destroyItem(RiskItem $item): RedirectResponse
    {
        if (! Auth::user()->hasRole('Superuser')) {
            return redirect()->back()->with('error', 'You do not have permission to delete this risk item.');
        }

        $assessment = $item->assessment;
        $item->delete();

        return redirect()->route('tech.risk.show', $assessment)
            ->with('success', 'Risk item deleted successfully.');
    }

    /**
     * Validate fields shared by create and update assessment requests.
     *
     * A client id is required only when the submitted scope is "client". For
     * internal assessments the actions intentionally persist client_id as null.
     */
    private function validateAssessment(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'client_id' => 'nullable|required_if:scope,client|exists:clients,id',
            'scope' => 'required|in:internal,client',
        ]);
    }

    /**
     * Return active clients for the context selector and assessment form.
     */
    private function activeClients()
    {
        return Client::where('active', true)->orderBy('name')->get();
    }

    /**
     * Return categories suitable for risk items.
     *
     * The system has used both "risk" and "risk_item" category types. The
     * fallback to all categories keeps older installations usable even if their
     * category seed data predates this module split.
     */
    private function riskCategories()
    {
        $categories = Category::whereIn('type', ['risk', 'risk_item'])->orderBy('name')->get();

        return $categories->isEmpty() ? Category::orderBy('name')->get() : $categories;
    }

    /**
     * Build summary data used by the PDF report.
     *
     * The thresholds mirror the badge logic on the RiskItem and RiskAssessment
     * models: 16+ critical, 10+ high, 5+ medium, otherwise low.
     */
    private function summaryFor(RiskAssessment $risk): array
    {
        $avgScore = $risk->items->avg('score') ?? 0;

        return [
            'total' => $risk->items->count(),
            'mitigated' => $risk->items->whereIn('status', ['mitigated', 'accepted'])->count(),
            'open' => $risk->items->where('status', 'open')->count(),
            'critical_areas' => $risk->items->where('score', '>=', 16)->pluck('category.name')->unique()->filter()->values()->all(),
            'level' => match (true) {
                $avgScore >= 16 => 'Critical',
                $avgScore >= 10 => 'High',
                $avgScore >= 5 => 'Medium',
                default => 'Low',
            },
        ];
    }
}
