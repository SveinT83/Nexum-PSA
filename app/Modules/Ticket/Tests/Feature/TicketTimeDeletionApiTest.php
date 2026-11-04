<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Synthetic records prove removal, retained audit, dependency fences and retry semantics. */
class TicketTimeDeletionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $this->actor = $this->technician();
        app(EnsureTicketDefaults::class)->handle();
        Sanctum::actingAs($this->actor, ['tickets.time.delete', 'tickets.time.read']);
    }

    public function test_removes_one_other_technician_duplicate_and_retains_exact_audit_and_independent_receipt(): void
    {
        $ticket = $this->ticket();
        $worker = $this->technician();
        $original = $this->entry($ticket, ['user_id' => $worker->id]);
        $duplicate = $this->entry($ticket, ['user_id' => $worker->id]);
        $before = $duplicate->fresh()->attributesToArray();
        $count = TicketEvent::count();
        $payload = $this->payload($ticket, $duplicate);
        $this->assertSame($count, TicketEvent::count(), 'Preview must be read-only.');
        $result = $this->deleteJson($this->url($ticket, $duplicate), $payload)->assertOk()
            ->assertJsonPath('data.deleted', true)->assertJsonPath('data.time_entry_id', $duplicate->id)
            ->assertJsonPath('data.actor_id', $this->actor->id);
        $event = TicketEvent::findOrFail($result->json('data.audit_event_id'));
        $this->assertSame($before, $event->before);
        $this->assertSame($worker->id, $event->before['user_id']);
        $this->assertSame('Duplicate registration', $event->after['reason']);
        $this->assertDatabaseMissing('ticket_time_entries', ['id' => $duplicate->id]);
        $this->assertDatabaseHas('ticket_time_entries', ['id' => $original->id, 'minutes' => 24]);
        $this->assertSame(24, (int) $ticket->timeEntries()->sum('minutes'));
        $this->getJson(route('api.v1.tickets.time-entries.show', [$ticket, $duplicate]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.time-entries.index', $ticket))->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($result->json('data.receipt_url'))->assertOk()->assertExactJson($result->json());
        $this->assertArrayNotHasKey('metadata', $result->json('data'));
        $this->assertArrayNotHasKey('before', $result->json('data'));
        $this->assertContains('tickets.time.delete', app(ApiAbilityCatalog::class)->values());
        $this->assertFalse(app(ApiAbilityCatalog::class)->isReadOnly('tickets.time.delete'));
    }

    public function test_exact_retry_survives_source_removal_and_expired_preview_without_second_audit(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        $first = $this->deleteJson($this->url($ticket, $entry), $payload)->assertOk()->json();
        $count = TicketEvent::count();
        $revision = (int) DB::table('economy_write_mutex')->value('revision');
        $this->travel(11)->minutes();
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertOk()->assertExactJson($first);
        $this->assertSame($count, TicketEvent::count());
        $this->assertSame($revision, (int) DB::table('economy_write_mutex')->value('revision'));
        $payload['reason'] = 'Different request';
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
        $this->travelBack();
        $other = $this->entry($ticket);
        $new = $this->payload($ticket, $other);
        $new['idempotency_key'] = $payload['idempotency_key'];
        $this->deleteJson($this->url($ticket, $other), $new)->assertConflict();
        $this->assertModelExists($other);
    }

    public function test_each_current_permission_and_scope_is_required_on_preview_delete_receipt_and_replay(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        Sanctum::actingAs($this->actor, ['tickets.time.update', 'tickets.time.read']);
        $this->getJson($this->previewUrl($ticket, $entry))->assertForbidden();
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertForbidden();
        Sanctum::actingAs($this->actor, ['tickets.time.delete']);
        foreach (['ticket.view', 'ticket.register_time', 'ticket.update'] as $permission) {
            $this->actor->revokePermissionTo($permission);
            $this->getJson($this->previewUrl($ticket, $entry))->assertForbidden();
            $this->deleteJson($this->url($ticket, $entry), $payload)->assertForbidden();
            $this->actor->givePermissionTo($permission);
        }
        $receipt = $this->deleteJson($this->url($ticket, $entry), $payload)->assertOk()->json('data.receipt_url');
        $this->actor->revokePermissionTo('ticket.update');
        $this->getJson($receipt)->assertForbidden();
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertForbidden();
    }

    public function test_wrong_ticket_unknown_source_and_wrong_event_are_not_found(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $other = $this->ticket();
        $payload = $this->payload($ticket, $entry);
        $this->getJson($this->previewUrl($other, $entry))->assertNotFound();
        $this->deleteJson($this->url($other, $entry), $payload)->assertNotFound();
        $this->deleteJson(route('api.v1.tickets.time-entries.destroy', [$ticket, 999999]), $payload)->assertNotFound();
        $event = TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $this->actor->id, 'type' => 'time_entry_added']);
        $this->getJson(route('api.v1.tickets.time-entry-deletions.show', [$ticket, $event]))->assertNotFound();
        $receipt = $this->deleteJson($this->url($ticket, $entry), $payload)->assertOk()->json('data.audit_event_id');
        $this->getJson(route('api.v1.tickets.time-entry-deletions.show', [$other, $receipt]))->assertNotFound();
    }

    public function test_reason_key_token_and_unsupported_fields_are_validated(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        $this->deleteJson($this->url($ticket, $entry), [])->assertUnprocessable()
            ->assertJsonValidationErrors(['reason', 'preview_token', 'idempotency_key']);
        foreach (['reason' => '   ', 'idempotency_key' => 'short', 'preview_token' => 'broken-token'] as $field => $invalid) {
            $this->deleteJson($this->url($ticket, $entry), array_replace($payload, [$field => $invalid]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->deleteJson($this->url($ticket, $entry), $payload + ['force' => true])->assertUnprocessable();
        $this->assertModelExists($entry);
    }

    public function test_changed_expired_cross_record_and_cross_actor_previews_cannot_authorize_deletion(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        $other = $this->entry($ticket);
        $this->deleteJson($this->url($ticket, $other), $payload)->assertConflict();
        $otherActor = $this->technician();
        Sanctum::actingAs($otherActor, ['tickets.time.delete']);
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
        Sanctum::actingAs($this->actor, ['tickets.time.delete']);
        $entry->update(['minutes' => 25]);
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
        $payload = $this->payload($ticket, $entry);
        $this->travel(10)->minutes();
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
        $this->travelBack();
        $this->assertModelExists($entry);
        $this->assertModelExists($other);
        $this->assertSame(0, TicketEvent::where('type', 'time_entry_deleted')->count());
    }

    public function test_all_processed_and_task_projection_states_are_blocked_even_with_an_old_allowed_preview(): void
    {
        $ticket = $this->ticket();
        foreach ([
            ['billing_status' => 'ordered'], ['billing_status' => 'invoiced'],
            ['billing_status' => 'cancelled'], ['timebank_status' => 'covered'],
            ['timebank_status' => 'consumed'], ['type' => 'task_billing'], ['type' => 'timer'],
        ] as $protected) {
            $entry = $this->entry($ticket);
            $payload = $this->payload($ticket, $entry);
            $entry->update($protected);
            $this->getJson($this->previewUrl($ticket, $entry))->assertOk()->assertJsonPath('data.allowed', false)
                ->assertJsonPath('data.preview_token', null);
            $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
            $this->assertModelExists($entry);
        }
    }

    public function test_pending_status_does_not_hide_financial_allocations_or_polymorphic_order_references(): void
    {
        $ticket = $this->ticket();
        $client = Client::factory()->create();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        $allocation = $entry->allocation()->create(['ticket_id' => $ticket->id, 'client_id' => $client->id,
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30']);
        $this->getJson($this->previewUrl($ticket, $entry))->assertOk()
            ->assertJsonPath('data.blockers.0', 'financial_allocation_exists');
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
        $this->assertModelExists($allocation);
        $this->assertModelExists($entry);
        $other = $this->entry($ticket);
        $payload = $this->payload($ticket, $other);
        $order = EconomyOrder::create(['client_id' => $client->id, 'order_number' => 'TIME-DELETE-TEST',
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'draft']);
        $line = EconomyOrderLine::create(['economy_order_id' => $order->id, 'client_id' => $client->id,
            'source_type' => $other->getMorphClass(), 'source_id' => $other->id,
            'line_type' => 'ticket_time', 'description' => 'Synthetic protected time',
            'quantity' => 24, 'unit' => 'minute', 'unit_price_ex_vat' => 10,
            'line_total_ex_vat' => 240, 'total_inc_vat' => 240, 'currency' => 'NOK', 'status' => 'cancelled']);
        $this->deleteJson($this->url($ticket, $other), $payload)->assertConflict();
        $this->getJson($this->previewUrl($ticket, $other))->assertOk()->assertJsonPath('data.blockers.0', 'financial_order_exists');
        $this->assertModelExists($line);
        $this->assertModelExists($other);
    }

    public function test_historical_and_pre_index_workday_references_block_deletion_without_touching_payroll(): void
    {
        $ticket = $this->ticket();
        foreach ([false, true] as $indexed) {
            $entry = $this->entry($ticket);
            $payload = $this->payload($ticket, $entry);
            $revision = $this->historicalReference($entry, $indexed);
            $this->getJson($this->previewUrl($ticket, $entry))->assertOk()
                ->assertJsonPath('data.blockers.0', 'retained_workday_reference');
            $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
            $this->assertDatabaseHas('workday_revisions', ['id' => $revision]);
            $this->assertModelExists($entry);
        }
    }

    public function test_manual_rows_with_a_task_reference_and_missing_dependency_schema_fail_closed(): void
    {
        $ticket = $this->ticket();
        $task = \App\Modules\Task\Models\Task::create(['title' => 'Synthetic linked actual work',
            'owner_type' => Ticket::class, 'owner_id' => $ticket->id, 'created_by' => $this->actor->id]);
        $entry = $this->entry($ticket, ['task_id' => $task->id]);
        $this->getJson($this->previewUrl($ticket, $entry))->assertOk()->assertJsonPath('data.allowed', false)
            ->assertJsonPath('data.blockers.0', 'not_direct_manual_time');
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        \Illuminate\Support\Facades\Schema::rename('workday_source_allocations', 'synthetic_missing_source_allocations');
        try {
            $this->getJson($this->previewUrl($ticket, $entry))->assertStatus(503);
            $this->deleteJson($this->url($ticket, $entry), $payload)->assertStatus(503);
            $this->assertModelExists($entry);
        } finally {
            \Illuminate\Support\Facades\Schema::rename('synthetic_missing_source_allocations', 'workday_source_allocations');
        }
    }

    public function test_audit_failure_rolls_back_removal_and_mutex_revision(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        $mutex = (int) DB::table('economy_write_mutex')->value('revision');
        TicketEvent::creating(function ($event) {
            if ($event->type === 'time_entry_deleted') {
                throw new \RuntimeException('Synthetic audit failure');
            }
        });
        try {
            $this->withoutExceptionHandling()->deleteJson($this->url($ticket, $entry), $payload);
            $this->fail('An audit failure must abort deletion.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        } finally {
            TicketEvent::flushEventListeners();
        }
        $this->assertModelExists($entry);
        $this->assertSame($mutex, (int) DB::table('economy_write_mutex')->value('revision'));
        $this->assertSame(0, TicketEvent::where('type', 'time_entry_deleted')->count());
    }

    public function test_closed_ticket_workflow_and_inactive_actor_are_rechecked(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket);
        $payload = $this->payload($ticket, $entry);
        $ticket->update(['closed_at' => now()]);
        $this->getJson($this->previewUrl($ticket, $entry))->assertOk()->assertJsonPath('data.blockers.0', 'workflow_blocked');
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertConflict();
        $ticket->update(['closed_at' => null]);
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson($this->previewUrl($ticket, $entry))->assertForbidden();
        $this->deleteJson($this->url($ticket, $entry), $payload)->assertForbidden();
        $this->assertModelExists($entry);
    }

    private function historicalReference(TicketTimeEntry $entry, bool $indexed): int
    {
        $day = DB::table('workdays')->insertGetId(['uuid' => (string) Str::uuid(), 'user_id' => $entry->user_id,
            'work_date' => $indexed ? '2026-11-03' : '2026-11-02', 'timezone' => 'Europe/Oslo',
            'version' => 2, 'expires_at' => now()->addYears(3), 'created_at' => now(), 'updated_at' => now()]);
        $revision = DB::table('workday_revisions')->insertGetId(['uuid' => (string) Str::uuid(), 'workday_id' => $day,
            'version' => 1, 'state' => 'draft', 'snapshot' => json_encode(['allocations' => [['source_key' => 'ticket:'.$entry->id]],
                'actual_minutes' => 60]), 'author_id' => $this->actor->id, 'origin' => 'api', 'created_at' => now()]);
        if ($indexed) {
            DB::table('workday_source_allocations')->insert(['revision_id' => $revision, 'source_key' => 'ticket:'.$entry->id, 'minutes' => 24]);
        }

        return $revision;
    }

    private function technician(): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');
        foreach (['ticket.view', 'ticket.update', 'ticket.register_time'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }

    private function ticket(): Ticket
    {
        return app(StoreTicket::class)->handle(['subject' => 'Synthetic time deletion', 'client_id' => null, 'owner_id' => $this->actor->id], $this->actor);
    }

    private function entry(Ticket $ticket, array $fields = []): TicketTimeEntry
    {
        return $ticket->timeEntries()->create($fields + ['user_id' => $this->actor->id, 'type' => 'manual',
            'work_date' => '2026-11-04', 'minutes' => 24, 'invoice_text' => 'Synthetic work', 'note' => 'Internal test note',
            'billing_status' => 'pending', 'timebank_status' => 'pending']);
    }

    private function previewUrl(Ticket $ticket, TicketTimeEntry $entry): string
    {
        return route('api.v1.tickets.time-entries.delete-preview', [$ticket, $entry]);
    }

    private function url(Ticket $ticket, TicketTimeEntry $entry): string
    {
        return route('api.v1.tickets.time-entries.destroy', [$ticket, $entry->id]);
    }

    private function payload(Ticket $ticket, TicketTimeEntry $entry): array
    {
        $preview = $this->getJson($this->previewUrl($ticket, $entry))->assertOk()->assertJsonPath('data.allowed', true);

        return ['reason' => 'Duplicate registration', 'preview_token' => $preview->json('data.preview_token'),
            'idempotency_key' => (string) Str::uuid()];
    }
}
