<?php

namespace App\Modules\Ticket\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Services\CoordinatorPseudonymizer;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaleTicketController extends Controller
{
    public function index(Request $request, CoordinatorPseudonymizer $aliases): JsonResponse
    {
        abort_unless($request->user()?->can('ticket.view'), 403);
        $limits = $request->attributes->get('coordinator_policy_limits');
        $validated = $request->validate([
            'stale_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.$limits['maximum_page_size']],
        ]);
        $workload = $request->attributes->get('coordinator_workload');
        $staleDays = (int) ($validated['stale_days'] ?? 7);
        $perPage = (int) ($validated['per_page'] ?? min(25, $limits['maximum_page_size']));
        $query = Ticket::query()
            ->with(['priority:id,level', 'status:id,is_closed'])
            ->whereHas('status', fn (Builder $status) => $status->where('is_closed', false))
            ->where('updated_at', '<=', now()->subDays($staleDays));
        $this->applyScope($query, $workload);
        $paginator = $query->oldest('updated_at')->paginate($perPage);
        $paginator->getCollection()->transform(fn (Ticket $ticket): array => [
            'ticket_alias' => $aliases->alias($workload, 'ticket', $ticket->id),
            'technician_alias' => $aliases->alias($workload, 'technician', $ticket->owner_id),
            'client_alias' => $aliases->alias($workload, 'client', $ticket->client_id),
            'work_context_alias' => $aliases->alias($workload, 'work_context', $ticket->work_context_id),
            'age_days' => max(0, (int) $ticket->updated_at->diffInDays(now())),
            'priority_level' => $ticket->priority?->level,
            'first_response_overdue' => (bool) ($ticket->first_response_due_at?->isPast() && ! $ticket->first_responded_at),
            'resolution_overdue' => (bool) $ticket->resolve_due_at?->isPast(),
        ]);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'profile' => 'pseudonymized',
                'stale_days' => $staleDays,
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => min($paginator->total(), $limits['maximum_results']),
                'last_page' => min($paginator->lastPage(), (int) ceil($limits['maximum_results'] / $perPage)),
            ],
        ]);
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
}
