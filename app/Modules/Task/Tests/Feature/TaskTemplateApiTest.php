<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Task\Models\TaskTemplateRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTemplateApiTest extends TestCase
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
        foreach (['task.view', 'task.create', 'task.manage_templates', 'client.view', 'ticket.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTaskDefaults::class)->handle();
        Sanctum::actingAs($this->tech, ['tasks.templates.read', 'tasks.templates.manage', 'tasks.templates.apply', 'tasks.read', 'clients.read', 'tickets.read']);
    }

    public function test_catalog_and_complete_graph_crud_are_bounded_atomic_and_nested(): void
    {
        $templateId = $this->postJson(route('api.v1.task-templates.store'), ['name' => 'API template', 'is_active' => true])->assertCreated()->json('data.id');
        $template = TaskTemplateGroup::findOrFail($templateId);
        $a = $this->postJson(route('api.v1.task-templates.items.store', $template), ['title' => 'First', 'assigned_to' => $this->tech->id, 'checklist_text' => "Check one\nCheck two", 'tag_names' => 'Network, API'])->assertCreated()
            ->assertJsonCount(2, 'data.checklist_items')->assertJsonCount(2, 'data.tags')->json('data.id');
        $b = $this->postJson(route('api.v1.task-templates.items.store', $template), ['title' => 'Second', 'parent_id' => $a, 'depends_on_ids' => [$a]])->assertCreated()->json('data.id');
        $this->putJson(route('api.v1.task-templates.items.update', [$template, $a]), ['title' => 'First changed', 'depends_on_ids' => [$b]])->assertUnprocessable();
        $this->assertSame('First', TaskTemplateItem::findOrFail($a)->title);
        $this->getJson(route('api.v1.task-templates.items.index', ['template' => $template, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson(route('api.v1.task-templates.items.index', ['template' => $template, 'per_page' => 101]))->assertUnprocessable();
        $this->deleteJson(route('api.v1.task-templates.items.destroy', [$template, $a]))->assertUnprocessable();
        $foreign = $this->template();
        $this->getJson(route('api.v1.task-templates.items.show', [$foreign, $a]))->assertNotFound();
        $this->postJson(route('api.v1.task-templates.items.store', $foreign), ['title' => 'Foreign edge', 'depends_on_ids' => [$a]])->assertUnprocessable();
        $this->deleteJson(route('api.v1.task-templates.items.destroy', [$template, $b]))->assertOk();
        $this->deleteJson(route('api.v1.task-templates.items.destroy', [$template, $a]))->assertOk();
        $this->deleteJson(route('api.v1.task-templates.destroy', $template))->assertOk();
        $this->getJson(route('api.v1.task-templates.show', $template))->assertNotFound();
    }

    public function test_read_scope_does_not_manage_catalog_and_ordinary_creators_only_read_active_templates(): void
    {
        $active = $this->template();
        $inactive = $this->template(false);
        $active->forceFill(['settings' => ['private_secret' => 'never-export']])->save();
        Sanctum::actingAs($this->tech, ['tasks.templates.read']);
        $this->postJson(route('api.v1.task-templates.store'), ['name' => 'Denied'])->assertForbidden();
        $this->getJson(route('api.v1.task-templates.show', $active))->assertOk()->assertJsonMissingPath('data.settings');
        $this->tech->revokePermissionTo('task.manage_templates');
        $this->getJson(route('api.v1.task-templates.index'))->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson(route('api.v1.task-templates.show', $inactive))->assertNotFound();
        Sanctum::actingAs($this->tech, ['tasks.templates.manage']);
        $this->postJson(route('api.v1.task-templates.store'), ['name' => 'Still denied'])->assertForbidden();
    }

    public function test_preview_is_read_only_and_apply_is_actor_bound_idempotent_and_preserves_anchor(): void
    {
        $template = $this->template();
        $template->allItems()->create(['title' => 'Work {date}', 'due_offset_minutes' => 60]);
        $params = ['owner_type' => 'user', 'owner_id' => $this->tech->id, 'anchor_at' => '2030-01-15T10:00:00Z'];
        $preview = $this->getJson(route('api.v1.task-templates.preview', array_merge(['template' => $template], $params)))->assertOk()->assertJsonPath('data.will_create_task_count', 1);
        $this->assertSame(0, Task::count());
        $this->assertSame(0, TaskTemplateRun::count());
        $payload = $params + ['assigned_to' => $this->tech->id, 'idempotency_key' => 'apply-once', 'expected_template_snapshot' => $preview->json('data.expected_template_snapshot')];
        $url = route('api.v1.task-templates.apply', $template);
        $runId = $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.task_count', 1)->json('data.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $runId);
        $this->assertSame(1, Task::count());
        $task = Task::firstOrFail();
        $this->assertSame('Work 2030-01-15', $task->title);
        $this->assertSame('2030-01-15 11:00:00', $task->due_at->utc()->format('Y-m-d H:i:s'));
        $payload['anchor_at'] = '2030-01-16T10:00:00Z';
        $this->postJson($url, $payload)->assertStatus(409);
        $this->getJson(route('api.v1.task-templates.runs.show', [$template, $runId]))->assertOk()->assertJsonPath('data.requested_by', $this->tech->id);
        $this->getJson(route('api.v1.task-templates.runs.tasks', [$template, $runId]))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $task->id);
        $this->deleteJson(route('api.v1.task-templates.destroy', $template))->assertUnprocessable();
    }

    public function test_changed_graph_rejects_preview_and_keeps_failed_generation_evidence_without_partial_tasks(): void
    {
        $template = $this->template();
        $item = $template->allItems()->create(['title' => 'Before']);
        $params = ['owner_type' => 'user', 'owner_id' => $this->tech->id];
        $snapshot = $this->getJson(route('api.v1.task-templates.preview', array_merge(['template' => $template], $params)))->assertOk()->json('data.expected_template_snapshot');
        $this->putJson(route('api.v1.task-templates.items.update', [$template, $item]), ['title' => 'Changed'])->assertOk();
        $payload = $params + ['idempotency_key' => 'stale-preview', 'expected_template_snapshot' => $snapshot];
        $this->postJson(route('api.v1.task-templates.apply', $template), $payload)->assertUnprocessable()->assertJsonValidationErrors('expected_template_snapshot');
        $this->assertSame(0, Task::count());
        $this->assertSame('failed', TaskTemplateRun::firstOrFail()->status);
        $this->postJson(route('api.v1.task-templates.apply', $template), $payload)->assertStatus(409);
        $this->getJson(route('api.v1.task-templates.runs.index', $template))->assertOk()->assertJsonPath('data.0.status', 'failed');
    }

    public function test_target_lookup_and_application_enforce_owner_visibility_and_conditional_read_grants(): void
    {
        $template = $this->template();
        $template->allItems()->create(['title' => 'Client work']);
        $client = Client::factory()->create(['active' => true]);
        Sanctum::actingAs($this->tech, ['tasks.templates.read', 'tasks.templates.apply']);
        $this->getJson(route('api.v1.task-templates.targets', ['owner_type' => 'client']))->assertForbidden();
        Sanctum::actingAs($this->tech, ['tasks.templates.read', 'tasks.templates.apply', 'clients.read']);
        $this->getJson(route('api.v1.task-templates.targets', ['owner_type' => 'client']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.password');
        $params = ['owner_type' => 'client', 'owner_id' => $client->id];
        $snapshot = $this->getJson(route('api.v1.task-templates.preview', array_merge(['template' => $template], $params)))->assertOk()->json('data.expected_template_snapshot');
        $this->postJson(route('api.v1.task-templates.apply', $template), $params + ['idempotency_key' => 'client-apply', 'expected_template_snapshot' => $snapshot])->assertOk();
        $this->assertSame($client->id, Task::firstOrFail()->workContext->client_id);
        $this->tech->revokePermissionTo('client.view');
        $this->getJson(route('api.v1.task-templates.preview', array_merge(['template' => $template], $params)))->assertForbidden();
    }

    public function test_run_history_is_scoped_and_global_legacy_key_cannot_leak_another_actor_run(): void
    {
        $template = $this->template();
        $template->allItems()->create(['title' => 'Own work']);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $key = 'collision';
        $serverKey = 'api-task-template:'.$this->tech->id.':'.hash('sha256', $key);
        $run = TaskTemplateRun::create(['template_group_id' => $template->id, 'actor_id' => $other->id, 'trigger_type' => 'manual', 'owner_type' => $other->getMorphClass(), 'owner_id' => $other->id, 'template_name' => $template->name, 'idempotency_key' => $serverKey, 'status' => 'completed', 'task_count' => 0]);
        $params = ['owner_type' => 'user', 'owner_id' => $this->tech->id];
        $snapshot = $this->getJson(route('api.v1.task-templates.preview', array_merge(['template' => $template], $params)))->assertOk()->json('data.expected_template_snapshot');
        $this->postJson(route('api.v1.task-templates.apply', $template), $params + ['idempotency_key' => $key, 'expected_template_snapshot' => $snapshot])->assertStatus(409);
        $this->assertSame(0, Task::count());
        $this->tech->revokePermissionTo('task.manage_templates');
        $this->getJson(route('api.v1.task-templates.runs.show', [$template, $run]))->assertNotFound();
        $this->getJson(route('api.v1.task-templates.runs.index', $template))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_catalog_form_references_are_bounded_and_do_not_serialize_arbitrary_model_fields(): void
    {
        foreach (['statuses', 'queues', 'priorities', 'categories', 'tags', 'users', 'clients'] as $catalog) {
            $this->getJson(route('api.v1.task-templates.context', ['catalog' => $catalog, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('data.0.password');
        }
        $this->getJson(route('api.v1.task-templates.context', ['catalog' => 'arbitrary-model']))->assertNotFound();
        $this->postJson(route('api.v1.task-templates.store'), ['name' => 'Slug test', 'slug' => 'Normalized Slug', 'is_active' => true])->assertCreated();
        $this->postJson(route('api.v1.task-templates.store'), ['name' => 'Collision', 'slug' => 'normalized slug', 'is_active' => true])->assertUnprocessable()->assertJsonValidationErrors('slug');
    }

    public function test_configuration_history_is_content_free_and_failed_audit_rolls_back_the_graph(): void
    {
        $id = $this->postJson(route('api.v1.task-templates.store'), ['name' => 'Sensitive template title'])->assertCreated()->json('data.id');
        $template = TaskTemplateGroup::findOrFail($id);
        $this->postJson(route('api.v1.task-templates.items.store', $template), ['title' => 'Private checklist content', 'checklist_text' => 'Private checklist line'])->assertCreated();
        $history = $this->getJson(route('api.v1.task-templates.history', $template))->assertOk()->assertJsonPath('meta.total', 2);
        $this->assertStringNotContainsString('Sensitive template title', $history->getContent());
        $this->assertStringNotContainsString('Private checklist', $history->getContent());
        app(\Spatie\Activitylog\ActivityLogStatus::class)->disable();
        $this->postJson(route('api.v1.task-templates.items.store', $template), ['title' => 'Must roll back'])->assertUnprocessable()->assertJsonValidationErrors('audit');
        $this->assertSame(1, $template->allItems()->count());
        app(\Spatie\Activitylog\ActivityLogStatus::class)->enable();
        $this->tech->revokePermissionTo('task.manage_templates');
        $this->getJson(route('api.v1.task-templates.history', $template))->assertForbidden();
    }

    private function template(bool $active = true): TaskTemplateGroup
    {
        return TaskTemplateGroup::create(['name' => 'Template '.uniqid(), 'is_active' => $active]);
    }
}
