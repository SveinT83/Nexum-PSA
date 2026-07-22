<?php

// ---------------------------------------------------------------------------------------------------------------------------------------------------
// Use Domain Architecture rout file in the module folder, Read module-architecture.md for more info.
// ---------------------------------------------------------------------------------------------------------------------------------------------------

/**
 * Tech Administration Routes
 *
 * This file contains routes for the technical administration dashboard.
 * All routes are protected by auth, tech, and admin middleware.
 */

use Illuminate\Support\Facades\Route;

// ------------------------------------------------------------------------------------------
// Authenticated Tech-Admin/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth', 'tech', 'admin', 'tech.permission'])->group(function () {

    // ------------------------------------------------------------------------------------------
    // Admin Settings routes
    // ------------------------------------------------------------------------------------------

    // -----------------------------------------
    // Contracts & Services Settings
    // -----------------------------------------
    Route::redirect('/admin/settings/cs/contacts', '/tech/admin/settings/cs/contracts')
        ->name('admin.settings.cs.contracts.legacy');

    Route::get('/admin/settings/cs/contracts', function () {
        return view('tech.admin.settings.cs.contracts');
    })->name('admin.settings.cs.contracts');

    Route::get('/admin/settings/cs/services', function () {
        return view('tech.admin.settings.cs.services');
    })->name('admin.settings.cs.services');

});
