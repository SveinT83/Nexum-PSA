<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Queries\TicketRuleExecutionIndexQuery;
use App\Modules\Ticket\Services\TicketRuleExecutionPresenter;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only rule detail surface backed by immutable published versions.
 */
final class TicketRuleReadController extends Controller
{
    public function show(
        TicketRule $rule,
        Request $request,
        TicketRuleExecutionIndexQuery $executionQuery,
        TicketRuleExecutionPresenter $presenter,
    ): View {
        $rule->load([
            'versions' => fn ($query) => $query
                ->orderByDesc('version_number')
                ->orderByDesc('id'),
        ])->loadCount(['executions', 'logs']);

        $canViewExecutions = (bool) $request->user()?->can('ticket.view')
            && (bool) $request->user()?->can('ticket.rule_execution_view');
        $recentRuns = null;

        if ($canViewExecutions) {
            $recentRuns = $executionQuery->paginate([
                'rule_id' => (int) $rule->id,
                'sort' => 'started_at',
                'direction' => 'desc',
            ], 20);
            $recentRuns->through(
                fn (TicketRuleRun $run): array => $presenter->runSummary($run, $request->user()),
            );
        }

        return view('ticket::Admin.Settings.rules.show', [
            'ruleModel' => $rule,
            'rule' => $presenter->ruleDetail($rule),
            'recentRuns' => $recentRuns,
            'canViewExecutions' => $canViewExecutions,
        ]);
    }
}
