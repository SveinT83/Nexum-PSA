<?php

namespace App\Modules\Signal\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Signal\Actions\EnsureSignalDefaults;
use App\Modules\Signal\Actions\ProcessSignalRules;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRuleExecution;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SignalController extends Controller
{
    public function index(Request $request, EnsureSignalDefaults $defaults): View
    {
        $defaults->handle();

        $request->validate([
            'range' => ['nullable', 'in:7,30,90,custom,all'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'q' => ['nullable', 'string', 'max:255'],
            'source_domain' => ['nullable', 'string', 'max:80'],
            'signal_type' => ['nullable', 'string', 'max:100'],
            'severity' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'sort' => ['nullable', 'in:signal_type,source_domain,severity,confidence,status,executions_count,occurred_at'],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $range = (string) $request->query('range', '30');
        $allowedRanges = ['7', '30', '90', 'custom', 'all'];
        if (! in_array($range, $allowedRanges, true)) {
            $range = '30';
        }

        $sort = (string) $request->query('sort', 'occurred_at');
        $allowedSorts = ['signal_type', 'source_domain', 'severity', 'confidence', 'status', 'executions_count', 'occurred_at'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'occurred_at';
        }

        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $query = Signal::query()->with(['contact', 'client'])->withCount('executions');

        if ($range === 'custom') {
            $query
                ->when($request->date('from'), fn (Builder $builder, $from) => $builder->where('occurred_at', '>=', $from->startOfDay()))
                ->when($request->date('to'), fn (Builder $builder, $to) => $builder->where('occurred_at', '<=', $to->endOfDay()));
        } elseif ($range !== 'all') {
            $query->where('occurred_at', '>=', now()->subDays((int) $range));
        }

        $query
            ->when(trim((string) $request->query('q')), function (Builder $builder, string $search): void {
                $builder->where(function (Builder $nested) use ($search): void {
                    $like = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';
                    $nested
                        ->where('summary', 'like', $like)
                        ->orWhere('signal_type', 'like', $like)
                        ->orWhere('source_domain', 'like', $like)
                        ->orWhereHas('contact', fn (Builder $contact) => $contact->where('display_name', 'like', $like))
                        ->orWhereHas('client', fn (Builder $client) => $client->where('name', 'like', $like));
                });
            })
            ->when($request->query('source_domain'), fn (Builder $builder, string $value) => $builder->where('source_domain', $value))
            ->when($request->query('signal_type'), fn (Builder $builder, string $value) => $builder->where('signal_type', $value))
            ->when($request->query('severity'), fn (Builder $builder, string $value) => $builder->where('severity', $value))
            ->when($request->query('status'), fn (Builder $builder, string $value) => $builder->where('status', $value))
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction);

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
