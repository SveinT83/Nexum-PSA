<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreScheduledTicketOccurrence;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketSchedule;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ScheduledTicketRuntimeIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function repeated_occurrence_delivery_returns_one_ticket_and_clones_notes_once(): void
    {
        Queue::fake();
        config()->set('ticket_rules.v2_enabled', false);
        app(EnsureTicketDefaults::class)->handle();
        $creator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $parent = app(StoreTicket::class)->handle([
            'subject' => 'Owned scheduler fixture',
            'description' => 'Internal disposable fixture.',
            'channel' => 'manual',
            'suppress_notifications' => true,
            '_skip_initial_description_note' => true,
        ], $creator);
        $at = Carbon::now()->addDays(2)->startOfMinute();
        TicketSchedule::query()->create([
            'ticket_id' => $parent->id, 'schedule_type' => 'recurring',
            'planned_start_at' => $at, 'recurrence_rule' => 'FREQ=WEEKLY',
            'sla_mode' => 'defer_until_planned_start', 'status' => 'active',
            'created_by' => $creator->id, 'updated_by' => $creator->id,
        ]);
        TicketMessage::query()->create([
            'ticket_id' => $parent->id, 'author_id' => $creator->id, 'author_type' => 'user',
            'type' => 'internal_note', 'visibility' => 'internal', 'body' => 'Owned template note.',
        ]);

        $first = app(StoreScheduledTicketOccurrence::class)->handle($parent, $at);
        $second = app(StoreScheduledTicketOccurrence::class)->handle($parent->fresh(), $at->copy());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Ticket::query()->where('metadata->parent_ticket_id', $parent->id)->count());
        $this->assertSame(1, $first->messages()->count());
        $this->assertSame($parent->workflow_version_id, $second->workflow_version_id);

        $later = app(StoreScheduledTicketOccurrence::class)->handle($parent, $at->copy()->addWeek());
        $this->assertNotSame($first->id, $later->id);
        $this->assertSame(2, Ticket::query()->where('metadata->parent_ticket_id', $parent->id)->count());

        $first->delete();
        $removedRetry = app(StoreScheduledTicketOccurrence::class)->handle($parent, $at);
        $this->assertSame($first->id, $removedRetry->id);
        $this->assertTrue($removedRetry->trashed());
    }
}
