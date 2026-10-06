<?php

namespace App\Modules\Workday\Support;

use App\Modules\Workday\Controllers\Admin\WorkdaySettingsController;
use App\Modules\Workday\Controllers\Tech\AbsenceController;
use App\Modules\Workday\Controllers\Tech\SourceController;
use App\Modules\Workday\Controllers\Tech\TaskConversionController;
use App\Modules\Workday\Controllers\Tech\WorkdayController;
use App\Modules\Workday\Models\Workday;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;

class WorkdayPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('api/v1/workdays', 'api/v1/workdays/*', 'api/v1/workday-*',
            'tech/workdays', 'tech/workdays/*', 'tech/workday-*', 'tech/admin/settings/workday', 'tech/admin/settings/workday/*');
    }

    /** Render validation in the same request: input survives in the form, never in a persistent session copy. */
    public static function validation(ValidationException $exception, Request $request)
    {
        if (! self::matches($request) || $request->expectsJson() || $request->is('api/*')) {
            return null;
        }
        $session = $request->session();
        $previous = $session->get('_old_input');
        $session->put('_old_input', $request->except(['_token', '_method', 'password']));
        try {
            $read = clone $request;
            $read->setMethod('GET');
            $read->replace([]);
            $read->query->replace([]);
            $id = $request->route('id');
            $name = $request->route()?->getName();
            $view = match ($name) {
                'tech.absences.store' => app(AbsenceController::class)->create($read),
                'tech.absences.update', 'tech.absences.cancel' => app(AbsenceController::class)->show($read, $id),
                'tech.workdays.allocations' => app(SourceController::class)->index($read, $id),
                'tech.workdays.task-conversions.preview', 'tech.workdays.task-conversions.store' => app(TaskConversionController::class)->create($read, $id),
                'tech.admin.settings.workday.update' => app(WorkdaySettingsController::class)->settings($read),
                'tech.workdays.draft' => self::draft($read, $request),
                'tech.workdays.preview', 'tech.workdays.confirm', 'tech.workdays.correction' => app(WorkdayController::class)->show($read, $id),
                default => null,
            };
            if ($view instanceof \Illuminate\Contracts\View\View) {
                $view->with('errors', (new ViewErrorBag)->put('default', new MessageBag($exception->errors())));

                return response($view->render(), 422);
            }

            // Read/filter forms have no work text to retain; no withInput() or flash payload.
            return redirect()->back()->withErrors($exception->errors());
        } finally {
            $session->forget('_old_input');
            if ($previous !== null) {
                $session->put('_old_input', $previous);
            }
        }
    }

    private static function draft(Request $read, Request $submitted)
    {
        $date = $submitted->route('work_date');
        $day = Workday::where('user_id', $submitted->user()->id)->where('work_date', $date)->where('expires_at', '>', now())->first();
        if ($day) {
            return app(WorkdayController::class)->show($read, $day->uuid);
        }
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)) {
            $read->query->set('work_date', $date);
        }

        return app(WorkdayController::class)->create($read);
    }
}
