<?php

namespace App\Modules\Marketing\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\ClientUser;
use App\Modules\Contact\Models\Contact;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\ResolveMarketingListMembers;
use App\Modules\Marketing\Models\MarketingConsentCategory;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Support\MarketingSettings;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class MarketingListController extends Controller
{
    use \App\Modules\Marketing\Support\MarketingListInput;

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
            'manualClientUsers' => $this->manualClientUsers(),
            'salesCategories' => $this->salesCategories(),
        ]);
    }

    public function store(Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $data = $this->validatedListData($request);

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
        $settingsPayload = $settings->get();
        $memberContactIds = $list->members()->whereNotNull('contact_id')->pluck('contact_id');

        return view('marketing::Tech.lists.show', [
            'list' => $list->load('consentCategory')->loadCount('members'),
            'members' => $list->members()->with(['client', 'contact', 'clientUser'])->orderBy('email')->paginate(50),
            'settings' => $settingsPayload,
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
            'addableContacts' => $this->manualContacts($settingsPayload)
                ->whereNotIn('id', $memberContactIds)
                ->values(),
            'salesCategories' => $this->salesCategories()
                ->whereIn('id', collect($list->segment_criteria ?? [])->get('sales_category_ids', []))
                ->keyBy('id'),
        ]);
    }

    public function edit(MarketingList $list, MarketingSettings $settings, EnsureMarketingDefaults $defaults): View
    {
        $defaults->handle();
        $settingsPayload = $settings->get();
        $list->loadCount('campaigns');
        $list->setAttribute('campaign_usage_count', $list->campaignUsageCount());

        return view('marketing::Tech.lists.form', [
            'list' => $list,
            'settings' => $settingsPayload,
            'categories' => MarketingConsentCategory::query()->where('is_active', true)->orderBy('name')->get(),
            'tags' => $this->activeTags(),
            'manualContacts' => $this->manualContacts($settingsPayload),
            'manualClientUsers' => $this->manualClientUsers(),
            'salesCategories' => $this->salesCategories(),
        ]);
    }

    public function update(MarketingList $list, Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $data = $this->validatedListData($request);
        $selectedManualContactIds = $this->requestIntegerIds($request, 'manual_contact_ids');
        $existingExclusions = $this->criteriaContactIds($list, 'excluded_contact_ids')
            ->reject(fn (int $id): bool => $selectedManualContactIds->contains($id))
            ->values();

        $list->forceFill([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'audience_type' => $data['audience_type'],
            'consent_category_id' => $data['consent_category_id'] ?? null,
            'segment_criteria' => $this->segmentCriteria($data['audience_type'], $request, $existingExclusions),
            'updated_by' => $request->user()?->id,
        ])->save();

        $count = $resolve->handle($list);

        return redirect()
            ->route('tech.marketing.lists.show', $list)
            ->with('status', "Marketing list updated and refreshed with {$count} eligible recipients.");
    }

    public function destroy(MarketingList $list): RedirectResponse
    {
        if ($list->isUsedByCampaigns()) {
            return redirect()
                ->route('tech.marketing.lists.edit', $list)
                ->withErrors([
                    'list' => 'This marketing list is used by one or more campaigns and cannot be deleted.',
                ]);
        }

        $list->delete();

        return redirect()
            ->route('tech.marketing.lists.index')
            ->with('status', 'Marketing list deleted.');
    }

    public function addContacts(MarketingList $list, Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $data = $request->validate([
            'contact_ids' => ['required', 'array', 'min:1'],
            'contact_ids.*' => ['integer', 'exists:contacts,id'],
        ]);
        $contactIds = collect($data['contact_ids'])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $criteria = $list->segment_criteria ?? [];
        $manualContactIds = $this->criteriaContactIds($list, 'manual_contact_ids')
            ->merge($contactIds)
            ->unique()
            ->values();
        $excludedContactIds = $this->criteriaContactIds($list, 'excluded_contact_ids')
            ->reject(fn (int $id): bool => $contactIds->contains($id))
            ->values();

        $criteria['audience_type'] = $list->audience_type;
        $criteria['manual_contact_ids'] = $manualContactIds->all();
        $criteria['excluded_contact_ids'] = $excludedContactIds->all();

        $list->forceFill([
            'segment_criteria' => $criteria,
            'updated_by' => $request->user()?->id,
        ])->save();

        $count = $resolve->handle($list);

        return redirect()
            ->route('tech.marketing.lists.show', $list)
            ->with('status', "Contacts added and list refreshed with {$count} eligible recipients.");
    }

    public function removeContact(MarketingList $list, Contact $contact, Request $request, ResolveMarketingListMembers $resolve): RedirectResponse
    {
        $criteria = $list->segment_criteria ?? [];
        $manualContactIds = $this->criteriaContactIds($list, 'manual_contact_ids')
            ->reject(fn (int $id): bool => $id === (int) $contact->id)
            ->values();
        $excludedContactIds = $this->criteriaContactIds($list, 'excluded_contact_ids')
            ->push((int) $contact->id)
            ->unique()
            ->values();

        $criteria['audience_type'] = $list->audience_type;
        $criteria['manual_contact_ids'] = $manualContactIds->all();
        $criteria['excluded_contact_ids'] = $excludedContactIds->all();

        $list->forceFill([
            'segment_criteria' => $criteria,
            'updated_by' => $request->user()?->id,
        ])->save();

        $count = $resolve->handle($list);

        return redirect()
            ->route('tech.marketing.lists.show', $list)
            ->with('status', "Contact removed and list refreshed with {$count} eligible recipients.");
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

    private function manualClientUsers()
    {
        return ClientUser::query()
            ->with('site.client')
            ->whereNull('contact_id')
            ->where('active', true)
            ->whereNotNull('email')
            ->orderBy('name')
            ->get();
    }

    private function salesCategories()
    {
        return Category::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);
    }
}
