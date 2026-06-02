<?php

use App\Modules\UserManagement\Controllers\Api\V1\UserManagementController;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

/*
|--------------------------------------------------------------------------
| User Management API routes
|--------------------------------------------------------------------------
|
| User lifecycle is status-driven. The API can create pending/active/disabled
| users, update canonical profile details, sync roles, and resend invites.
|
*/

Route::get('users', [UserManagementController::class, 'index'])
    ->name('users.index')
    ->middleware(CheckAbilities::class.':users.read');

Route::get('users/roles', [UserManagementController::class, 'roles'])
    ->name('users.roles.index')
    ->middleware(CheckAbilities::class.':users.read');

Route::post('users', [UserManagementController::class, 'store'])
    ->name('users.store')
    ->middleware(CheckAbilities::class.':users.create');

Route::get('users/{user}', [UserManagementController::class, 'show'])
    ->name('users.show')
    ->middleware(CheckAbilities::class.':users.read');

Route::match(['put', 'patch'], 'users/{user}', [UserManagementController::class, 'update'])
    ->name('users.update')
    ->middleware(CheckAbilities::class.':users.update');

Route::post('users/{user}/status', [UserManagementController::class, 'updateStatus'])
    ->name('users.status.update')
    ->middleware(CheckAbilities::class.':users.update');

Route::post('users/{user}/roles', [UserManagementController::class, 'updateRoles'])
    ->name('users.roles.update')
    ->middleware(CheckAbilities::class.':users.update');

Route::post('users/{user}/invite', [UserManagementController::class, 'sendInvite'])
    ->name('users.invite.send')
    ->middleware(CheckAbilities::class.':users.update');
