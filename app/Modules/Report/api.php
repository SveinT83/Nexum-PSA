<?php

use App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload;
use App\Modules\Report\Controllers\Api\V1\ReportController;
use App\Modules\Report\Controllers\Api\V1\WorklogController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
|--------------------------------------------------------------------------
| Report API routes
|--------------------------------------------------------------------------
|
| The Report domain owns report discovery. Report calculations remain owned by
| the source domain until a shared runnable report contract is introduced.
|
*/

Route::get('reports', [ReportController::class, 'index'])
    ->name('reports.index')
    ->middleware(CheckAbilities::class.':report.read');

Route::get('reports/{reportKey}', [ReportController::class, 'show'])
    ->where('reportKey', '[A-Za-z0-9_.-]+')
    ->name('reports.show')
    ->middleware(CheckAbilities::class.':report.read');

Route::get('worklog/technicians', [WorklogController::class, 'technicians'])
    ->name('worklog.technicians.index')
    ->middleware(EnforceCoordinatorWorkload::class.':pseudonymized,worklog.read');

Route::get('worklog/time-entries', [WorklogController::class, 'timeEntries'])
    ->name('worklog.time-entries.index')
    ->middleware(EnforceCoordinatorWorkload::class.':pseudonymized,time-entries.read');
