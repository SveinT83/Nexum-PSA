<?php

// Official calendar-only API is loaded explicitly by the legacy api.php entry.
if (($tdpsaLoadingNextcloudCalendarApiRoutes ?? false) === true) {
    // Register on the framework handler: routing can render before outer middleware catches.
    $nextcloudHandler = app(\Illuminate\Contracts\Debug\ExceptionHandler::class);
    $nextcloudHandler->renderable(fn (\Throwable $error, \Illuminate\Http\Request $request) => \App\Modules\Nextcloud\Support\NextcloudApiPrivacy::render($error, $request));
    $nextcloudHandler->reportable(function (\Throwable $error) {
        if (\App\Modules\Nextcloud\Support\NextcloudApiPrivacy::matches(request())) {
            \Illuminate\Support\Facades\Log::error('Nextcloud API operation failed.', ['exception_class' => get_class($error)]);

            return false;
        }
    });

    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'connections'])->name('nextcloud.calendar.connections')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'show'])->name('nextcloud.calendar.show')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/mappings', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'mappings'])->name('nextcloud.calendar.mappings')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/attempts', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'attempts'])->name('nextcloud.calendar.attempts')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/attempts/{attempt}', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'attempt'])->name('nextcloud.calendar.attempt')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::get('nextcloud/calendar/connections/{connection}/conflicts', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'conflicts'])->name('nextcloud.calendar.conflicts')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.read');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/preview', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'preview'])->name('nextcloud.calendar.preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.preview');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/mappings', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'upsertMapping'])->name('nextcloud.calendar.upsertMapping')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.mapping');
    \Illuminate\Support\Facades\Route::delete('nextcloud/calendar/connections/{connection}/mappings/{mapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'removeMapping'])->name('nextcloud.calendar.removeMapping')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.mapping');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/activation', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'activation'])->name('nextcloud.calendar.activation')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.activate');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/sync', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'sync'])->name('nextcloud.calendar.sync')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.sync');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/health', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarOperationsController::class, 'health'])->name('nextcloud.calendar.health')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.health');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/conflicts/{conflict}/preview', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarConflictController::class, 'preview'])->name('nextcloud.calendar.conflict.preview')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.reconcile');
    \Illuminate\Support\Facades\Route::post('nextcloud/calendar/connections/{connection}/conflicts/{conflict}/apply', [\App\Modules\Nextcloud\Controllers\Api\V1\CalendarConflictController::class, 'apply'])->name('nextcloud.calendar.conflict.apply')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.calendar.reconcile');

    // Issue #205: the ordinary service-user Talk workflow shares native authority and command receipts.
    foreach ([
        ['get', 'talk-notifications', 'context', ['nextcloud.talk.read'], [], false, false],
        ['post', 'talk-notifications/refresh', 'refresh', ['nextcloud.talk.write'], ['revision'], true, false],
        ['patch', 'talk-notifications', 'update', ['nextcloud.talk.write'], ['revision', 'enabled', 'destination_ids', 'default_destination_id'], false, false],
        ['post', 'talk-notifications/conversations', 'create', ['nextcloud.talk.write', 'nextcloud.providers.execute'], ['revision', 'name', 'confirm'], true, true],
        ['post', 'talk-notifications/destinations/{destination}/test', 'test', ['nextcloud.talk.execute', 'nextcloud.providers.execute'], ['revision', 'confirm'], true, true],
    ] as [$method, $path, $action, $abilities, $fields, $external, $write]) {
        \Illuminate\Support\Facades\Route::$method('nextcloud/connections/{connection}/'.$path,
            [\App\Modules\Nextcloud\Controllers\Api\V1\TalkNotificationController::class, $action])
            ->name('nextcloud.full.talkNotifications'.ucfirst($action))
            ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':'.implode(',', $abilities),
                \App\Modules\Nextcloud\Support\NextcloudApiAccess::class,
                \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
            ->defaults('nextcloud_fields', $fields)
            ->defaults('nextcloud_permissions', ['nextcloud.connection_manage', 'nextcloud.talk_manage'])
            ->defaults('nextcloud_abilities', $abilities)->defaults('nextcloud_key_required', $method !== 'get')
            ->defaults('nextcloud_preview', false)->defaults('nextcloud_external', $external)
            ->defaults('nextcloud_provider_write', $write)->defaults('nextcloud_talk_destination', true);
    }

    // Full operational Nextcloud API, separate from compatible calendar-only commands.
    \Illuminate\Support\Facades\Route::get('nextcloud/context', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'context'])
        ->name('nextcloud.full.nextcloudApiConnectionContextGlobal')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['kind', 'page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'index'])
        ->name('nextcloud.full.nextcloudApiConnectionIndex')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['scope', 'client_id', 'is_active', 'search', 'page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'store'])
        ->name('nextcloud.full.nextcloudApiConnectionStore')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['name', 'scope', 'mode', 'client_id', 'client_site_id', 'is_default', 'is_active', 'base_url', 'admin_url', 'root_folder', 'documents_folder', 'sync_interval_minutes', 'service_username', 'service_password', 'allow_user_credentials', 'calendar_sync_enabled', 'file_browser_enabled', 'users_groups_read_enabled', 'talk_bot_id', 'talk_bot_secret', 'talk_default_conversation_token', 'talk_bot_features'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'show'])
        ->name('nextcloud.full.nextcloudApiConnectionShow')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/context', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'context'])
        ->name('nextcloud.full.nextcloudApiConnectionContext')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['kind', 'page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::patch('nextcloud/connections/{connection}', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'update'])
        ->name('nextcloud.full.nextcloudApiConnectionUpdate')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['name', 'scope', 'mode', 'client_id', 'client_site_id', 'is_default', 'is_active', 'base_url', 'admin_url', 'root_folder', 'documents_folder', 'sync_interval_minutes', 'service_username', 'service_password', 'allow_user_credentials', 'calendar_sync_enabled', 'file_browser_enabled', 'users_groups_read_enabled', 'talk_bot_id', 'talk_bot_secret', 'talk_default_conversation_token', 'talk_bot_features'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::delete('nextcloud/connections/{connection}', [\App\Modules\Nextcloud\Controllers\Api\V1\ConnectionController::class, 'destroy'])
        ->name('nextcloud.full.nextcloudApiConnectionDestroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->missing(fn (\Illuminate\Http\Request $request) => app(\App\Modules\Nextcloud\Support\ReplayMissingNextcloudDeletion::class)->handle($request))
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/users', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'users'])
        ->name('nextcloud.full.nextcloudApiMappingUsers')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/users', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'storeUser'])
        ->name('nextcloud.full.nextcloudApiMappingStoreUser')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['remote_user_id', 'remote_username', 'remote_email', 'user_id', 'client_user_id', 'identity_type', 'mapping_action', 'client_role'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/users/{userMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'showUser'])
        ->name('nextcloud.full.nextcloudApiMappingShowUser')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::delete('nextcloud/connections/{connection}/users/{userMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'deleteUser'])
        ->name('nextcloud.full.nextcloudApiMappingDeleteUser')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->missing(fn (\Illuminate\Http\Request $request) => app(\App\Modules\Nextcloud\Support\ReplayMissingNextcloudDeletion::class)->handle($request))
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.user_mapping_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/groups', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'groups'])
        ->name('nextcloud.full.nextcloudApiMappingGroups')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/groups', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'storeGroup'])
        ->name('nextcloud.full.nextcloudApiMappingStoreGroup')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['remote_group_id', 'remote_group_name', 'role_id', 'client_id', 'client_role', 'sync_mode', 'is_managed'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/groups/{groupMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'showGroup'])
        ->name('nextcloud.full.nextcloudApiMappingShowGroup')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::delete('nextcloud/connections/{connection}/groups/{groupMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'deleteGroup'])
        ->name('nextcloud.full.nextcloudApiMappingDeleteGroup')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->missing(fn (\Illuminate\Http\Request $request) => app(\App\Modules\Nextcloud\Support\ReplayMissingNextcloudDeletion::class)->handle($request))
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.user_mapping_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/calendars', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'calendars'])
        ->name('nextcloud.full.nextcloudApiMappingCalendars')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/calendars', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'storeCalendar'])
        ->name('nextcloud.full.nextcloudApiMappingStoreCalendar')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['remote_calendar_id', 'remote_display_name', 'calendar_id', 'user_id', 'sync_direction'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/calendars/{calendarMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'showCalendar'])
        ->name('nextcloud.full.nextcloudApiMappingShowCalendar')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.mappings.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::delete('nextcloud/connections/{connection}/calendars/{calendarMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'deleteCalendar'])
        ->name('nextcloud.full.nextcloudApiMappingDeleteCalendar')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.mappings.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->missing(fn (\Illuminate\Http\Request $request) => app(\App\Modules\Nextcloud\Support\ReplayMissingNextcloudDeletion::class)->handle($request))
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.sync'])->defaults('nextcloud_abilities', ['nextcloud.mappings.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/folders', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'folders'])
        ->name('nextcloud.full.nextcloudApiMappingFolders')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/folders', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'storeFolder'])
        ->name('nextcloud.full.nextcloudApiMappingStoreFolder')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['client_id', 'purpose', 'remote_path'])->defaults('nextcloud_permissions', ['nextcloud.folder_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/folders/{folderMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'showFolder'])
        ->name('nextcloud.full.nextcloudApiMappingShowFolder')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::delete('nextcloud/connections/{connection}/folders/{folderMapping}', [\App\Modules\Nextcloud\Controllers\Api\V1\MappingController::class, 'deleteFolder'])
        ->name('nextcloud.full.nextcloudApiMappingDeleteFolder')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->missing(fn (\Illuminate\Http\Request $request) => app(\App\Modules\Nextcloud\Support\ReplayMissingNextcloudDeletion::class)->handle($request))
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.folder_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/files', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'files'])
        ->name('nextcloud.full.nextcloudApiProviderFiles')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['path'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::patch('nextcloud/connections/{connection}/folders', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'folders'])
        ->name('nextcloud.full.nextcloudApiProviderFolders')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['folder_type', 'remote_path'])->defaults('nextcloud_permissions', ['nextcloud.folder_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/folders/auto-match/preview', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'previewMatch'])
        ->name('nextcloud.full.nextcloudApiProviderPreviewMatch')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.folder_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.write'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', true)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/folders/auto-match/apply', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'applyMatch'])
        ->name('nextcloud.full.nextcloudApiProviderApplyMatch')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.folders.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['preview_token'])->defaults('nextcloud_permissions', ['nextcloud.folder_manage'])->defaults('nextcloud_abilities', ['nextcloud.folders.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/health', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'health'])
        ->name('nextcloud.full.nextcloudApiProviderHealth')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.health', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.health'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', true)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/sync/preview', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'previewSync'])
        ->name('nextcloud.full.nextcloudApiProviderPreviewSync')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.sync', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.sync'])->defaults('nextcloud_abilities', ['nextcloud.sync'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', true)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/sync', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'sync'])
        ->name('nextcloud.full.nextcloudApiProviderSync')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.sync,nextcloud.providers.execute', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['preview_token', 'confirm'])->defaults('nextcloud_permissions', ['nextcloud.sync'])->defaults('nextcloud_abilities', ['nextcloud.sync', 'nextcloud.providers.execute'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', true)->defaults('nextcloud_provider_write', true);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/attempts', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'attempts'])
        ->name('nextcloud.full.nextcloudApiProviderAttempts')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['page', 'per_page'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/attempts/{attempt}', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'attempt'])
        ->name('nextcloud.full.nextcloudApiProviderAttempt')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.connections.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.connections.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::get('nextcloud/connections/{connection}/talk-bot', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'talk'])
        ->name('nextcloud.full.nextcloudApiProviderTalk')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.talk.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.talk.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::patch('nextcloud/connections/{connection}/talk-bot', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'updateTalk'])
        ->name('nextcloud.full.nextcloudApiProviderUpdateTalk')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.talk.write', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['talk_bot_id', 'talk_bot_secret', 'talk_default_conversation_token', 'talk_bot_features'])->defaults('nextcloud_permissions', ['nextcloud.connection_manage'])->defaults('nextcloud_abilities', ['nextcloud.talk.write'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);
    \Illuminate\Support\Facades\Route::post('nextcloud/connections/{connection}/talk-bot/test', [\App\Modules\Nextcloud\Controllers\Api\V1\ProviderController::class, 'testTalk'])
        ->name('nextcloud.full.nextcloudApiProviderTestTalk')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.talk.execute,nextcloud.providers.execute', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', ['confirm'])->defaults('nextcloud_permissions', ['nextcloud.talk_manage'])->defaults('nextcloud_abilities', ['nextcloud.talk.execute', 'nextcloud.providers.execute'])->defaults('nextcloud_key_required', true)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', true)->defaults('nextcloud_provider_write', true);
    \Illuminate\Support\Facades\Route::get('nextcloud/operations/{operation}', [\App\Modules\Nextcloud\Controllers\Api\V1\OperationController::class, 'show'])
        ->name('nextcloud.full.nextcloudApiOperationShow')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':nextcloud.operations.read', \App\Modules\Nextcloud\Support\NextcloudApiAccess::class, \App\Modules\Nextcloud\Actions\ExecuteNextcloudApiCommand::class])
        ->defaults('nextcloud_fields', [])->defaults('nextcloud_permissions', [])->defaults('nextcloud_abilities', ['nextcloud.operations.read'])->defaults('nextcloud_key_required', false)->defaults('nextcloud_preview', false)->defaults('nextcloud_external', false)->defaults('nextcloud_provider_write', false);

    return;
}

use App\Modules\Nextcloud\Controllers\Admin\NextcloudConnectionController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Nextcloud Module Routes
|--------------------------------------------------------------------------
|
| These routes own Nextcloud connection setup and operational sync controls.
| They are loaded inside the authenticated tech route group.
|
*/

Route::middleware('admin')->group(function () {
    // Ordinary Talk setup is connection-scoped; no arbitrary webhook URL is accepted.
    foreach (['refresh' => 'post', 'update' => 'patch', 'create' => 'post', 'test' => 'post'] as $action => $method) {
        $path = '/admin/system/nextcloud/connections/{connection}/talk-notifications';
        $path .= match ($action) {
            'refresh' => '/refresh', 'create' => '/conversations', 'test' => '/destinations/{destination}/test', default => '',
        };
        Route::$method($path, [\App\Modules\Nextcloud\Controllers\Admin\TalkNotificationController::class, $action])
            ->name('admin.nextcloud.talk-notifications.'.$action);
    }


    Route::get('/admin/system/nextcloud', [NextcloudConnectionController::class, 'index'])
        ->name('admin.nextcloud.connections.index');

    Route::get('/admin/system/nextcloud/connections/{connection}', [NextcloudConnectionController::class, 'show'])
        ->name('admin.nextcloud.connections.show');

    Route::post('/admin/system/nextcloud/connections', [NextcloudConnectionController::class, 'store'])
        ->name('admin.nextcloud.connections.store');

    Route::patch('/admin/system/nextcloud/connections/{connection}', [NextcloudConnectionController::class, 'update'])
        ->name('admin.nextcloud.connections.update');

    Route::delete('/admin/system/nextcloud/connections/{connection}', [NextcloudConnectionController::class, 'destroy'])
        ->name('admin.nextcloud.connections.destroy');

    Route::post('/admin/system/nextcloud/connections/{connection}/check', [NextcloudConnectionController::class, 'check'])
        ->name('admin.nextcloud.connections.check');

    Route::post('/admin/system/nextcloud/connections/{connection}/sync', [NextcloudConnectionController::class, 'sync'])
        ->name('admin.nextcloud.connections.sync');

    Route::post('/admin/system/nextcloud/connections/{connection}/test-talk-bot', [NextcloudConnectionController::class, 'testTalkBot'])
        ->name('admin.nextcloud.connections.test-talk-bot');

    Route::patch('/admin/system/nextcloud/connections/{connection}/talk-bot', [NextcloudConnectionController::class, 'updateTalkBot'])
        ->name('admin.nextcloud.connections.update-talk-bot');

    Route::post('/admin/system/nextcloud/connections/{connection}/users', [NextcloudConnectionController::class, 'storeUserMapping'])
        ->name('admin.nextcloud.connections.users.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/groups', [NextcloudConnectionController::class, 'storeGroupMapping'])
        ->name('admin.nextcloud.connections.groups.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/calendars', [NextcloudConnectionController::class, 'storeCalendarMapping'])
        ->name('admin.nextcloud.connections.calendars.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/folders', [NextcloudConnectionController::class, 'storeFolderMapping'])
        ->name('admin.nextcloud.connections.folders.store');

    Route::post('/admin/system/nextcloud/connections/{connection}/folders/auto-match', [NextcloudConnectionController::class, 'autoMatchClientFolders'])
        ->name('admin.nextcloud.connections.folders.auto_match');

    Route::patch('/admin/system/nextcloud/connections/{connection}/folders', [NextcloudConnectionController::class, 'updateFolders'])
        ->name('admin.nextcloud.connections.folders.update');

    Route::delete('/admin/system/nextcloud/user-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyUserMapping'])
        ->name('admin.nextcloud.user-mappings.destroy');

    Route::delete('/admin/system/nextcloud/group-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyGroupMapping'])
        ->name('admin.nextcloud.group-mappings.destroy');

    Route::delete('/admin/system/nextcloud/calendar-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyCalendarMapping'])
        ->name('admin.nextcloud.calendar-mappings.destroy');

    Route::delete('/admin/system/nextcloud/folder-mappings/{mapping}', [NextcloudConnectionController::class, 'destroyFolderMapping'])
        ->name('admin.nextcloud.folder-mappings.destroy');
});
