<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketType;
use App\Modules\Ticket\Models\TicketSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Carbon\Carbon;

class ScheduledTicketSlaTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        \Spatie\Permission\Models\Role::create(['name' => 'Tech']);
        $this->admin = User::factory()->create(['status' => 'ACTIVE']);
        $this->admin->assignRole('Tech');
        $this->admin->givePermissionTo([
            'ticket.view',
            'ticket.create',
            'ticket.update',
        ]);
        $this->actingAs($this->admin);
    }

    /** @test */
    public function it_defers_sla_for_future_scheduled_tickets()
    {
        $this->seed(\Database\Seeders\SlaSeeder::class);
        $defaults = app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();
        $priority = $defaults['priority'];
        $type = $defaults['type'];
        $status = $defaults['status'];
        $queue = $defaults['queue'];

        $plannedStart = Carbon::now()->addDays(7)->startOfMinute();

        $response = $this->post(route('tech.tickets.store'), [
            'subject' => 'Planned Starlink Installation',
            'description' => 'Install Starlink at customer site',
            'priority_id' => $priority->id,
            'ticket_type_id' => $type->id,
            'status_id' => $status->id,
            'queue_id' => $queue->id,
            'channel' => 'web',
            'is_scheduled' => true,
            'planned_start_at' => $plannedStart->toDateTimeString(),
            'sla_mode' => 'defer_until_planned_start',
        ]);

        $response->assertStatus(302);
        $ticket = Ticket::latest('id')->first();

        $this->assertNotNull($ticket->schedule);
        $this->assertEquals('scheduled', $ticket->schedule->status);
        $this->assertEquals($plannedStart->toDateTimeString(), $ticket->schedule->planned_start_at->toDateTimeString());

        // SLA should be calculated from planned_start_at
        // Default Medium priority (normal) has 4 hours response time
        $expectedSla = $plannedStart->copy()->addHours(4);
        $this->assertNotNull($ticket->first_response_due_at);
        $this->assertEquals($expectedSla->toDateTimeString(), $ticket->first_response_due_at->toDateTimeString());
        $this->assertTrue($ticket->first_response_due_at->isAfter(Carbon::now()->addDays(6)));
    }

    /** @test */
    public function it_does_not_assign_sla_when_mode_is_non_sla()
    {
        $this->seed(\Database\Seeders\SlaSeeder::class);
        $defaults = app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();

        $response = $this->post(route('tech.tickets.store'), [
            'subject' => 'Non-SLA Planned Work',
            'priority_id' => $defaults['priority']->id,
            'ticket_type_id' => $defaults['type']->id,
            'status_id' => $defaults['status']->id,
            'queue_id' => $defaults['queue']->id,
            'channel' => 'web',
            'is_scheduled' => true,
            'planned_start_at' => Carbon::now()->addDays(7)->toDateTimeString(),
            'sla_mode' => 'non_sla_until_start',
        ]);

        $response->assertStatus(302);
        $ticket = Ticket::latest('id')->first();

        $this->assertNull($ticket->first_response_due_at);
        $this->assertNull($ticket->resolve_due_at);
        $this->assertEquals('non_sla_until_start', $ticket->schedule->sla_mode);
    }

    /** @test */
    public function it_preserves_existing_sla_when_adding_a_schedule_later()
    {
        $this->seed(\Database\Seeders\SlaSeeder::class);
        $defaults = app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();

        // Create a normal ticket first
        $response = $this->post(route('tech.tickets.store'), [
            'subject' => 'Normal Ticket',
            'priority_id' => $defaults['priority']->id,
            'ticket_type_id' => $defaults['type']->id,
            'status_id' => $defaults['status']->id,
            'queue_id' => $defaults['queue']->id,
            'channel' => 'web',
        ]);

        $ticket = Ticket::latest('id')->first();
        $originalDueAt = $ticket->first_response_due_at;
        $this->assertNotNull($originalDueAt);

        // Now update it to be scheduled
        $plannedStart = Carbon::now()->addDays(10);
        $response = $this->patch(route('tech.tickets.update', $ticket), [
            'subject' => 'Normal Ticket',
            'priority_id' => $defaults['priority']->id,
            'status_id' => $defaults['status']->id,
            'queue_id' => $defaults['queue']->id,
            'is_scheduled' => true,
            'planned_start_at' => $plannedStart->toDateTimeString(),
            'sla_mode' => 'defer_until_planned_start',
        ]);

        $response->assertStatus(302);
        $ticket->refresh();

        $this->assertNotNull($ticket->schedule);
        // SLA should NOT have changed
        $this->assertEquals($originalDueAt->toDateTimeString(), $ticket->first_response_due_at->toDateTimeString());
    }
}
