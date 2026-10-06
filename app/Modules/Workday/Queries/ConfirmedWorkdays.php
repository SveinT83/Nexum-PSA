<?php

namespace App\Modules\Workday\Queries;

use App\Models\Core\User;
use App\Modules\Calendar\Queries\WorkdayEvidence;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use App\Modules\Workday\Support\WorkdayAccess;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfirmedWorkdays
{
    /** This projection never loads the current revision or absence relation. */
    private function query(): Builder
    {
        return Workday::query()
            ->join('workday_revisions as confirmed', function ($join) {
                $join->on('confirmed.id', '=', 'workdays.confirmed_revision_id')
                    ->on('confirmed.workday_id', '=', 'workdays.id')->whereIn('confirmed.state', ['confirmed', 'recorded']);
            })
            ->join((new User)->getTable().' as worker', 'worker.id', '=', 'workdays.user_id')
            ->where('workdays.expires_at', '>', now())
            ->select(['workdays.id', 'workdays.uuid', 'workdays.user_id', 'workdays.work_date', 'workdays.timezone',
                'workdays.confirmed_revision_id', 'worker.name as worker_name'])
            ->with('confirmedRevision');
    }

    public function filters(array $input): array
    {
        $this->fields($input, ['from', 'to', 'worker_id', 'worker', 'page', 'per_page']);
        $data = Validator::make($input, [
            'from' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'required', 'date_format:Y-m-d'],
            'worker_id' => ['nullable', 'integer', 'min:1'],
            'worker' => ['nullable', 'string', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ])->validate();
        $from = $data['from'] ?? now('Europe/Oslo')->startOfMonth()->toDateString();
        $to = $data['to'] ?? now('Europe/Oslo')->toDateString();
        $days = CarbonImmutable::parse($from)->diffInDays(CarbonImmutable::parse($to), false);
        if ($days < 0 || $days >= 93) {
            throw ValidationException::withMessages(['to' => 'Choose an inclusive range of 1 to 93 calendar dates.']);
        }

        return ['from' => $from, 'to' => $to, 'worker_id' => isset($data['worker_id']) ? (int) $data['worker_id'] : null,
            'worker' => trim($data['worker'] ?? ''), 'page' => (int) ($data['page'] ?? 1), 'per_page' => (int) ($data['per_page'] ?? 20)];
    }

    public function listing(User $viewer, array $input): array
    {
        app(WorkdayAccess::class)->authorize($viewer, 'view_all');
        $filters = $this->filters($input);

        // One DB read transaction keeps the page and aggregate on the same normal database snapshot.
        return DB::transaction(function () use ($viewer, $filters) {
            $query = $this->query()->whereBetween('workdays.work_date', [$filters['from'], $filters['to']])
                ->when($filters['worker_id'], fn ($q, $id) => $q->where('workdays.user_id', $id))
                ->when($filters['worker'] !== '', function ($q) use ($filters) {
                    // Literal name substring, not a caller-supplied wildcard or raw SQL expression.
                    $needle = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['worker']);
                    $q->whereRaw("worker.name LIKE ? ESCAPE '!'", ['%'.$needle.'%']);
                });
            $total = (clone $query)->count();
            $actual = (float) (clone $query)->sum('confirmed.snapshot->actual_minutes');
            $allocated = (int) (clone $query)->sum('confirmed.snapshot->allocated_minutes');
            $data = $query->orderByDesc('workdays.work_date')->orderBy('workdays.user_id')->orderBy('workdays.id')
                ->offset(($filters['page'] - 1) * $filters['per_page'])->limit($filters['per_page'])->get()
                ->map(fn ($day) => $this->project($viewer, $day, $day->confirmedRevision, false))->all();

            return ['data' => $data, 'meta' => $this->pageMeta($total, count($data), $filters['page'], $filters['per_page']) + [
                'from' => $filters['from'], 'to' => $filters['to'], 'worker_id' => $filters['worker_id'], 'worker' => $filters['worker'],
            ], 'totals' => ['confirmed_days' => $total, 'actual_minutes' => $actual, 'allocated_minutes' => $allocated,
                'unallocated_minutes' => $actual - $allocated, 'scope' => 'all_matching_confirmed_days']];
        });
    }

    public function find(User $viewer, string $id): Workday
    {
        app(WorkdayAccess::class)->authorize($viewer, 'view_all');

        return $this->query()->where('workdays.uuid', $id)->firstOrFail();
    }

    public function detail(User $viewer, string $id): array
    {
        $day = $this->find($viewer, $id);

        return ['data' => $this->project($viewer, $day, $day->confirmedRevision)];
    }

    public function history(User $viewer, string $id, array $input): array
    {
        $day = $this->find($viewer, $id);
        $this->fields($input, ['page', 'per_page']);
        $data = Validator::make($input, ['page' => ['sometimes', 'integer', 'min:1', 'max:100000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']])->validate();
        $page = (int) ($data['page'] ?? 1);
        $size = (int) ($data['per_page'] ?? 20);
        $query = WorkdayRevision::query()->where('workday_id', $day->id)->whereIn('state', ['confirmed', 'recorded']);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('version')->offset(($page - 1) * $size)->limit($size)->get()
            ->map(fn ($revision) => $this->project($viewer, $day, $revision))->all();

        return ['data' => $rows, 'meta' => $this->pageMeta($total, count($rows), $page, $size)];
    }

    private function project(User $viewer, Workday $day, WorkdayRevision $revision, bool $sources = true): array
    {
        // Explicit allowlist: never serialize the model, draft version, correction reason or absence acknowledgement.
        $snapshot = Arr::only($revision->snapshot, ['description', 'intervals', 'breaks', 'gross_minutes',
            'actual_minutes', 'excluded_break_minutes', 'included_break_minutes', 'durations']);
        $snapshot['intervals'] = array_map(fn ($i) => Arr::only($i, ['start', 'end', 'minutes', 'description']), $snapshot['intervals']);
        $snapshot['breaks'] = array_map(fn ($b) => Arr::only($b, ['start', 'end', 'minutes', 'included']), $snapshot['breaks']);
        $snapshot['allocated_minutes'] = $revision->snapshot['allocated_minutes'] ?? 0;
        $snapshot['unallocated_minutes'] = $snapshot['actual_minutes'] - $snapshot['allocated_minutes'];
        $snapshot['allocations'] = array_map(function ($allocation) use ($viewer, $day, $revision, $sources) {
            $source = $sources ? $this->source($viewer, $day, $revision, $allocation) : null;

            return Arr::only($allocation, ['kind', 'basis', 'minutes', 'start', 'end', 'acknowledged']) + [
                'source' => $source ? ['status' => hash_equals($source['source_revision'], $allocation['source_revision']) ? 'current' : 'stale',
                    'title' => $source['title'], 'url' => $source['url']] : ['status' => $sources ? 'unavailable' : 'not_loaded', 'title' => null, 'url' => null],
            ];
        }, $revision->snapshot['allocations'] ?? []);

        return ['id' => $day->uuid, 'worker' => ['id' => $day->user_id, 'name' => $day->worker_name],
            'work_date' => $day->work_date, 'timezone' => $day->timezone,
            'confirmed' => ['id' => $revision->uuid, 'version' => $revision->version, 'confirmed_at' => $revision->created_at->toIso8601String(),
                'snapshot' => $snapshot]];
    }

    /** Source access belongs to the viewer, never impersonate the employee whose day is being read. */
    private function source(User $viewer, Workday $day, WorkdayRevision $revision, array $allocation): ?array
    {
        $evidence = app(SourceEvidence::class);
        $kind = $allocation['kind'];
        if (! $evidence->permitted($viewer, $kind)) {
            return null;
        }
        if ($kind === 'calendar') {
            [$from, $to] = $evidence->window($day, $revision->snapshot);
            $result = app(WorkdayEvidence::class)->read($viewer, (int) $allocation['calendar_id'], $from, $to);

            return collect($result['rows'])->firstWhere('source_key', $allocation['source_key']);
        }
        if (! preg_match('/^'.preg_quote($kind, '/').':([1-9][0-9]*)$/D', $allocation['source_key'], $match)) {
            return null;
        }
        $entry = $kind === 'task'
            ? app(\App\Modules\Task\Queries\OwnWorkdayTime::class)->reference($viewer, $day->user_id, (int) $match[1])
            : app(\App\Modules\Ticket\Queries\OwnWorkdayTime::class)->reference($viewer, $day->user_id, (int) $match[1]);

        return $entry ? $evidence->describeTime($entry, $kind) : null;
    }

    private function pageMeta(int $total, int $returned, int $page, int $perPage): array
    {
        return ['total' => $total, 'returned_count' => $returned, 'page' => $page, 'per_page' => $perPage,
            'last_page' => max(1, (int) ceil($total / $perPage)), 'next_page' => $page * $perPage < $total ? $page + 1 : null,
            'status' => $page === 1 && $returned === $total ? 'complete' : 'partial', 'truncated' => false];
    }

    private function fields(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) {
            throw ValidationException::withMessages(['filters' => 'Unknown filter. This report covers this installation only.']);
        }
    }
}
