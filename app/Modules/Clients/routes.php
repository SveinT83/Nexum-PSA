<?php

use App\Modules\Clients\Controllers\Admin\ClientFormatSettingsController;
use App\Modules\Clients\Controllers\Portal\CloudFactoryLicenceController as PortalCloudFactoryLicenceController;
use App\Modules\Clients\Controllers\Tech\ClientController;
use App\Modules\Clients\Controllers\Tech\ClientCustomFieldValueController;
use App\Modules\Clients\Controllers\Tech\ClientTimeUsageController;
use App\Modules\Clients\Controllers\Tech\CloudFactoryLicenceController;
use App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

if (($clientsPortalApiRoutes ?? false) === true) {
    Route::middleware(['web', 'auth', \App\Modules\CustomerPortal\Middleware\EnsureCustomerPortalAccess::class, 'throttle:60,1'])->group(function (): void {
        Route::get('portal/licenses', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'index'])->name('clients.portal.licenses.index')->defaults('clients_portal', true);
        Route::get('portal/licenses/context', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'context'])->name('clients.portal.licenses.context')->defaults('clients_portal', true);
        Route::get('portal/licenses/operations', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'operations'])->name('clients.portal.licenses.operations')->defaults('clients_portal', true);
        Route::get('portal/licenses/operations/{operation}', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'operation'])->name('clients.portal.licenses.operations.show')->defaults('clients_portal', true);
        Route::post('portal/licenses', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.portal.licenses.issue')->defaults('clients_portal', true)->defaults('licence_action', 'issue');
        Route::patch('portal/licenses/{subscription}/quantity', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.portal.licenses.quantity')->defaults('clients_portal', true)->defaults('licence_action', 'quantity');
        Route::patch('portal/licenses/{subscription}/renewal', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.portal.licenses.renewal')->defaults('clients_portal', true)->defaults('licence_action', 'renewal');
    });

    return;
}

if (($clientsApiRoutes ?? false) === true) {
    Route::middleware([\App\Modules\Clients\Http\Middleware\ClientApiGuard::class, \App\Modules\Clients\Http\Middleware\ClientApiErrors::class])->group(function (): void {
        Route::get('client-sites/{site}/context', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'siteContext'])->name('client-sites.context')->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view']);
        Route::delete('client-sites/{site}', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'deleteSite'])->name('client-sites.destroy')->middleware(CheckAbilities::class.':clients.delete')->defaults('client_permissions', ['client.delete']);
        Route::get('clients/{client}/settings', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'settings'])->name('clients.settings')->middleware(CheckAbilities::class.':clients.settings.read')->defaults('client_permissions', ['client.manage_settings']);
        Route::patch('clients/{client}/settings', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'updateSettings'])->name('clients.settings.update')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings']);
        Route::patch('clients/{client}/notes', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'notes'])->name('clients.notes.update')->middleware(CheckAbilities::class.':clients.update')->defaults('client_permissions', ['client.update']);
        Route::get('client-settings/formats', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'formats'])->name('clients.formats.index')->middleware(CheckAbilities::class.':clients.settings.read')->defaults('client_permissions', ['client.manage_settings']);
        Route::post('client-settings/formats', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'formatStore'])->name('clients.formats.store')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings']);
        Route::get('client-settings/formats/{clientFormat}', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'formatShow'])->name('clients.formats.show')->middleware(CheckAbilities::class.':clients.settings.read')->defaults('client_permissions', ['client.manage_settings']);
        Route::patch('client-settings/formats/{clientFormat}', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'formatUpdate'])->name('clients.formats.update')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings']);
        Route::get('client-operations/{command}', [\App\Modules\Clients\Controllers\Api\V1\ClientOperationsController::class, 'operation'])->name('clients.operations.show')->middleware(CheckAbilities::class.':clients.operations.read')->defaults('client_permissions', ['client.view']);
        Route::get('clients/{client}/related/{section}', [\App\Modules\Clients\Controllers\Api\V1\ClientRelatedController::class, 'index'])
            ->name('clients.related.index')->middleware(CheckAbilities::class.':clients.read');
        Route::get('clients/{client}/time-usage', [\App\Modules\Clients\Controllers\Api\V1\ClientTimeController::class, 'index'])
            ->name('clients.time.index')->middleware(CheckAbilities::class.':clients.time.read');
        Route::get('clients/{client}/time-usage/{source}/{entry}', [\App\Modules\Clients\Controllers\Api\V1\ClientTimeController::class, 'show'])
            ->name('clients.time.show')->middleware(CheckAbilities::class.':clients.time.read')->whereIn('source', ['quick', 'ticket', 'task']);
        Route::patch('clients/{client}/time-usage/{source}/{entry}', [\App\Modules\Clients\Controllers\Api\V1\ClientTimeController::class, 'update'])
            ->name('clients.time.update')->middleware(CheckAbilities::class.':clients.time.update')->whereIn('source', ['quick', 'ticket', 'task']);
        Route::get('clients/{client}/licenses', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'index'])->name('clients.licenses.index')->middleware(CheckAbilities::class.':clients.licenses.read')->defaults('client_permissions', ['client.view']);
        Route::get('clients/{client}/licenses/context', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'context'])->name('clients.licenses.context')->middleware(CheckAbilities::class.':clients.licenses.read')->defaults('client_permissions', ['client.view']);
        Route::get('clients/{client}/licenses/operations', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'operations'])->name('clients.licenses.operations')->middleware(CheckAbilities::class.':clients.licenses.read')->defaults('client_permissions', ['client.view']);
        Route::get('clients/{client}/licenses/operations/{operation}', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'operation'])->name('clients.licenses.operations.show')->middleware(CheckAbilities::class.':clients.licenses.read')->defaults('client_permissions', ['client.view']);
        Route::post('clients/{client}/licenses', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.licenses.issue')->middleware(CheckAbilities::class.':clients.licenses.write')->defaults('client_permissions', ['client.view', 'integration.cloudfactory_write'])->defaults('licence_action', 'issue');
        Route::patch('clients/{client}/licenses/{subscription}/quantity', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.licenses.quantity')->middleware(CheckAbilities::class.':clients.licenses.write')->defaults('client_permissions', ['client.view', 'integration.cloudfactory_write'])->defaults('licence_action', 'quantity');
        Route::patch('clients/{client}/licenses/{subscription}/renewal', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.licenses.renewal')->middleware(CheckAbilities::class.':clients.licenses.write')->defaults('client_permissions', ['client.view', 'integration.cloudfactory_write'])->defaults('licence_action', 'renewal');
        Route::patch('clients/{client}/licenses/{subscription}/status', [\App\Modules\Clients\Controllers\Api\V1\ClientLicenceController::class, 'write'])->name('clients.licenses.status')->middleware(CheckAbilities::class.':clients.licenses.write')->defaults('client_permissions', ['client.view', 'integration.cloudfactory_write'])->defaults('licence_action', 'status');
        Route::get('clients/context/rmm', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'rmmLookup'])->name('clients.context.rmm')->middleware(CheckAbilities::class.':clients.settings.read')->defaults('client_permissions', ['client.manage_settings'])->defaults('rmm_reconcile', false);
        Route::get('clients/context/tripletex/customers', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'tripletex'])->name('clients.context.tripletex')->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view', 'client.create'])->defaults('rmm_reconcile', false);
        Route::get('clients/context/tripletex/customers/{customer}', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'tripletex'])->name('clients.context.tripletex.profile')->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view', 'client.create']);
        Route::get('clients/context/tripletex/number', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'number'])->name('clients.context.number')->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view', 'client.create'])->defaults('rmm_reconcile', false);
        Route::post('clients/{client}/rmm', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'rmm'])->name('clients.rmm.create')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings'])->defaults('rmm_reconcile', false);
        Route::post('clients/{client}/rmm/reconcile', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'rmm'])->name('clients.rmm.reconcile')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings'])->defaults('rmm_reconcile', true);
        Route::post('client-sites/{site}/rmm', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'rmm'])->name('client-sites.rmm.create')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings'])->defaults('rmm_reconcile', false);
        Route::post('client-sites/{site}/rmm/reconcile', [\App\Modules\Clients\Controllers\Api\V1\ClientProviderController::class, 'rmm'])->name('client-sites.rmm.reconcile')->middleware(CheckAbilities::class.':clients.settings.update')->defaults('client_permissions', ['client.manage_settings'])->defaults('rmm_reconcile', true);
        Route::get('clients/context', [\App\Modules\Clients\Controllers\Api\V1\ClientWorkspaceController::class, 'context'])
            ->name('clients.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':clients.read')
            ->defaults('client_permissions', ['client.view']);
        Route::get('clients/{client}/workspace', [\App\Modules\Clients\Controllers\Api\V1\ClientWorkspaceController::class, 'workspace'])
            ->name('clients.workspace')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':clients.read');
        Route::get('client-sites/{site}', [\App\Modules\Clients\Controllers\Api\V1\ClientWorkspaceController::class, 'site'])
            ->name('client-sites.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':clients.read');

        Route::get('clients', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'index'])
            ->name('clients.index')
            ->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view']);

        Route::post('clients', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'store'])
            ->name('clients.store')
            ->middleware(CheckAbilities::class.':clients.create')->defaults('client_permissions', ['client.create']);

        Route::get('clients/{client}', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'show'])
            ->name('clients.show')
            ->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view']);

        Route::match(['put', 'patch'], 'clients/{client}', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'update'])
            ->name('clients.update')
            ->middleware(CheckAbilities::class.':clients.update')->defaults('client_permissions', ['client.update']);

        Route::get('clients/{client}/assets', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'assets'])
            ->middleware(CheckAbilities::class.':assets.read')->defaults('client_permissions', ['client.view', 'asset.view'])
            ->name('clients.assets');

        Route::get('clients/{client}/sites', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'sites'])
            ->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view'])
            ->name('clients.sites.index');

        Route::post('clients/{client}/sites', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'storeSite'])
            ->middleware(CheckAbilities::class.':clients.update')->defaults('client_permissions', ['client.update'])
            ->name('clients.sites.store')->defaults('client_permissions', ['client.create']);

        Route::get('client-sites', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'siteIndex'])
            ->middleware(CheckAbilities::class.':clients.read')->defaults('client_permissions', ['client.view'])
            ->name('client-sites.index');

        Route::match(['put', 'patch'], 'client-sites/{site}', [\App\Modules\Clients\Controllers\Api\V1\ClientController::class, 'updateSite'])
            ->middleware(CheckAbilities::class.':clients.update')->defaults('client_permissions', ['client.update'])
            ->name('client-sites.update');

    });

    return;
}

if (($clientsPortalRoutes ?? false) === true) {
    Route::middleware(['auth', EnsureCustomerPortalAccess::class])
        ->prefix('portal/licenses')
        ->name('customer-portal.licenses.')
        ->group(function (): void {
            Route::get('/', [PortalCloudFactoryLicenceController::class, 'index'])->name('index');
            Route::post('/', [PortalCloudFactoryLicenceController::class, 'issue'])->name('issue');
            Route::patch('/{subscription}/quantity', [PortalCloudFactoryLicenceController::class, 'quantity'])->name('quantity');
            Route::patch('/{subscription}/renewal', [PortalCloudFactoryLicenceController::class, 'renewal'])->name('renewal');
        });

    return;
}

Route::get('/clients', [ClientController::class, 'index'])->name('clients.index');
Route::get('/clients/index', [ClientController::class, 'index'])->name('client.index');
Route::get('/clients/create', [ClientController::class, 'create'])->name('clients.create');
Route::post('/clients/store', [ClientController::class, 'store'])->name('clients.store');
Route::get('/clients/show/{client}', [ClientController::class, 'show'])->name('clients.show');
Route::get('/clients/{client}/licenses', [CloudFactoryLicenceController::class, 'index'])
    ->name('clients.licenses.index');

Route::post('/clients/{client}/licenses', [CloudFactoryLicenceController::class, 'issue'])
    ->name('clients.licenses.issue');
Route::patch('/clients/{client}/licenses/{subscription}/quantity', [CloudFactoryLicenceController::class, 'quantity'])
    ->name('clients.licenses.quantity');
Route::patch('/clients/{client}/licenses/{subscription}/renewal', [CloudFactoryLicenceController::class, 'renewal'])
    ->name('clients.licenses.renewal');
Route::patch('/clients/{client}/licenses/{subscription}/status', [CloudFactoryLicenceController::class, 'status'])
    ->name('clients.licenses.status');

Route::patch('/clients/{client}/time-usage/{source}/{entry}', [ClientTimeUsageController::class, 'update'])
    ->whereIn('source', ['quick', 'ticket', 'task'])
    ->name('clients.time-usage.update');

// Sites
Route::get('/clients/sites/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'index'])->name('clients.sites.index');
Route::get('/clients/sites/show/{site}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'show'])->name('clients.sites.show');
Route::get('/clients/sites/edit/{site}/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'edit'])->name('clients.sites.edit');
Route::put('/clients/sites/update/{site}/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'update'])->name('clients.sites.update');
Route::delete('/clients/sites/destroy/{site}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'destroy'])->name('clients.sites.destroy');
Route::get('/clients/sites/create/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'create'])->name('clients.sites.create');
Route::post('/clients/sites/store/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientSiteController::class, 'store'])->name('clients.sites.store');
Route::patch('/clients/sites/{site}/custom-fields/{definition}', [ClientCustomFieldValueController::class, 'updateSite'])->name('clients.sites.custom-fields.update');

// Users
Route::get('/clients/users/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'index'])->name('clients.users.index');
Route::get('/clients/user_management/{client?}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'index'])->name('clients.user_management.index');
Route::get('/clients/user/show/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'show'])->name('clients.user.show');
Route::get('/clients/user/create/{client}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'create'])->name('clients.user.create');
Route::post('/clients/user/store/{client}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'store'])->name('clients.user.store');
Route::get('/clients/user/edit/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'edit'])->name('clients.user.edit');
Route::put('/clients/user/update/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'update'])->name('clients.user.update');
Route::delete('/clients/user/delete/{ClientUser}', [\App\Modules\Clients\Controllers\Tech\ClientUsersController::class, 'delete'])->name('clients.user.delete');

// Settings
Route::get('/clients/{client}/settings', [\App\Modules\Clients\Controllers\Tech\ClientSettingsController::class, 'edit'])->name('clients.settings.edit');
Route::put('/clients/{client}/settings', [\App\Modules\Clients\Controllers\Tech\ClientSettingsController::class, 'update'])->name('clients.settings.update');
Route::patch('/clients/{client}/custom-fields/{definition}', [ClientCustomFieldValueController::class, 'update'])->name('clients.custom-fields.update');

Route::middleware('admin')->group(function (): void {
    Route::get('/admin/settings/clients/client-formats', [ClientFormatSettingsController::class, 'index'])
        ->name('admin.settings.clients.client-formats');

    Route::post('/admin/settings/clients/client-formats', [ClientFormatSettingsController::class, 'store'])
        ->name('admin.settings.clients.client-formats.store');

    Route::patch('/admin/settings/clients/client-formats/{clientFormat}', [ClientFormatSettingsController::class, 'update'])
        ->name('admin.settings.clients.client-formats.update');
});
