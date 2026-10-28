<?php

use App\Modules\Task\Controllers\Admin\TaskSettingsController;
use App\Modules\Task\Controllers\Admin\TaskTemplateController;
use App\Modules\Task\Controllers\Tech\TaskController;
use Illuminate\Support\Facades\Route;

// Full operational API registration remains isolated from browser and compatibility routes.
if (($tdpsaLoadingApiRoutes ?? false) === true) {
    Route::get('task-settings/history', [\App\Modules\Task\Controllers\Api\V1\TaskSettingsApiController::class, 'history'])
        ->name('task-settings.history')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_settings'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.settings.read')->defaults('parity_permissions', 'task.manage_settings');

    Route::get('task-templates/{template}/history', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'history'])
        ->name('task-templates.history')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.manage_templates');

    Route::get('task-settings', [\App\Modules\Task\Controllers\Api\V1\TaskSettingsApiController::class, 'show'])
        ->name('task-settings.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_settings'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.settings.read')->defaults('parity_permissions', 'task.manage_settings');
    Route::put('task-settings', [\App\Modules\Task\Controllers\Api\V1\TaskSettingsApiController::class, 'update'])
        ->name('task-settings.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_settings'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.settings.update')->defaults('parity_permissions', 'task.manage_settings')->defaults('parity_input_fields', ['default_estimated_minutes', 'default_priority_id', 'default_status_id', 'ticket_billing_minutes_mode'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('task-settings/context/{catalog}', [\App\Modules\Task\Controllers\Api\V1\TaskSettingsApiController::class, 'context'])
        ->name('task-settings.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.settings.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_settings'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.settings.read')->defaults('parity_permissions', 'task.manage_settings');
    Route::post('task-settings/initialize', [\App\Modules\Task\Controllers\Api\V1\TaskSettingsApiController::class, 'initialize'])
        ->name('task-settings.initialize')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.settings.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_settings'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.settings.update')->defaults('parity_permissions', 'task.manage_settings')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('task-templates/{template}/schedules', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'index'])
        ->name('task-schedules.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.read')->defaults('parity_permissions', 'task.manage_templates');
    Route::get('task-templates/{template}/schedules/{schedule}', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'show'])
        ->name('task-schedules.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.read')->defaults('parity_permissions', 'task.manage_templates');
    Route::post('task-templates/{template}/schedules', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'store'])
        ->name('task-schedules.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['assigned_to', 'due_offset_minutes', 'interval', 'next_run_at', 'owner_id', 'owner_type', 'schedule_name', 'timezone'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('task-templates/{template}/schedules/{schedule}', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'update'])
        ->name('task-schedules.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['assigned_to', 'due_offset_minutes', 'interval', 'next_run_at', 'owner_id', 'owner_type', 'schedule_name', 'timezone'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::patch('task-templates/{template}/schedules/{schedule}/active', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'active'])
        ->name('task-schedules.active')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['is_active'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('task-templates/{template}/schedules/{schedule}', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'destroy'])
        ->name('task-schedules.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('task-templates/{template}/schedules/{schedule}/run-preview', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'preview'])
        ->name('task-schedules.preview')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.read')->defaults('parity_permissions', 'task.manage_templates');
    Route::post('task-templates/{template}/schedules/{schedule}/run', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'run'])
        ->name('task-schedules.run')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.run', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.run')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['expected_schedule_snapshot', 'expected_template_snapshot', 'idempotency_key'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('task-templates/{template}/schedules/{schedule}/runs', [\App\Modules\Task\Controllers\Api\V1\TaskScheduleApiController::class, 'runs'])
        ->name('task-schedules.runs')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.schedules.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.schedules.read')->defaults('parity_permissions', 'task.manage_templates');

    Route::get('task-templates', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'index'])
        ->name('task-templates.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::get('task-templates/{template}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'show'])
        ->name('task-templates.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::post('task-templates', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'store'])
        ->name('task-templates.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['description', 'is_active', 'name', 'slug'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('task-templates/{template}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'update'])
        ->name('task-templates.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['description', 'is_active', 'name', 'slug'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('task-templates/{template}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'destroy'])
        ->name('task-templates.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('task-templates/{template}/items', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'items'])
        ->name('task-templates.items.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::get('task-templates/{template}/items/{item}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'item'])
        ->name('task-templates.items.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::post('task-templates/{template}/items', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'storeItem'])
        ->name('task-templates.items.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['assigned_to', 'blocks_owner_completion', 'category_id', 'checklist_text', 'depends_on_ids', 'description', 'due_offset_minutes', 'estimated_minutes', 'parent_id', 'priority_id', 'queue_id', 'scheduled_end_offset_minutes', 'scheduled_start_offset_minutes', 'sort_order', 'status_id', 'tag_names', 'title'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('task-templates/{template}/items/{item}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'updateItem'])
        ->name('task-templates.items.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', ['assigned_to', 'blocks_owner_completion', 'category_id', 'checklist_text', 'depends_on_ids', 'description', 'due_offset_minutes', 'estimated_minutes', 'parent_id', 'priority_id', 'queue_id', 'scheduled_end_offset_minutes', 'scheduled_start_offset_minutes', 'sort_order', 'status_id', 'tag_names', 'title'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::delete('task-templates/{template}/items/{item}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'destroyItem'])
        ->name('task-templates.items.destroy')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.manage', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.manage')->defaults('parity_permissions', 'task.manage_templates')->defaults('parity_input_fields', [])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('task-templates/{template}/preview', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'preview'])
        ->name('task-templates.preview')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create');
    Route::post('task-templates/{template}/apply', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'apply'])
        ->name('task-templates.apply')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.apply', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.apply')->defaults('parity_permissions', 'task.create')->defaults('parity_input_fields', ['anchor_at', 'assigned_to', 'expected_template_snapshot', 'idempotency_key', 'owner_id', 'owner_type'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::get('task-template-targets', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'targets'])
        ->name('task-templates.targets')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create');
    Route::get('task-templates/{template}/runs', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'runs'])
        ->name('task-templates.runs.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::get('task-templates/{template}/runs/{run}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'run'])
        ->name('task-templates.runs.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::get('task-templates/{template}/runs/{run}/tasks', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'runTasks'])
        ->name('task-templates.runs.tasks')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create|task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.create|task.manage_templates');
    Route::get('task-templates/context/{catalog}', [\App\Modules\Task\Controllers\Api\V1\TaskTemplateApiController::class, 'context'])
        ->name('task-templates.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.templates.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.manage_templates'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.templates.read')->defaults('parity_permissions', 'task.manage_templates');

    Route::patch('tasks/{task}/assignee', [\App\Modules\Task\Controllers\Api\V1\TaskOperationController::class, 'assign'])
        ->name('tasks.assignee.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.assign', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.assign'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.assign')->defaults('parity_permissions', 'task.assign')->defaults('parity_input_fields', ['assigned_to'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::patch('tasks/{task}/status', [\App\Modules\Task\Controllers\Api\V1\TaskOperationController::class, 'status'])
        ->name('tasks.status.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.status.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.update'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.status.update')->defaults('parity_permissions', 'task.update')->defaults('parity_input_fields', ['status_id'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::put('tasks/{task}/checklist/{item}', [\App\Modules\Task\Controllers\Api\V1\TaskOperationController::class, 'checklist'])
        ->name('tasks.checklist.update')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.checklist.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.update'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.checklist.update')->defaults('parity_permissions', 'task.update')->defaults('parity_input_fields', ['is_checked'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tasks/{task}/time-entries', [\App\Modules\Task\Controllers\Api\V1\TaskOperationController::class, 'time'])
        ->name('tasks.time-entries.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.time.create', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.update'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.time.create')->defaults('parity_permissions', 'task.update')->defaults('parity_conditional_authorization', ['Ticket billing: ticket.register_time and an available rate.'])->defaults('parity_input_fields', ['idempotency_key', 'invoice_text', 'minutes', 'note', 'rate_key', 'work_date'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tasks/{task}/complete', [\App\Modules\Task\Controllers\Api\V1\TaskOperationController::class, 'complete'])
        ->name('tasks.complete')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.complete', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.complete'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.complete')->defaults('parity_permissions', 'task.complete')->defaults('parity_conditional_authorization', ['Time registration: tasks.time.create. Ticket billing additionally requires ticket.register_time and an available rate.'])->defaults('parity_input_fields', ['invoice_text', 'minutes', 'note', 'rate_key', 'work_date'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);
    Route::post('tasks/ai-suggestions', [\App\Modules\Task\Controllers\Api\V1\TaskOperationController::class, 'aiSuggest'])
        ->name('tasks.ai-suggestions.store')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.ai.suggest', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.ai.suggest')->defaults('parity_permissions', 'task.view')->middleware(\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tickets.read')->defaults('parity_additional_scopes', ['tickets.read'])->middleware(\App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':ticket.view')->defaults('parity_additional_permissions', ['ticket.view'])->defaults('parity_conditional_authorization', ['Selected ticket_id: tickets.messages.read. Private parent Tasks remain private.'])->defaults('parity_input_fields', ['assigned_to', 'category_id', 'checklist_text', 'client_id', 'description', 'estimated_minutes', 'parent_id', 'priority_id', 'queue_id', 'site_id', 'tag_names', 'ticket_id', 'ticket_rate_key', 'title'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

    Route::get('tasks/docs', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'documentation'])
        ->name('tasks.docs')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');

    Route::get('tasks/capabilities', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'capabilities'])
        ->name('tasks.capabilities')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view|task.create|task.update'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view|task.create|task.update');
    Route::get('tasks/context', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'context'])
        ->name('tasks.context')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view|task.create|task.update'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view|task.create|task.update');
    Route::get('tasks/context/{catalog}', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'options'])
        ->name('tasks.context.options')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view|task.create|task.update'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view|task.create|task.update');
    Route::get('tasks/{task}/context', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'recordContext'])
        ->name('tasks.context.show')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');
    Route::get('tasks/{task}/activities', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'activities'])
        ->name('tasks.activities.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');
    Route::get('tasks/{task}/time-entries', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'timeEntries'])
        ->name('tasks.time-entries.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.time.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.time.read')->defaults('parity_permissions', 'task.view');
    Route::get('tasks/{task}/children', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'children'])
        ->name('tasks.children.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');
    Route::get('tasks/{task}/dependencies', [\App\Modules\Task\Controllers\Api\V1\TaskReadbackController::class, 'dependencies'])
        ->name('tasks.dependencies.index')->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])
        ->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');

    // Existing public API compatibility routes share this authoritative module route owner.
Route::get('tasks', [\App\Modules\Task\Controllers\Api\V1\TaskController::class, 'index'])
    ->name('tasks.index')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');

Route::get('tasks/stale', [\App\Modules\Task\Controllers\Api\V1\StaleTaskController::class, 'index'])
    ->name('tasks.stale.index')
    ->middleware(\App\Modules\Integration\Http\Middleware\EnforceCoordinatorWorkload::class.':pseudonymized,tasks.read')->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view')->defaults('parity_coordinator_profile', 'pseudonymized');

Route::post('tasks', [\App\Modules\Task\Controllers\Api\V1\TaskController::class, 'store'])
    ->name('tasks.store')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.create', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.create'])->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.create')->defaults('parity_permissions', 'task.create')->defaults('parity_conditional_authorization', ['Ticket owner: tickets.read and ticket.view; current Ticket/client/site context.'])->defaults('parity_input_fields', ['assigned_to', 'blocks_owner_completion', 'category_id', 'checklist_text', 'client_id', 'description', 'due_at', 'estimated_minutes', 'metadata', 'owner_id', 'owner_type', 'parent_id', 'priority_id', 'queue_id', 'scheduled_end_at', 'scheduled_start_at', 'site_id', 'status_id', 'tag_names', 'ticket_rate_key', 'title'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);

Route::get('tasks/{task}', [\App\Modules\Task\Controllers\Api\V1\TaskController::class, 'show'])
    ->name('tasks.show')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.read', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.view'])->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.read')->defaults('parity_permissions', 'task.view');

Route::match(['put', 'patch'], 'tasks/{task}', [\App\Modules\Task\Controllers\Api\V1\TaskController::class, 'update'])
    ->name('tasks.update')
    ->middleware([\Laravel\Sanctum\Http\Middleware\CheckAbilities::class.':tasks.update', \App\Modules\Integration\Http\Middleware\RequireDomainApiPermission::class.':task.update'])->defaults('parity_domain', 'task')->defaults('parity_scope', 'tasks.update')->defaults('parity_permissions', 'task.update')->defaults('parity_conditional_authorization', ['Ticket owner: tickets.read and ticket.view; current Ticket/client/site context.'])->defaults('parity_input_fields', ['assigned_to', 'blocks_owner_completion', 'category_id', 'checklist_text', 'client_id', 'description', 'due_at', 'estimated_minutes', 'metadata', 'parent_id', 'priority_id', 'queue_id', 'scheduled_end_at', 'scheduled_start_at', 'site_id', 'status_id', 'tag_names', 'ticket_rate_key', 'title'])->middleware(\App\Modules\Integration\Http\Middleware\RejectUnsupportedDomainApiInput::class);


    return;
}

Route::get('/admin/settings/tasks', [TaskSettingsController::class, 'edit'])->name('admin.settings.tasks');
Route::put('/admin/settings/tasks', [TaskSettingsController::class, 'update'])->name('admin.settings.tasks.update');
Route::get('/admin/task-templates', [TaskTemplateController::class, 'index'])->name('admin.task-templates.index');
Route::get('/admin/task-templates/create', [TaskTemplateController::class, 'create'])->name('admin.task-templates.create');
Route::post('/admin/task-templates', [TaskTemplateController::class, 'store'])->name('admin.task-templates.store');
Route::get('/admin/task-templates/{template}', [TaskTemplateController::class, 'show'])->name('admin.task-templates.show');
Route::get('/admin/task-templates/{template}/edit', [TaskTemplateController::class, 'edit'])->name('admin.task-templates.edit');
Route::put('/admin/task-templates/{template}', [TaskTemplateController::class, 'update'])->name('admin.task-templates.update');
Route::delete('/admin/task-templates/{template}', [TaskTemplateController::class, 'destroy'])->name('admin.task-templates.destroy');
Route::post('/admin/task-templates/{template}/items', [TaskTemplateController::class, 'storeItem'])->name('admin.task-templates.items.store');
Route::put('/admin/task-templates/{template}/items/{item}', [TaskTemplateController::class, 'updateItem'])->name('admin.task-templates.items.update');
Route::delete('/admin/task-templates/{template}/items/{item}', [TaskTemplateController::class, 'destroyItem'])->name('admin.task-templates.items.destroy');
Route::post('/admin/task-templates/{template}/schedules', [TaskTemplateController::class, 'storeSchedule'])->name('admin.task-templates.schedules.store');
Route::put('/admin/task-templates/{template}/schedules/{schedule}', [TaskTemplateController::class, 'updateSchedule'])->name('admin.task-templates.schedules.update');
Route::patch('/admin/task-templates/{template}/schedules/{schedule}/toggle', [TaskTemplateController::class, 'toggleSchedule'])->name('admin.task-templates.schedules.toggle');
Route::delete('/admin/task-templates/{template}/schedules/{schedule}', [TaskTemplateController::class, 'destroySchedule'])->name('admin.task-templates.schedules.destroy');
Route::post('/admin/task-templates/{template}/schedules/{schedule}/run', [TaskTemplateController::class, 'runSchedule'])->name('admin.task-templates.schedules.run');
Route::get('/task-templates/choose', [TaskTemplateController::class, 'choose'])->name('task-templates.choose');
Route::get('/task-templates/{template}/preview', [TaskTemplateController::class, 'preview'])->name('task-templates.preview');
Route::post('/task-templates/{template}/apply', [TaskTemplateController::class, 'apply'])->name('task-templates.apply');

Route::get('/tasks', [TaskController::class, 'index'])->name('tasks.index');
Route::get('/tasks/create', [TaskController::class, 'create'])->name('tasks.create');
Route::post('/tasks/ai-suggest', [TaskController::class, 'aiSuggest'])->name('tasks.ai-suggest');
Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
Route::get('/tasks/docs', [TaskController::class, 'docs'])->name('tasks.docs');
Route::get('/tasks/{task}/edit', [TaskController::class, 'edit'])->name('tasks.edit');
Route::get('/tasks/{task}', [TaskController::class, 'show'])->name('tasks.show');
Route::patch('/tasks/{task}', [TaskController::class, 'update'])->name('tasks.update');
Route::patch('/tasks/{task}/status', [TaskController::class, 'updateStatus'])->name('tasks.status.update');
Route::patch('/tasks/{task}/assign', [TaskController::class, 'assign'])->name('tasks.assign');
Route::post('/tasks/{task}/time-entries', [TaskController::class, 'storeTimeEntry'])->name('tasks.time-entries.store');
Route::post('/tasks/{task}/complete', [TaskController::class, 'complete'])->name('tasks.complete');
Route::patch('/tasks/{task}/checklist/{item}', [TaskController::class, 'toggleChecklistItem'])->name('tasks.checklist.toggle');
