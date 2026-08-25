<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Ticket\Models\TicketPriority;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskIndexDefaultTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;
    private User $otherTech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo(['task.view']);

        $this->otherTech = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        app(EnsureTaskDefaults::class)->handle();
    }

    #[Test]
    public function it_defaults_to_assigned_to_me_for_open_tasks_ordered_by_priority(): void
    {
        $openStatus = TaskStatus::where('slug', 'open')->first();
        $inProgressStatus = TaskStatus::where('slug', 'in-progress')->first();
        $blockedStatus = TaskStatus::where('slug', 'blocked')->first();
        $doneStatus = TaskStatus::where('slug', 'done')->first();
        $cancelledStatus = TaskStatus::where('slug', 'cancelled')->first();

        $p1 = TicketPriority::create(['name' => 'P1', 'slug' => 'p1', 'level' => 1, 'is_active' => true]);
        $p2 = TicketPriority::create(['name' => 'P2', 'slug' => 'p2', 'level' => 2, 'is_active' => true]);
        $p4 = TicketPriority::create(['name' => 'P4', 'slug' => 'p4', 'level' => 4, 'is_active' => true]);

        // 1. Task for other user (should be excluded by default)
        Task::query()->create([
            'title' => 'Other Tech Task',
            'assigned_to' => $this->otherTech->id,
            'status_id' => $openStatus->id,
            'created_by' => $this->tech->id,
            'owner_type' => $this->tech->getMorphClass(),
            'owner_id' => $this->tech->id,
        ]);

        // 2. Done task (should be excluded)
        Task::query()->create([
            'title' => 'Done Task',
            'assigned_to' => $this->tech->id,
            'status_id' => $doneStatus->id,
            'created_by' => $this->tech->id,
            'owner_type' => $this->tech->getMorphClass(),
            'owner_id' => $this->tech->id,
        ]);

        // 3. Cancelled task (should be excluded)
        Task::query()->create([
            'title' => 'Cancelled Task',
            'assigned_to' => $this->tech->id,
            'status_id' => $cancelledStatus->id,
            'created_by' => $this->tech->id,
            'owner_type' => $this->tech->getMorphClass(),
            'owner_id' => $this->tech->id,
        ]);

        // 4. Completed task with status open (should be excluded)
        Task::query()->create([
            'title' => 'Completed At Task',
            'assigned_to' => $this->tech->id,
            'status_id' => $openStatus->id,
            'completed_at' => now(),
            'created_by' => $this->tech->id,
            'owner_type' => $this->tech->getMorphClass(),
            'owner_id' => $this->tech->id,
        ]);

        // Open tasks for tech (should be included and ordered)
        // Order expected: P1, P2, P4, No Priority
        $t4 = Task::query()->create(['title' => 'No Priority Task', 'assigned_to' => $this->tech->id, 'status_id' => $openStatus->id, 'priority_id' => null, 'due_at' => null, 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);
        $t2 = Task::query()->create(['title' => 'P2 Task', 'assigned_to' => $this->tech->id, 'status_id' => $inProgressStatus->id, 'priority_id' => $p2->id, 'due_at' => now()->addDays(2), 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);
        $t1 = Task::query()->create(['title' => 'P1 Task', 'assigned_to' => $this->tech->id, 'status_id' => $openStatus->id, 'priority_id' => $p1->id, 'due_at' => now()->addDay(), 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);
        $t3 = Task::query()->create(['title' => 'P4 Task', 'assigned_to' => $this->tech->id, 'status_id' => $blockedStatus->id, 'priority_id' => $p4->id, 'due_at' => now()->addDays(3), 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);

        // Within P2, test due date ordering
        $t2b = Task::query()->create(['title' => 'P2 Task Later', 'assigned_to' => $this->tech->id, 'status_id' => $openStatus->id, 'priority_id' => $p2->id, 'due_at' => now()->addDays(5), 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);
        $t2a = Task::query()->create(['title' => 'P2 Task Earlier', 'assigned_to' => $this->tech->id, 'status_id' => $openStatus->id, 'priority_id' => $p2->id, 'due_at' => now()->addDay(), 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);
        $t2c = Task::query()->create(['title' => 'P2 Task No Due', 'assigned_to' => $this->tech->id, 'status_id' => $openStatus->id, 'priority_id' => $p2->id, 'due_at' => null, 'created_by' => $this->tech->id, 'owner_type' => $this->tech->getMorphClass(), 'owner_id' => $this->tech->id]);

        $response = $this->actingAs($this->tech)->get(route('tech.tasks.index'));

        $response->assertOk();
        $tasks = $response->viewData('tasks');

        $this->assertCount(7, $tasks);

        // Assert Ordering: P1, P2 (Earlier, Middle, Later, No Due), P4, No Priority
        $this->assertEquals($t1->id, $tasks[0]->id);
        $this->assertEquals($t2a->id, $tasks[1]->id);
        $this->assertEquals($t2->id, $tasks[2]->id);
        $this->assertEquals($t2b->id, $tasks[3]->id);
        $this->assertEquals($t2c->id, $tasks[4]->id);
        $this->assertEquals($t3->id, $tasks[5]->id);
        $this->assertEquals($t4->id, $tasks[6]->id);

        // Assert filter UI reflects default
        $response->assertSee('name="mine" value="1" checked', false);
    }

    #[Test]
    public function explicit_filters_override_defaults(): void
    {
        $openStatus = TaskStatus::where('slug', 'open')->first();

        $tOther = Task::query()->create([
            'title' => 'Other Tech Task',
            'assigned_to' => $this->otherTech->id,
            'status_id' => $openStatus->id,
            'created_by' => $this->tech->id,
            'owner_type' => $this->tech->getMorphClass(),
            'owner_id' => $this->tech->id,
        ]);

        // Default should not show it
        $this->actingAs($this->tech)->get(route('tech.tasks.index'))
            ->assertDontSee('Other Tech Task');

        // Explicitly filtering for other tech should show it
        $this->actingAs($this->tech)->get(route('tech.tasks.index', ['assigned_to' => $this->otherTech->id]))
            ->assertSee('Other Tech Task');

        // Explicitly clearing "mine" should show all
        $this->actingAs($this->tech)->get(route('tech.tasks.index', ['mine' => '0']))
            ->assertSee('Other Tech Task');
    }
}
