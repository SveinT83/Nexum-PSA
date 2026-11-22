<?php

use App\Modules\Report\Controllers\Tech\ReportController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

// Keep API definitions in the module routes.php; api.php is a compatibility loader.
if (($reportTimeApiRoutes ?? false) === true) {
    // Identified personal reporting is separate from coordinator Worklog.
    Route::get('reports/time/entries', [\App\Modules\Report\Controllers\Tech\TimeReportController::class, 'index'])
        ->name('reports.time.entries')->middleware(CheckAbilities::class.':reports.time.read');
    Route::get('reports/time/lookups/{kind}', [\App\Modules\Report\Controllers\Tech\TimeReportController::class, 'lookup'])
        ->name('reports.time.lookup')->middleware(CheckAbilities::class.':reports.time.read');
    Route::get('reports/time/export', [\App\Modules\Report\Controllers\Tech\TimeReportController::class, 'export'])
        ->name('reports.time.export')->middleware(CheckAbilities::class.':reports.time.read,reports.time.export');

    return;
}

Route::get('/reports/time', [\App\Modules\Report\Controllers\Tech\TimeReportController::class, 'index'])->name('reports.time');
Route::get('/reports/time/lookups/{kind}', [\App\Modules\Report\Controllers\Tech\TimeReportController::class, 'lookup'])->name('reports.time.lookup');

Route::get('/reports', [ReportController::class, 'index'])
    ->name('reports.index');
