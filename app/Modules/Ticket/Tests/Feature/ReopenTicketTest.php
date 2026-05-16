<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\ReopenTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReopenTicketTest extends TestCase
{
    use RefreshDatabase;

    private function createOpenStatus(): TicketStatus
    {
        return TicketStatus::create([
            'name' => 'Open',
            'slug' => 'open',
            'state' => 'open',
            'is_default' => true,
            'is_closed' => false,
            'is_active' => true,
            'sort_order' => 10,
        ]);
    }

    private function createClosedStatus(): TicketStatus
    {
        return TicketStatus::create([
            'name' => 'Closed',
            'slug' => 'closed',
            'state' => 'closed',
            'is_default' => false,
            'is_closed' => true,
            'is_active' => true,
            'sort_order' => 50,
        ]);
    }

    private function createClosedTicket(): Ticket
    {
        $openStatus = $this->createOpenStatus();
        $closedStatus = $this->createClosedStatus();
        $queue = TicketStatus::count() > 0
            ? \App\Modules\Ticket\Models\TicketQueue::create(['name' => 'General', 'slug' => 'general', 'is_active' => true, 'sort_order' => 10])
            : \App\Modules\Ticket\Models\TicketQueue::first();

        $ticket = Ticket::create([
            'ticket_key' => 'TK-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'subject' => 'Test ticket',
            'status_id' => $closedStatus->id,
            'closed_at' => now(),
            'resolved_at' => now(),
        ]);

        return $ticket;
    }

    public function test_reopen_moves_closed_ticket_to_open(): void
    {
        $ticket = $this->createClosedTicket();
        $user = User::factory()->create(['status' => 'active']);

        $action = new ReopenTicket();
        $result = $action->handle($ticket, $user);

        $this->assertNull($result->fresh()->closed_at);
        $this->assertNull($result->fresh()->resolved_at);
        $this->assertNotNull($result->fresh()->reopened_at);
        $this->assertEquals(1, $result->fresh()->reopen_count);
        $this->assertFalse($result->fresh()->status->is_closed);
    }

    public function test_reopen_increments_count_on_multiple_reopens(): void
    {
        $ticket = $this->createClosedTicket();
        $user = User::factory()->create(['status' => 'active']);

        $action = new ReopenTicket();

        // First reopen
        $action->handle($ticket, $user);
        // Close it again
        $closedStatus = TicketStatus::where('is_closed', true)->first();
        $ticket->update(['status_id' => $closedStatus->id, 'closed_at' => now(), 'resolved_at' => now()]);
        // Second reopen
        $action->handle($ticket->refresh(), $user);

        $this->assertEquals(2, $ticket->fresh()->reopen_count);
    }

    public function test_reopen_creates_event(): void
    {
        $ticket = $this->createClosedTicket();
        $user = User::factory()->create(['status' => 'active']);

        $action = new ReopenTicket();
        $action->handle($ticket, $user, 'Customer reported issue persists');

        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'actor_id' => $user->id,
            'type' => 'reopened',
        ]);

        $event = \App\Modules\Ticket\Models\TicketEvent::where('ticket_id', $ticket->id)
            ->where('type', 'reopened')
            ->first();

        $this->assertStringContainsString('Customer reported issue persists', $event->message);
    }

    public function test_action_guard_allows_reopen_on_closed_ticket(): void
    {
        $ticket = $this->createClosedTicket();
        $user = User::factory()->create(['status' => 'active']);
        $guard = new TicketActionGuard();

        $this->assertTrue($guard->allowed($ticket, TicketAction::REOPEN, $user));
        $this->assertNull($guard->reason($ticket, TicketAction::REOPEN, $user));
    }

    public function test_action_guard_blocks_reopen_on_open_ticket(): void
    {
        $openStatus = $this->createOpenStatus();
        $ticket = Ticket::create([
            'ticket_key' => 'TK-' . str_pad(random_int(1, 9999), 4, '0', STR_PAD_LEFT),
            'subject' => 'Open ticket',
            'status_id' => $openStatus->id,
        ]);
        $user = User::factory()->create(['status' => 'active']);
        $guard = new TicketActionGuard();

        $reason = $guard->reason($ticket, TicketAction::REOPEN, $user);

        $this->assertNotNull($reason);
        $this->assertStringContainsString('closed', strtolower($reason));
    }

    public function test_reopen_with_reason(): void
    {
        $ticket = $this->createClosedTicket();
        $user = User::factory()->create(['status' => 'active']);

        $action = new ReopenTicket();
        $result = $action->handle($ticket, $user, 'Issue not fully resolved');

        $event = \App\Modules\Ticket\Models\TicketEvent::where('ticket_id', $ticket->id)
            ->where('type', 'reopened')
            ->first();

        $this->assertEquals('Ticket reopened: Issue not fully resolved', $event->message);
    }
}