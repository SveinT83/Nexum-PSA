<?php

use App\Modules\UserManagement\Controllers\AcceptInviteController;
use App\Modules\UserManagement\Controllers\Admin\PermissionManagementController;
use App\Modules\UserManagement\Controllers\Admin\RolesManagementController;
use App\Modules\UserManagement\Controllers\Admin\UserManagementController;
use App\Modules\UserManagement\Controllers\ProfileController;
use App\Modules\UserManagement\Controllers\ProfilePreferencesController;
use App\Modules\UserManagement\Controllers\ProfileSecurityController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| User Management Module Routes
|--------------------------------------------------------------------------
|
| These routes are loaded inside the /tech group and add admin middleware here.
| The URL segment remains "user_management" because that is the established
| admin route name, but it is not a database table contract.
|
*/

/*
|--------------------------------------------------------------------------
| Public Invite Routes (No Auth)
|--------------------------------------------------------------------------
|
| These routes handle the invite acceptance flow — an unauthenticated user
| clicks the invite link, sets a password, and gets activated.
|
*/

Route::get('/invite/{token}', [AcceptInviteController::class, 'show'])
    ->name('invite.accept');
Route::post('/invite/{token}', [AcceptInviteController::class, 'store'])
    ->name('invite.accept.post');

if (isset($userManagementPublicRoutes) && $userManagementPublicRoutes === true) {
    return;
}

/*
|--------------------------------------------------------------------------
| Profile / Security Routes (Authenticated users)
|--------------------------------------------------------------------------
|
| These routes handle the authenticated user's own security settings —
| enabling/disabling 2FA, confirming setup, regenerating recovery codes,
| and changing password.
|
*/

Route::middleware(['auth'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'index'])
        ->name('profile.index');
    Route::patch('/profile', [ProfileController::class, 'update'])
        ->name('profile.update');
    Route::get('/profile/integrations', [ProfileController::class, 'integrations'])
        ->name('profile.integrations');
    Route::get('/profile/view', [ProfileController::class, 'viewPreferences'])
        ->name('profile.view');

    Route::get('/profile/preferences', [ProfilePreferencesController::class, 'show'])
        ->name('profile.preferences');
    Route::patch('/profile/preferences', [ProfilePreferencesController::class, 'update'])
        ->name('profile.preferences.update');

    Route::get('/profile/security', [ProfileSecurityController::class, 'show'])
        ->name('profile.security');
    Route::post('/profile/security/2fa/enable', [ProfileSecurityController::class, 'enable'])
        ->name('profile.security.2fa.enable');
    Route::post('/profile/security/2fa/confirm', [ProfileSecurityController::class, 'confirm'])
        ->name('profile.security.2fa.confirm');
    Route::post('/profile/security/2fa/disable', [ProfileSecurityController::class, 'disable'])
        ->name('profile.security.2fa.disable');
    Route::post('/profile/security/recovery-codes', [ProfileSecurityController::class, 'regenerateRecoveryCodes'])
        ->name('profile.security.recovery-codes');
    Route::post('/profile/security/password', [ProfileSecurityController::class, 'updatePassword'])
        ->name('profile.security.password');
});

/*
|--------------------------------------------------------------------------
| Admin User Management Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['admin'])->group(function () {
    Route::get('/admin/user_management', [UserManagementController::class, 'index'])
        ->name('admin.user_management.index');
    Route::get('/admin/user_management/create', [UserManagementController::class, 'create'])
        ->name('admin.user_management.create');
    Route::post('/admin/user_management/store', [UserManagementController::class, 'store'])
        ->name('admin.user_management.store');
    Route::get('/admin/user_management/users/{user}', [UserManagementController::class, 'show'])
        ->name('admin.user_management.show');
    Route::post('/admin/user_management/users/{user}/profile', [UserManagementController::class, 'updateProfile'])
        ->name('admin.user_management.profile.update');
    Route::post('/admin/user_management/users/{user}/roles', [UserManagementController::class, 'updateRoles'])
        ->name('admin.user_management.roles.update-user');
    Route::post('/admin/user_management/{user}/status', [UserManagementController::class, 'updateStatus'])
        ->name('admin.user_management.status.update');
    Route::post('/admin/user_management/{user}/invite', [UserManagementController::class, 'sendInvite'])
        ->name('admin.user_management.invite.send');

    // 2FA enforcement settings
    Route::get('/admin/user_management/2fa-settings', [\App\Modules\UserManagement\Controllers\Admin\TwoFactorSettingsController::class, 'show'])
        ->name('admin.user_management.2fa-settings');
    Route::post('/admin/user_management/2fa-settings', [\App\Modules\UserManagement\Controllers\Admin\TwoFactorSettingsController::class, 'update'])
        ->name('admin.user_management.2fa-settings.update');

    Route::get('/admin/user_management/roles', [RolesManagementController::class, 'rolesIndex'])
        ->name('admin.user_management.roles.index');
    Route::get('/admin/user_management/roles/edit/{id}', [RolesManagementController::class, 'rolesEdit'])
        ->name('admin.user_management.roles.edit');
    Route::post('/admin/user_management/roles/update/{id}', [RolesManagementController::class, 'rolesUpdate'])
        ->name('admin.user_management.roles.update');
    Route::get('/admin/user_management/roles/create', [RolesManagementController::class, 'rolesCreate'])
        ->name('admin.user_management.roles.create');
    Route::post('/admin/user_management/roles/store', [RolesManagementController::class, 'rolesStore'])
        ->name('admin.user_management.roles.store');
    Route::delete('/admin/user_management/roles/destroy/{id}', [RolesManagementController::class, 'rolesDestroy'])
        ->name('admin.user_management.roles.destroy');

    Route::get('/admin/user_management/permissions', [PermissionManagementController::class, 'permissionsIndex'])
        ->name('admin.user_management.permissions.index');
    Route::get('/admin/user_management/permissions/edit/{id}', [PermissionManagementController::class, 'permissionsEdit'])
        ->name('admin.user_management.permissions.edit');
    Route::post('/admin/user_management/permissions/update/{id}', [PermissionManagementController::class, 'permissionsUpdate'])
        ->name('admin.user_management.permissions.update');
    Route::get('/admin/user_management/permissions/create', [PermissionManagementController::class, 'permissionsCreate'])
        ->name('admin.user_management.permissions.create');
    Route::post('/admin/user_management/permissions/store', [PermissionManagementController::class, 'permissionsStore'])
        ->name('admin.user_management.permissions.store');
    Route::delete('/admin/user_management/permissions/destroy/{id}', [PermissionManagementController::class, 'permissionsDestroy'])
        ->name('admin.user_management.permissions.destroy');
});
