<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Actions\SuggestTaskFieldsWithAi;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskDependency;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Task\Support\TaskSettings;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskOperationApiTest extends TestCase
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
        foreach (['task.view', 'task.update', 'task.assign', 'task.complete', 'ticket.view', 'ticket.register_time'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTaskDefaults::class)->handle();
        app(EnsureTicketDefaults::class)->handle();
    }

    public function test_assign_status_and_checklist_setters_record_activity_once_on_retries(): void
    {
        $task = $this->task();
        $assignee = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $status = TaskStatus::query()->where('is_active', true)->where('id', '!=', $task->status_id)->firstOrFail();
        $item = $task->checklistItems()->create(['title' => 'Verify result', 'sort_order' => 10]);
        Sanctum::actingAs($this->tech, ['tasks.assign', 'tasks.status.update', 'tasks.checklist.update']);
        foreach (range(1, 2) as $attempt) {
            $this->patchJson(route('api.v1.tasks.assignee.update', $task), ['assigned_to' => $assignee->id])->assertOk()->assertJsonPath('data.assigned_to', $assignee->id);
            $this->patchJson(route('api.v1.tasks.status.update', $task), ['status_id' => $status->id])->assertOk()->assertJsonPath('data.status_id', $status->id);
            $this->putJson(route('api.v1.tasks.checklist.update', [$task, $item]), ['is_checked' => true])->assertOk()->assertJsonPath('data.is_checked', true);
        }
        $this->assertSame(1, $task->activities()->where('type', 'assigned')->count());
        $this->assertSame(1, $task->activities()->where('type', 'status_changed')->count());
        $this->assertSame(1, $task->activities()->where('type', 'checklist_checked')->count());
        $this->putJson(route('api.v1.tasks.checklist.update', [$this->task(), $item]), ['is_checked' => false])->assertNotFound();
        $this->assertSame($this->tech->id, $item->refresh()->checked_by);
    }

    public function test_each_action_requires_scope_domain_rights_and_private_task_visibility(): void
    {
        $task = $this->task();
        Sanctum::actingAs($this->tech, ['tasks.read']);
        $this->patchJson(route('api.v1.tasks.assignee.update', $task), ['assigned_to' => null])->assertForbidden();
        Sanctum::actingAs($this->tech, ['tasks.assign']);
        $this->tech->revokePermissionTo('task.assign');
        $this->patchJson(route('api.v1.tasks.assignee.update', $task), ['assigned_to' => null])->assertForbidden();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $private = app(StoreTask::class)->handle(['title' => 'Private', 'visibility' => 'private'], $other);
        Sanctum::actingAs($this->tech, ['tasks.complete']);
        $this->postJson(route('api.v1.tasks.complete', $private))->assertNotFound();
    }

    public function test_completion_checks_dependencies_children_checklist_and_is_idempotent(): void
    {
        $task = $this->task(['estimated_minutes' => 20]);
        $dependency = $this->task();
        TaskDependency::create(['task_id' => $task->id, 'depends_on_task_id' => $dependency->id, 'dependency_type' => TaskDependency::TYPE_BLOCKS_COMPLETION, 'is_required' => true]);
        Sanctum::actingAs($this->tech, ['tasks.complete', 'tasks.checklist.update']);
        $url = route('api.v1.tasks.complete', $task);
        $this->postJson($url)->assertUnprocessable();
        $this->postJson(route('api.v1.tasks.complete', $dependency))->assertOk();
        $child = $this->task(['parent_id' => $task->id]);
        $this->postJson($url)->assertUnprocessable();
        $this->postJson(route('api.v1.tasks.complete', $child))->assertOk();
        $item = $task->checklistItems()->create(['title' => 'Final check']);
        $this->postJson($url)->assertUnprocessable();
        $this->putJson(route('api.v1.tasks.checklist.update', [$task, $item]), ['is_checked' => true])->assertOk();
        $this->postJson($url)->assertOk()->assertJsonPath('data.completed_by', $this->tech->id);
        $this->postJson($url)->assertOk();
        $this->assertSame(1, $task->activities()->where('type', 'completed')->count());
        $this->assertSame(20, (int) $task->timeEntries()->sum('minutes'));
    }

    public function test_actual_task_time_is_idempotent_and_ticket_minimum_is_cumulative_only(): void
    {
        [$task, $ticket, $rate] = $this->ticketTask();
        app(TaskSettings::class)->update(['ticket_billing_minutes_mode' => TaskSettings::TICKET_BILLING_ESTIMATE_MINIMUM]);
        Sanctum::actingAs($this->tech, ['tasks.time.create', 'tasks.time.read']);
        $payload = ['idempotency_key' => 'actual-one', 'work_date' => '2026-10-20', 'minutes' => 15, 'rate_key' => 'global:'.$rate->id, 'invoice_text' => 'Verified work'];
        $url = route('api.v1.tasks.time-entries.store', $task);
        $first = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.minutes', 15)->json('data.id');
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('created', false)->assertJsonPath('data.id', $first);
        $this->assertSame(15, (int) $task->timeEntries()->sum('minutes'));
        $this->assertSame(60, (int) $ticket->timeEntries()->sum('minutes'));
        $payload['idempotency_key'] = 'actual-two';
        $payload['minutes'] = 10;
        $this->postJson($url, $payload)->assertCreated();
        $this->assertSame(25, (int) $task->timeEntries()->sum('minutes'));
        $this->assertSame(60, (int) $ticket->timeEntries()->sum('minutes'));
        $this->assertSame(1, TicketTimeEntry::where('task_id', $task->id)->count());
        $this->getJson(route('api.v1.tasks.time-entries.index', $task))->assertOk()->assertJsonPath('meta.total', 2);
        $payload['minutes'] = 11;
        $this->postJson($url, $payload)->assertStatus(409);
    }

    public function test_ticket_task_completion_requires_time_grant_rate_and_ticket_permission_when_registering_time(): void
    {
        [$task, $ticket, $rate] = $this->ticketTask();
        $url = route('api.v1.tasks.complete', $task);
        $payload = ['work_date' => '2026-10-20', 'minutes' => 12, 'rate_key' => 'global:'.$rate->id, 'invoice_text' => 'Completion work'];
        Sanctum::actingAs($this->tech, ['tasks.complete']);
        $this->postJson($url, $payload)->assertForbidden();
        Sanctum::actingAs($this->tech, ['tasks.complete', 'tasks.time.create']);
        $this->postJson($url)->assertUnprocessable();
        $this->tech->revokePermissionTo('ticket.register_time');
        $this->postJson($url, $payload)->assertForbidden();
        $this->tech->givePermissionTo('ticket.register_time');
        $this->postJson($url, $payload)->assertOk();
        $this->postJson($url, $payload)->assertOk();
        $this->assertSame(12, (int) $task->timeEntries()->sum('minutes'));
        $this->assertSame(12, (int) $ticket->timeEntries()->sum('minutes'));
        $this->postJson(route('api.v1.tasks.time-entries.store', $task), $payload + ['idempotency_key' => 'too-late'])->assertUnprocessable();
    }

    public function test_internal_actual_time_never_creates_ticket_billing(): void
    {
        $task = $this->task();
        Sanctum::actingAs($this->tech, ['tasks.time.create']);
        $this->postJson(route('api.v1.tasks.time-entries.store', $task), ['idempotency_key' => 'internal-time', 'work_date' => '2026-10-20', 'minutes' => 9])->assertCreated()->assertJsonPath('data.billable', false);
        $this->assertSame(1, TaskTimeEntry::count());
        $this->assertSame(0, TicketTimeEntry::count());
    }

    public function test_ai_suggestions_are_drafts_and_private_parent_context_is_guarded(): void
    {
        $parent = $this->task();
        $this->mock(SuggestTaskFieldsWithAi::class)->shouldReceive('handle')->once()->andReturn(['title' => 'Suggested title', 'checklist_items' => ['Verify result']]);
        Sanctum::actingAs($this->tech, ['tasks.ai.suggest', 'tickets.read']);
        $payload = ['title' => 'Task draft', 'description' => 'Draft context', 'parent_id' => $parent->id];
        $this->postJson(route('api.v1.tasks.ai-suggestions.store'), $payload)->assertOk()->assertJsonPath('data.title', 'Suggested title');
        $this->assertSame(1, Task::count());
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $private = app(StoreTask::class)->handle(['title' => 'Private', 'visibility' => 'private'], $other);
        $payload['parent_id'] = $private->id;
        $this->postJson(route('api.v1.tasks.ai-suggestions.store'), $payload)->assertNotFound();
    }

    private function task(array $data = []): Task
    {
        return app(StoreTask::class)->handle(array_merge(['title' => 'API Task'], $data), $this->tech);
    }

    private function ticketTask(): array
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Task billing Ticket', 'client_id' => null, 'owner_id' => $this->tech->id], $this->tech);
        $task = app(StoreTask::class)->handle(['title' => 'Billable Task', 'estimated_minutes' => 60], $this->tech, $ticket);
        $rate = TimeRate::create(['name' => 'API work', 'slug' => 'api-work', 'code' => 'API', 'rate_type' => 'hourly', 'unit' => 'hour', 'amount_ex_vat' => 999, 'currency' => 'NOK', 'applies_without_contract' => true, 'is_active' => true]);

        return [$task, $ticket, $rate];
    }
}
