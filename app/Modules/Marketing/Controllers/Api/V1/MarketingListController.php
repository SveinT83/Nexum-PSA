<?php

namespace App\Modules\Marketing\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Models\Contact;
use App\Modules\Marketing\Actions\EnsureMarketingDefaults;
use App\Modules\Marketing\Actions\ResolveMarketingListMembers;
use App\Modules\Marketing\Models\MarketingList;
use App\Modules\Marketing\Resources\Api\V1\MarketingListMemberResource;
use App\Modules\Marketing\Resources\Api\V1\MarketingListResource;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Marketing',
    description: 'API endpoints for marketing mailing lists, campaigns, and settings.'
)]
class MarketingListController extends Controller
{
    public function index(Request $request, EnsureMarketingDefaults $defaults)
    {
        $defaults->handle();

        $query = MarketingList::query()
            ->with('consentCategory')
            ->withCount(['members', 'campaigns'])
            ->latest('updated_at');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        foreach (['status', 'audience_type', 'consent_category_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $field === 'consent_category_id' ? $request->integer($field) : $request->input($field));
            }
        }

        return MarketingListResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function store(Request $request, ResolveMarketingListMembers $resolve)
    {
        $data = $this->validatedListData($request);

        $list = MarketingList::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'audience_type' => $data['audience_type'],
            'consent_category_id' => $data['consent_category_id'] ?? null,
            'status' => 'active',
            'segment_criteria' => $this->segmentCriteria($data['audience_type'], $request),
            'created_by' => $request->user()?->id,
            'updated_by' => $request->user()?->id,
        ]);

        $count = $resolve->handle($list);

        return (new MarketingListResource($this->loadList($list)))
            ->additional(['meta' => ['resolved_members' => $count]])
            ->response()
            ->setStatusCode(201);
    }

    public function show(MarketingList $list)
    {
        return new MarketingListResource($this->loadList($list));
    }

    public function update(MarketingList $list, Request $request, ResolveMarketingListMembers $resolve)
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

        return (new MarketingListResource($this->loadList($list)))
            ->additional(['meta' => ['resolved_members' => $count]]);
    }

    public function destroy(MarketingList $list)
    {
        if ($list->isUsedByCampaigns()) {
            throw ValidationException::withMessages([
                'list' => 'This marketing list is used by one or more campaigns and cannot be deleted.',
            ]);
        }

        $list->delete();

        return response()->noContent();
    }

    public function members(Request $request, MarketingList $list)
    {
        $query = $list->members()
            ->with(['client', 'contact'])
            ->orderBy('email');

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('email', 'like', $needle)
                    ->orWhere('name', 'like', $needle)
                    ->orWhereHas('client', fn ($client) => $client->where('name', 'like', $needle));
            });
        }

        return MarketingListMemberResource::collection($query->paginate($request->integer('per_page') ?: 50));
    }

    public function refresh(MarketingList $list, ResolveMarketingListMembers $resolve)
    {
        $count = $resolve->handle($list);

        return (new MarketingListResource($this->loadList($list)))
            ->additional(['meta' => ['resolved_members' => $count]]);
    }

    public function addContacts(MarketingList $list, Request $request, ResolveMarketingListMembers $resolve)
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

        return (new MarketingListResource($this->loadList($list)))
            ->additional([
                'meta' => [
                    'resolved_members' => $count,
                    'added_contact_ids' => $contactIds->all(),
                ],
            ]);
    }

    public function removeContact(MarketingList $list, Contact $contact, Request $request, ResolveMarketingListMembers $resolve)
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

        return (new MarketingListResource($this->loadList($list)))
            ->additional([
                'meta' => [
                    'resolved_members' => $count,
                    'removed_contact_id' => $contact->id,
                ],
            ]);
    }

    private function validatedListData(Request $request): array
    {
        return $request->validate([
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
    }

    private function segmentCriteria(string $audienceType, Request $request, array|Collection $excludedContactIds = []): array
    {
        return [
            'audience_type' => $audienceType,
            'contact_tag_ids' => $this->requestIntegerIds($request, 'contact_tag_ids')->all(),
            'client_tag_ids' => $this->requestIntegerIds($request, 'client_tag_ids')->all(),
            'manual_contact_ids' => $this->requestIntegerIds($request, 'manual_contact_ids')->all(),
            'excluded_contact_ids' => collect($excludedContactIds)
                ->map(fn ($id): int => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
        ];
    }

    private function requestIntegerIds(Request $request, string $key): Collection
    {
        return collect($request->input($key, []))
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    private function criteriaContactIds(MarketingList $list, string $key): Collection
    {
        return collect(($list->segment_criteria ?? [])[$key] ?? [])
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
    }

    private function loadList(MarketingList $list): MarketingList
    {
        return $list->fresh()
            ->load('consentCategory')
            ->loadCount(['members', 'campaigns']);
    }
}
