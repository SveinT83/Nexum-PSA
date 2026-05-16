<?php

use App\Modules\UserManagement\Controllers\AcceptInviteController;
use App\Modules\UserManagement\Controllers\Admin\PermissionManagementController;
use App\Modules\UserManagement\Controllers\Admin\RolesManagementController;
use App\Modules\UserManagement\Controllers\Admin\UserManagementController;
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
    Route::post('/admin/user_management/{user}/status', [UserManagementController::class, 'updateStatus'])
        ->name('admin.user_management.status.update');
    Route::post('/admin/user_management/{user}/invite', [UserManagementController::class, 'sendInvite'])
        ->name('admin.user_management.invite.send');

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
