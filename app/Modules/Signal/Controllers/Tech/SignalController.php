<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Signal\Actions\EnsureSignalDefaults;
use App\Modules\Signal\Actions\ProcessSignalRules;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRuleExecution;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SignalController extends Controller
{
    public function index(Request $request, EnsureSignalDefaults $defaults): View
    {
        $defaults->handle();

        $query = app(\App\Modules\Signal\Queries\SignalFeed::class)->query($request);
        $range = (string) $request->query('range', '30');
        $sort = (string) $request->query('sort', 'occurred_at');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';

        return view('signal::Tech.index', [
            'signals' => $query->paginate(50)->withQueryString(),
            'range' => $range,
            'sort' => $sort,
            'direction' => $direction,
            'sourceOptions' => Signal::query()->distinct()->orderBy('source_domain')->pluck('source_domain'),
            'typeOptions' => Signal::query()->distinct()->orderBy('signal_type')->pluck('signal_type'),
            'severityOptions' => Signal::query()->distinct()->orderBy('severity')->pluck('severity'),
            'statusOptions' => Signal::query()->distinct()->orderBy('status')->pluck('status'),
        ]);
    }

    public function show(Signal $signal): View
    {
        return view('signal::Tech.show', [
            'signal' => $signal->load([
                'contact',
                'client',
                'executions' => fn ($query) => $query->with(['rule', 'retryOf', 'retries'])->orderByDesc('executed_at')->orderByDesc('id'),
            ]),
        ]);
    }

    public function retryExecution(
        Signal $signal,
        SignalRuleExecution $execution,
        Request $request,
        ProcessSignalRules $rules,
    ): RedirectResponse {
        abort_unless($execution->signal_id === $signal->id, 404);

        $data = $request->validate([
            'mode' => ['required', 'in:failed,all'],
        ]);

        $retry = $rules->retry($execution, $data['mode'] === 'all');

        return redirect()
            ->route('tech.admin.system.signals.show', $signal)
            ->with('status', $retry
                ? 'Signal rule retry completed and was added to the execution log.'
                : 'No retryable actions remain for this execution.');
    }
}
