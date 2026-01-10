<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Tech\Admin\Settings\Economy\EconomyController;
use App\Http\Controllers\Tech\Admin\Settings\Economy\UnitsController;

// ------------------------------------------------------------------------------------------
// Authenticated Tech-Admin/Superuser routes
// ------------------------------------------------------------------------------------------
Route::middleware(['auth', 'tech', 'admin'])->group(function () {

    // ------------------------------------------------------------------------------------------
    // Admin Settings routes
    // ------------------------------------------------------------------------------------------

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
    // Economy Settings
    // -----------------------------------------

    // Economy Settings Dashboard
    Route::get('/admin/settings/economy', [EconomyController::class, 'index'])
        ->name('admin.settings.economy');

    // Economy Unit View's
    Route::get('/admin/settings/economy/units', [UnitsController::class, 'index'])
        ->name('admin.settings.economy.units');

    //Unit Store
    Route::get('/admin/settings/economy/units/store', [UnitsController::class, 'store'])
        ->name('admin.settings.economy.units.store');

    //Units Update
    Route::post('/admin/settings/economy/units/update/{unit}', [UnitsController::class, 'update'])
        ->name('admin.settings.economy.units.update');


    // -----------------------------------------
    // Email Settings
    // -----------------------------------------
    // Email Settings (controllers)
    Route::get('/admin/settings/email/accounts', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'index'])
        ->name('admin.settings.email.accounts');
    Route::get('/admin/settings/email/accounts/create', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'create'])
        ->name('admin.settings.email.accounts.create');
    Route::post('/admin/settings/email/accounts', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'store'])
        ->name('admin.settings.email.accounts.store');
    Route::get('/admin/settings/email/accounts/{account}/edit', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'edit'])
        ->name('admin.settings.email.accounts.edit');
    Route::put('/admin/settings/email/accounts/{account}', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'update'])
        ->name('admin.settings.email.accounts.update');
    Route::post('/admin/settings/email/accounts/{account}/toggle', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'toggleActive'])
        ->name('admin.settings.email.accounts.toggle');

    // Live connection test (IMAP/SMTP)
    Route::post('/admin/settings/email/accounts/{account}/test', [\App\Http\Controllers\Tech\Admin\Settings\Email\AccountsController::class, 'test'])
        ->name('admin.settings.email.accounts.test');

    Route::get('/admin/settings/email/config', [\App\Http\Controllers\Tech\Admin\Settings\Email\ConfigController::class, 'index'])
        ->name('admin.settings.email.config');
    Route::post('/admin/settings/email/config', [\App\Http\Controllers\Tech\Admin\Settings\Email\ConfigController::class, 'update'])
        ->name('admin.settings.email.config.update');

    Route::get('/admin/settings/email/rules', [\App\Http\Controllers\Tech\Admin\Settings\Email\RulesController::class, 'index'])
        ->name('admin.settings.email.rules');

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

    // ------------------------------------------------------------------------------------------
    // Other Admin routes
    // ------------------------------------------------------------------------------------------

    // -----------------------------------------
    // Templates
    // -----------------------------------------
    Route::get('/admin/templates', function () {
        return view('tech.admin.templates.index');
    })->name('admin.templates.index');

    // -----------------------------------------
    // Users
    // -----------------------------------------
    Route::get('/admin/users', function () {
        return view('tech.admin.users.index');
    })->name('admin.users.index');
    });
