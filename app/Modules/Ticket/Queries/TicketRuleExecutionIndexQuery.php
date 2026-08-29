<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Build the bounded internal Ticket Rule execution ledger query.
 */
final class TicketRuleExecutionIndexQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function paginate(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $sort = match ($filters['sort'] ?? null) {
            'status' => 'status',
            'event' => 'root_event_key',
            'duration' => 'duration_ms',
            default => 'started_at',
        };
        $direction = ($filters['direction'] ?? null) === 'asc' ? 'asc' : 'desc';
        $ticketReference = trim((string) ($filters['ticket'] ?? ''));

        $query = TicketRuleRun::query()
            ->with([
                'ticket:id,ticket_key,work_context_id',
                'retryOf:id,status,started_at',
                'executions' => fn ($query) => $query
                    ->with([
                        'rule' => fn ($ruleQuery) => $ruleQuery->withTrashed(),
                        'version:id,ticket_rule_id,version_number,name,definition_schema_version,definition_checksum,definition_json',
                    ])
                    ->orderBy('order_position')
                    ->orderBy('id'),
            ])
            ->withCount(['events', 'executions', 'actionResults']);

        if (($filters['rule_id'] ?? null) !== null) {
            $query->whereHas(
                'executions',
                fn (Builder $execution): Builder => $execution->where(
                    'ticket_rule_id',
                    (int) $filters['rule_id'],
                ),
            );
        }

        if ($ticketReference !== '') {
            $query->whereHas('ticket', function (Builder $ticket) use ($ticketReference): void {
                $ticket->where(function (Builder $candidate) use ($ticketReference): void {
                    $candidate->where('ticket_key', $ticketReference);

                    if (ctype_digit($ticketReference) && (int) $ticketReference > 0) {
                        $candidate->orWhereKey((int) $ticketReference);
                    }
                });
            });
        }

        if (is_string($filters['event'] ?? null) && $filters['event'] !== '') {
            $query->where('root_event_key', $filters['event']);
        }

        if (is_string($filters['result'] ?? null) && $filters['result'] !== '') {
            $query->where('status', $filters['result']);
        }

        if (is_string($filters['from'] ?? null) && $filters['from'] !== '') {
            $query->where('started_at', '>=', $filters['from'].' 00:00:00');
        }

        if (is_string($filters['to'] ?? null) && $filters['to'] !== '') {
            $query->where('started_at', '<=', $filters['to'].' 23:59:59');
        }

        return $query
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate(min(100, max(1, $perPage)))
            ->withQueryString();
    }

    /** @return list<string> */
    public function eventOptions(): array
    {
        return TicketRuleRun::query()
            ->whereNotNull('root_event_key')
            ->distinct()
            ->orderBy('root_event_key')
            ->pluck('root_event_key')
            ->filter(fn (mixed $value): bool => is_string($value) && $value !== '')
            ->values()
            ->all();
    }

    /**
     * Bound the selector independently from the ledger. A selected historical
     * rule remains visible even when it sorts outside the display cap.
     *
     * @return array{options: Collection<int, TicketRule>, omitted_count: int}
     */
    public function ruleOptions(?int $selectedRuleId = null, int $limit = 200): array
    {
        $limit = min(200, max(1, $limit));
        $base = TicketRule::query()->withTrashed();
        $total = (clone $base)->count();
        $options = $base
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'name', 'deleted_at']);

        if ($selectedRuleId !== null
            && $selectedRuleId > 0
            && ! $options->contains('id', $selectedRuleId)) {
            $selected = TicketRule::query()
                ->withTrashed()
                ->find($selectedRuleId, ['id', 'name', 'deleted_at']);
            if ($selected !== null) {
                if ($options->count() >= $limit) {
                    $options->pop();
                }
                $options->prepend($selected);
            }
        }

        return [
            'options' => $options->values(),
            'omitted_count' => max(0, $total - $options->count()),
        ];
    }
}
