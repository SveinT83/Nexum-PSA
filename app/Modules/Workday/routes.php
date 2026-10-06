<?php

use App\Modules\Workday\Controllers\Admin\WorkdaySettingsController;
use App\Modules\Workday\Controllers\Tech\AbsenceController;
use App\Modules\Workday\Controllers\Tech\OverviewController;
use App\Modules\Workday\Controllers\Tech\WorkdayController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Both transports call the same owner-scoped, versioned actions.
if (($tdpsaLoadingApiRoutes ?? false) === true) {
    Route::post('workday-settings/retention-preview', [\App\Modules\Workday\Controllers\Admin\RetentionController::class, 'preview'])->name('workdays.retention-preview')->middleware(CheckAbilities::class.':workdays.settings');
    Route::post('workdays/{id}/task-conversions/preview', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'preview'])->whereUuid('id')->name('workdays.task-conversions.preview')->middleware(CheckAbilities::class.':workday-task-conversion.write,tasks.read,tasks.create,tasks.update');
    Route::post('workdays/{id}/task-conversions', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'store'])->whereUuid('id')->name('workdays.task-conversions.store')->middleware(CheckAbilities::class.':workday-task-conversion.write,tasks.read,tasks.create,tasks.update');
    Route::get('workdays/{id}/task-conversions/{token}', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'show'])->whereUuid(['id', 'token'])->name('workdays.task-conversions.show')->middleware(CheckAbilities::class.':workday-task-conversion.write,tasks.read,tasks.create,tasks.update');
    Route::get('workday-reminders', [\App\Modules\Workday\Controllers\Tech\ReminderController::class, 'index'])->name('workday-reminders.index')->middleware(CheckAbilities::class.':workday-reminders.read');
    Route::post('workday-reminders/{id}/snooze', [\App\Modules\Workday\Controllers\Tech\ReminderController::class, 'snooze'])->whereUuid('id')->name('workday-reminders.snooze')->middleware(CheckAbilities::class.':workday-reminders.write');
    Route::get('workday-reminder-preferences', [\App\Modules\Workday\Controllers\Tech\ReminderController::class, 'preferences'])->name('workday-reminder-preferences.show')->middleware(CheckAbilities::class.':workday-reminders.read');
    Route::put('workday-reminder-preferences', [\App\Modules\Workday\Controllers\Tech\ReminderController::class, 'updatePreferences'])->name('workday-reminder-preferences.update')->middleware(CheckAbilities::class.':workday-reminders.write');
    Route::get('workdays/overview', [OverviewController::class, 'index'])->name('workdays.overview')->middleware(CheckAbilities::class.':workdays.read-all');
    Route::get('workdays/overview/{id}', [OverviewController::class, 'show'])->whereUuid('id')->name('workdays.overview.show')->middleware(CheckAbilities::class.':workdays.read-all');
    Route::get('workdays/overview/{id}/history', [OverviewController::class, 'history'])->whereUuid('id')->name('workdays.overview.history')->middleware(CheckAbilities::class.':workdays.read-all');
    Route::get('workdays/{work_date}/entry', [WorkdayController::class, 'entry'])->name('workdays.entry')->middleware(CheckAbilities::class.':workdays.read');
    Route::get('workdays', [WorkdayController::class, 'index'])->name('workdays.index')->middleware(CheckAbilities::class.':workdays.read');
    Route::get('workdays/{id}/sources', [\App\Modules\Workday\Controllers\Tech\SourceController::class, 'index'])->whereUuid('id')->name('workdays.sources')->middleware(CheckAbilities::class.':workdays.read');
    Route::put('workdays/{id}/allocations', [\App\Modules\Workday\Controllers\Tech\SourceController::class, 'update'])->whereUuid('id')->name('workdays.allocations')->middleware(CheckAbilities::class.':workdays.write');
    Route::get('workdays/{id}', [WorkdayController::class, 'show'])->name('workdays.show')->middleware(CheckAbilities::class.':workdays.read');
    Route::get('workdays/{id}/history', [WorkdayController::class, 'history'])->name('workdays.history')->middleware(CheckAbilities::class.':workdays.read');
    Route::put('workdays/{work_date}/draft', [WorkdayController::class, 'draft'])->name('workdays.draft')->middleware(CheckAbilities::class.':workdays.write');
    Route::put('workdays/{work_date}/save', [WorkdayController::class, 'save'])->name('workdays.save')->middleware(CheckAbilities::class.':workdays.write');
    Route::post('workdays/{id}/preview', [WorkdayController::class, 'preview'])->name('workdays.preview')->middleware(CheckAbilities::class.':workdays.read');
    Route::post('workdays/{id}/confirm', [WorkdayController::class, 'confirm'])->name('workdays.confirm')->middleware(CheckAbilities::class.':workdays.confirm');
    Route::post('workdays/{id}/corrections', [WorkdayController::class, 'correction'])->name('workdays.correction')->middleware(CheckAbilities::class.':workdays.write');
    Route::get('workday-settings', [WorkdaySettingsController::class, 'settings'])->name('workdays.settings')->middleware(CheckAbilities::class.':workdays.settings');
    Route::patch('workday-settings', [WorkdaySettingsController::class, 'updateSettings'])->name('workdays.settings.update')->middleware(CheckAbilities::class.':workdays.settings');

    Route::get('workday-absences', [AbsenceController::class, 'index'])->name('absences.index')->middleware(CheckAbilities::class.':workday-absences.read');
    Route::post('workday-absences', [AbsenceController::class, 'store'])->name('absences.store')->middleware(CheckAbilities::class.':workday-absences.write');
    Route::get('workday-absences/{id}', [AbsenceController::class, 'show'])->whereUuid('id')->name('absences.show')->middleware(CheckAbilities::class.':workday-absences.read');
    Route::get('workday-absences/{id}/history', [AbsenceController::class, 'history'])->whereUuid('id')->name('absences.history')->middleware(CheckAbilities::class.':workday-absences.read');
    Route::patch('workday-absences/{id}', [AbsenceController::class, 'update'])->whereUuid('id')->name('absences.update')->middleware(CheckAbilities::class.':workday-absences.write');
    Route::post('workday-absences/{id}/cancel', [AbsenceController::class, 'cancel'])->whereUuid('id')->name('absences.cancel')->middleware(CheckAbilities::class.':workday-absences.write');

    return;
}

Route::get('/workday-reminders/{id}/open', [\App\Modules\Workday\Controllers\Tech\ReminderController::class, 'open'])->whereUuid('id')->name('workday-reminders.open');
Route::post('/workday-reminders/{id}/snooze', [\App\Modules\Workday\Controllers\Tech\ReminderController::class, 'snooze'])->whereUuid('id')->name('workday-reminders.snooze');

Route::get('/workdays/overview', [OverviewController::class, 'index'])->name('workdays.overview');
Route::get('/workdays/overview/{id}', [OverviewController::class, 'show'])->whereUuid('id')->name('workdays.overview.show');
Route::get('/workdays/overview/{id}/history', [OverviewController::class, 'history'])->whereUuid('id')->name('workdays.overview.history');
Route::get('/workdays', [WorkdayController::class, 'index'])->name('workdays.index');
Route::get('/workdays/create', [WorkdayController::class, 'create'])->name('workdays.create');
Route::get('/workdays/{id}', [WorkdayController::class, 'show'])->name('workdays.show');
Route::put('/workdays/{work_date}/draft', [WorkdayController::class, 'draft'])->name('workdays.draft');
Route::put('/workdays/{work_date}/save', [WorkdayController::class, 'save'])->name('workdays.save');
Route::post('/workdays/{id}/preview', [WorkdayController::class, 'preview'])->name('workdays.preview');
Route::post('/workdays/{id}/confirm', [WorkdayController::class, 'confirm'])->name('workdays.confirm');
Route::post('/workdays/{id}/corrections', [WorkdayController::class, 'correction'])->name('workdays.correction');
Route::post('/admin/settings/workday/retention-preview', [\App\Modules\Workday\Controllers\Admin\RetentionController::class, 'preview'])->name('admin.settings.workday.retention-preview');
Route::get('/admin/settings/workday', [WorkdaySettingsController::class, 'settings'])->name('admin.settings.workday');
Route::patch('/admin/settings/workday', [WorkdaySettingsController::class, 'updateSettings'])->name('admin.settings.workday.update');

Route::get('/workday-absences', [AbsenceController::class, 'index'])->name('absences.index');
Route::get('/workday-absences/create', [AbsenceController::class, 'create'])->name('absences.create');
Route::post('/workday-absences', [AbsenceController::class, 'store'])->name('absences.store');
Route::get('/workday-absences/{id}', [AbsenceController::class, 'show'])->whereUuid('id')->name('absences.show');
Route::patch('/workday-absences/{id}', [AbsenceController::class, 'update'])->whereUuid('id')->name('absences.update');
Route::post('/workday-absences/{id}/cancel', [AbsenceController::class, 'cancel'])->whereUuid('id')->name('absences.cancel');

// Explicit conversion is isolated from day confirmation and uses the same action over UI and API.
Route::get('/workdays/{id}/task-conversions/create', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'create'])->whereUuid('id')->name('workdays.task-conversions.create');
Route::post('/workdays/{id}/task-conversions/preview', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'preview'])->whereUuid('id')->name('workdays.task-conversions.preview');
Route::post('/workdays/{id}/task-conversions', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'store'])->whereUuid('id')->name('workdays.task-conversions.store');
Route::get('/workdays/{id}/task-conversions/{token}', [\App\Modules\Workday\Controllers\Tech\TaskConversionController::class, 'show'])->whereUuid(['id', 'token'])->name('workdays.task-conversions.show');

Route::get('/workdays/{id}/sources', [\App\Modules\Workday\Controllers\Tech\SourceController::class, 'index'])->whereUuid('id')->name('workdays.sources');
Route::put('/workdays/{id}/allocations', [\App\Modules\Workday\Controllers\Tech\SourceController::class, 'update'])->whereUuid('id')->name('workdays.allocations');
