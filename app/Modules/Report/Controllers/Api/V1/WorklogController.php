<?php

namespace App\Modules\Report\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Services\CoordinatorPseudonymizer;
use App\Modules\Integration\Services\CoordinatorReadScope;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use OpenApi\Attributes as OA;

#[OA\Tag(name: 'Worklog', description: 'Policy-bound pseudonymized Ticket and Task time; not payroll or invoice totals.')]
class WorklogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/worklog/technicians', operationId: 'getWorklogTechnicians', summary: 'Summarize registered Ticket and Task time by technician alias',
        description: 'Requires report.view, ticket.view, task.view and a read-only Sanctum bearer token bound to an active approved coordinator workload with worklog.read in both token and workload. No wildcard/write scopes. Installation, provider/model, workload, expiry, network and rate gates apply; response profile is always pseudonymized. maximum_results caps the whole date window, not each page. meta.total is uncapped; meta.truncated=true requires disjoint period subdivision. A single truncated day requires policy review; never claim a complete export. No snapshot isolation. Installation context_scope and workload allowlist intersection apply; see Report Knowledge contract.',
        security: [['bearerAuth' => []]], tags: ['Worklog'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive work date; defaults to six days before the resolved date_to. Send YYYY-MM-DD.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive work date; defaults to today in the installation timezone. Range must fit maximum_query_days.', schema: new OA\Schema(type: 'string', format: 'date')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful bounded read; check meta.truncated even when next_page is null.', content: new OA\JsonContent(ref: '#/components/schemas/WorklogTechniciansResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid Sanctum authentication; JSON message.'),
            new OA\Response(response: 403, description: 'Coordinator policy denial with reason_code/request_id, or missing report.view with JSON message.', content: new OA\JsonContent(ref: '#/components/schemas/WorklogAccessDenied')),
            new OA\Response(response: 422, description: 'Invalid date/page input or range/page size above policy; JSON message and errors keyed by field.'),
            new OA\Response(response: 429, description: 'Workload/binding rate exceeded; reason_code=request_rate_exceeded and request_id. Wait for the 60-second limiter window before retrying.'),
        ],
        x: ['required-scopes' => ['worklog.read'], 'workload-bound' => true, 'data-profile' => 'pseudonymized'],
    )]
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
            })->sortBy('technician_alias')->values();

        $total = $data->count();
        $data = $data->take($this->limits($request)['maximum_results']);

        return response()->json([
            'data' => $data,
            'meta' => $this->resultMeta($request, $from, $to, $total, $data->count()),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/worklog/time-entries', operationId: 'getWorklogTimeEntries', summary: 'Read registered Ticket and Task time within a bounded date window',
        description: 'Requires report.view, ticket.view, task.view and a read-only Sanctum bearer token bound to an active approved coordinator workload with time-entries.read in both token and workload. No wildcard/write scopes. Installation, provider/model, workload, expiry, network and rate gates apply; response profile is always pseudonymized. maximum_results caps the whole date window, not each page. meta.total is uncapped; meta.truncated=true requires disjoint period subdivision. A single truncated day requires policy review; never claim a complete export. No snapshot isolation. Installation context_scope and workload allowlist intersection apply; see Report Knowledge contract.',
        security: [['bearerAuth' => []]], tags: ['Worklog'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive work date; defaults to six days before the resolved date_to. Send YYYY-MM-DD.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive work date; defaults to today in the installation timezone. Range must fit maximum_query_days.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', description: 'One-based page within available_total, never beyond the policy ceiling. Follow meta.next_page.', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Default min(25, maximum_page_size); larger than the policy maximum returns 422.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful bounded read; check meta.truncated even when next_page is null.', content: new OA\JsonContent(ref: '#/components/schemas/WorklogTimeEntriesResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid Sanctum authentication; JSON message.'),
            new OA\Response(response: 403, description: 'Coordinator policy denial with reason_code/request_id, or missing report.view with JSON message.', content: new OA\JsonContent(ref: '#/components/schemas/WorklogAccessDenied')),
            new OA\Response(response: 422, description: 'Invalid date/page input or range/page size above policy; JSON message and errors keyed by field.'),
            new OA\Response(response: 429, description: 'Workload/binding rate exceeded; reason_code=request_rate_exceeded and request_id. Wait for the 60-second limiter window before retrying.'),
        ],
        x: ['required-scopes' => ['time-entries.read'], 'workload-bound' => true, 'data-profile' => 'pseudonymized'],
    )]
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
            ->values();
        // Count before the policy ceiling so consumers cannot mistake a capped window for a full export.
        $total = $all->count();
        $availableTotal = min($total, $limits['maximum_results']);
        $lastPage = max(1, (int) ceil($availableTotal / $perPage));
        $data = $all->take($limits['maximum_results'])->forPage($page, $perPage)->values();

        return response()->json([
            'data' => $data,
            'meta' => array_merge($this->resultMeta($request, $from, $to, $total, $data->count()), [
                'page' => $page,
                'per_page' => $perPage,
                'last_page' => $lastPage,
                'next_page' => $page < $lastPage ? $page + 1 : null,
            ]),
        ]);
    }

    private function ticketQuery(AiWorkloadProfile $workload, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return TicketTimeEntry::query()
            ->whereNull('task_id')
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
        app(CoordinatorReadScope::class)->records($query, $workload);
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
            'registration_basis' => $source === 'ticket' ? 'recorded' : match ($entry->source_type) {
                'estimated' => 'estimated',
                'manual', 'ticket_time_entry' => 'recorded',
                default => 'unknown',
            },
            'work_date' => $entry->work_date?->toDateString(),
            'minutes' => (int) $entry->minutes,
            'billable' => (bool) $entry->billable,
        ];
    }

    private function dates(Request $request): array
    {
        return app(\App\Modules\Integration\Services\CoordinatorReadWindow::class)->dates($request);
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
        abort_unless($request->user()?->can('report.view')
            && $request->user()->can('ticket.view')
            && $request->user()->can('task.view'), 403);
    }

    private function resultMeta(Request $request, CarbonImmutable $from, CarbonImmutable $to, int $total, int $returned): array
    {
        return app(\App\Modules\Integration\Services\CoordinatorReadWindow::class)->resultMeta($request, $from, $to, $total, $returned);
    }
}
