<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Queries\TicketApiReferenceQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketReadbackApiTest extends TestCase
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
        foreach (['ticket.view', 'ticket.create', 'ticket.update', 'task.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
        app(EnsureTaskDefaults::class)->handle();
    }

    public function test_new_read_routes_require_both_token_and_active_actor_rights(): void
    {
        $ticket = $this->ticket();
        $this->getJson(route('api.v1.tickets.context'))->assertUnauthorized();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.time-entries.index', $ticket))->assertForbidden();
        $this->getJson(route('api.v1.tickets.context'))->assertOk();

        $withoutRights = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($withoutRights, ['*']);
        $this->getJson(route('api.v1.tickets.context'))->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->tech->forceFill(['status' => 'pending'])->save();
        $this->getJson(route('api.v1.tickets.context'))->assertForbidden();
    }

    public function test_capabilities_list_registered_contracts_and_token_eligibility_only(): void
    {
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $data = $this->getJson(route('api.v1.tickets.capabilities'))->assertOk()->json('data');
        $this->assertSame('domain_api', $data['surface']);
        $this->assertGreaterThanOrEqual(13, count($data['operations']));
        $time = collect($data['operations'])->firstWhere('name', 'api.v1.tickets.time-entries.index');
        $this->assertFalse($time['token_authorized']);
        $this->assertTrue($time['actor_authorized']);
        $this->assertTrue($time['object_and_runtime_checks_required']);
        foreach ($data['operations'] as $operation) {
            $this->assertNotNull(Route::getRoutes()->getByName($operation['name']));
        }
        $this->assertTrue(app(ApiAbilityCatalog::class)->isReadOnly('tickets.time.read'));
        $this->assertTrue(app(ApiAbilityCatalog::class)->isReadOnly('tickets.costs.read'));
    }

    public function test_every_ticket_catalog_is_bounded_and_uses_safe_fields(): void
    {
        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.costs.read']);
        foreach (TicketApiReferenceQuery::CATALOGS as $catalog) {
            $this->getJson(route('api.v1.tickets.context.options', ['catalog' => $catalog, 'per_page' => 1]))
                ->assertOk()->assertJsonPath('meta.per_page', 1)->assertJsonMissingPath('data.0.password');
        }
        $this->getJson(route('api.v1.tickets.context.options', ['catalog' => 'user_management']))->assertNotFound();
        $this->getJson(route('api.v1.tickets.context.options', ['catalog' => 'clients', 'per_page' => 101]))->assertUnprocessable();
        $this->getJson(route('api.v1.tickets.context.options', ['catalog' => 'clients', 'page' => 0]))->assertUnprocessable();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.context.options', ['catalog' => 'storage-items']))->assertForbidden();
    }

    public function test_reference_context_rejects_foreign_site_before_disclosure(): void
    {
        $first = Client::factory()->create(['active' => true]);
        $other = Client::factory()->create(['active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $other->id]);
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.context.options', ['catalog' => 'assets', 'client_id' => $first->id, 'site_id' => $site->id]))->assertUnprocessable();
    }

    public function test_ticket_time_is_paginated_complete_and_nested_ids_are_guarded(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket('Other');
        $first = $ticket->timeEntries()->create(['user_id' => $this->tech->id, 'type' => 'manual', 'minutes' => 17, 'work_date' => '2026-10-19', 'billable' => false, 'billing_status' => 'pending', 'note' => 'Verified API record']);
        $second = $ticket->timeEntries()->create(['user_id' => $this->tech->id, 'type' => 'manual', 'minutes' => 23, 'work_date' => '2026-10-19', 'billable' => false, 'billing_status' => 'pending']);
        Sanctum::actingAs($this->tech, ['tickets.time.read']);
        $this->getJson(route('api.v1.tickets.time-entries.index', ['ticket' => $ticket, 'per_page' => 1]))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $second->id)->assertJsonPath('meta.last_page', 2);
        $this->getJson(route('api.v1.tickets.time-entries.index', ['ticket' => $ticket, 'per_page' => 1, 'page' => 2]))
            ->assertOk()->assertJsonPath('data.0.id', $first->id);
        $this->getJson(route('api.v1.tickets.time-entries.show', ['ticket' => $ticket, 'timeEntry' => $first]))
            ->assertOk()->assertJsonPath('data.note', 'Verified API record')->assertJsonPath('data.minutes', 17);
        $this->getJson(route('api.v1.tickets.time-entries.show', ['ticket' => $other, 'timeEntry' => $first]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.time-entries.index', ['ticket' => $ticket, 'per_page' => 101]))->assertUnprocessable();
    }

    public function test_cost_readback_and_foreign_cost_ids_are_guarded(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket('Other');
        $cost = $ticket->costEntries()->create(['user_id' => $this->tech->id, 'quantity' => 2, 'item_name' => 'Test part', 'unit_price_ex_vat' => 10, 'currency' => 'NOK', 'status' => 'reserved', 'billing_status' => 'pending']);
        Sanctum::actingAs($this->tech, ['tickets.costs.read']);
        $this->getJson(route('api.v1.tickets.cost-entries.index', $ticket))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $cost->id);
        $this->getJson(route('api.v1.tickets.cost-entries.show', ['ticket' => $ticket, 'costEntry' => $cost]))->assertOk()->assertJsonPath('data.unit_price_ex_vat', '10.00');
        $this->getJson(route('api.v1.tickets.cost-entries.show', ['ticket' => $other, 'costEntry' => $cost]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.planned-lines.index', $ticket))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_get_context_activity_and_schedule_do_not_mark_ticket_read_or_mutate_events(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill(['is_unread' => true])->save();
        $event = TicketEvent::create(['ticket_id' => $ticket->id, 'actor_id' => $this->tech->id, 'type' => 'custom_field_changed', 'message' => 'restricted-value', 'metadata' => ['secret' => 'restricted-value']]);
        $count = TicketEvent::count();
        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.time.read']);
        $this->getJson(route('api.v1.tickets.context.show', $ticket))->assertOk()->assertJsonPath('data.ticket_id', $ticket->id);
        $this->getJson(route('api.v1.tickets.activities.index', $ticket))->assertOk()->assertJsonPath('data.0.id', $event->id)->assertJsonMissingPath('data.0.message')->assertJsonMissingPath('data.0.metadata');
        $this->getJson(route('api.v1.tickets.schedule.show', $ticket))->assertOk()->assertJsonPath('data', null);
        $this->getJson(route('api.v1.tickets.time-rate-options.index', $ticket))->assertOk()->assertJsonPath('meta.per_page', 25);
        $this->assertTrue($ticket->refresh()->is_unread);
        $this->assertSame($count, TicketEvent::count());
    }

    public function test_record_context_mismatch_is_rejected(): void
    {
        $ticket = $this->ticket();
        $client = Client::factory()->create();
        $ticket->forceFill(['client_id' => $client->id])->save();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.context.show', $ticket))->assertUnprocessable();
    }

    public function test_ticket_task_read_requires_task_domain_right_and_hides_private_children(): void
    {
        $ticket = $this->ticket();
        $visible = app(StoreTask::class)->handle(['title' => 'Visible Task'], $this->tech, $ticket);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        app(StoreTask::class)->handle(['title' => 'Private Task', 'visibility' => 'private'], $other, $ticket);
        Sanctum::actingAs($this->tech, ['tasks.read']);
        $this->getJson(route('api.v1.tickets.tasks.index', $ticket))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $visible->id);
        $this->tech->revokePermissionTo('task.view');
        $this->getJson(route('api.v1.tickets.tasks.index', $ticket))->assertForbidden();
    }

    private function ticket(string $subject = 'Read-back Ticket'): Ticket
    {
        return app(StoreTicket::class)->handle(['subject' => $subject, 'client_id' => null], $this->tech);
    }
}
