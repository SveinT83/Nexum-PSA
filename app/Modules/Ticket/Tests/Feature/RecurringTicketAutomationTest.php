<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Jobs\ProcessScheduledTickets;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

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
}
