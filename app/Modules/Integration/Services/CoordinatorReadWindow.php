<?php

namespace App\Modules\Integration\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/** Common bounded-date read envelope; domain controllers retain ownership of source queries. */
class CoordinatorReadWindow
{
    public function dates(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
        ]);
        $to = CarbonImmutable::parse($validated['date_to'] ?? now()->toDateString())->endOfDay();
        $from = CarbonImmutable::parse($validated['date_from'] ?? $to->subDays(6)->toDateString())->startOfDay();
        if ($from->greaterThan($to)) {
            throw ValidationException::withMessages(['date_from' => 'The start date must not be after the end date.']);
        }
        // Compare calendar dates, not fractional days ending at 23:59:59.999999.
        if ($from->diffInDays($to->startOfDay()) + 1 > $this->limits($request)['maximum_query_days']) {
            throw ValidationException::withMessages(['date_from' => 'The requested date range exceeds the workload policy.']);
        }

        return [$from, $to];
    }

    private function limits(Request $request): array
    {
        return $request->attributes->get('coordinator_policy_limits');
    }

    /**
     * The result ceiling still applies to the whole date window, including all its pages.
     * A client must split a truncated window or obtain an explicitly reviewed policy change.
     */
    public function resultMeta(Request $request, CarbonImmutable $from, CarbonImmutable $to, int $total, int $returned): array
    {
        $limits = $this->limits($request);
        $truncated = $total > $limits['maximum_results'];

        return [
            'profile' => 'pseudonymized',
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
            'total' => $total,
            'available_total' => min($total, $limits['maximum_results']),
            'returned_count' => $returned,
            'truncated' => $truncated,
            'recovery' => $truncated
                ? ($from->isSameDay($to) ? 'policy_limit_requires_review' : 'split_date_range')
                : null,
            'maximum_query_days' => $limits['maximum_query_days'],
            'maximum_page_size' => $limits['maximum_page_size'],
            'maximum_results' => $limits['maximum_results'],
        ];
    }

    public function paginate(Request $request, Collection $rows, CarbonImmutable $from, CarbonImmutable $to): JsonResponse
    {
        $limits = $this->limits($request);
        $input = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$limits['maximum_page_size']],
        ]);
        $page = (int) ($input['page'] ?? 1);
        $perPage = (int) ($input['per_page'] ?? min(25, $limits['maximum_page_size']));
        $total = $rows->count();
        $lastPage = max(1, (int) ceil(min($total, $limits['maximum_results']) / $perPage));
        $data = $rows->sortByDesc(fn (array $row): string => $row['work_date'].'|'.$row['entry_alias'])
            ->values()->take($limits['maximum_results'])->forPage($page, $perPage)->values();

        return response()->json(['data' => $data, 'meta' => array_merge($this->resultMeta($request, $from, $to, $total, $data->count()), [
            'page' => $page, 'per_page' => $perPage, 'last_page' => $lastPage,
            'next_page' => $page < $lastPage ? $page + 1 : null,
        ])]);
    }
}
