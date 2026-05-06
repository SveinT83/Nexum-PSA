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
use App\Http\Controllers\Tech\Admin\Settings\Economy\EconomyController;
use App\Http\Controllers\Tech\Admin\Settings\Economy\UnitsController;
use App\Http\Controllers\Tech\Admin\System\integrations\IntegrationsController;
use App\Http\Controllers\Tech\Admin\System\integrations\ApiController;
use App\Http\Controllers\Tech\Admin\System\TemplatesManagement\TemplatesManagementController;
use App\Http\Controllers\Tech\Admin\UserManagement\UserManagementController;
use App\Http\Controllers\Tech\Admin\UserManagement\RolesManagementController;
use App\Http\Controllers\Tech\Admin\UserManagement\PermissionManagementController;

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
        // Economy Settings
        // -----------------------------------------

        // Economy Settings Dashboard
        Route::get('/admin/settings/economy', [EconomyController::class, 'index'])
            ->name('admin.settings.economy');

        // Economy Settings Update
        Route::post('/admin/settings/economy/update', [EconomyController::class, 'update'])
            ->name('admin.settings.economy.update');

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
        // TemplatesManagement
        // -----------------------------------------

        //Index: Main page
        Route::get('/admin/system/templatesManagement', [TemplatesManagementController::class, 'index'])
            ->name('admin.system.templatesManagement.index');

        //doc: Documentation page
        Route::get('/admin/system/templatesManagement/doc', [TemplatesManagementController::class, 'docIndex'])
            ->name('admin.system.templatesManagement.doc.index');

        //doc: Create page
        Route::get('/admin/system/templatesManagement/doc/create', [TemplatesManagementController::class, 'docCreate'])
            ->name('admin.system.templatesManagement.doc.create');

        //doc: Edit page
        Route::get('/admin/system/templatesManagement/doc/edit/{id}', [TemplatesManagementController::class, 'docEdit'])
            ->name('admin.system.templatesManagement.doc.edit');

        // -----------------------------------------
        // Users
        // -----------------------------------------

        //User Dashboard
        Route::get('/admin/user_management', [UserManagementController::class, 'index'])
            ->name('admin.user_management.index');

        //User Create
        Route::get('/admin/user_management/create', [UserManagementController::class, 'create'])
            ->name('admin.user_management.create');

        //User store
        Route::post('/admin/user_management/store', [UserManagementController::class, 'store'])
            ->name('admin.user_management.store');

        //Roles Dashboard
        Route::get('/admin/user_management/roles', [RolesManagementController::class, 'rolesIndex'])
            ->name('admin.user_management.roles.index');

        //Roles Edit
        Route::get('/admin/user_management/roles/edit/{id}', [RolesManagementController::class, 'rolesEdit'])
            ->name('admin.user_management.roles.edit');

        //Roles Update
        Route::post('/admin/user_management/roles/update/{id}', [RolesManagementController::class, 'rolesUpdate'])
            ->name('admin.user_management.roles.update');

        //Roles Create
        Route::get('/admin/user_management/roles/create', [RolesManagementController::class, 'rolesCreate'])
            ->name('admin.user_management.roles.create');

        //Roles Store
        Route::post('/admin/user_management/roles/store', [RolesManagementController::class, 'rolesStore'])
            ->name('admin.user_management.roles.store');

        //Roles Destroy
        Route::delete('/admin/user_management/roles/destroy/{id}', [RolesManagementController::class, 'rolesDestroy'])
            ->name('admin.user_management.roles.destroy');

        //Permissions Dashboard
        Route::get('/admin/user_management/permissions', [PermissionManagementController::class, 'permissionsIndex'])
            ->name('admin.user_management.permissions.index');

        //Permissions Edit
        Route::get('/admin/user_management/permissions/edit/{id}', [PermissionManagementController::class, 'permissionsEdit'])
            ->name('admin.user_management.permissions.edit');

        //Permissions Update
        Route::post('/admin/user_management/permissions/update/{id}', [PermissionManagementController::class, 'permissionsUpdate'])
            ->name('admin.user_management.permissions.update');

        //Permissions Create
        Route::get('/admin/user_management/permissions/create', [PermissionManagementController::class, 'permissionsCreate'])
            ->name('admin.user_management.permissions.create');

        //Permissions Store
        Route::post('/admin/user_management/permissions/store', [PermissionManagementController::class, 'permissionsStore'])
            ->name('admin.user_management.permissions.store');

        //Permissions Destroy
        Route::delete('/admin/user_management/permissions/destroy/{id}', [PermissionManagementController::class, 'permissionsDestroy'])
            ->name('admin.user_management.permissions.destroy');

        // -----------------------------------------
        // Category
        // -----------------------------------------
        Route::get('/admin/system/category', [App\Http\Controllers\Tech\Admin\System\CategoryController::class, 'index'])
            ->name('admin.system.category.index');

        Route::post('/admin/system/category/store', [App\Http\Controllers\Tech\Admin\System\CategoryController::class, 'store'])
            ->name('admin.system.category.store');

        Route::put('/admin/system/category/update/{category}', [App\Http\Controllers\Tech\Admin\System\CategoryController::class, 'update'])
            ->name('admin.system.category.update');

        Route::delete('/admin/system/category/destroy/{category}', [App\Http\Controllers\Tech\Admin\System\CategoryController::class, 'destroy'])
            ->name('admin.system.category.destroy');

        // -----------------------------------------
        // Tag
        // -----------------------------------------
        Route::get('/admin/system/tag', [App\Http\Controllers\Tech\Admin\System\TagController::class, 'index'])
            ->name('admin.system.tag.index');

        Route::post('/admin/system/tag/store', [App\Http\Controllers\Tech\Admin\System\TagController::class, 'store'])
            ->name('admin.system.tag.store');

        Route::put('/admin/system/tag/update/{tag}', [App\Http\Controllers\Tech\Admin\System\TagController::class, 'update'])
            ->name('admin.system.tag.update');

        Route::delete('/admin/system/tag/destroy/{tag}', [App\Http\Controllers\Tech\Admin\System\TagController::class, 'destroy'])
            ->name('admin.system.tag.destroy');

        // -----------------------------------------
        // Integration Settings
        // -----------------------------------------
        Route::get('/admin/system/integrations', [IntegrationsController::class, 'index'])
            ->name('admin.system.integrations.index');

        Route::post('/admin/system/integrations/toggle', [IntegrationsController::class, 'toggle'])
            ->name('admin.system.integrations.toggle');

        Route::get('/admin/system/integrations/nable-rmm', [IntegrationsController::class, 'nableRmmSettings'])
            ->name('admin.system.integrations.nable_rmm.settings');

        Route::post('/admin/system/integrations/nable-rmm', [IntegrationsController::class, 'nableRmmUpdate'])
            ->name('admin.system.integrations.nable_rmm.update');

        Route::post('/admin/system/integrations/nable-rmm/settings', [IntegrationsController::class, 'nableRmmUpdateSettings'])
            ->name('admin.system.integrations.nable_rmm.update_settings');

        Route::post('/admin/system/integrations/nable-rmm/sync-from', [IntegrationsController::class, 'nableRmmSyncFrom'])
            ->name('admin.system.integrations.nable_rmm.sync_from');

        Route::post('/admin/system/integrations/nable-rmm/sync-to', [IntegrationsController::class, 'nableRmmSyncTo'])
            ->name('admin.system.integrations.nable_rmm.sync_to');

        Route::post('/admin/system/integrations/nable-rmm/sync-sites-from', [IntegrationsController::class, 'nableRmmSyncSitesFrom'])
            ->name('admin.system.integrations.nable_rmm.sync_sites_from');

        Route::post('/admin/system/integrations/nable-rmm/sync-sites-to', [IntegrationsController::class, 'nableRmmSyncSitesTo'])
            ->name('admin.system.integrations.nable_rmm.sync_sites_to');

        // Tactical RMM
        Route::get('/admin/system/integrations/tactical-rmm', [IntegrationsController::class, 'tacticalRmmSettings'])
            ->name('admin.system.integrations.tactical_rmm.settings');

        Route::post('/admin/system/integrations/tactical-rmm', [IntegrationsController::class, 'tacticalRmmUpdate'])
            ->name('admin.system.integrations.tactical_rmm.update');

        Route::post('/admin/system/integrations/tactical-rmm/settings', [IntegrationsController::class, 'tacticalRmmUpdateSettings'])
            ->name('admin.system.integrations.tactical_rmm.update_settings');

        // -----------------------------------------
        // API Management
        // -----------------------------------------
        Route::get('/admin/system/integrations/api', [ApiController::class, 'index'])
            ->name('admin.system.integrations.api.index');

        Route::post('/admin/system/integrations/api/store', [ApiController::class, 'store'])
            ->name('admin.system.integrations.api.store');

        Route::delete('/admin/system/integrations/api/{apiKey}', [ApiController::class, 'destroy'])
            ->name('admin.system.integrations.api.destroy');

        Route::get('/admin/system/integrations/api/docs', [ApiController::class, 'documentation'])
            ->name('admin.system.integrations.api.docs');

    });
