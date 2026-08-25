<?php

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketSchedule;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class DebugTicketUpdateTest extends TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    public function test_update_ticket_schedule()
    {
        $this->seed(\Database\Seeders\AdminUserSeeder::class);
        $this->seed(\Database\Seeders\SlaSeeder::class);
        app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();
        $admin = User::first();
        $this->actingAs($admin);

        $defaults = app(EnsureTicketDefaults::class)->handle();

        $ticket = Ticket::create([
            'ticket_key' => 'TEST-1',
            'subject' => 'Test Ticket',
            'type' => $defaults['type']->slug,
            'ticket_type_id' => $defaults['type']->id,
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'sla_id' => 1,
            'sla_source' => 'default',
            'workflow_id' => 1,
            'workflow_version_id' => 1,
            'workflow_state_key' => 'open',
            'work_context_id' => 1,
        ]);

        $response = $this->patch(route('tech.tickets.update', $ticket), [
            'subject' => 'Updated Subject',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'is_scheduled' => '1', // Laravel often gets '1' from forms
            'planned_start_at' => now()->addDays(1)->toDateTimeString(),
            'sla_mode' => 'defer_until_planned_start',
        ]);

        if ($response->getStatusCode() === 500) {
            $response->dump();
        }

        echo "Response status: " . $response->getStatusCode() . "\n";
        if ($response->getStatusCode() !== 302) {
            echo "Errors: " . json_encode(session('errors')) . "\n";
        }

        $ticket->refresh();
        echo "Schedule exists: " . ($ticket->schedule ? 'YES' : 'NO') . "\n";
        if ($ticket->schedule) {
            echo "Schedule data: " . json_encode($ticket->schedule) . "\n";
        }
    }
}
