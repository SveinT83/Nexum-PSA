<?php

namespace App\Modules\DataExchange\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\DataExchange\Actions\NextDataExchangeScheduleRun;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DataExchangeScheduleController extends Controller
{
    public function store(Request $request, NextDataExchangeScheduleRun $nextRun): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.schedule'), 403);

        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');
        $data['weekdays'] = array_values(array_filter(array_map('intval', $request->input('weekdays', []))));
        $data['created_by'] = $request->user()?->id;
        $data['next_run_at'] = $data['active'] ? $nextRun->handle($data) : null;

        DataExchangeSchedule::query()->create($data);

        return back()->with('success', 'Schedule saved.');
    }

    public function update(Request $request, DataExchangeSchedule $schedule, NextDataExchangeScheduleRun $nextRun): RedirectResponse
    {
        abort_unless($request->user()?->can('data_exchange.schedule'), 403);

        $data = $this->validated($request);
        $data['active'] = $request->boolean('active');
        $data['weekdays'] = array_values(array_filter(array_map('intval', $request->input('weekdays', []))));
        $schedule->forceFill($data);
        $schedule->next_run_at = $schedule->active ? $nextRun->handle($schedule) : null;
        $schedule->save();

        return back()->with('success', 'Schedule updated.');
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'profile_id' => ['required', 'exists:data_exchange_profiles,id'],
            'delivery_target_id' => ['nullable', 'exists:data_exchange_delivery_targets,id'],
            'direction' => ['required', Rule::in(['export', 'import'])],
            'frequency' => ['required', Rule::in(['hourly', 'daily', 'weekly', 'monthly'])],
            'run_time' => ['nullable', 'date_format:H:i'],
        ]);
    }
}
