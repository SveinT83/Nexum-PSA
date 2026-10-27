<?php

use App\Modules\UserManagement\Controllers\AcceptInviteController;
use App\Modules\UserManagement\Controllers\Admin\PermissionManagementController;
use App\Modules\UserManagement\Controllers\Admin\RolesManagementController;
use App\Modules\UserManagement\Controllers\Admin\UserManagementController;
use App\Modules\UserManagement\Controllers\ProfileController;
use App\Modules\UserManagement\Controllers\ProfilePreferencesController;
use App\Modules\UserManagement\Controllers\ProfileSecurityController;
use Illuminate\Support\Facades\Route;

// Public capabilities and the first-party OIDC session protocol have distinct authority.
if (($userManagementPublicApiRoutes ?? false) === true) {
    Route::middleware(\App\Modules\UserManagement\Support\UserManagementApiPrivacy::class)->group(function () {
        $identity = \App\Modules\UserManagement\Controllers\Api\V1\PublicIdentityApiController::class;
        Route::get('users/invitations/{token}', [$identity, 'invitation'])->name('users.invitations.show')->middleware('throttle:20,1');
        Route::post('users/invitations/{token}', [$identity, 'accept'])->name('users.invitations.accept')->middleware(['web', 'throttle:10,1']);
        Route::get('users/auth/sso', [$identity, 'discovery'])->name('users.auth.sso')->middleware('throttle:30,1');
        Route::middleware('web')->group(function () use ($identity) {
            Route::post('users/auth/sso/start', [$identity, 'start'])->name('users.auth.sso.start')->middleware('throttle:10,1');
            Route::get('users/auth/sso/callback', [$identity, 'callback'])->name('users.auth.sso.callback')->middleware('throttle:20,1');
        });
        Route::post('users/auth/sso/backchannel', [$identity, 'backchannel'])->name('users.auth.sso.backchannel')->middleware('throttle:60,1');
    });

    return;
}

// The same owning route file is explicitly loaded by the versioned API entry point.
if (($tdpsaLoadingApiRoutes ?? false) === true) {
    Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class, \App\Modules\UserManagement\Support\UserManagementApiAccess::class])->group(function () {
        Route::get('users', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'index'])
            ->name('users.index')->defaults('um_permissions', ['user.view'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.read');

        Route::get('users/roles', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'roles'])
            ->name('users.roles.index')->defaults('um_permissions', ['user.view'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.read');

        Route::post('users', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'store'])
            ->name('users.store')->defaults('um_permissions', ['user.create'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.create');

        Route::get('users/{user}', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'show'])
            ->whereNumber('user')->name('users.show')->defaults('um_permissions', ['user.view'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.read');

        Route::match(['put', 'patch'], 'users/{user}', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'update'])
            ->whereNumber('user')->name('users.update')->defaults('um_permissions', ['user.update'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.update');

        Route::post('users/{user}/avatar', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'update'])
            ->whereNumber('user')->name('users.avatar.update')->defaults('um_permissions', ['user.update'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.update');

        Route::post('users/{user}/status', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'updateStatus'])
            ->whereNumber('user')->name('users.status.update')->defaults('um_permissions', ['user.update'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.update');

        Route::post('users/{user}/roles', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'updateRoles'])
            ->whereNumber('user')->name('users.roles.update')->defaults('um_permissions', ['user.manage_roles'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.update');

        Route::post('users/{user}/invitations', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'sendInvite'])
            ->whereNumber('user')->name('users.invitations.send')->defaults('um_permissions', ['user.invite'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.invite.execute');

        Route::post('users/{user}/invite', [\App\Modules\UserManagement\Controllers\Api\V1\UserManagementController::class, 'sendInvite'])
            ->whereNumber('user')->name('users.invite.send')->defaults('um_permissions', ['user.invite'])
            ->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.update');

    });

    Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class, \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class])->group(function () {
        Route::middleware(\App\Modules\UserManagement\Http\Middleware\EnsureEmployeeWorkPlan::class)->group(function () {
            Route::get('users/me/work-plan', [\App\Modules\UserManagement\Controllers\WorkPlanController::class, 'show'])
                ->name('users.work-plan.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.work-plan.read');
            Route::patch('users/me/work-plan', [\App\Modules\UserManagement\Controllers\WorkPlanController::class, 'update'])
                ->name('users.work-plan.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.work-plan.update');
        });

        Route::get('users/me/calendar-preferences', [\App\Modules\UserManagement\Controllers\Api\V1\CalendarPreferencesController::class, 'show'])->name('users.calendar-preferences.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.calendar-preferences.read');
        Route::patch('users/me/calendar-preferences', [\App\Modules\UserManagement\Controllers\Api\V1\CalendarPreferencesController::class, 'update'])->name('users.calendar-preferences.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.calendar-preferences.update');

    });

    Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class, \App\Modules\UserManagement\Support\UserManagementApiAccess::class])->group(function () {
            $own = \App\Modules\UserManagement\Controllers\Api\V1\ProfileApiController::class;
            Route::get('users/context', [$own, 'context'])->name('users.context')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.context.read');
            Route::get('users/me/profile', [$own, 'show'])->name('users.me.profile')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.profile.read');
            Route::patch('users/me/profile', [$own, 'update'])->name('users.me.profile.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.profile.update');
            Route::post('users/me/profile/avatar', [$own, 'update'])->name('users.me.avatar.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.profile.update');
            Route::get('users/me/preferences', [$own, 'preferences'])->name('users.me.preferences')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.preferences.read');
            Route::patch('users/me/preferences', [$own, 'updatePreferences'])->name('users.me.preferences.update')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.preferences.update');
            Route::get('users/me/shell', [$own, 'shell'])->name('users.me.shell')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.profile.read');
        });

    Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class, \App\Modules\UserManagement\Support\UserManagementApiAccess::class])->group(function () {
            $authorization = \App\Modules\UserManagement\Controllers\Api\V1\AuthorizationApiController::class;
            Route::get('users/administration/roles', [$authorization, 'roles'])->name('users.admin.roles')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.read');
            Route::post('users/administration/roles', [$authorization, 'createRole'])->name('users.admin.roles.store')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.write');
            Route::get('users/administration/roles/{role}/permission-options', [$authorization, 'permissionOptions'])->name('users.admin.roles.permission-options')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.read');
            Route::get('users/administration/roles/{role}', [$authorization, 'role'])->name('users.admin.roles.show')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.read');
            Route::patch('users/administration/roles/{role}', [$authorization, 'updateRole'])->name('users.admin.roles.update')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.write');
            Route::delete('users/administration/roles/{role}', [$authorization, 'deleteRole'])->name('users.admin.roles.destroy')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.write');
            Route::put('users/administration/roles/{role}/permissions/{permission}', [$authorization, 'setPermission'])->name('users.admin.roles.permission')->defaults('um_permissions', ['user.manage_roles'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.roles.write');
            Route::get('users/administration/permissions', [$authorization, 'permissions'])->name('users.admin.permissions')->defaults('um_permissions', ['user.manage_permissions'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.permissions.read');
            Route::post('users/administration/permissions', [$authorization, 'createPermission'])->name('users.admin.permissions.store')->defaults('um_permissions', ['user.manage_permissions'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.permissions.write');
            Route::get('users/administration/permissions/{permission}', [$authorization, 'permission'])->name('users.admin.permissions.show')->defaults('um_permissions', ['user.manage_permissions'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.permissions.read');
            Route::patch('users/administration/permissions/{permission}', [$authorization, 'updatePermission'])->name('users.admin.permissions.update')->defaults('um_permissions', ['user.manage_permissions'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.permissions.write');
            Route::delete('users/administration/permissions/{permission}', [$authorization, 'deletePermission'])->name('users.admin.permissions.destroy')->defaults('um_permissions', ['user.manage_permissions'])->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.permissions.write');
        });

    Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class, \App\Modules\UserManagement\Support\UserManagementApiAccess::class])->group(function () {
            $security = \App\Modules\UserManagement\Controllers\Api\V1\SecurityApiController::class;
            Route::get('users/me/security', [$security, 'show'])->name('users.security')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.read');
            Route::post('users/me/security/material', [$security, 'material'])->name('users.security.material')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.write')->middleware('throttle:10,1');
            Route::post('users/me/security/2fa/enable', [$security, 'enable'])->name('users.security.enable')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.write')->middleware('throttle:10,1');
            Route::post('users/me/security/2fa/confirm', [$security, 'confirm'])->name('users.security.confirm')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.write')->middleware('throttle:10,1');
            Route::post('users/me/security/2fa/disable', [$security, 'disable'])->name('users.security.disable')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.write')->middleware('throttle:10,1');
            Route::post('users/me/security/recovery-codes', [$security, 'recoveryCodes'])->name('users.security.recovery')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.write')->middleware('throttle:10,1');
            Route::patch('users/me/security/password', [$security, 'password'])->name('users.security.password')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.security.write')->middleware('throttle:10,1');
            Route::get('users/administration/2fa-settings', [$security, 'policy'])->name('users.settings.2fa')->defaults('um_permissions', ['user.manage_2fa'])->defaults('um_session', false)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.settings.read');
            Route::patch('users/administration/2fa-settings', [$security, 'updatePolicy'])->name('users.settings.2fa.update')->defaults('um_permissions', ['user.manage_2fa'])->defaults('um_session', false)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.settings.write')->middleware('throttle:10,1');
        });

    Route::middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class, \App\Modules\UserManagement\Support\UserManagementApiAccess::class])->group(function () {
            $sso = \App\Modules\UserManagement\Controllers\Api\V1\SsoApiController::class;
            Route::get('users/me/work-account', [$sso, 'show'])->name('users.sso')->defaults('um_permissions', [])->defaults('um_session', false)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.sso.read');
            Route::post('users/me/work-account/link', [$sso, 'link'])->name('users.sso.link')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.sso.write')->middleware('throttle:5,1');
            Route::post('users/me/work-account/unlink', [$sso, 'unlink'])->name('users.sso.unlink')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.sso.write')->middleware('throttle:5,1');
            Route::post('users/me/work-account/logout', [$sso, 'logout'])->name('users.sso.logout')->defaults('um_permissions', [])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.sso.write')->middleware('throttle:5,1');
            Route::get('users/administration/sso', [$sso, 'settings'])->name('users.settings.sso')->defaults('um_permissions', ['user.manage_2fa'])->defaults('um_session', false)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.settings.read');
            Route::patch('users/administration/sso', [$sso, 'updateSettings'])->name('users.settings.sso.update')->defaults('um_permissions', ['user.manage_2fa'])->defaults('um_session', true)->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.settings.write')->middleware('throttle:5,1');
        });

    Route::get('users/operations/{operation}', [\App\Modules\UserManagement\Controllers\Api\V1\OperationApiController::class, 'show'])
        ->name('users.operations.show')->whereUuid('operation')
        ->middleware([\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            \App\Modules\UserManagement\Support\UserManagementApiPrivacy::class,
            \App\Modules\UserManagement\Support\UserManagementApiAccess::class,
            \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':users.operations.read']);

    // Route defaults are the contract for strict fields, concurrency and safe durable replay.
    $profileFields = ['name', 'email', 'work_phone', 'private_phone', 'phone_work', 'phone_private', 'timezone',
        'working_hours', 'availability_notes', 'profile_notes', 'avatar', 'remove_avatar'];
    $fields = [
        'users.index' => ['q', 'status', 'role', 'per_page', 'page'], 'users.roles.index' => [],
        'users.store' => ['name', 'email', 'status', 'role_id', 'role_ids'],
        'users.avatar.update' => ['avatar', 'remove_avatar'], 'users.update' => $profileFields, 'users.status.update' => ['status'], 'users.roles.update' => ['role_ids'],
        'users.invitations.send' => [], 'users.invite.send' => [], 'users.show' => [], 'users.context' => [], 'users.me.profile' => [],
        'users.me.profile.update' => $profileFields, 'users.me.avatar.update' => ['avatar', 'remove_avatar'],
        'users.me.preferences' => [], 'users.me.preferences.update' => ['timezone', 'default_calendar_view', 'workday_start', 'workday_end', 'theme'],
        'users.me.shell' => [], 'users.admin.roles' => ['q'], 'users.admin.roles.store' => ['name'],
        'users.admin.roles.permission-options' => ['q'], 'users.admin.roles.show' => [], 'users.admin.roles.update' => ['name'], 'users.admin.roles.destroy' => [],
        'users.admin.roles.permission' => ['enabled'], 'users.admin.permissions' => ['q'],
        'users.admin.permissions.store' => ['name'], 'users.admin.permissions.show' => [],
        'users.admin.permissions.update' => ['name'], 'users.admin.permissions.destroy' => [],
        'users.settings.2fa' => [], 'users.settings.2fa.update' => ['enforce_two_factor', 'enforce_two_factor_roles'],
        'users.settings.sso' => [], 'users.settings.sso.update' => ['issuer', 'client_id', 'client_secret', 'enabled', 'current_password', 'code'],
        'users.sso' => [], 'users.sso.link' => ['current_password', 'code'], 'users.sso.unlink' => ['current_password', 'code'],
        'users.sso.logout' => [], 'users.security' => [], 'users.security.material' => ['current_password', 'code'],
        'users.security.enable' => ['current_password', 'code'], 'users.security.confirm' => ['current_password', 'code'],
        'users.security.disable' => ['current_password', 'code'], 'users.security.recovery' => ['current_password', 'code'],
        'users.security.password' => ['current_password', 'password', 'password_confirmation'], 'users.operations.show' => [],
    ];
    $legacy = ['users.store', 'users.update', 'users.status.update', 'users.roles.update', 'users.invite.send'];
    foreach (Route::getRoutes() as $apiRoute) {
        $shortName = preg_replace('/^api\.v1\./', '', (string) $apiRoute->getName());
        if (! array_key_exists($shortName, $fields)) {
            continue;
        }
        $apiRoute->defaults('um_fields', $fields[$shortName]);
        $sensitive = ($apiRoute->defaults['um_session'] ?? false);
        $apiRoute->defaults('um_owner_replay', $sensitive);
        $apiRoute->defaults('um_key_required', ! in_array($shortName, $legacy, true));
        $target = null;
        if (str_contains($apiRoute->uri(), '{user}')) {
            $target = 'user';
        }
        if (str_contains($shortName, 'me.profile') || $shortName === 'users.me.avatar.update') {
            $target = 'me';
        }
        if (str_contains($shortName, 'me.preferences')) {
            $target = 'preferences';
        }
        if (str_contains($apiRoute->uri(), '{role}')) {
            $target = 'role';
        } elseif (str_contains($apiRoute->uri(), '{permission}')) {
            $target = 'permission';
        }
        if (str_starts_with($shortName, 'users.settings.2fa')) {
            $target = 'policy';
        }
        $apiRoute->defaults('um_target', $target);
        $apiRoute->defaults('um_revision_required', $target !== null && ! in_array($shortName, $legacy, true));
        foreach ($apiRoute->middleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, \Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':')) {
                $apiRoute->defaults('um_ability', explode(':', $middleware, 2)[1]);
            }
        }
        $apiRoute->middleware(\App\Modules\UserManagement\Actions\ExecuteUserManagementApiCommand::class);
    }

    // All authenticated UserManagement operations share the web enrollment policy,
    // including the retained Work Plan and Calendar-preference owner endpoints.
    foreach (Route::getRoutes() as $apiRoute) {
        if (str_starts_with($apiRoute->uri(), 'api/v1/')
            && str_starts_with($apiRoute->getActionName(), 'App\\Modules\\UserManagement\\')
            && in_array('auth:sanctum', $apiRoute->middleware(), true)) {
            $apiRoute->middleware(\App\Modules\UserManagement\Support\UserManagementApiMfa::class);
        }
    }

    return;
}

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
    Route::get('/sso/login', [\App\Modules\UserManagement\Controllers\SsoController::class, 'start'])->name('sso.login')->middleware('throttle:10,1');
    Route::get('/sso/callback', [\App\Modules\UserManagement\Controllers\SsoController::class, 'callback'])->name('sso.callback')->middleware('throttle:20,1');
    Route::post('/sso/backchannel', [\App\Modules\UserManagement\Controllers\SsoController::class, 'backchannel'])->name('sso.backchannel')->middleware('throttle:60,1')->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);

    return;
}

// SSO account management stays under the existing tech, account and MFA gates.
Route::get('/profile/work-account', [\App\Modules\UserManagement\Controllers\SsoController::class, 'profile'])->name('profile.sso');
Route::post('/profile/work-account/link', [\App\Modules\UserManagement\Controllers\SsoController::class, 'link'])->name('profile.sso.link')->middleware('throttle:5,1');
Route::post('/profile/work-account/unlink', [\App\Modules\UserManagement\Controllers\SsoController::class, 'unlink'])->name('profile.sso.unlink')->middleware('throttle:5,1');
Route::post('/profile/work-account/logout', [\App\Modules\UserManagement\Controllers\SsoController::class, 'logout'])->name('profile.sso.logout');
Route::get('/admin/user_management/sso', [\App\Modules\UserManagement\Controllers\Admin\SsoSettingsController::class, 'show'])->name('admin.user_management.sso');
Route::post('/admin/user_management/sso', [\App\Modules\UserManagement\Controllers\Admin\SsoSettingsController::class, 'update'])->name('admin.user_management.sso.update')->middleware('throttle:5,1');

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
    Route::middleware(\App\Modules\UserManagement\Http\Middleware\EnsureEmployeeWorkPlan::class)->group(function () {
        Route::get('/profile/work-plan', [\App\Modules\UserManagement\Controllers\WorkPlanController::class, 'show'])->name('profile.work-plan');
        Route::patch('/profile/work-plan', [\App\Modules\UserManagement\Controllers\WorkPlanController::class, 'update'])->name('profile.work-plan.update');
    });
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
