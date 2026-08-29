<?php

namespace App\Modules\Ticket\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Ticket\Actions\RetryTicketRuleAction;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Queries\TicketRuleExecutionDetailQuery;
use App\Modules\Ticket\Queries\TicketRuleExecutionIndexQuery;
use App\Modules\Ticket\Services\TicketRuleActionRetrySelector;
use App\Modules\Ticket\Services\TicketRuleEvidenceAccess;
use App\Modules\Ticket\Services\TicketRuleExecutionPresenter;
use App\Modules\Ticket\Services\TicketRuleFullRerunBoundary;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use RuntimeException;

/**
 * Internal, read-mostly Ticket Rule execution workspace.
 */
final class TicketRuleExecutionController extends Controller
{
    public function index(
        Request $request,
        TicketRuleExecutionIndexQuery $query,
        TicketRuleExecutionPresenter $presenter,
        TicketRuleEvidenceAccess $evidenceAccess,
    ): View {
        $filters = $request->validate([
            'rule_id' => ['nullable', 'integer', 'min:1'],
            'ticket' => ['nullable', 'string', 'max:64'],
            'event' => ['nullable', 'string', 'max:80'],
            'result' => ['nullable', 'in:running,succeeded,failed,no_change,loop_blocked'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['nullable', 'in:started_at,status,event,duration'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);
        $this->assertTicketView($request);
        $viewer = $request->user();
        $outcomeControlsAvailable = $evidenceAccess->canUseOutcomeIndexControls($viewer);
        if (! $outcomeControlsAvailable) {
            unset($filters['result']);
            if (in_array($filters['sort'] ?? null, ['status', 'duration'], true)) {
                unset($filters['sort'], $filters['direction']);
            }
        }
        $runs = $query->paginate($filters);
        $runs->through(fn (TicketRuleRun $run): array => $presenter->runSummary($run, $viewer));
        $ruleSelector = $query->ruleOptions(
            isset($filters['rule_id']) ? (int) $filters['rule_id'] : null,
        );

        return view('ticket::Admin.Settings.rules.executions.index', [
            'runs' => $runs,
            'filters' => $filters,
            'outcomeControlsAvailable' => $outcomeControlsAvailable,
            'eventOptions' => $query->eventOptions(),
            'resultOptions' => [
                'succeeded' => 'Succeeded',
                'failed' => 'Failed',
                'no_change' => 'No change',
                'loop_blocked' => 'Loop blocked',
                'running' => 'Running',
            ],
            'ruleOptions' => $ruleSelector['options'],
            'ruleOptionsOmittedCount' => $ruleSelector['omitted_count'],
            'canManageRules' => (bool) $request->user()?->can('ticket.manage_rules'),
        ]);
    }

    public function show(
        TicketRuleRun $run,
        Request $request,
        TicketRuleExecutionPresenter $presenter,
        TicketRuleExecutionDetailQuery $detailQuery,
        TicketRuleActionRetrySelector $retrySelector,
        TicketRuleFullRerunBoundary $fullRerun,
        TicketRuleRuntimeGate $runtimeGate,
    ): View {
        $this->assertTicketView($request);
        $run->load([
            'ticket',
            'retryOf:id,status,started_at',
            'events' => fn ($query) => $query->orderBy('sequence')->orderBy('id'),
            'executions' => fn ($query) => $query
                ->with([
                    'rule' => fn ($ruleQuery) => $ruleQuery->withTrashed(),
                    'version',
                ])
                ->orderBy('event_id')
                ->orderBy('order_position')
                ->orderBy('id'),
            'afterCommitResults' => fn ($query) => $query->orderBy('id'),
        ])->loadCount(['events', 'executions', 'actionResults']);
        $detailQuery->hydrateBoundedActionAttempts($run);

        $evidence = $presenter->runDetail($run, $request->user());
        $restricted = (bool) $evidence['restricted_evidence'];
        $canRetry = ! $restricted
            && $runtimeGate->enabled()
            && (bool) $request->user()?->can('ticket.rule_retry')
            && $run->ticket !== null;
        $retryableIds = $canRetry
            ? $retrySelector->candidates($run->ticket, (int) $run->id)->pluck('id')->map('intval')->all()
            : [];
        $fullRerunPreview = $request->session()->get('ticket_rule_full_rerun_preview');
        if ($restricted
            || ! is_array($fullRerunPreview)
            || (int) ($fullRerunPreview['source_run_id'] ?? 0) !== (int) $run->id) {
            $fullRerunPreview = null;
        }

        return view('ticket::Admin.Settings.rules.executions.show', [
            'run' => $run,
            'evidence' => $evidence,
            'retryableActionIds' => $retryableIds,
            'fullRerunAvailable' => ! $restricted && $fullRerun->availableFor($run, $request->user()),
            'fullRerunPreview' => $fullRerunPreview,
            'canViewRuleDetails' => (bool) $request->user()?->can('ticket.manage_rules'),
        ]);
    }

    public function retryAction(
        TicketRuleRun $run,
        TicketRuleActionResult $actionResult,
        Request $request,
        RetryTicketRuleAction $retry,
    ): RedirectResponse {
        $this->assertTicketView($request);
        abort_unless((int) $actionResult->run_id === (int) $run->id, 404);

        try {
            $attempt = $retry->handle($run, $actionResult, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['retry' => $exception->getMessage()]);
        }

        return redirect()
            ->route('tech.admin.settings.tickets.rules.executions.show', $run)
            ->with(
                $attempt->status === TicketRuleActionResult::STATUS_FAILED ? 'warning' : 'success',
                $attempt->status === TicketRuleActionResult::STATUS_FAILED
                    ? 'The action retry was recorded as failed. Review the immutable attempt evidence.'
                    : 'The action retry completed and was added to the immutable execution evidence.',
            );
    }

    public function previewFullRerun(
        TicketRuleRun $run,
        Request $request,
        TicketRuleFullRerunBoundary $fullRerun,
    ): RedirectResponse {
        $this->assertTicketView($request);

        try {
            $preview = $fullRerun->preview($run, $request->user());
        } catch (RuntimeException $exception) {
            return back()->withErrors(['full_rerun' => $exception->getMessage()]);
        }

        return back()->with('ticket_rule_full_rerun_preview', $preview);
    }

    public function fullRerun(
        TicketRuleRun $run,
        Request $request,
        TicketRuleFullRerunBoundary $fullRerun,
    ): RedirectResponse {
        $this->assertTicketView($request);
        $data = $request->validate([
            'preview_receipt' => ['required', 'string', 'max:8192'],
            'confirm_full_rerun' => ['accepted'],
        ]);

        try {
            $result = $fullRerun->execute($run, $request->user(), $data['preview_receipt']);
        } catch (RuntimeException $exception) {
            return back()->withErrors(['full_rerun' => $exception->getMessage()]);
        }

        $successful = in_array($result->terminalStatus, [
            TicketRuleRun::STATUS_SUCCEEDED,
            TicketRuleRun::STATUS_NO_CHANGE,
        ], true);
        $message = match ($result->terminalStatus) {
            TicketRuleRun::STATUS_SUCCEEDED => 'The separately previewed full rerun succeeded as a linked immutable run.',
            TicketRuleRun::STATUS_NO_CHANGE => 'The separately previewed full rerun completed with no changes as a linked immutable run.',
            TicketRuleRun::STATUS_LOOP_BLOCKED => 'The linked full rerun was stopped by a loop or execution-budget guard. Review its immutable evidence.',
            default => 'The linked full rerun completed with a failed status. Review its immutable evidence.',
        };

        return redirect()
            ->route('tech.admin.settings.tickets.rules.executions.show', $result->run)
            ->with($successful ? 'success' : 'warning', $message);
    }

    private function assertTicketView(Request $request): void
    {
        abort_unless($request->user()?->can('ticket.view'), 403);
    }
}
