<?php

namespace App\Modules\Report\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Services\CoordinatorPseudonymizer;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WorklogController extends Controller
{
    public function technicians(Request $request, CoordinatorPseudonymizer $aliases): JsonResponse
    {
        $this->authorizeReport($request);
        [$from, $to] = $this->dates($request);
        $workload = $this->workload($request);
        $entries = $this->ticketQuery($workload, $from, $to)
            ->get(['user_id', 'work_date', 'minutes', 'billable'])
            ->concat($this->taskQuery($workload, $from, $to)->get(['user_id', 'work_date', 'minutes', 'billable']));

        $data = $entries->filter(fn ($entry): bool => $entry->user_id !== null)
            ->groupBy('user_id')
            ->map(function (Collection $rows, int|string $userId) use ($workload, $aliases): array {
                return [
                    'technician_alias' => $aliases->alias($workload, 'technician', $userId),
                    'total_minutes' => (int) $rows->sum('minutes'),
                    'billable_minutes' => (int) $rows->where('billable', true)->sum('minutes'),
                    'entry_count' => $rows->count(),
                    'active_days' => $rows->pluck('work_date')->filter()->map->toDateString()->unique()->count(),
                ];
            })->sortBy('technician_alias')->values()->take($this->limits($request)['maximum_results']);

        return response()->json(['data' => $data, 'meta' => $this->meta($from, $to)]);
    }

    public function timeEntries(Request $request, CoordinatorPseudonymizer $aliases): JsonResponse
    {
        $this->authorizeReport($request);
        [$from, $to] = $this->dates($request);
        $workload = $this->workload($request);
        $limits = $this->limits($request);
        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$limits['maximum_page_size']],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? min(25, $limits['maximum_page_size']));

        $tickets = $this->ticketQuery($workload, $from, $to)->with('ticket:id,client_id,work_context_id')->get()
            ->map(fn (TicketTimeEntry $entry): array => $this->projection($entry, 'ticket', $entry->ticket_id, $entry->ticket?->client_id, $entry->ticket?->work_context_id, $workload, $aliases));
        $tasks = $this->taskQuery($workload, $from, $to)->with('task:id,client_id,work_context_id')->get()
            ->map(fn (TaskTimeEntry $entry): array => $this->projection($entry, 'task', $entry->task_id, $entry->task?->client_id, $entry->task?->work_context_id, $workload, $aliases));
        $all = $tickets->concat($tasks)
            ->sortByDesc(fn (array $entry): string => $entry['work_date'].'|'.$entry['entry_alias'])
            ->values()->take($limits['maximum_results']);

        return response()->json([
            'data' => $all->forPage($page, $perPage)->values(),
            'meta' => array_merge($this->meta($from, $to), ['page' => $page, 'per_page' => $perPage, 'total' => $all->count()]),
        ]);
    }

    private function ticketQuery(AiWorkloadProfile $workload, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TicketTimeEntry::query()
            ->whereBetween('work_date', [$from, $to])
            ->whereHas('ticket', fn (Builder $query) => $this->applyScope($query, $workload));
    }

    private function taskQuery(AiWorkloadProfile $workload, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TaskTimeEntry::query()
            ->whereBetween('work_date', [$from, $to])
            ->whereHas('task', fn (Builder $query) => $this->applyScope($query, $workload));
    }

    private function applyScope(Builder $query, AiWorkloadProfile $workload): void
    {
        if (($workload->allowed_client_ids ?? []) !== []) {
            $query->whereIn('client_id', $workload->allowed_client_ids);
        }
        if (($workload->allowed_work_context_ids ?? []) !== []) {
            $query->whereIn('work_context_id', $workload->allowed_work_context_ids);
        }
    }

    private function projection(
        mixed $entry,
        string $source,
        int $recordId,
        ?int $clientId,
        ?int $workContextId,
        AiWorkloadProfile $workload,
        CoordinatorPseudonymizer $aliases,
    ): array {
        return [
            'entry_alias' => $aliases->alias($workload, $source.'_entry', $entry->id),
            'record_alias' => $aliases->alias($workload, $source, $recordId),
            'technician_alias' => $aliases->alias($workload, 'technician', $entry->user_id),
            'client_alias' => $aliases->alias($workload, 'client', $clientId),
            'work_context_alias' => $aliases->alias($workload, 'work_context', $workContextId),
            'source' => $source,
            'work_date' => $entry->work_date?->toDateString(),
            'minutes' => (int) $entry->minutes,
            'billable' => (bool) $entry->billable,
        ];
    }

    private function dates(Request $request): array
    {
        $validated = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $to = CarbonImmutable::parse($validated['date_to'] ?? now()->toDateString())->endOfDay();
        $from = CarbonImmutable::parse($validated['date_from'] ?? $to->subDays(6)->toDateString())->startOfDay();
        if ($from->diffInDays($to) + 1 > $this->limits($request)['maximum_query_days']) {
            throw ValidationException::withMessages(['date_from' => 'The requested date range exceeds the workload policy.']);
        }

        return [$from, $to];
    }

    private function workload(Request $request): AiWorkloadProfile
    {
        return $request->attributes->get('coordinator_workload');
    }

    private function limits(Request $request): array
    {
        return $request->attributes->get('coordinator_policy_limits');
    }

    private function authorizeReport(Request $request): void
    {
        abort_unless($request->user()?->can('report.view'), 403);
    }

    private function meta(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return [
            'profile' => 'pseudonymized',
            'date_from' => $from->toDateString(),
            'date_to' => $to->toDateString(),
        ];
    }
}
