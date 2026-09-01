<?php

namespace App\Modules\Ticket\Queries;

use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Services\TicketRuleRetryPolicy;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Hydrate only the newest configured action attempts for each immutable
 * position while retaining an explicit count of older historical evidence.
 */
final class TicketRuleExecutionDetailQuery
{
    public function __construct(
        private readonly TicketRuleRetryPolicy $retryPolicy,
    ) {}

    public function hydrateBoundedActionAttempts(TicketRuleRun $run): void
    {
        $limit = $this->retryPolicy->maxAttemptsPerPosition();
        $ranked = TicketRuleActionResult::query()
            ->where('run_id', $run->id)
            ->select([
                'id',
                'execution_id',
                'position_key',
                'attempt_number',
            ])
            ->selectRaw(
                'ROW_NUMBER() OVER (PARTITION BY position_key ORDER BY attempt_number DESC, id DESC) AS attempt_rank',
            );
        $boundedIds = DB::query()
            ->fromSub($ranked->toBase(), 'ranked_action_attempts')
            ->select('id')
            ->where('attempt_rank', '<=', $limit);
        $results = TicketRuleActionResult::query()
            ->joinSub(
                $boundedIds,
                'bounded_action_attempts',
                fn (JoinClause $join): JoinClause => $join->on(
                    'ticket_rule_action_results.id',
                    '=',
                    'bounded_action_attempts.id',
                ),
            )
            ->select('ticket_rule_action_results.*')
            ->orderBy('ticket_rule_action_results.position')
            ->orderBy('ticket_rule_action_results.attempt_number')
            ->orderBy('ticket_rule_action_results.id')
            ->get();
        $totals = TicketRuleActionResult::query()
            ->where('run_id', $run->id)
            ->selectRaw('execution_id, COUNT(*) AS attempt_count')
            ->groupBy('execution_id')
            ->pluck('attempt_count', 'execution_id');
        $byExecution = $results->groupBy(
            fn (TicketRuleActionResult $result): int => (int) $result->execution_id,
        );

        foreach ($run->executions as $execution) {
            $displayed = $byExecution->get((int) $execution->id, collect())->values();
            $total = (int) ($totals[(int) $execution->id] ?? 0);
            $execution->setRelation('actionResults', $displayed);
            $execution->setAttribute(
                'action_attempts_omitted_count',
                max(0, $total - $displayed->count()),
            );
        }
    }
}
