<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateRun;
use App\Modules\Task\Support\TaskSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskScheduleSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        foreach (['task.view', 'task.create', 'task.manage_templates', 'task.manage_settings'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTaskDefaults::class)->handle();
        Sanctum::actingAs($this->tech, ['tasks.templates.read', 'tasks.templates.manage', 'tasks.schedules.read', 'tasks.schedules.manage', 'tasks.schedules.run', 'tasks.settings.read', 'tasks.settings.update']);
    }

    public function test_schedule_crud_and_active_setter_preserve_timezone_and_nested_ownership(): void
    {
        $template = $this->template();
        $id = $this->postJson(route('api.v1.task-schedules.store', $template), $this->schedulePayload())
            ->assertCreated()->assertJsonPath('data.is_active', true)->assertJsonPath('data.timezone', 'Europe/Oslo')->json('data.id');
        $schedule = TaskRecurringTemplate::findOrFail($id);
        $this->assertSame('2027-01-15 09:00:00', $schedule->next_run_at->utc()->format('Y-m-d H:i:s'));
        $this->putJson(route('api.v1.task-schedules.update', [$template, $schedule]), $this->schedulePayload() + ['unused' => true])->assertUnprocessable()->assertJsonValidationErrors('unused');
        $this->putJson(route('api.v1.task-schedules.update', [$template, $schedule]), $this->schedulePayload())->assertOk();
        $this->patchJson(route('api.v1.task-schedules.active', [$template, $schedule]), ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $savedAt = $schedule->refresh()->updated_at->toISOString();
        $this->travel(1)->seconds();
        $this->patchJson(route('api.v1.task-schedules.active', [$template, $schedule]), ['is_active' => false])->assertOk();
        $this->assertSame($savedAt, $schedule->refresh()->updated_at->toISOString());
        $this->getJson(route('api.v1.task-schedules.show', [$this->template(), $schedule]))->assertNotFound();
        $this->getJson(route('api.v1.task-schedules.index', ['template' => $template, 'per_page' => 101]))->assertUnprocessable();
        $this->deleteJson(route('api.v1.task-schedules.destroy', [$template, $schedule]))->assertOk();
        $this->getJson(route('api.v1.task-schedules.show', [$template, $schedule]))->assertNotFound();
    }

    public function test_schedule_targets_and_intervals_are_the_same_supported_future_owner_contract(): void
    {
        $template = $this->template();
        $payload = $this->schedulePayload();
        $payload['owner_type'] = 'ticket';
        $this->postJson(route('api.v1.task-schedules.store', $template), $payload)->assertUnprocessable()->assertJsonValidationErrors('owner_type');
        $payload = $this->schedulePayload();
        $payload['interval'] = 'yearly';
        $this->postJson(route('api.v1.task-schedules.store', $template), $payload)->assertUnprocessable()->assertJsonValidationErrors('interval');
        $payload = $this->schedulePayload();
        $payload['timezone'] = 'invalid/timezone';
        $this->postJson(route('api.v1.task-schedules.store', $template), $payload)->assertUnprocessable()->assertJsonValidationErrors('timezone');
        $this->assertSame(0, TaskRecurringTemplate::count());
    }

    public function test_manual_run_uses_reviewed_snapshots_and_stable_key_without_advancing_the_automatic_schedule(): void
    {
        $template = $this->template();
        $template->allItems()->create(['title' => 'Scheduled work']);
        $schedule = $this->schedule($template);
        $next = $schedule->next_run_at->toISOString();
        $preview = $this->getJson(route('api.v1.task-schedules.preview', [$template, $schedule]))->assertOk()->assertJsonPath('data.will_create_task_count', 1);
        $this->assertSame(0, Task::count());
        $this->assertSame(0, TaskTemplateRun::count());
        $payload = ['idempotency_key' => 'manual-run', 'expected_schedule_snapshot' => $preview->json('data.expected_schedule_snapshot'), 'expected_template_snapshot' => $preview->json('data.expected_template_snapshot')];
        $url = route('api.v1.task-schedules.run', [$template, $schedule]);
        $runId = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.requested_by', $this->tech->id)->json('data.id');
        $last = $schedule->refresh()->last_run_at->toISOString();
        $this->travel(1)->seconds();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $runId);
        $this->assertSame(1, Task::count());
        $this->assertSame(1, TaskTemplateRun::count());
        $this->assertSame($next, $schedule->refresh()->next_run_at->toISOString());
        $this->assertSame($last, $schedule->last_run_at->toISOString());
        $this->getJson(route('api.v1.task-schedules.runs', [$template, $schedule]))->assertOk()->assertJsonPath('meta.total', 1);
        $this->deleteJson(route('api.v1.task-schedules.destroy', [$template, $schedule]))->assertOk();
        $this->getJson(route('api.v1.task-templates.runs.index', ['template' => $template, 'schedule_id' => $schedule->id]))->assertOk()->assertJsonPath('meta.total', 1);
    }

    public function test_generation_retains_configured_creator_and_audits_a_different_requester(): void
    {
        $template = $this->template();
        $template->allItems()->create(['title' => 'Delegated schedule work']);
        $schedule = $this->schedule($template);
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $schedule->forceFill(['created_by' => $creator->id])->save();
        $preview = $this->getJson(route('api.v1.task-schedules.preview', [$template, $schedule]))->assertOk();
        $this->postJson(route('api.v1.task-schedules.run', [$template, $schedule]), ['idempotency_key' => 'delegated', 'expected_schedule_snapshot' => $preview->json('data.expected_schedule_snapshot'), 'expected_template_snapshot' => $preview->json('data.expected_template_snapshot')])
            ->assertOk()->assertJsonPath('data.actor_id', $creator->id)->assertJsonPath('data.requested_by', $this->tech->id);
        $this->assertSame($creator->id, Task::firstOrFail()->created_by);
    }

    public function test_failed_manual_generation_preserves_history_without_partial_tasks_or_retry_duplicates(): void
    {
        $template = $this->template();
        $item = $template->allItems()->create(['title' => 'Original']);
        $schedule = $this->schedule($template);
        $preview = $this->getJson(route('api.v1.task-schedules.preview', [$template, $schedule]))->assertOk();
        $item->update(['title' => 'Changed after preview']);
        $payload = ['idempotency_key' => 'failed-run', 'expected_schedule_snapshot' => $preview->json('data.expected_schedule_snapshot'), 'expected_template_snapshot' => $preview->json('data.expected_template_snapshot')];
        $url = route('api.v1.task-schedules.run', [$template, $schedule]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_template_snapshot');
        $this->assertSame(0, Task::count());
        $this->assertSame('failed', TaskTemplateRun::firstOrFail()->status);
        $this->assertSame('failed', $schedule->refresh()->last_result);
        $this->postJson($url, $payload)->assertStatus(409);
        $this->assertSame(1, TaskTemplateRun::count());
        $this->getJson(route('api.v1.task-schedules.runs', [$template, $schedule]))->assertOk()->assertJsonPath('data.0.status', 'failed');
    }

    public function test_schedule_preview_drift_and_inactive_creator_fail_before_generation(): void
    {
        $template = $this->template();
        $template->allItems()->create(['title' => 'Never generated']);
        $schedule = $this->schedule($template);
        $previewUrl = route('api.v1.task-schedules.preview', [$template, $schedule]);
        $preview = $this->getJson($previewUrl)->assertOk();
        $schedule->update(['interval' => 'monthly']);
        $payload = ['idempotency_key' => 'stale-schedule', 'expected_schedule_snapshot' => $preview->json('data.expected_schedule_snapshot'), 'expected_template_snapshot' => $preview->json('data.expected_template_snapshot')];
        $url = route('api.v1.task-schedules.run', [$template, $schedule]);
        $this->postJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_schedule_snapshot');
        $preview = $this->getJson($previewUrl)->assertOk();
        $payload['expected_schedule_snapshot'] = $preview->json('data.expected_schedule_snapshot');
        $inactive = User::factory()->create(['status' => 'pending']);
        $schedule->update(['created_by' => $inactive->id]);
        $preview = $this->getJson($previewUrl)->assertOk();
        $payload['expected_schedule_snapshot'] = $preview->json('data.expected_schedule_snapshot');
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame(0, TaskTemplateRun::count());
        $this->assertSame(0, Task::count());
    }

    public function test_task_settings_read_and_update_share_validated_transactional_defaults(): void
    {
        $status = TaskStatus::query()->where('is_active', true)->where('is_default', false)->firstOrFail();
        $this->getJson(route('api.v1.task-settings.show'))->assertOk();
        $this->putJson(route('api.v1.task-settings.update'), ['default_status_id' => $status->id, 'default_estimated_minutes' => 35, 'ticket_billing_minutes_mode' => TaskSettings::TICKET_BILLING_ESTIMATE_MINIMUM])
            ->assertOk()->assertJsonPath('data.default_status_id', $status->id)->assertJsonPath('data.default_estimated_minutes', 35);
        $this->getJson(route('api.v1.task-settings.show'))->assertOk()->assertJsonPath('data.ticket_billing_minutes_mode', TaskSettings::TICKET_BILLING_ESTIMATE_MINIMUM);
        $this->postJson(route('api.v1.task-settings.initialize'))->assertOk()->assertJsonPath('data.default_status_id', $status->id);
        $this->getJson(route('api.v1.task-settings.context', ['catalog' => 'statuses', 'per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->putJson(route('api.v1.task-settings.update'), ['default_status_id' => $status->id, 'default_estimated_minutes' => 0, 'ticket_billing_minutes_mode' => 'unsupported'])->assertUnprocessable();
    }

    public function test_read_scopes_and_full_token_still_require_the_actual_admin_domain_rights(): void
    {
        $template = $this->template();
        Sanctum::actingAs($this->tech, ['tasks.schedules.read', 'tasks.settings.read']);
        $this->postJson(route('api.v1.task-schedules.store', $template), $this->schedulePayload())->assertForbidden();
        $this->putJson(route('api.v1.task-settings.update'), [])->assertForbidden();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($other, ['*']);
        $this->getJson(route('api.v1.task-schedules.index', $template))->assertForbidden();
        $this->getJson(route('api.v1.task-settings.show'))->assertForbidden();
    }

    public function test_task_settings_audit_failure_rolls_back_defaults_and_readback_excludes_content(): void
    {
        $status = TaskStatus::query()->where('is_active', true)->where('is_default', false)->firstOrFail();
        $original = TaskStatus::query()->default()->value('id');
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $payload = ['default_status_id' => $status->id, 'default_estimated_minutes' => 41, 'ticket_billing_minutes_mode' => 'actual'];
        $this->putJson(route('api.v1.task-settings.update'), $payload)->assertUnprocessable()->assertJsonValidationErrors('audit');
        $this->assertSame($original, TaskStatus::query()->default()->value('id'));
        app(\Spatie\Activitylog\ActivityLogStatus::class)->enable();
        $this->putJson(route('api.v1.task-settings.update'), $payload)->assertOk();
        $this->getJson(route('api.v1.task-settings.history'))->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.event', 'task.settings.updated')->assertJsonMissingPath('data.0.properties.before');
        $this->putJson(route('api.v1.task-settings.update'), $payload)->assertOk();
        $this->getJson(route('api.v1.task-settings.history'))->assertOk()->assertJsonPath('meta.total', 1);
    }

    private function template(): TaskTemplateGroup
    {
        return TaskTemplateGroup::create(['name' => 'Schedule template '.uniqid(), 'is_active' => true]);
    }

    private function schedulePayload(): array
    {
        return ['owner_type' => 'user', 'owner_id' => $this->tech->id, 'schedule_name' => 'Recurring API work', 'interval' => 'weekly', 'timezone' => 'Europe/Oslo', 'next_run_at' => '2027-01-15 10:00:00', 'assigned_to' => $this->tech->id];
    }

    private function schedule(TaskTemplateGroup $template): TaskRecurringTemplate
    {
        $id = $this->postJson(route('api.v1.task-schedules.store', $template), $this->schedulePayload())->assertCreated()->json('data.id');

        return TaskRecurringTemplate::findOrFail($id);
    }
}
