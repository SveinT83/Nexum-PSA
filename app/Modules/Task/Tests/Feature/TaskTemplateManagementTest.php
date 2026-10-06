<?php

namespace App\Modules\Task\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Task\Actions\RunDueTaskTemplateSchedules;
use App\Modules\Task\Actions\RunTaskTemplateSchedule;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskRecurringTemplate;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Models\TaskTemplateDependency;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskTemplateManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Admin');
        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo(['task.manage_templates', 'task.create', 'client.view', 'ticket.view']);
    }

    #[Test]
    public function authorized_user_manages_a_simple_mutable_template_and_applies_it(): void
    {
        $status = TaskStatus::query()->create(['name' => 'Ready', 'slug' => 'ready', 'is_active' => true]);
        $queue = TicketQueue::query()->create(['name' => 'Operations', 'slug' => 'operations', 'is_active' => true]);
        $priority = TicketPriority::query()->create(['name' => 'Normal', 'slug' => 'normal', 'is_active' => true]);
        $category = Category::query()->create(['name' => 'Maintenance', 'slug' => 'maintenance', 'is_active' => true]);
        $this->actingAs($this->admin)->post(route('tech.admin.task-templates.store'), [
            'name' => 'Client maintenance', 'is_active' => 1,
        ])->assertRedirect();
        $template = TaskTemplateGroup::query()->firstOrFail();

        $storeItemResponse = $this->actingAs($this->admin)
            ->from(route('tech.admin.task-templates.show', $template))
            ->post(route('tech.admin.task-templates.items.store', $template), [
                'title' => 'Review {client}',
                'estimated_minutes' => 20,
                'status_id' => $status->id,
                'queue_id' => $queue->id,
                'priority_id' => $priority->id,
                'category_id' => $category->id,
                'checklist_text' => "Check backup\nDocument result",
                'tag_names' => 'Routine, Monthly',
            ]);
        $item = $template->allItems()->firstOrFail();
        $storeItemResponse
            ->assertRedirect(route('tech.admin.task-templates.show', $template))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('expanded_template_item_id', $item->id);

        $expandedView = $this->actingAs($this->admin)->get(route('tech.admin.task-templates.show', $template));
        $expandedView->assertOk()
            ->assertSee('for="add_task_title"', false)
            ->assertSee('for="add_task_estimated_minutes"', false)
            ->assertSee('for="add_task_sort_order"', false)
            ->assertSee('for="add_task_status"', false)
            ->assertSee('for="add_task_queue"', false)
            ->assertSee('for="add_task_priority"', false)
            ->assertSee('for="add_task_category"', false)
            ->assertSee('for="add_task_checklist"', false)
            ->assertSee('data-owner-picker', false)
            ->assertSee('task-template-owner-users', false)
            ->assertSee('task-template-owner-clients', false)
            ->assertSee("const listIds = {user: 'task-template-owner-users', client: 'task-template-owner-clients'};", false)
            ->assertDontSee('<option value="ticket">Ticket</option>', false)
            ->assertSee('id="template-task-collapse-'.$item->id.'" class="accordion-collapse collapse show"', false);

        $this->actingAs($this->admin)
            ->get(route('tech.admin.task-templates.show', $template))
            ->assertOk()
            ->assertSee('id="template-task-collapse-'.$item->id.'" class="accordion-collapse collapse"', false)
            ->assertDontSee('id="template-task-collapse-'.$item->id.'" class="accordion-collapse collapse show"', false);

        $client = Client::factory()->create(['name' => 'Contoso']);
        $this->actingAs($this->admin)->get(route('tech.task-templates.preview', [
            'template' => $template, 'owner_type' => 'client', 'owner_id' => $client->id,
        ]))->assertOk()->assertSee('Review {client}')->assertSee('Create Tasks');

        $this->actingAs($this->admin)->post(route('tech.task-templates.apply', $template), [
            'owner_type' => 'client', 'owner_id' => $client->id, 'idempotency_key' => 'web:test:1',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('Review Contoso', Task::query()->firstOrFail()->title);
        $this->assertSame(2, Task::query()->firstOrFail()->checklistItems()->count());
        $this->assertSame($status->id, Task::query()->firstOrFail()->status_id);
        $this->assertSame($queue->id, Task::query()->firstOrFail()->queue_id);
        $this->assertSame($priority->id, Task::query()->firstOrFail()->priority_id);
        $this->assertSame($category->id, Task::query()->firstOrFail()->category_id);
    }

    #[Test]
    public function due_schedule_generates_once_and_advances_to_the_future(): void
    {
        CarbonImmutable::setTestNow('2026-09-03 10:00:00');
        $template = TaskTemplateGroup::query()->create(['name' => 'Daily', 'slug' => 'daily']);
        TaskTemplateItem::query()->create(['template_group_id' => $template->id, 'title' => 'Daily check']);
        $schedule = TaskRecurringTemplate::query()->create([
            'template_group_id' => $template->id,
            'name' => 'Every day',
            'owner_type' => $this->admin->getMorphClass(),
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'interval' => 'daily',
            'timezone' => 'Europe/Oslo',
            'next_run_at' => now()->subDay(),
            'is_active' => true,
        ]);

        $first = app(RunDueTaskTemplateSchedules::class)->handle();
        $second = app(RunDueTaskTemplateSchedules::class)->handle();

        $this->assertSame(['completed' => 1, 'failed' => 0], $first);
        $this->assertSame(['completed' => 0, 'failed' => 0], $second);
        $this->assertSame(1, Task::query()->count());
        $this->assertTrue($schedule->fresh()->next_run_at->isFuture());
    }

    #[Test]
    public function ordinary_task_creator_cannot_manage_templates(): void
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->givePermissionTo('task.create');
        $this->actingAs($user)->get(route('tech.admin.task-templates.index'))->assertForbidden();
    }

    #[Test]
    public function task_creator_can_choose_for_visible_owners_without_template_management(): void
    {
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $creator->givePermissionTo(['task.create', 'client.view', 'ticket.view']);
        $client = Client::factory()->create();
        $ticket = Ticket::factory()->create(['client_id' => $client->id]);
        $active = TaskTemplateGroup::query()->create(['name' => 'Active template', 'slug' => 'active-template']);
        TaskTemplateItem::query()->create(['template_group_id' => $active->id, 'title' => 'Created for {ticket.key}']);
        $inactive = TaskTemplateGroup::query()->create(['name' => 'Inactive template', 'slug' => 'inactive-template', 'is_active' => false]);

        $this->actingAs($creator)->get(route('tech.task-templates.choose', [
            'owner_type' => 'ticket', 'owner_id' => $ticket->id,
        ]))->assertOk()->assertSee('Active template')->assertDontSee('Inactive template');

        $this->actingAs($creator)->post(route('tech.task-templates.apply', $active), [
            'owner_type' => 'ticket',
            'owner_id' => $ticket->id,
            'idempotency_key' => 'web:ticket:return',
        ])->assertRedirect(route('tech.tickets.show', $ticket))->assertSessionHasNoErrors();

        $this->actingAs($creator)->get(route('tech.task-templates.preview', [
            'template' => $inactive, 'owner_type' => 'client', 'owner_id' => $client->id,
        ]))->assertNotFound();
    }

    #[Test]
    public function owner_visibility_is_required_for_manual_application(): void
    {
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $creator->givePermissionTo('task.create');
        $client = Client::factory()->create();
        $template = TaskTemplateGroup::query()->create(['name' => 'Protected', 'slug' => 'protected']);

        $this->actingAs($creator)->get(route('tech.task-templates.choose', [
            'owner_type' => 'client', 'owner_id' => $client->id,
        ]))->assertForbidden();
    }

    #[Test]
    public function schedule_can_be_edited_deactivated_and_removed(): void
    {
        $template = TaskTemplateGroup::query()->create(['name' => 'Managed schedule', 'slug' => 'managed-schedule']);
        $schedule = TaskRecurringTemplate::query()->create([
            'template_group_id' => $template->id,
            'name' => 'Monthly',
            'owner_type' => $this->admin->getMorphClass(),
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'interval' => 'monthly',
            'timezone' => 'Europe/Oslo',
            'next_run_at' => now()->addMonth(),
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)->put(route('tech.admin.task-templates.schedules.update', [$template, $schedule]), [
            'schedule_name' => 'Quarterly',
            'owner_type' => 'user',
            'owner_id' => $this->admin->id,
            'interval' => 'quarterly',
            'timezone' => 'Europe/Oslo',
            'next_run_at' => now()->addMonths(3)->format('Y-m-d H:i:s'),
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('quarterly', $schedule->fresh()->interval);

        $this->actingAs($this->admin)->patch(route('tech.admin.task-templates.schedules.toggle', [$template, $schedule]))->assertRedirect();
        $this->assertFalse($schedule->fresh()->is_active);

        $this->actingAs($this->admin)->delete(route('tech.admin.task-templates.schedules.destroy', [$template, $schedule]))->assertRedirect();
        $this->assertDatabaseMissing('task_recurring_templates', ['id' => $schedule->id]);
    }

    #[Test]
    public function deleting_a_referenced_template_task_requires_references_to_be_removed_first(): void
    {
        $template = TaskTemplateGroup::query()->create(['name' => 'Safe deletion', 'slug' => 'safe-deletion']);
        $parent = TaskTemplateItem::query()->create(['template_group_id' => $template->id, 'title' => 'Parent']);
        $child = TaskTemplateItem::query()->create([
            'template_group_id' => $template->id,
            'parent_id' => $parent->id,
            'title' => 'Child',
        ]);
        TaskTemplateDependency::query()->create([
            'template_item_id' => $child->id,
            'depends_on_template_item_id' => $parent->id,
        ]);

        $this->actingAs($this->admin)
            ->delete(route('tech.admin.task-templates.items.destroy', [$template, $parent]))
            ->assertRedirect()
            ->assertSessionHasErrors('item');

        $this->assertDatabaseHas('task_template_items', ['id' => $parent->id]);
        $this->assertDatabaseHas('task_template_items', ['id' => $child->id, 'parent_id' => $parent->id]);
    }

    #[Test]
    public function schedule_input_is_interpreted_in_its_selected_timezone(): void
    {
        $template = TaskTemplateGroup::query()->create(['name' => 'Timezone', 'slug' => 'timezone']);

        $this->actingAs($this->admin)->post(route('tech.admin.task-templates.schedules.store', $template), [
            'schedule_name' => 'Local morning',
            'owner_type' => 'user',
            'owner_id' => $this->admin->id,
            'interval' => 'daily',
            'timezone' => 'Europe/Oslo',
            'next_run_at' => '2026-09-10T09:00',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(
            '2026-09-10 07:00:00',
            TaskRecurringTemplate::query()->firstOrFail()->next_run_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    #[Test]
    public function task_template_schedule_rejects_a_ticket_as_a_future_owner(): void
    {
        $template = TaskTemplateGroup::query()->create(['name' => 'Future owner', 'slug' => 'future-owner']);
        $ticket = Ticket::factory()->create();

        $this->actingAs($this->admin)->post(route('tech.admin.task-templates.schedules.store', $template), [
            'schedule_name' => 'Invalid future Ticket',
            'owner_type' => 'ticket',
            'owner_id' => $ticket->id,
            'interval' => 'daily',
            'timezone' => 'Europe/Oslo',
            'next_run_at' => '2026-09-10T09:00',
        ])->assertRedirect()->assertSessionHasErrors('owner_type');

        $this->assertDatabaseCount('task_recurring_templates', 0);
    }

    #[Test]
    public function daily_schedule_keeps_local_wall_clock_time_across_dst(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-03-28 08:30', 'Europe/Oslo'));
        $template = TaskTemplateGroup::query()->create(['name' => 'DST', 'slug' => 'dst']);
        $schedule = TaskRecurringTemplate::query()->create([
            'template_group_id' => $template->id,
            'name' => 'Local morning',
            'owner_type' => $this->admin->getMorphClass(),
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'interval' => 'daily',
            'timezone' => 'Europe/Oslo',
            'next_run_at' => CarbonImmutable::parse('2026-03-28 09:00', 'Europe/Oslo')->utc(),
            'is_active' => true,
        ]);

        $next = app(RunTaskTemplateSchedule::class)->nextRun(
            $schedule,
            CarbonImmutable::parse('2026-03-28 09:00', 'Europe/Oslo'),
        );

        $this->assertSame('2026-03-29 09:00:00', $next->format('Y-m-d H:i:s'));
        $this->assertSame('+02:00', $next->format('P'));
    }
}
