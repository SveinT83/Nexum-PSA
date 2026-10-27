<?php

use App\Modules\Contact\Controllers\Admin\ContactSettingsController;
use App\Modules\Contact\Controllers\Tech\ContactController;
use Illuminate\Support\Facades\Route;

// The compatibility loader sets this only inside the authenticated versioned API group.
if (($contactApiRoutes ?? false) === true) {
    $fields = ['existing_contact_id', 'display_name', 'type', 'status', 'organization_name', 'job_title',
        'email', 'phone', 'sms_allowed', 'preferred_language', 'do_not_call', 'do_not_email',
        'marketing_consent', 'relation_type', 'client_id', 'site_id', 'address', 'co_address', 'zip',
        'city', 'county', 'country', 'reuse_existing', 'send_customer_portal_invitation', 'portal_role'];
    $routes = [
        ['get', 'contacts/context', 'contacts.context', 'ContactWorkspaceController', 'context', [], ['contacts.read'], ['client_id', 'site_id', 'contact_id'], ['contact.view', 'contact.create', 'contact.update']],
        ['get', 'contacts/lookups/{kind}', 'contacts.lookups', 'ContactWorkspaceController', 'lookups', [], ['contacts.read'], ['q', 'client_id', 'site_id', 'page', 'per_page'], ['contact.view', 'contact.create', 'contact.update']],
        ['get', 'contacts/duplicates', 'contacts.duplicates', 'ContactWorkspaceController', 'duplicates', [], ['contacts.read'], ['email', 'phone', 'exclude_contact_id'], ['contact.view', 'contact.create', 'contact.update']],
        ['get', 'contacts/statistics', 'contacts.statistics', 'ContactWorkspaceController', 'statistics', ['contact.view'], ['contacts.read'], ['client_id', 'site_id']],
        ['get', 'contacts/settings', 'contacts.settings.show', 'ContactWorkspaceController', 'settings', ['contact.manage_settings'], ['contacts.settings.read'], []],
        ['put', 'contacts/settings', 'contacts.settings.update', 'ContactWorkspaceController', 'updateSettings', ['contact.manage_settings'], ['contacts.settings.manage'], ['default_contact_type', 'default_status', 'enabled_relation_types', 'default_relation_type', 'send_portal_invitation_by_default']],
        ['get', 'contacts/operations/{operation}', 'contacts.operations.show', 'ContactWorkspaceController', 'operation', [], ['contacts.operations.read'], []],
        ['post', 'contacts/preview', 'contacts.preview', 'ContactController', 'preview', ['contact.create', 'contact.update'], ['contacts.create', 'contacts.update'], $fields],
        ['get', 'clients/{client}/contacts', 'clients.contacts.index', 'ContactOwnershipController', 'index', ['contact.view'], ['contacts.read'], []],
        ['get', 'contacts', 'contacts.index', 'ContactController', 'index', ['contact.view'], ['contacts.read'], ['q', 'email', 'phone', 'status', 'client_id', 'site_id', 'page', 'per_page']],
        ['post', 'contacts', 'contacts.store', 'ContactController', 'store', ['contact.create', 'contact.update'], ['contacts.create', 'contacts.update'], $fields],
        ['get', 'contacts/{contact}/signals', 'contacts.signals', 'ContactWorkspaceController', 'signals', ['contact.view'], ['contacts.read'], []],
        ['get', 'contacts/{contact}', 'contacts.show', 'ContactController', 'show', [], ['contacts.read'], [], ['contact.view', 'contact.update']],
        ['put|patch', 'contacts/{contact}', 'contacts.update', 'ContactController', 'update', ['contact.update'], ['contacts.update'], array_values(array_diff($fields, ['reuse_existing', 'send_customer_portal_invitation', 'portal_role', 'existing_contact_id']))],
        ['post', 'contacts/{contact}/move', 'contacts.move', 'ContactOwnershipController', 'move', ['contact.update'], ['contacts.ownership_manage'], ['target_client_id', 'target_client_number', 'target_site_id', 'dry_run', 'reason']],
        ['post', 'clients/{client}/contacts/bulk-fix', 'clients.contacts.bulk-fix', 'ContactOwnershipController', 'bulkFix', ['contact.update'], ['contacts.ownership_manage'], ['contact_ids', 'target_site_id', 'dry_run', 'reason']],
        ['post', 'clients/{client}/contacts/legacy-orphans/cleanup', 'clients.contacts.legacy-orphans.cleanup', 'ContactOwnershipController', 'cleanupLegacyOrphans', ['contact.create', 'contact.update'], ['contacts.ownership_manage'], ['client_user_ids', 'dry_run', 'reason']],
        ['delete', 'clients/{client}/contacts/{contact}', 'clients.contacts.detach', 'ContactOwnershipController', 'detach', ['contact.update'], ['contacts.ownership_manage'], ['dry_run', 'delete_if_orphan', 'reason']],
    ];
    Route::middleware([\App\Modules\Contact\Support\ContactApiAccess::class, \App\Modules\Contact\Actions\ExecuteContactApiCommand::class])
        ->group(function () use ($routes): void {
            foreach ($routes as $row) {
                [$method, $uri, $name, $controller, $action, $permissions, $abilities, $allowed] = $row;
                $route = Route::match(explode('|', $method), $uri, ['App\\Modules\\Contact\\Controllers\\Api\\V1\\'.$controller, $action])
                    ->name($name)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.implode(',', $abilities))
                    ->defaults('contact_permissions', $permissions)->defaults('contact_abilities', $abilities)
                    ->defaults('contact_any_permissions', $row[8] ?? [])->defaults('contact_fields', $allowed);
                if ($action === 'preview') {
                    $route->defaults('contact_preview', true);
                }
                if (in_array($action, ['move', 'bulkFix', 'cleanupLegacyOrphans', 'detach'], true)) {
                    $route->defaults('contact_risky', true);
                }
                if ($controller === 'ContactOwnershipController' && in_array($action, ['bulkFix', 'cleanupLegacyOrphans'], true)) {
                    $route->defaults('contact_bulk_preview_default', true);
                }
            }
        });

    return;
}

Route::middleware('admin')->group(function () {
    Route::get('/admin/settings/contacts', [ContactSettingsController::class, 'edit'])
        ->name('admin.settings.contacts');

    Route::put('/admin/settings/contacts', [ContactSettingsController::class, 'update'])
        ->name('admin.settings.contacts.update');
});

Route::get('/contacts', [ContactController::class, 'index'])
    ->name('contacts.index');

Route::post('/contacts/context/clear', [ContactController::class, 'clearContext'])
    ->name('contacts.context.clear');

Route::get('/contacts/create', [ContactController::class, 'create'])
    ->name('contacts.create');

Route::post('/contacts', [ContactController::class, 'store'])
    ->name('contacts.store');

Route::get('/contacts/{contact}/edit', [ContactController::class, 'edit'])
    ->name('contacts.edit');

Route::get('/contacts/{contact}', [ContactController::class, 'show'])
    ->name('contacts.show');
