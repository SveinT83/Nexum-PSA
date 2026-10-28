<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Integration\Services\AiAgentResolver;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Ticket\Actions\DraftTicketTimeInvoiceText;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\ReserveTicketStorageItem;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketLedgerApiTest extends TestCase
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
        foreach (['ticket.view', 'ticket.update', 'ticket.register_time', 'storage.reserve', 'storage.pick'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
    }

    public function test_time_update_uses_authoritative_rate_and_exact_nested_readback(): void
    {
        $ticket = $this->ticket();
        $entry = $ticket->timeEntries()->create(['user_id' => $this->tech->id, 'type' => 'manual', 'work_date' => '2026-10-19', 'minutes' => 15, 'billing_status' => 'pending', 'timebank_status' => 'pending']);
        $rate = TimeRate::create(['name' => 'API hourly rate', 'slug' => 'api-hourly-rate', 'code' => 'API-RATE', 'rate_type' => 'hourly', 'unit' => 'hour', 'amount_ex_vat' => 999, 'currency' => 'NOK', 'applies_without_contract' => true, 'is_active' => true]);
        $payload = ['work_date' => '2026-10-20', 'minutes' => 25, 'rate_key' => 'global:'.$rate->id, 'invoice_text' => 'Verified work', 'note' => 'Internal detail'];
        Sanctum::actingAs($this->tech, ['tickets.time.update', 'tickets.time.read']);
        $url = route('api.v1.tickets.time-entries.update', [$ticket, $entry]);
        $this->patchJson($url, $payload)->assertOk()->assertJsonPath('data.minutes', 25)->assertJsonPath('data.rate_name', $rate->name)->assertJsonPath('data.billing_status', 'pending');
        $this->getJson(route('api.v1.tickets.time-entries.show', [$ticket, $entry]))->assertOk()->assertJsonPath('data.invoice_text', 'Verified work');
        $this->assertSame(1, TicketTimeEntry::count());
        $this->patchJson(route('api.v1.tickets.time-entries.update', [$this->ticket(), $entry]), $payload)->assertNotFound();
        $payload['rate_key'] = 'global:999999';
        $this->patchJson($url, $payload)->assertUnprocessable()->assertJsonValidationErrors('rate_key');
    }

    public function test_time_scope_does_not_replace_domain_permission(): void
    {
        $ticket = $this->ticket();
        $entry = $ticket->timeEntries()->create(['user_id' => $this->tech->id, 'type' => 'manual', 'minutes' => 5]);
        Sanctum::actingAs($this->tech, ['tickets.time.read']);
        $this->patchJson(route('api.v1.tickets.time-entries.update', [$ticket, $entry]), [])->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.time.update']);
        $this->tech->revokePermissionTo('ticket.register_time');
        $this->patchJson(route('api.v1.tickets.time-entries.update', [$ticket, $entry]), [])->assertForbidden();
    }

    public function test_reservation_update_release_and_zero_quantity_follow_existing_lifecycle(): void
    {
        [$ticket, $item, $entry] = $this->reservation();
        Sanctum::actingAs($this->tech, ['tickets.costs.update', 'tickets.costs.release']);
        $this->patchJson(route('api.v1.tickets.cost-entries.update', [$ticket, $entry]), ['quantity' => 3, 'invoice_text' => 'Three items'])->assertOk()->assertJsonPath('data.quantity', 3);
        $this->assertSame(3, $item->refresh()->qty_reserved);
        $url = route('api.v1.tickets.cost-entries.release', [$ticket, $entry]);
        $this->deleteJson($url)->assertOk()->assertJsonPath('data.status', 'released')->assertJsonPath('data.billing_status', 'cancelled');
        $count = TicketEvent::count();
        $this->deleteJson($url)->assertOk();
        $this->assertSame($count, TicketEvent::count());
        $this->assertSame(0, $item->refresh()->qty_reserved);
        $this->assertSame('released', $entry->refresh()->reservation->status);
        [$other, $otherItem, $otherEntry] = $this->reservation();
        $this->patchJson(route('api.v1.tickets.cost-entries.update', [$other, $otherEntry]), ['quantity' => 0])->assertOk()->assertJsonPath('data.status', 'released');
        $this->assertSame(0, $otherItem->refresh()->qty_reserved);
        $this->deleteJson(route('api.v1.tickets.cost-entries.release', [$ticket, $otherEntry]))->assertNotFound();
    }

    public function test_pick_preview_is_required_actor_bound_current_and_pick_is_idempotent(): void
    {
        [$ticket, $item, $entry] = $this->reservation();
        $this->mock(GenerateOrders::class)->shouldReceive('handle')->once();
        Sanctum::actingAs($this->tech, ['tickets.costs.pick']);
        $preview = route('api.v1.tickets.cost-entries.pick-preview', [$ticket, $entry]);
        $pick = route('api.v1.tickets.cost-entries.pick', [$ticket, $entry]);
        $before = TicketEvent::count();
        $token = $this->getJson($preview)->assertOk()->assertJsonPath('data.allowed', true)->json('data.preview_token');
        $this->assertSame($before, TicketEvent::count());
        $this->postJson($pick)->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $item->forceFill(['qty_on_hand' => 9])->save();
        $this->postJson($pick, ['preview_token' => $token])->assertUnprocessable()->assertJsonValidationErrors('preview_token');
        $token = $this->getJson($preview)->assertOk()->json('data.preview_token');
        $this->postJson($pick, ['preview_token' => $token])->assertOk()->assertJsonPath('data.status', 'picked');
        $this->postJson($pick, ['preview_token' => $token])->assertOk();
        $this->assertSame(7, $item->refresh()->qty_on_hand);
        $this->assertSame(0, $item->qty_reserved);
        $this->assertSame(1, Movement::where('type', 'ticket_pick')->count());
        $this->assertSame('fulfilled', $entry->refresh()->reservation->status);
    }

    public function test_pick_previews_enforce_storage_permission_stock_and_scope(): void
    {
        [$ticket, $item, $entry] = $this->reservation();
        $url = route('api.v1.tickets.cost-entries.pick-preview', [$ticket, $entry]);
        Sanctum::actingAs($this->tech, ['tickets.costs.read']);
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.costs.pick']);
        $item->forceFill(['qty_on_hand' => 0])->save();
        $this->getJson($url)->assertOk()->assertJsonPath('data.allowed', false)->assertJsonPath('data.preview_token', null);
        $this->tech->revokePermissionTo('storage.pick');
        $this->getJson($url)->assertForbidden();
    }

    public function test_invoice_suggestion_calls_shared_action_without_creating_time(): void
    {
        $ticket = $this->ticket();
        $this->mock(DraftTicketTimeInvoiceText::class)->shouldReceive('handle')->once()->andReturn(['text' => 'Driver configuration']);
        Sanctum::actingAs($this->tech, ['tickets.time.suggest', 'tickets.messages.read', 'tickets.read']);
        $this->postJson(route('api.v1.tickets.time-entries.invoice-text-suggestion', $ticket), ['existing_text' => 'Drivers'])->assertOk()->assertJsonPath('data.text', 'Driver configuration');
        $this->assertSame(0, TicketTimeEntry::count());
    }

    public function test_browser_and_api_invoice_suggestion_share_missing_agent_validation(): void
    {
        $ticket = $this->ticket();
        $this->mock(AiAgentResolver::class)->shouldReceive('defaultAgent')->twice()->andReturn(null);
        Sanctum::actingAs($this->tech, ['tickets.time.suggest', 'tickets.messages.read', 'tickets.read']);
        $this->postJson(route('api.v1.tickets.time-entries.invoice-text-suggestion', $ticket))->assertUnprocessable()->assertJsonValidationErrors('agent');
        $this->actingAs($this->tech)->postJson(route('tech.tickets.time-entries.draft', $ticket))->assertUnprocessable()->assertJsonValidationErrors('agent');
    }

    private function ticket(): Ticket
    {
        return app(StoreTicket::class)->handle(['subject' => 'Ledger test', 'client_id' => null, 'owner_id' => $this->tech->id], $this->tech);
    }

    private function reservation(): array
    {
        $ticket = $this->ticket();
        $warehouse = Warehouse::create(['name' => 'API warehouse', 'code' => 'API-'.uniqid()]);
        $item = Item::create(['warehouse_id' => $warehouse->id, 'sku' => 'API-'.uniqid(), 'name' => 'API part', 'sale_price' => 99, 'qty_on_hand' => 10, 'qty_reserved' => 0, 'can_be_ordered' => false, 'status' => 'active']);
        $entry = app(ReserveTicketStorageItem::class)->handle($ticket, $item, ['quantity' => 2], $this->tech);

        return [$ticket, $item, $entry];
    }
}
