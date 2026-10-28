<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Controllers\Api\V1\TaskReadbackController;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskReadbackApiTest extends TestCase
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
        foreach (['task.view', 'task.create', 'task.update', 'ticket.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
        app(EnsureTaskDefaults::class)->handle();
    }

    public function test_scope_is_not_a_substitute_for_active_actor_rights(): void
    {
        $task = $this->task();
        $this->getJson(route('api.v1.tasks.context'))->assertUnauthorized();
        Sanctum::actingAs($this->tech, ['tasks.read']);
        $this->getJson(route('api.v1.tasks.context'))->assertOk();
        $this->getJson(route('api.v1.tasks.time-entries.index', $task))->assertForbidden();
        $withoutRights = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($withoutRights, ['*']);
        $this->getJson(route('api.v1.tasks.context'))->assertForbidden();
    }

    public function test_actual_time_has_complete_pagination_and_no_ticket_billing_projection(): void
    {
        $task = $this->task();
        $entry = $task->timeEntries()->create(['user_id' => $this->tech->id, 'source_type' => 'manual', 'work_date' => '2026-10-19', 'minutes' => 5, 'billable' => false, 'note' => 'Actual session']);
        Sanctum::actingAs($this->tech, ['tasks.time.read']);
        $this->getJson(route('api.v1.tasks.time-entries.index', $task))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $entry->id)->assertJsonPath('data.0.minutes', 5)->assertJsonMissingPath('data.0.rate_amount_ex_vat');
        $this->getJson(route('api.v1.tasks.time-entries.index', ['task' => $task, 'per_page' => 101]))->assertUnprocessable();
        $this->assertTrue(app(ApiAbilityCatalog::class)->isReadOnly('tasks.time.read'));
    }

    public function test_all_task_catalogs_are_bounded_and_ticket_lookup_requires_both_grants(): void
    {
        Sanctum::actingAs($this->tech, ['tasks.read', 'tickets.read']);
        foreach (TaskReadbackController::CATALOGS as $catalog) {
            $this->getJson(route('api.v1.tasks.context.options', ['catalog' => $catalog, 'per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1);
        }
        Sanctum::actingAs($this->tech, ['tasks.read']);
        $this->getJson(route('api.v1.tasks.context.options', ['catalog' => 'tickets']))->assertForbidden();
        $this->getJson(route('api.v1.tasks.context.options', ['catalog' => 'unrestricted']))->assertNotFound();
    }

    public function test_private_records_and_dependency_targets_remain_hidden(): void
    {
        $parent = $this->task();
        $visible = $this->task('Visible child', ['parent_id' => $parent->id]);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $private = app(StoreTask::class)->handle(['title' => 'Private target', 'visibility' => 'private', 'parent_id' => $parent->id], $other);
        $dependency = $parent->dependencies()->create(['depends_on_task_id' => $private->id, 'dependency_type' => 'blocks_completion', 'is_required' => true]);
        Sanctum::actingAs($this->tech, ['tasks.read', 'tasks.time.read']);
        $this->getJson(route('api.v1.tasks.context.show', $private))->assertNotFound();
        $this->getJson(route('api.v1.tasks.time-entries.index', $private))->assertNotFound();
        $this->getJson(route('api.v1.tasks.children.index', $parent))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->getJson(route('api.v1.tasks.dependencies.index', $parent))->assertOk()->assertJsonPath('data.0.id', $dependency->id)->assertJsonPath('data.0.target', null)->assertJsonPath('data.0.target_restricted', true)->assertJsonMissingPath('data.0.depends_on_task_id');
    }

    public function test_get_activity_and_context_do_not_complete_or_mutate_task(): void
    {
        $task = $this->task();
        $count = TaskActivity::count();
        Sanctum::actingAs($this->tech, ['tasks.read']);
        $this->getJson(route('api.v1.tasks.context.show', $task))->assertOk()->assertJsonPath('data.completed', false);
        $this->getJson(route('api.v1.tasks.activities.index', $task))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonMissingPath('data.0.changes');
        $capabilities = $this->getJson(route('api.v1.tasks.capabilities'))->assertOk();
        $this->assertGreaterThanOrEqual(8, count($capabilities->json('data.operations')));
        $this->assertNull($task->refresh()->completed_at);
        $this->assertSame($count, TaskActivity::count());
    }

    public function test_documentation_api_returns_the_exact_browser_markdown_without_task_mutation(): void
    {
        $before = [Task::count(), TaskActivity::count()];
        $browser = $this->actingAs($this->tech)->get(route('tech.tasks.docs'))->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $browser->baseResponse);
        Sanctum::actingAs($this->tech, ['tasks.read']);
        $api = $this->get('/api/v1/tasks/docs', ['Accept' => 'text/markdown'])->assertOk();
        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\BinaryFileResponse::class, $api->baseResponse);
        $this->assertStringStartsWith('text/markdown', $api->headers->get('Content-Type'));
        $this->assertNotEmpty($api->baseResponse->getFile()->getContent());
        $this->assertSame($browser->baseResponse->getFile()->getContent(), $api->baseResponse->getFile()->getContent());
        $this->get('/api/v1/tasks/docs', ['Accept' => 'text/markdown', 'Range' => 'bytes=0-15'])
            ->assertStatus(206)
            ->assertHeader('Content-Length', '16')
            ->assertHeader('Content-Range', 'bytes 0-15/'.strlen($api->baseResponse->getFile()->getContent()));
        $this->assertSame($before, [Task::count(), TaskActivity::count()]);
    }

    public function test_documentation_requires_both_token_scope_and_current_actor_permission(): void
    {
        $this->get('/api/v1/tasks/docs', ['Accept' => 'text/markdown'])->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        Sanctum::actingAs($this->tech, ['tasks.time.read']);
        $this->getJson('/api/v1/tasks/docs')->assertForbidden();
        $withoutRights = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($withoutRights, ['*']);
        $this->getJson('/api/v1/tasks/docs')->assertForbidden();
    }

    private function task(string $title = 'Read-back Task', array $extra = []): Task
    {
        return app(StoreTask::class)->handle(array_merge(['title' => $title], $extra), $this->tech);
    }
}
