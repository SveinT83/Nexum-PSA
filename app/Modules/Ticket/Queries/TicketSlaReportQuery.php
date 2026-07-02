<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TicketSlaReportQuery
{
    /*
    |--------------------------------------------------------------------------
    | Ticket SLA reporting foundation
    |--------------------------------------------------------------------------
    |
    | The first report intentionally uses stored ticket timestamps only. It
    | avoids business-hour recalculation so the numbers match the operational
    | SLA state already visible on ticket list and show screens.
    |
    */
    public function summary(?Carbon $from = null, ?Carbon $to = null, string $context = 'client'): array
    {
        $base = $this->baseQuery($from, $to, $context);
        $openBase = $this->openTickets($base);

        return [
            'response_overdue' => (clone $openBase)
                ->whereNotNull('first_response_due_at')
                ->whereNull('first_responded_at')
                ->where('first_response_due_at', '<', now())
                ->count(),
            'resolve_overdue' => (clone $openBase)
                ->whereNotNull('resolve_due_at')
                ->whereNull('resolved_at')
                ->where('resolve_due_at', '<', now())
                ->count(),
            'responded_within_sla' => (clone $base)
                ->whereNotNull('first_response_due_at')
                ->whereNotNull('first_responded_at')
                ->whereColumn('first_responded_at', '<=', 'first_response_due_at')
                ->count(),
            'resolved_within_sla' => (clone $base)
                ->whereNotNull('resolve_due_at')
                ->whereNotNull('resolved_at')
                ->whereColumn('resolved_at', '<=', 'resolve_due_at')
                ->count(),
            'responded_late' => (clone $base)
                ->whereNotNull('first_response_due_at')
                ->whereNotNull('first_responded_at')
                ->whereColumn('first_responded_at', '>', 'first_response_due_at')
                ->count(),
            'resolved_late' => (clone $base)
                ->whereNotNull('resolve_due_at')
                ->whereNotNull('resolved_at')
                ->whereColumn('resolved_at', '>', 'resolve_due_at')
                ->count(),
            'tickets_with_sla' => (clone $base)
                ->whereNotNull('sla_id')
                ->count(),
        ];
    }

    public function overdueTickets(int $limit = 15, string $context = 'client'): Collection
    {
        return $this->openTickets($this->applyContext(Ticket::query(), $context))
            ->with(['client', 'priority', 'status', 'owner'])
            ->where(function ($query): void {
                $query
                    ->where(function ($responseQuery): void {
                        $responseQuery
                            ->whereNotNull('first_response_due_at')
                            ->whereNull('first_responded_at')
                            ->where('first_response_due_at', '<', now());
                    })
                    ->orWhere(function ($resolveQuery): void {
                        $resolveQuery
                            ->whereNotNull('resolve_due_at')
                            ->whereNull('resolved_at')
                            ->where('resolve_due_at', '<', now());
                    });
            })
            ->orderByRaw('CASE
                WHEN first_response_due_at IS NULL THEN resolve_due_at
                WHEN resolve_due_at IS NULL THEN first_response_due_at
                WHEN first_response_due_at <= resolve_due_at THEN first_response_due_at
                ELSE resolve_due_at
            END ASC')
            ->limit($limit)
            ->get();
    }

    private function baseQuery(?Carbon $from = null, ?Carbon $to = null, string $context = 'client'): Builder
    {
        return $this->applyContext(Ticket::query(), $context)
            ->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to));
    }

    private function applyContext(Builder $query, string $context): Builder
    {
        return match ($context) {
            'all' => $query,
            'internal' => $query->whereHas('workContext', fn (Builder $contextQuery) => $contextQuery->where('type', 'internal')),
            default => $query->whereNotNull('client_id'),
        };
    }

    private function openTickets(Builder $query): Builder
    {
        return $query->whereHas('status', fn ($statusQuery) => $statusQuery->where('is_closed', false));
    }
}
