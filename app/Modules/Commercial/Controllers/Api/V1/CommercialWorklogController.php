<?php

namespace App\Modules\Commercial\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Services\CoordinatorPseudonymizer;
use App\Modules\Integration\Services\CoordinatorReadScope;
use App\Modules\Integration\Services\CoordinatorReadWindow;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Commercial facts remain separate from actual technician work in Report. */
#[OA\Tag(name: 'Commercial worklog', description: 'Workload-bound direct consumption and pseudonymous contract/billing evidence.')]
class CommercialWorklogController extends Controller
{
    #[OA\Get(
        path: '/api/v1/commercial/worklog/time-consumptions', operationId: 'getCommercialWorklogConsumptions', summary: 'Read direct Commercial timebank consumption',
        description: 'Requires commercial.worklog.read in both read-only bound token and approved coordinator workload; report.view, commercial.view and commercial.timebank.view are mandatory. Contract links additionally require ticket.view and task.view. Installation context maximum, workload allowlists, provider/model approvals, expiry, network, rate and audit apply. Fixed pseudonymized profile; no raw IDs, names, notes or prices. Same date-window limit and truncation/period-subdivision contract as Report worklog. Billing basis and allocations are not additional actual time. Contract metadata is current, not an immutable historical document.',
        security: [['bearerAuth' => []]], tags: ['Commercial worklog'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive work date; default resolved date_to minus six days.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive work date; default today in installation timezone. Range bounded by maximum_query_days.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Default min(25, maximum_page_size); follow meta.next_page and inspect meta.truncated.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ], responses: [
            new OA\Response(response: 200, description: 'Bounded result; never infer completeness without checking truncation.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialConsumptionResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid Sanctum authentication.'),
            new OA\Response(response: 403, description: 'Missing actor permissions or denied coordinator policy, including workload_context_scope_missing.', content: new OA\JsonContent(ref: '#/components/schemas/WorklogAccessDenied')),
            new OA\Response(response: 422, description: 'Validation failure; message plus errors keyed by date/page field.'),
            new OA\Response(response: 429, description: 'request_rate_exceeded; 60-second limiter window.'),
        ], x: ['required-scopes' => ['commercial.worklog.read'], 'workload-bound' => true, 'data-profile' => 'pseudonymized'],
    )]
    public function consumptions(Request $request, CoordinatorReadWindow $window, CoordinatorReadScope $scope, CoordinatorPseudonymizer $aliases): JsonResponse
    {
        $this->authorizeRead($request);
        [$from, $to] = $window->dates($request);
        $workload = $request->attributes->get('coordinator_workload');
        $query = ClientContractTimeConsumption::query()->where('source', 'quick_client')
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->with(['contract:id,client_id,start_date,end_date,approval_status', 'contractItem:id,contract_id']);
        $scope->clients($query, $workload);
        $rows = $query->get()->map(fn (ClientContractTimeConsumption $entry): array => [
            'entry_alias' => $aliases->alias($workload, 'commercial_consumption', $entry->id),
            'client_alias' => $aliases->alias($workload, 'client', $entry->client_id),
            'technician_alias' => $aliases->alias($workload, 'technician', $entry->user_id),
            'fact_type' => 'direct_timebank_consumption',
            'source' => 'quick_client',
            'work_date' => $entry->work_date->toDateString(),
            'minutes' => (int) $entry->minutes,
            'contract' => $this->contractProjection($entry->contract_id, $entry->contract_item_id, $entry->client_id, $entry->contract, $entry->contractItem, $workload, $aliases),
        ]);

        return $window->paginate($request, $rows, $from, $to);
    }

    #[OA\Get(
        path: '/api/v1/commercial/worklog/contract-links', operationId: 'getCommercialWorklogContractLinks', summary: 'Read pseudonymous Ticket billing-basis and contract evidence',
        description: 'Requires commercial.worklog-links.read in both read-only bound token and approved coordinator workload; report.view, commercial.view and commercial.timebank.view are mandatory. Contract links additionally require ticket.view and task.view. Installation context maximum, workload allowlists, provider/model approvals, expiry, network, rate and audit apply. Fixed pseudonymized profile; no raw IDs, names, notes or prices. Same date-window limit and truncation/period-subdivision contract as Report worklog. Billing basis and allocations are not additional actual time. Contract metadata is current, not an immutable historical document.',
        security: [['bearerAuth' => []]], tags: ['Commercial worklog'],
        parameters: [
            new OA\Parameter(name: 'date_from', in: 'query', description: 'Inclusive work date; default resolved date_to minus six days.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', description: 'Inclusive work date; default today in installation timezone. Range bounded by maximum_query_days.', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, default: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', description: 'Default min(25, maximum_page_size); follow meta.next_page and inspect meta.truncated.', schema: new OA\Schema(type: 'integer', minimum: 1)),
        ], responses: [
            new OA\Response(response: 200, description: 'Bounded result; never infer completeness without checking truncation.', content: new OA\JsonContent(ref: '#/components/schemas/CommercialContractLinksResponse')),
            new OA\Response(response: 401, description: 'Missing or invalid Sanctum authentication.'),
            new OA\Response(response: 403, description: 'Missing actor permissions or denied coordinator policy, including workload_context_scope_missing.', content: new OA\JsonContent(ref: '#/components/schemas/WorklogAccessDenied')),
            new OA\Response(response: 422, description: 'Validation failure; message plus errors keyed by date/page field.'),
            new OA\Response(response: 429, description: 'request_rate_exceeded; 60-second limiter window.'),
        ], x: ['required-scopes' => ['commercial.worklog-links.read'], 'workload-bound' => true, 'data-profile' => 'pseudonymized'],
    )]
    public function contractLinks(Request $request, CoordinatorReadWindow $window, CoordinatorReadScope $scope, CoordinatorPseudonymizer $aliases): JsonResponse
    {
        $this->authorizeRead($request);
        abort_unless($request->user()->can('ticket.view') && $request->user()->can('task.view'), 403);
        [$from, $to] = $window->dates($request);
        $workload = $request->attributes->get('coordinator_workload');
        $query = TicketTimeEntry::query()
            ->whereDate('work_date', '>=', $from->toDateString())->whereDate('work_date', '<=', $to->toDateString())
            ->whereHas('ticket', fn (Builder $tickets) => $scope->records($tickets, $workload))
            ->with([
                'ticket:id,client_id,work_context_id',
                'task' => function (\Illuminate\Database\Eloquent\Relations\BelongsTo $tasks) use ($scope, $workload): void {
                    $scope->records($tasks->getQuery(), $workload);
                },
                'contract:id,client_id,start_date,end_date,approval_status', 'contractItem:id,contract_id',
                'allocation.contract:id,client_id,start_date,end_date,approval_status', 'allocation.contractItem:id,contract_id',
            ]);
        $rows = $query->get()->map(function (TicketTimeEntry $entry) use ($workload, $aliases): array {
            $clientId = $entry->ticket->client_id;
            $task = $entry->task;
            $taskLinked = $task && $task->owner_type === (new Ticket)->getMorphClass()
                && (int) $task->owner_id === (int) $entry->ticket_id
                && $task->client_id === $clientId;
            $allocation = $entry->allocation;
            $allocationData = null;
            if ($allocation) {
                $consistent = (int) $allocation->ticket_id === (int) $entry->ticket_id
                    && (int) $allocation->client_id === (int) $clientId;
                $allocationData = [
                    'link_status' => $consistent ? 'linked' : 'inconsistent',
                    'allocation_alias' => $consistent ? $aliases->alias($workload, 'ticket_allocation', $allocation->id) : null,
                    'status' => $consistent ? $allocation->status : null,
                    'covered_minutes' => $consistent ? (int) $allocation->covered_minutes : null,
                    'billable_minutes' => $consistent ? (int) $allocation->billable_minutes : null,
                    'contract' => $consistent ? $this->contractProjection($allocation->contract_id, $allocation->contract_item_id, $clientId, $allocation->contract, $allocation->contractItem, $workload, $aliases) : null,
                ];
            }

            return [
                'entry_alias' => $aliases->alias($workload, 'ticket_entry', $entry->id),
                'record_alias' => $entry->task_id
                    ? ($taskLinked ? $aliases->alias($workload, 'task', $entry->task_id) : null)
                    : $aliases->alias($workload, 'ticket', $entry->ticket_id),
                'record_link_status' => ! $entry->task_id || $taskLinked ? 'linked' : 'inconsistent',
                'client_alias' => $aliases->alias($workload, 'client', $clientId),
                'fact_type' => 'ticket_billing_basis',
                'source' => $entry->task_id ? 'task_billing_projection' : 'ticket',
                'work_date' => $entry->work_date->toDateString(),
                'basis_minutes' => (int) $entry->minutes,
                'billable' => (bool) $entry->billable,
                'contract' => $this->contractProjection($entry->contract_id, $entry->contract_item_id, $clientId, $entry->contract, $entry->contractItem, $workload, $aliases),
                'allocation' => $allocationData,
            ];
        });

        return $window->paginate($request, $rows, $from, $to);
    }

    private function authorizeRead(Request $request): void
    {
        abort_unless($request->user()?->can('report.view')
            && $request->user()->can('commercial.view')
            && $request->user()->can('commercial.timebank.view'), 403);
    }

    /** Never disclose a relationship pointing outside the already-authorized fact's Client. */
    private function contractProjection(?int $contractId, ?int $itemId, ?int $clientId, ?Contracts $contract, ?ContractItem $item, AiWorkloadProfile $workload, CoordinatorPseudonymizer $aliases): array
    {
        $valid = $contract && $clientId !== null && (int) $contract->client_id === $clientId
            && ($itemId === null || ($item && (int) $item->contract_id === (int) $contract->id));

        return [
            'link_status' => $valid ? 'linked' : ($contractId === null && $itemId === null ? 'unlinked' : 'inconsistent'),
            'contract_alias' => $valid ? $aliases->alias($workload, 'contract', $contract->id) : null,
            'contract_item_alias' => $valid ? $aliases->alias($workload, 'contract_item', $itemId) : null,
            'start_date' => $valid ? $contract->start_date?->toDateString() : null,
            'end_date' => $valid ? $contract->end_date?->toDateString() : null,
            'approval_status' => $valid ? $contract->approval_status : null,
        ];
    }
}
