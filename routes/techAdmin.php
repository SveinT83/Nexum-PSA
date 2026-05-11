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
Route::middleware(['auth', 'tech', 'admin'])->group(function () {

        // ------------------------------------------------------------------------------------------
        // Admin Settings routes
        // ------------------------------------------------------------------------------------------

        // -----------------------------------------
        // Admin Page
        // -----------------------------------------
        Route::get('/admin', function () {
            return view('tech.admin.index');
        })->name('admin.index');

        // -----------------------------------------
        // Contracts & Services Settings
        // -----------------------------------------
        Route::get('/admin/settings/cs/contacts', function () {
            return view('tech.admin.settings.cs.contracts');
        })->name('admin.settings.cs.contracts');

        Route::get('/admin/settings/cs/services', function () {
            return view('tech.admin.settings.cs.services');
        })->name('admin.settings.cs.services');

        // -----------------------------------------
        // Sales Settings
        // -----------------------------------------
        Route::get('/admin/settings/sales/rules', function () {
            return view('tech.admin.settings.sales.rules.index');
        })->name('admin.settings.sales.rules');

        Route::get('/admin/settings/sales/workflows', function () {
            return view('tech.admin.settings.sales.workflows.index');
        })->name('admin.settings.sales.workflows');

        // -----------------------------------------
        // Ticket Settings
        // -----------------------------------------
        Route::get('/admin/settings/tickets', function () {
            return view('tech.admin.settings.tickets.index');
        })->name('admin.settings.tickets');

        Route::get('/admin/settings/tickets/rules', function () {
            return view('tech.admin.settings.tickets.rules.index');
        })->name('admin.settings.tickets.rules');

        Route::get('/admin/settings/tickets/workflows', function () {
            return view('tech.admin.settings.tickets.workflows.index');
        })->name('admin.settings.tickets.workflows');

    });
