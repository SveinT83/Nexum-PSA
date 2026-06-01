<?php

use App\Modules\Report\Controllers\Tech\ReportController;
use Illuminate\Support\Facades\Route;

Route::get('/reports', [ReportController::class, 'index'])
    ->name('reports.index');
