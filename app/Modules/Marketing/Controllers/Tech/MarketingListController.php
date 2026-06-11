<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\ResolveMarketingListMembers;
use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Support\MarketingSettings;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Contact\Models\Contact;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingListController extends Controller
{
    public function index(MarketingSettings $settings, EnsureMarketingDefaults $defaults): View
    {
        $defaults->handle();

        return view('marketing::Tech.lists.index', [
            'lists' => MarketingList::query()
                ->withCount('members')
                ->latest('updated_at')
                ->paginate(25),
            'settings' => $settings->get(),
        ]);
    }

    public function create(MarketingSettings $settings, EnsureMarketingDefaults $defaults): View
    {
        $defaults->handle();
        $settingsPayload = $settings->get();

        return view('marketing::Tech.lists.form', [
            'list' => new MarketingList(['audience_type' => 'all_business_contacts', 'status' => 'active']),
            'settings' => $settingsPayload,
            'categories' => MarketingConsentCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'tags' => $this->activeTags(),
            'manualContacts' => $this->manualContacts($settingsPayload),
        ]);
    }

    public function store(Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience_type' => ['required', 'string', 'in:all_business_contacts,manual_contacts'],
            'consent_category_id' => ['nullable', 'exists:marketing_consent_categories,id'],
            'contact_tag_ids' => ['nullable', 'array'],
            'contact_tag_ids.*' => ['integer', 'exists:tags,id'],
            'client_tag_ids' => ['nullable', 'array'],
            'client_tag_ids.*' => ['integer', 'exists:tags,id'],
            'manual_contact_ids' => ['nullable', 'array'],
            'manual_contact_ids.*' => ['integer', 'exists:contacts,id'],
        ]);

        $segmentCriteria = $this->segmentCriteria($data['audience_type'], $request);

        $list = MarketingList::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'audience_type' => $data['audience_type'],
            'consent_category_id' => $data['consent_category_id'] ?? null,
            'status' => 'active',
            'segment_criteria' => $segmentCriteria,
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $resolve->handle($list);

        return redirect()
            ->route('tech.marketing.lists.show', $list)
            ->with('status', 'Marketing list created and recipients resolved.');
    }

    public function show(MarketingList $list, MarketingSettings $settings): View
    {
        return view('marketing::Tech.lists.show', [
            'list' => $list->load('consentCategory')->loadCount('members'),
            'members' => $list->members()->with(['client', 'contact', 'clientUser'])->orderBy('email')->paginate(50),
            'settings' => $settings->get(),
            'segmentTags' => Tag::query()
                ->whereIn('id', collect($list->segment_criteria ?? [])
                    ->only(['contact_tag_ids', 'client_tag_ids'])
                    ->flatten()
                    ->filter()
                    ->unique()
                    ->values())
                ->orderBy('name')
                ->get()
                ->keyBy('id'),
        ]);
    }

    public function refresh(MarketingList $list, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $count = $resolve->handle($list);

        return redirect()
            ->route('tech.marketing.lists.show', $list)
            ->with('status', "Marketing list refreshed with {$count} eligible recipients.");
    }

    private function activeTags()
    {
        return Tag::query()
            ->where('active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    private function manualContacts(array $settings)
    {
        return Contact::query()
            ->with(['emails' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('id')])
            ->where('status', 'active')
            ->where('do_not_email', false)
            ->when(
                $settings['consent_mode'] === 'explicit_opt_in',
                fn ($query) => $query->where('marketing_consent', true),
            )
            ->whereHas('emails')
            ->orderBy('display_name')
            ->get();
    }

    private function segmentCriteria(string $audienceType, Request $request): array
    {
        return [
            'audience_type' => $audienceType,
            'contact_tag_ids' => collect($request->input('contact_tag_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'client_tag_ids' => collect($request->input('client_tag_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'manual_contact_ids' => collect($request->input('manual_contact_ids', []))
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }
}
