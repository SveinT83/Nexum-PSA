<?php

namespace App\Modules\Marketing\Support;

use App\Modules\Marketing\Models\MarketingList;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

/** Shared web/API validation and normalization boundary. */
trait MarketingListInput
{
    private function validatedListData(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'audience_type' => ['required', 'string', 'in:all_business_contacts,manual_contacts'],
            'consent_category_id' => ['nullable', 'integer', 'exists:marketing_consent_categories,id'],
            'contact_tag_ids' => ['nullable', 'array'],
            'contact_tag_ids.*' => ['integer', 'exists:tags,id'],
            'client_tag_ids' => ['nullable', 'array'],
            'client_tag_ids.*' => ['integer', 'exists:tags,id'],
            'manual_contact_ids' => ['nullable', 'array'],
            'manual_contact_ids.*' => ['integer', 'exists:contacts,id'],
            'manual_client_user_ids' => ['nullable', 'array'],
            'manual_client_user_ids.*' => ['integer', 'exists:client_users,id'],
            'postal_codes' => ['nullable', $this->locationValuesRule()],
            'counties' => ['nullable', $this->locationValuesRule()],
            'countries' => ['nullable', $this->locationValuesRule()],
            'sales_category_ids' => ['nullable', 'array'],
            'sales_category_ids.*' => ['integer', 'exists:categories,id'],
            'contract_filter' => ['nullable', 'string', Rule::in([
                'any',
                'with_contract',
                'without_contract',
                'active_contract',
                'without_active_contract',
                'won_contract',
            ])],
        ]);
    }

    /** Preserve text/numeric location inputs while rejecting nested or object payloads. */
    private function locationValuesRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            $valid = is_string($value) || is_int($value)
                || (is_array($value) && array_is_list($value) && collect($value)->every(fn ($item) => is_string($item) || is_int($item)));
            if (! $valid) {
                $fail('The '.$attribute.' field must be text, an integer, or a flat list of text/integer values.');
            }
        };
    }

    private function segmentCriteria(string $audienceType, Request $request, array|Collection $excludedContactIds = []): array
    {
        return [
            'audience_type' => $audienceType,
            'contact_tag_ids' => $this->requestIntegerIds($request, 'contact_tag_ids')->all(),
            'client_tag_ids' => $this->requestIntegerIds($request, 'client_tag_ids')->all(),
            'manual_contact_ids' => $this->requestIntegerIds($request, 'manual_contact_ids')->all(),
            'manual_client_user_ids' => $this->requestIntegerIds($request, 'manual_client_user_ids')->all(),
            'postal_codes' => $this->requestStringValues($request, 'postal_codes')->all(),
            'counties' => $this->requestStringValues($request, 'counties')->all(),
            'countries' => $this->requestStringValues($request, 'countries')->all(),
            'sales_category_ids' => $this->requestIntegerIds($request, 'sales_category_ids')->all(),
            'contract_filter' => $request->input('contract_filter', 'any') ?: 'any',
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

    private function requestStringValues(Request $request, string $key): Collection
    {
        return collect((array) $request->input($key, []))
            ->flatMap(fn ($value): array => is_string($value) ? preg_split('/[\r\n,]+/', $value) ?: [] : [$value])
            ->map(fn ($value): string => mb_strtolower(trim((string) $value)))
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
}
