<?php
// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture rout file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

use Illuminate\Support\Facades\Route;


// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Tech routes
//
// Routes fore tech url's - Middleware: auth, tech
// ---------------------------------------------------------------------------------------------------------------------------------------------------

// ------------------------------------------------------------------------------------------
// Authenticated Tech/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth','tech'])->group(function () {

    foreach (glob(app_path('Modules/*/routes.php')) as $routeFile) {
        require $routeFile;
    }

    Route::get('/dashboard', function () {
        return view('tech.dashboard');
    })->name('dashboard');

    // -----------------------------------------
    // Knowledge
    // -----------------------------------------
    // -----------------------------------------
    // Reports
    // -----------------------------------------
    Route::get('/reports', function () {
        return view('tech.reports.index');
    })->name('reports.index');

    // -----------------------------------------
    // Sales
    // -----------------------------------------
    Route::get('/sales', function () {
        return view('tech.sales.index');
    })->name('sales.index');

    // -----------------------------------------
    // Storage
    // -----------------------------------------
    Route::get('/storage', function () {
        return view('tech.storage.index');
    })->name('storage.index');

    // -----------------------------------------
    // Tasks
    // -----------------------------------------
    Route::get('/tasks', function () {
        return view('tech.tasks.index');
    })->name('tasks.index');

    // -----------------------------------------
    // Tickets
    // -----------------------------------------
    Route::get('/tickets', function () {
        return view('tech.tickets.index');
    })->name('tickets.index');

});
