<?php

namespace App\Modules\Economy\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Controllers\Tech\EconomyController;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Storage\Models\Item as StorageItem;
use App\Modules\Storage\Models\Warehouse as StorageWarehouse;
use App\Modules\Ticket\Actions\PickTicketStorageReservation;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EconomyModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function economy_routes_are_owned_by_economy_module(): void
    {
        $this->assertSame(EconomyController::class . '@index', Route::getRoutes()->getByName('tech.economy.orders.index')->getActionName());
        $this->assertSame(EconomyController::class . '@show', Route::getRoutes()->getByName('tech.economy.orders.show')->getActionName());
        $this->assertSame(EconomyController::class . '@settings', Route::getRoutes()->getByName('tech.economy.settings')->getActionName());
        $this->assertSame(EconomyController::class . '@generate', Route::getRoutes()->getByName('tech.economy.orders.generate')->getActionName());
        $this->assertSame(EconomyController::class . '@markReady', Route::getRoutes()->getByName('tech.economy.orders.ready')->getActionName());
        $this->assertSame(EconomyController::class . '@markDraft', Route::getRoutes()->getByName('tech.economy.orders.draft')->getActionName());
        $this->assertSame(EconomyController::class . '@destroyOrder', Route::getRoutes()->getByName('tech.economy.orders.destroy')->getActionName());
    }

    #[Test]
    public function tech_user_can_open_economy_orders_and_defaults_are_created(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.economy.orders.index'))
            ->assertOk()
            ->assertViewIs('economy::Tech.Orders.index')
            ->assertViewHas('orders');

        $this->assertDatabaseHas('economy_settings', [
            'create_orders_from_closed_ticket_time' => true,
            'create_orders_from_picked_ticket_costs' => true,
        ]);
    }

    #[Test]
    public function closed_without_contract_ticket_time_generates_an_order_line(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $client = Client::create(['name' => 'Acme AS', 'active' => true]);
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999901',
            'subject' => 'Billable work',
            'description' => 'Test',
            'client_id' => $client->id,
            'queue_id' => $defaults['queue']->id,
            'status_id' => $closed->id,
            'priority_id' => $defaults['priority']->id,
            'channel' => 'manual',
        ]);
        $entry = TicketTimeEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'work_date' => now()->toDateString(),
            'minutes' => 30,
            'billable' => true,
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
            'billing_basis' => 'without_contract',
            'invoice_text' => 'Updated driver',
            'rate_name' => 'Without contract',
            'rate_amount_ex_vat' => 1200,
            'rate_currency' => 'NOK',
        ]);

        $summary = app(GenerateOrders::class)->handle(now()->startOfMonth(), now()->endOfMonth(), $this->tech);

        $this->assertSame(1, $summary['lines_created']);
        $this->assertDatabaseHas('economy_orders', ['client_id' => $client->id, 'status' => 'draft']);
        $this->assertDatabaseHas('economy_order_lines', [
            'source_type' => $entry->getMorphClass(),
            'source_id' => $entry->id,
            'line_type' => 'ticket_time',
            'quantity' => 30,
            'line_total_ex_vat' => 600,
        ]);
        $this->assertSame('queued', $entry->refresh()->billing_status);
        $this->assertSame('600.00', EconomyOrder::query()->first()->subtotal_ex_vat);
    }

    #[Test]
    public function picking_ticket_cost_consumes_stock_and_generates_an_order_line(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $client = Client::create(['name' => 'Cost Client AS', 'active' => true]);
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999902',
            'subject' => 'Needs part',
            'description' => 'Test',
            'client_id' => $client->id,
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'channel' => 'manual',
        ]);
        $warehouse = StorageWarehouse::create(['name' => 'Main', 'code' => 'MAIN']);
        $item = StorageItem::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'USB-C-001',
            'name' => 'USB-C Cable',
            'short_description' => 'USB-C cable',
            'sale_price' => 100,
            'vat_rate' => 25,
            'qty_on_hand' => 5,
            'qty_reserved' => 2,
            'status' => 'active',
        ]);
        $costEntry = TicketCostEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'storage_item_id' => $item->id,
            'quantity' => 2,
            'item_name' => $item->name,
            'item_sku' => $item->sku,
            'unit_price_ex_vat' => 100,
            'currency' => 'NOK',
            'status' => 'reserved',
            'billing_status' => 'pending',
            'invoice_text' => 'USB-C cable',
        ]);

        app(PickTicketStorageReservation::class)->handle($ticket, $costEntry, $this->tech);

        $this->assertDatabaseHas('storage_items', [
            'id' => $item->id,
            'qty_on_hand' => 3,
            'qty_reserved' => 0,
        ]);
        $this->assertDatabaseHas('ticket_cost_entries', [
            'id' => $costEntry->id,
            'status' => 'picked',
            'billing_status' => 'queued',
        ]);
        $this->assertDatabaseHas('economy_order_lines', [
            'source_type' => $costEntry->getMorphClass(),
            'source_id' => $costEntry->id,
            'line_type' => 'ticket_cost',
            'line_total_ex_vat' => 200,
            'vat_amount' => 50,
            'total_inc_vat' => 250,
        ]);
    }

    #[Test]
    public function contract_timebank_covers_time_before_billable_order_line_is_created(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $client = Client::create(['name' => 'Contract Client AS', 'active' => true]);
        $service = Services::create([
            'name' => 'Managed support',
            'sku' => 'MSP',
            'status' => 'active',
            'unitId' => 1,
            'taxable' => 25,
            'timebank_enabled' => true,
            'timebank_minutes' => 60,
            'timebank_interval' => 'monthly',
            'created_by_user_id' => $this->tech->id,
        ]);
        $contract = Contracts::create([
            'client_id' => $client->id,
            'created_by' => $this->tech->id,
            'description' => 'Active contract',
            'approval_status' => 'won',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(),
        ]);
        $contractItem = ContractItem::create([
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'name' => 'Managed support',
            'sku' => 'MSP',
            'unit_price' => 1000,
            'quantity' => 1,
            'unit' => 'month',
            'billing_interval' => 'monthly',
        ]);
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999903',
            'subject' => 'Contract work',
            'description' => 'Test',
            'client_id' => $client->id,
            'queue_id' => $defaults['queue']->id,
            'status_id' => $closed->id,
            'priority_id' => $defaults['priority']->id,
            'channel' => 'manual',
        ]);
        $covered = TicketTimeEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'work_date' => now()->toDateString(),
            'minutes' => 45,
            'billable' => true,
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
            'billing_basis' => 'contract',
            'invoice_text' => 'Covered support',
            'contract_id' => $contract->id,
            'contract_item_id' => $contractItem->id,
            'rate_name' => 'Contract time',
            'rate_amount_ex_vat' => 650,
            'rate_currency' => 'NOK',
        ]);
        $partial = TicketTimeEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'work_date' => now()->toDateString(),
            'minutes' => 30,
            'billable' => true,
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
            'billing_basis' => 'contract',
            'invoice_text' => 'Overage support',
            'contract_id' => $contract->id,
            'contract_item_id' => $contractItem->id,
            'rate_name' => 'Contract time',
            'rate_amount_ex_vat' => 600,
            'rate_currency' => 'NOK',
        ]);

        app(GenerateOrders::class)->handle(now()->startOfMonth(), now()->endOfMonth(), $this->tech);

        $this->assertSame('covered', $covered->refresh()->billing_status);
        $this->assertSame('queued', $partial->refresh()->billing_status);
        $this->assertDatabaseHas('ticket_time_entry_allocations', [
            'ticket_time_entry_id' => $covered->id,
            'included_minutes' => 60,
            'covered_minutes' => 45,
            'billable_minutes' => 0,
            'status' => 'covered',
        ]);
        $this->assertDatabaseHas('ticket_time_entry_allocations', [
            'ticket_time_entry_id' => $partial->id,
            'covered_minutes' => 15,
            'billable_minutes' => 15,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('economy_order_lines', [
            'source_type' => $partial->getMorphClass(),
            'source_id' => $partial->id,
            'quantity' => 15,
            'line_total_ex_vat' => 150,
        ]);
    }

    #[Test]
    public function pending_without_contract_time_is_attached_to_active_timebank_contract_before_generation(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $closed = TicketStatus::where('slug', 'closed')->firstOrFail();
        $client = Client::create(['name' => 'Retroactive Contract Client AS', 'active' => true]);
        $service = Services::create([
            'name' => 'Support bank',
            'sku' => 'BANK',
            'status' => 'active',
            'unitId' => 1,
            'taxable' => 25,
            'timebank_enabled' => true,
            'timebank_minutes' => 720,
            'timebank_interval' => 'yearly',
            'created_by_user_id' => $this->tech->id,
        ]);
        $contract = Contracts::create([
            'client_id' => $client->id,
            'created_by' => $this->tech->id,
            'description' => 'Active retroactive contract',
            'approval_status' => 'won',
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
        ]);
        $contractItem = ContractItem::create([
            'contract_id' => $contract->id,
            'service_id' => $service->id,
            'name' => 'Support bank',
            'sku' => 'BANK',
            'unit_price' => 1000,
            'quantity' => 1,
            'unit' => 'year',
            'billing_interval' => 'yearly',
        ]);
        $ticket = Ticket::create([
            'ticket_key' => 'TD-2026-999904',
            'subject' => 'Old snapshot work',
            'description' => 'Test',
            'client_id' => $client->id,
            'queue_id' => $defaults['queue']->id,
            'status_id' => $closed->id,
            'priority_id' => $defaults['priority']->id,
            'channel' => 'manual',
        ]);
        $entry = TicketTimeEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'work_date' => now()->toDateString(),
            'minutes' => 800,
            'billable' => true,
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
            'billing_basis' => 'without_contract',
            'invoice_text' => 'Support work',
            'time_rate_id' => 1,
            'rate_name' => 'Time without contract',
            'rate_type' => 'labor',
            'rate_amount_ex_vat' => 950,
            'rate_currency' => 'NOK',
        ]);

        app(GenerateOrders::class)->handle(now()->startOfMonth(), now()->endOfMonth(), $this->tech);

        $entry->refresh();
        $this->assertSame('contract', $entry->billing_basis);
        $this->assertSame($contract->id, $entry->contract_id);
        $this->assertSame($contractItem->id, $entry->contract_item_id);
        $this->assertDatabaseHas('ticket_time_entry_allocations', [
            'ticket_time_entry_id' => $entry->id,
            'included_minutes' => 720,
            'covered_minutes' => 720,
            'billable_minutes' => 80,
            'status' => 'queued',
        ]);
        $this->assertDatabaseHas('economy_order_lines', [
            'source_type' => $entry->getMorphClass(),
            'source_id' => $entry->id,
            'quantity' => 80,
        ]);
    }

    #[Test]
    public function deleting_order_line_unlocks_ticket_time_for_recalculation(): void
    {
        $this->closed_without_contract_ticket_time_generates_an_order_line();

        $line = EconomyOrderLine::query()->firstOrFail();
        $entry = TicketTimeEntry::findOrFail($line->source_id);
        $order = $line->order;

        $this->actingAs($this->tech)
            ->delete(route('tech.economy.orders.lines.destroy', [$line->order, $line]))
            ->assertRedirect(route('tech.economy.orders.index'));

        $this->assertDatabaseMissing('economy_order_lines', ['id' => $line->id]);
        $this->assertDatabaseMissing('economy_orders', ['id' => $order->id]);
        $this->assertSame('pending', $entry->refresh()->billing_status);
        $this->assertDatabaseMissing('ticket_time_entry_allocations', ['ticket_time_entry_id' => $entry->id]);
    }

    #[Test]
    public function ready_order_can_be_moved_back_to_draft(): void
    {
        $client = Client::create(['name' => 'Ready Client AS', 'active' => true]);
        $order = EconomyOrder::create([
            'client_id' => $client->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'ready',
            'ready_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.economy.orders.draft', $order))
            ->assertRedirect(route('tech.economy.orders.show', $order));

        $order->refresh();
        $this->assertSame('draft', $order->status);
        $this->assertNull($order->ready_at);
    }

    #[Test]
    public function empty_draft_or_ready_order_can_be_deleted(): void
    {
        $client = Client::create(['name' => 'Empty Client AS', 'active' => true]);
        $order = EconomyOrder::create([
            'client_id' => $client->id,
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'status' => 'ready',
            'ready_at' => now(),
        ]);

        $this->actingAs($this->tech)
            ->delete(route('tech.economy.orders.destroy', $order))
            ->assertRedirect(route('tech.economy.orders.index'));

        $this->assertDatabaseMissing('economy_orders', ['id' => $order->id]);
    }
}
