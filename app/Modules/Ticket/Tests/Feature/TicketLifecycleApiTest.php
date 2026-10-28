<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Taxonomy\Models\Category;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketAssignmentRule;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Models\TicketType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketLifecycleApiTest extends TestCase
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
        foreach (['ticket.view', 'ticket.create', 'ticket.update', 'ticket.assign', 'ticket.delete'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.create', 'tickets.update']);
    }

    public function test_create_and_patch_schedule_tags_and_catalog_type_have_exact_readback(): void
    {
        $type = TicketType::create(['name' => 'API type', 'slug' => 'api-type', 'is_active' => true]);
        $category = Category::create(['name' => 'API category', 'slug' => 'api-category', 'type' => Category::TYPE_TICKET, 'is_active' => true]);
        $payload = ['subject' => 'Scheduled API Ticket', 'category_id' => $category->id, 'is_scheduled' => true,
            'planned_start_at' => '2026-10-25 10:00:00', 'planned_end_at' => '2026-10-25 11:00:00',
            'timezone' => 'Europe/Oslo', 'schedule_type' => 'recurring', 'recurrence_rule' => 'FREQ=WEEKLY', 'tag_names' => ['API review']];
        $key = $this->postJson(route('api.v1.tickets.store'), $payload)->assertCreated()->assertJsonPath('data.tags.0.name', 'API review')->json('data.ticket_key');
        $this->getJson(route('api.v1.tickets.schedule.show', $key))->assertOk()->assertJsonPath('data.schedule_type', 'recurring')->assertJsonPath('data.recurrence_rule', 'FREQ=WEEKLY');
        $this->patchJson(route('api.v1.tickets.update', $key), ['ticket_type_id' => $type->id, 'impact' => 4, 'urgency' => 3, 'tag_names' => []])
            ->assertOk()->assertJsonPath('data.ticket_type_id', $type->id)->assertJsonPath('data.type', 'api-type')->assertJsonPath('data.impact', 4)->assertJsonPath('data.tags', []);
        $this->patchJson(route('api.v1.tickets.update', $key), ['planned_end_at' => '2026-10-25 12:00:00'])->assertOk();
        $this->getJson(route('api.v1.tickets.schedule.show', $key))->assertOk()->assertJsonPath('data.recurrence_rule', 'FREQ=WEEKLY');
        $this->patchJson(route('api.v1.tickets.update', $key), ['is_scheduled' => false])->assertOk();
        $this->getJson(route('api.v1.tickets.schedule.show', $key))->assertOk()->assertJsonPath('data', null);
    }

    public function test_partial_schedule_validation_and_failed_portal_scope_do_not_create_tags_or_tickets(): void
    {
        $this->postJson(route('api.v1.tickets.store'), ['subject' => 'Invalid schedule', 'is_scheduled' => true, 'tag_names' => ['Must not leak']])->assertUnprocessable();
        [$client, $site, $contact] = $this->client();
        $payload = ['subject' => 'Portal publication', 'client_id' => $client->id, 'site_id' => $site->id, 'contact_id' => $contact->id, 'customer_portal_visibility' => 'published', 'tag_names' => ['Must not leak']];
        $this->postJson(route('api.v1.tickets.store'), $payload)->assertForbidden();
        $this->assertSame(0, Ticket::count());
        $this->assertSame(0, Tag::where('name', 'Must not leak')->count());
        Sanctum::actingAs($this->tech, ['tickets.create', 'tickets.portal.publish']);
        $this->postJson(route('api.v1.tickets.store'), $payload)->assertCreated()->assertJsonPath('data.portal_visible', true);
    }

    public function test_patch_can_choose_verified_client_contact_site_and_sla(): void
    {
        $ticket = $this->ticket();
        [$client, $site, $contact] = $this->client();
        $sla = Sla::create(['name' => 'API SLA', 'description' => 'Test policy', 'is_default' => false,
            'low_firstResponse' => 4, 'low_firstResponse_type' => 'hours', 'low_onsite' => 16, 'low_onsite_type' => 'hours',
            'medium_firstResponse' => 2, 'medium_firstResponse_type' => 'hours', 'medium_onsite' => 8, 'medium_onsite_type' => 'hours',
            'high_firstResponse' => 1, 'high_firstResponse_type' => 'hours', 'high_onsite' => 4, 'high_onsite_type' => 'hours', 'created_by_user_id' => $this->tech->id]);
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['client_id' => $client->id, 'contact_id' => $contact->id, 'sla_id' => $sla->id])->assertOk()
            ->assertJsonPath('data.client_id', $client->id)->assertJsonPath('data.site_id', $site->id)->assertJsonPath('data.sla_id', $sla->id);
        $this->assertSame($client->id, $ticket->refresh()->workContext->client_id);
    }

    public function test_acknowledging_one_reply_preserves_other_unread_customer_replies_and_retries(): void
    {
        $ticket = $this->ticket();
        $ticket->forceFill(['is_unread' => true])->save();
        $first = $this->reply($ticket);
        $second = $this->reply($ticket);
        Sanctum::actingAs($this->tech, ['tickets.read-state.write']);
        $url = route('api.v1.tickets.messages.read-state.store', [$ticket, $first]);
        $this->postJson($url)->assertOk()->assertJsonPath('data.is_unread', true);
        $this->postJson($url)->assertOk();
        $this->assertNotNull($first->refresh()->read_at);
        $this->assertNull($second->refresh()->read_at);
        $this->assertSame(1, $ticket->events()->where('type', 'message_marked_read')->count());
        $this->postJson(route('api.v1.tickets.messages.read-state.store', [$this->ticket(), $first]))->assertNotFound();
        $this->postJson(route('api.v1.tickets.read-state.store', $ticket))->assertOk()->assertJsonPath('data.is_unread', false);
        $this->assertNotNull($second->refresh()->read_at);
    }

    public function test_assignment_preview_has_no_mutation_and_retry_does_not_repeat_rule_hits(): void
    {
        $ticket = $this->ticket();
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $rule = TicketAssignmentRule::create(['name' => 'API assignment', 'weight' => 1, 'conditions_json' => [], 'action_type' => 'assign_user', 'action_value' => $owner->id, 'is_active' => true]);
        $events = TicketEvent::count();
        Sanctum::actingAs($this->tech, ['tickets.assign']);
        $this->getJson(route('api.v1.tickets.assignment.preview', $ticket))->assertOk()->assertJsonPath('data.planned_owner_id', $owner->id);
        $this->assertSame($events, TicketEvent::count());
        $this->assertSame($this->tech->id, $ticket->refresh()->owner_id);
        $payload = ['idempotency_key' => 'assignment-one'];
        $this->postJson(route('api.v1.tickets.assignment.store', $ticket), $payload)->assertOk()->assertJsonPath('data.owner_id', $owner->id);
        $this->postJson(route('api.v1.tickets.assignment.store', $ticket), $payload)->assertOk();
        $this->assertSame(1, $rule->refresh()->hit_count);
    }

    public function test_delete_requires_current_preview_and_has_independent_deleted_state_readback(): void
    {
        $ticket = $this->ticket();
        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.delete']);
        $state = route('api.v1.tickets.lifecycle-state.show', $ticket);
        $url = route('api.v1.tickets.destroy', $ticket);
        $snapshot = $this->getJson($state)->assertOk()->json('data.snapshot');
        $ticket->forceFill(['subject' => 'Changed after preview'])->save();
        $this->deleteJson($url, ['expected_snapshot' => $snapshot])->assertUnprocessable();
        $snapshot = $this->getJson($state)->assertOk()->json('data.snapshot');
        $this->deleteJson($url, ['expected_snapshot' => $snapshot])->assertOk();
        $this->deleteJson($url, ['expected_snapshot' => $snapshot])->assertOk();
        $this->getJson($state)->assertOk()->assertJsonPath('data.id', $ticket->id);
        $this->assertNotNull($ticket->refresh()->deleted_at);
        $this->assertSame(1, TicketEvent::where('ticket_id', $ticket->id)->where('type', 'deleted')->count());
        $this->getJson(route('api.v1.tickets.show', $ticket))->assertOk()->assertJsonPath('data.id', $ticket->id);
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['subject' => 'Forbidden archived edit'])->assertNotFound();
    }

    public function test_not_ticket_retains_existing_readiness_gate_without_partial_delete(): void
    {
        $ticket = $this->ticket();
        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.classify']);
        $snapshot = $this->getJson(route('api.v1.tickets.lifecycle-state.show', $ticket))->assertOk()->json('data.snapshot');
        $this->postJson(route('api.v1.tickets.not-ticket', $ticket), ['expected_snapshot' => $snapshot])->assertUnprocessable();
        $this->assertNull($ticket->refresh()->deleted_at);
        $this->assertSame(0, $ticket->events()->where('type', 'marked_not_ticket')->count());
    }

    public function test_legacy_ticket_crud_requires_active_actor_rights_even_with_wildcard_token(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($actor, ['*']);
        $this->getJson(route('api.v1.tickets.index'))->assertForbidden();
        $this->postJson(route('api.v1.tickets.store'), ['subject' => 'Denied'])->assertForbidden();
    }

    public function test_cross_client_merge_requires_no_preview_and_reconciles_tasks_without_duplicate_effects(): void
    {
        $oldClient = Client::factory()->create(['active' => true]);
        $newClient = Client::factory()->create(['active' => true]);
        $source = app(StoreTicket::class)->handle(['subject' => 'Source across clients', 'client_id' => $oldClient->id], $this->tech);
        $target = app(StoreTicket::class)->handle(['subject' => 'Target across clients', 'client_id' => $newClient->id], $this->tech);
        $task = app(\App\Modules\Task\Actions\StoreTask::class)->handle(['title' => 'Moved Task', 'client_id' => $oldClient->id], $this->tech, $source);
        $actual = \App\Modules\Task\Models\TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->tech->id, 'source_type' => 'manual', 'work_date' => now()->toDateString(), 'minutes' => 17, 'billable' => false]);
        Sanctum::actingAs($this->tech, ['tickets.merge', 'tickets.read', 'tasks.read']);
        $payload = ['target_ticket_id' => $target->id, 'source_ticket_ids' => [$source->id], 'idempotency_key' => 'merge-across-clients'];
        $this->postJson(route('api.v1.tickets.merge.store'), $payload)->assertOk()->assertJsonPath('data.target_ticket_id', $target->id);
        $task->refresh();
        $this->assertSame($target->id, $task->owner_id);
        $this->assertSame($newClient->id, $task->client_id);
        $this->assertSame($target->work_context_id, $task->work_context_id);
        $this->assertSame(17, $actual->refresh()->minutes);
        Permission::findOrCreate('task.view', 'web');
        $this->tech->givePermissionTo('task.view');
        $this->getJson(route('api.v1.tasks.show', $task))->assertOk()->assertJsonPath('data.owner_id', $target->id);
        $events = TicketEvent::count();
        $messages = TicketMessage::count();
        $this->postJson(route('api.v1.tickets.merge.store'), $payload)->assertOk();
        $this->assertSame($events, TicketEvent::count());
        $this->assertSame($messages, TicketMessage::count());
        $this->postJson(route('api.v1.tickets.merge.store'), array_replace($payload, ['reason' => 'Changed reason']))->assertStatus(409);
        $this->getJson(route('api.v1.tickets.lifecycle-state.show', $source))->assertOk()->assertJsonPath('data.merged_into_ticket_id', $target->id);
    }

    public function test_ticket_client_change_reconciles_existing_task_customer_context(): void
    {
        $first = Client::factory()->create(['active' => true]);
        $second = Client::factory()->create(['active' => true]);
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Change linked Ticket customer', 'client_id' => $first->id], $this->tech);
        $task = app(\App\Modules\Task\Actions\StoreTask::class)->handle(['title' => 'Existing Task', 'client_id' => $first->id], $this->tech, $ticket);
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['client_id' => $second->id])->assertOk();
        $this->assertSame($second->id, $task->refresh()->client_id);
        $this->assertSame($ticket->refresh()->work_context_id, $task->work_context_id);
    }

    public function test_merge_rejects_missing_authority_self_merge_and_foreign_request_key_reuse(): void
    {
        $a = $this->ticket();
        $b = $this->ticket();
        $payload = ['target_ticket_id' => $a->id, 'source_ticket_ids' => [$b->id], 'idempotency_key' => 'scoped-merge'];
        $this->postJson(route('api.v1.tickets.merge.store'), $payload)->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.merge']);
        $this->postJson(route('api.v1.tickets.merge.store'), array_replace($payload, ['source_ticket_ids' => [$a->id]]))->assertUnprocessable();
        $this->tech->revokePermissionTo('ticket.update');
        $this->postJson(route('api.v1.tickets.merge.store'), $payload)->assertForbidden();
        $this->assertFalse($b->refresh()->trashed());
    }

    public function test_cross_client_merge_preserves_booked_allocation_customer_and_detaches_only_unallocated_foreign_contracts(): void
    {
        $old = Client::factory()->create(['active' => true]);
        $new = Client::factory()->create(['active' => true]);
        $source = app(StoreTicket::class)->handle(['subject' => 'Financial merge source', 'client_id' => $old->id], $this->tech);
        $target = app(StoreTicket::class)->handle(['subject' => 'Financial merge target', 'client_id' => $new->id], $this->tech);
        $closed = \App\Modules\Ticket\Models\TicketStatus::where('slug', 'closed')->firstOrFail();
        $target->forceFill(['status_id' => $closed->id])->save();
        $contract = \App\Modules\Commercial\Models\Contracts\Contracts::create(['client_id' => $old->id, 'created_by' => $this->tech->id, 'description' => 'Original client contract', 'approval_status' => 'won', 'start_date' => now()->startOfMonth()->toDateString(), 'end_date' => now()->endOfYear()->toDateString()]);
        $attributes = ['ticket_id' => $source->id, 'user_id' => $this->tech->id, 'work_date' => now()->toDateString(), 'minutes' => 15, 'billable' => true, 'billing_status' => 'pending',
            'timebank_status' => 'pending', 'billing_basis' => 'contract', 'contract_id' => $contract->id, 'invoice_text' => 'Recorded work', 'rate_amount_ex_vat' => 800, 'rate_currency' => 'NOK'];
        $unallocated = \App\Modules\Ticket\Models\TicketTimeEntry::create($attributes);
        $allocated = \App\Modules\Ticket\Models\TicketTimeEntry::create($attributes);
        $allocation = \App\Modules\Ticket\Models\TicketTimeEntryAllocation::create(['ticket_time_entry_id' => $allocated->id, 'ticket_id' => $source->id, 'client_id' => $old->id, 'contract_id' => $contract->id,
            'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(), 'included_minutes' => 0, 'covered_minutes' => 0, 'billable_minutes' => 15, 'status' => 'billable']);
        Sanctum::actingAs($this->tech, ['tickets.merge']);
        $this->postJson(route('api.v1.tickets.merge.store'), ['target_ticket_id' => $target->id, 'source_ticket_ids' => [$source->id], 'idempotency_key' => 'financial-context'])->assertOk();
        $this->assertNull($unallocated->refresh()->contract_id);
        $this->assertSame('without_contract', $unallocated->billing_basis);
        $this->assertSame('800.00', $unallocated->rate_amount_ex_vat);
        $this->assertSame($contract->id, $allocated->refresh()->contract_id);
        $this->assertSame($old->id, $allocation->refresh()->client_id);
        $this->assertSame($target->id, $allocation->ticket_id);
        $this->assertSame(0, \App\Modules\Economy\Models\EconomyOrderLine::count());
        app(\App\Modules\Economy\Actions\GenerateOrders::class)->handle(now()->startOfMonth(), now()->endOfMonth(), $this->tech);
        $line = \App\Modules\Economy\Models\EconomyOrderLine::where('source_type', $allocated->getMorphClass())->where('source_id', $allocated->id)->firstOrFail();
        $this->assertSame($old->id, $line->client_id);
        $this->assertSame($old->id, $line->order->client_id);
        $newLine = \App\Modules\Economy\Models\EconomyOrderLine::where('source_type', $unallocated->getMorphClass())->where('source_id', $unallocated->id)->firstOrFail();
        $this->assertSame($new->id, $newLine->client_id);
    }

    public function test_merge_moves_active_reservation_ownership_without_repeating_stock_movement(): void
    {
        Permission::findOrCreate('storage.reserve', 'web');
        $this->tech->givePermissionTo('storage.reserve');
        $source = $this->ticket();
        $target = $this->ticket();
        $warehouse = \App\Modules\Storage\Models\Warehouse::create(['name' => 'Merge warehouse', 'code' => 'MERGE']);
        $item = \App\Modules\Storage\Models\Item::create(['warehouse_id' => $warehouse->id, 'sku' => 'MERGE-ITEM', 'name' => 'Reserved part', 'sale_price' => 99, 'qty_on_hand' => 10, 'qty_reserved' => 0, 'can_be_ordered' => false, 'status' => 'active']);
        $entry = app(\App\Modules\Ticket\Actions\ReserveTicketStorageItem::class)->handle($source, $item, ['quantity' => 2], $this->tech);
        $movementCount = \App\Modules\Storage\Models\Movement::count();
        Sanctum::actingAs($this->tech, ['tickets.merge', 'tickets.costs.read']);
        $payload = ['target_ticket_id' => $target->id, 'source_ticket_ids' => [$source->id], 'idempotency_key' => 'stock-owner-merge'];
        $this->postJson(route('api.v1.tickets.merge.store'), $payload)->assertOk();
        $this->postJson(route('api.v1.tickets.merge.store'), $payload)->assertOk();
        $this->assertSame($target->id, $entry->refresh()->ticket_id);
        $this->assertSame((string) $target->id, (string) $entry->reservation->source_id);
        $this->assertSame('active', $entry->reservation->status);
        $this->assertSame(10, $item->refresh()->qty_on_hand);
        $this->assertSame(2, $item->qty_reserved);
        $this->assertSame($movementCount, \App\Modules\Storage\Models\Movement::count());
        $this->getJson(route('api.v1.tickets.cost-entries.show', [$target, $entry]))->assertOk()->assertJsonPath('data.ticket_id', $target->id);
    }

    private function ticket(): Ticket
    {
        return app(StoreTicket::class)->handle(['subject' => 'API lifecycle Ticket', 'client_id' => null, 'owner_id' => $this->tech->id], $this->tech);
    }

    private function reply(Ticket $ticket): TicketMessage
    {
        return $ticket->messages()->create(['author_type' => 'contact', 'type' => 'customer_reply', 'visibility' => 'public', 'body' => 'Customer reply']);
    }

    private function client(): array
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create(['client_site_id' => $site->id, 'active' => true, 'email' => 'client@example.test']);

        return [$client, $site, $contact];
    }
}
