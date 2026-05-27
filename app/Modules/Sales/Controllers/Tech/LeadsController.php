<?php

namespace App\Modules\Sales\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Sales\Actions\EnsureSalesDefaults;
use App\Modules\Sales\Actions\StoreSalesOpportunity;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LeadsController extends Controller
{
    public function index(Request $request, EnsureSalesDefaults $defaults): View
    {
        $defaults->handle();
        $groupBy = $request->string('group_by')->toString();
        $sort = $request->string('sort', 'name')->toString();
        $direction = $request->input('direction') === 'desc' ? 'desc' : 'asc';
        $leadCandidateQuery = $this->leadCandidateQuery();
        $activeOpportunityClientIds = SalesOpportunity::query()
            ->whereNotIn('status', ['won', 'lost', 'not_qualified'])
            ->pluck('client_id')
            ->all();

        $clients = (clone $leadCandidateQuery)
            ->with(['salesCategory', 'tags'])
            ->withCount(['contacts', 'assets'])
            ->when($request->filled('q'), function ($query) use ($request): void {
                $search = '%'.$request->string('q')->trim()->toString().'%';
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', $search)
                        ->orWhere('org_no', 'like', $search)
                        ->orWhere('billing_email', 'like', $search)
                        ->orWhere('website', 'like', $search);
                });
            })
            ->when($request->filled('category'), fn ($query) => $query->where('sales_category_id', $request->integer('category')))
            ->when($request->filled('temperature'), fn ($query) => $query->where('lead_temperature', $request->integer('temperature')))
            ->when($request->filled('tag'), fn ($query) => $query->whereHas('tags', fn ($query) => $query->whereKey($request->integer('tag'))))
            ->when($sort === 'temperature', fn ($query) => $query->orderBy('lead_temperature', $direction)->orderBy('name'))
            ->when($sort === 'contacts', fn ($query) => $query->orderBy('contacts_count', $direction)->orderBy('name'))
            ->when($sort === 'assets', fn ($query) => $query->orderBy('assets_count', $direction)->orderBy('name'))
            ->when($sort === 'status', function ($query) use ($activeOpportunityClientIds, $direction): void {
                if ($activeOpportunityClientIds === []) {
                    $query->orderBy('lead_temperature', $direction)->orderBy('name');
                    return;
                }

                $placeholders = implode(',', array_fill(0, count($activeOpportunityClientIds), '?'));
                $query->orderByRaw(
                    'CASE WHEN clients.id IN ('.$placeholders.') THEN 0 ELSE 1 END '.($direction === 'desc' ? 'DESC' : 'ASC'),
                    $activeOpportunityClientIds
                )->orderBy('lead_temperature', $direction)->orderBy('name');
            })
            ->when($sort === 'name', fn ($query) => $query->orderBy('name', $direction))
            ->when(! in_array($sort, ['name', 'temperature', 'contacts', 'assets', 'status'], true), fn ($query) => $query->orderBy('name'))
            ->paginate(25)
            ->withQueryString();

        $leadCandidateIds = (clone $leadCandidateQuery)->pluck('clients.id');
        $usedCategoryIds = (clone $leadCandidateQuery)
            ->whereNotNull('sales_category_id')
            ->distinct()
            ->pluck('sales_category_id');
        $usedTagIds = DB::table('taggables')
            ->where('taggable_type', Client::class)
            ->whereIn('taggable_id', $leadCandidateIds)
            ->distinct()
            ->pluck('tag_id');

        $categories = Category::query()
            ->active()
            ->whereIn('id', $usedCategoryIds)
            ->where(function ($query): void {
                $query->whereNull('type')
                    ->orWhereIn('type', ['sales', 'sales_lead', 'client', 'industry']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
        $tags = Tag::query()->where('active', true)->whereIn('id', $usedTagIds)->orderBy('name')->get(['id', 'name', 'color']);
        $classifyCategories = Category::query()
            ->active()
            ->where(function ($query): void {
                $query->whereNull('type')
                    ->orWhereIn('type', ['sales', 'sales_lead', 'client', 'industry']);
            })
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
        $classifyTags = Tag::query()->where('active', true)->orderBy('name')->get(['id', 'name', 'color']);

        return view('sales::Tech.Sales.leads.index', [
            'clients' => $clients,
            'groupedClients' => $this->groupedClients($clients->getCollection(), $groupBy),
            'groupBy' => $groupBy,
            'categories' => $categories,
            'tags' => $tags,
            'classifyCategories' => $classifyCategories,
            'classifyTags' => $classifyTags,
            'owners' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->get(['id', 'name']),
            'types' => EnsureSalesDefaults::TYPES,
            'statuses' => EnsureSalesDefaults::STATUSES,
            'nextActions' => EnsureSalesDefaults::NEXT_ACTIONS,
            'filters' => $request->only(['q', 'category', 'tag', 'temperature', 'sort', 'direction', 'group_by']),
            'activeOpportunityClientIds' => $activeOpportunityClientIds,
        ]);
    }

    public function updateClassification(Request $request, Client $lead): RedirectResponse
    {
        $data = $request->validate([
            'sales_category_id' => 'nullable|exists:categories,id',
            'lead_temperature' => 'required|integer|min:1|max:5',
            'website' => 'nullable|url|max:255',
            'tag_names' => 'array',
            'tag_names.*' => 'string|max:255',
        ]);

        $lead->forceFill([
            'sales_category_id' => $data['sales_category_id'] ?? null,
            'lead_temperature' => $data['lead_temperature'],
            'website' => $data['website'] ?? null,
        ])->save();

        $lead->tags()->syncWithPivotValues($this->resolveSalesTagIds($data['tag_names'] ?? []), ['module' => 'sales']);

        return back()->with('success', 'Lead classification updated.');
    }

    public function show(Client $lead): View
    {
        return view('sales::Tech.Sales.leads.show', [
            'lead' => $lead,
        ]);
    }

    private function leadCandidateQuery()
    {
        return Client::query()
            ->whereDoesntHave('contracts', function ($query): void {
                $query->whereIn('approval_status', ['won', 'active', 'approved']);
            })
            ->whereDoesntHave('contracts', function ($query): void {
                $query->where('approval_status', 'won');
            });
    }

    public function start(Request $request, Client $lead, StoreSalesOpportunity $storeOpportunity): RedirectResponse
    {
        if ($request->has('next_follow_up_type')) {
            $request->merge([
                'next_follow_up_type' => EnsureSalesDefaults::normalizeNextAction($request->input('next_follow_up_type')),
            ]);
        }

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'type' => 'required|string|max:100',
            'owner_id' => ['nullable', Rule::exists((new User())->getTable(), 'id')],
            'status' => 'nullable|string|max:100',
            'summary' => 'nullable|string|max:4000',
            'needs' => 'nullable|string|max:4000',
            'employee_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'user_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'workstation_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'server_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'site_count_estimate' => 'nullable|integer|min:0|max:1000000',
            'expected_close_date' => 'nullable|date',
            'next_follow_up_at' => 'nullable|date',
            'next_follow_up_type' => ['nullable', Rule::in(array_keys(EnsureSalesDefaults::NEXT_ACTIONS))],
            'next_follow_up_note' => 'nullable|string|max:2000',
        ]);

        $opportunity = $storeOpportunity->handle(array_merge($data, [
            'client_id' => $lead->id,
            'owner_id' => $data['owner_id'] ?? $request->user()->id,
            'status' => $data['status'] ?? 'new_lead',
        ]), $request->user());

        return redirect()->route('tech.sales.show', $opportunity)
            ->with('success', 'Sales process started.');
    }

    private function groupedClients($clients, string $groupBy): array
    {
        if ($groupBy === 'temperature') {
            return $clients
                ->groupBy(fn (Client $client) => 'Temperature '.$client->lead_temperature)
                ->all();
        }

        if ($groupBy === 'category') {
            return $clients
                ->groupBy(fn (Client $client) => $client->salesCategory?->name ?? 'Uncategorized')
                ->all();
        }

        return ['All leads' => $clients];
    }

    private function resolveSalesTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->map(fn ($name) => trim((string) $name))
            ->filter()
            ->unique(fn ($name) => Str::lower($name))
            ->map(function (string $name): int {
                $tag = Tag::query()
                    ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                    ->first();

                if ($tag) {
                    return (int) $tag->id;
                }

                return (int) Tag::create([
                    'name' => $name,
                    'slug' => $this->uniqueTagSlug($name),
                    'color' => '#6c757d',
                    'active' => true,
                ])->id;
            })
            ->values()
            ->all();
    }

    private function uniqueTagSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: Str::random(8);
        $slug = $baseSlug;
        $counter = 2;

        while (Tag::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
