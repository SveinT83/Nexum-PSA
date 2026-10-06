<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Task\Models\TaskTemplateRun;
use App\Modules\Ticket\Jobs\ProcessScheduledTickets;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringTicketAutomationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::create(['name' => 'Tech']);
        $this->admin = User::factory()->create(['status' => 'ACTIVE']);
        $this->admin->assignRole('Tech');
        $this->seed(\Database\Seeders\SlaSeeder::class);
        app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();
    }

    /** @test */
    public function it_activates_one_time_scheduled_tickets()
    {
        $plannedStart = Carbon::now()->subMinute();

        $ticket = Ticket::factory()->create([
            'status_id' => \App\Modules\Ticket\Models\TicketStatus::where('slug', 'new')->first()->id,
        ]);

        $schedule = TicketSchedule::create([
            'ticket_id' => $ticket->id,
            'schedule_type' => 'one_time',
            'planned_start_at' => $plannedStart,
            'status' => 'scheduled',
            'sla_mode' => 'defer_until_planned_start',
            'created_by' => $this->admin->id,
        ]);

        // Run the job
        app(ProcessScheduledTickets::class)->handle(app(\App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence::class));

        $this->assertEquals('active', $schedule->fresh()->status);
        $this->assertEquals('new', $ticket->fresh()->workflow_state_key);
    }

    /** @test */
    public function it_generates_occurrences_for_recurring_tickets()
    {
        $plannedStart = Carbon::now()->addDay()->startOfMinute();

        $parentTicket = Ticket::factory()->create([
            'subject' => 'Weekly Maintenance',
            'description' => 'Check the servers',
        ]);

        TicketSchedule::create([
            'ticket_id' => $parentTicket->id,
            'schedule_type' => 'recurring',
            'planned_start_at' => $plannedStart,
            'recurrence_rule' => 'FREQ=WEEKLY',
            'status' => 'active',
            'sla_mode' => 'defer_until_planned_start',
            'created_by' => $this->admin->id,
        ]);

        // Run the job - it should look ahead 7 days
        app(ProcessScheduledTickets::class)->handle(app(\App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence::class));

        // Should have created one occurrence for the next week
        $occurrences = Ticket::where('metadata->parent_ticket_id', $parentTicket->id)->get();

        $this->assertCount(1, $occurrences);
        $this->assertEquals('Weekly Maintenance', $occurrences->first()->subject);
        $this->assertEquals('scheduled', $occurrences->first()->channel);

        $expectedPlannedStart = $plannedStart->toISOString();
        $this->assertEquals($expectedPlannedStart, $occurrences->first()->metadata['occurrence_planned_start']);
    }

    /** @test */
    public function it_applies_the_selected_task_template_to_each_generated_ticket_occurrence()
    {
        $plannedStart = Carbon::now()->addDay()->startOfMinute();
        $taskTemplate = TaskTemplateGroup::query()->create([
            'name' => 'Recurring maintenance Tasks',
            'slug' => 'recurring-maintenance-tasks',
            'is_active' => true,
        ]);
        TaskTemplateItem::query()->create([
            'template_group_id' => $taskTemplate->id,
            'title' => 'Prepare {ticket.key}',
            'sort_order' => 10,
        ]);
        TaskTemplateItem::query()->create([
            'template_group_id' => $taskTemplate->id,
            'title' => 'Complete maintenance',
            'sort_order' => 20,
        ]);
        $parentTicket = Ticket::factory()->create([
            'subject' => 'Weekly maintenance template',
            'description' => 'Generate a Ticket and its Tasks.',
            'created_by' => $this->admin->id,
        ]);
        $schedule = TicketSchedule::query()->create([
            'ticket_id' => $parentTicket->id,
            'task_template_group_id' => $taskTemplate->id,
            'schedule_type' => 'recurring',
            'planned_start_at' => $plannedStart,
            'recurrence_rule' => 'FREQ=WEEKLY',
            'timezone' => 'Europe/Oslo',
            'status' => 'active',
            'sla_mode' => 'defer_until_planned_start',
            'created_by' => $this->admin->id,
        ]);

        app(ProcessScheduledTickets::class)->handle(app(\App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence::class));
        app(ProcessScheduledTickets::class)->handle(app(\App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence::class));

        $occurrence = Ticket::query()->where('metadata->parent_ticket_id', $parentTicket->id)->firstOrFail();
        $this->assertSame(2, $occurrence->tasks()->count());
        $this->assertSame(0, $parentTicket->tasks()->count());
        $this->assertSame('Prepare '.$occurrence->ticket_key, $occurrence->tasks()->orderBy('sort_order')->firstOrFail()->title);
        $this->assertSame(2, Task::query()->count());
        $run = TaskTemplateRun::query()->sole();
        $this->assertSame('ticket_schedule', $run->trigger_type);
        $this->assertSame($schedule->id, $run->source_id);
        $this->assertSame($occurrence->id, $run->owner_id);
    }
}
