<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderTicketScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private PurchaseOrder $order;

    private Ticket $ticket;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        Permission::findOrCreate('storage.purchase_view', 'web');
        Permission::findOrCreate('ticket.view', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');

        $warehouse = Warehouse::query()->create([
            'name' => 'Scoped Warehouse',
            'code' => 'SCOPE',
            'is_active' => true,
        ]);
        $vendor = Vendor::query()->create([
            'name' => 'Scoped Supplier',
            'vendor_code' => 'SCOPE-SUP',
            'is_vendor' => true,
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $item = Item::query()->create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'SCOPE-ITEM',
            'name' => 'Scoped Item',
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);
        $this->ticket = Ticket::factory()->create([
            'subject' => 'Restricted customer incident',
            'ticket_key' => 'TD-2026-SCOPED',
        ]);
        $this->order = PurchaseOrder::query()->create([
            'po_number' => 'PO-SCOPED-TICKET',
            'vendor_id' => $vendor->id,
            'supplier_name_snapshot' => $vendor->name,
            'deliver_to_warehouse_id' => $warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'status_changed_at' => now(),
            'status_changed_by' => $this->actor->id,
            'ordered_at' => now()->toDateString(),
            'currency' => 'NOK',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $this->order->lines()->create([
            'item_id' => $item->id,
            'item_name_snapshot' => $item->name,
            'sku_snapshot' => $item->sku,
            'ticket_id' => $this->ticket->id,
            'qty_ordered' => 1,
            'qty_received' => 0,
            'qty_cancelled' => 0,
            'currency' => 'NOK',
            'metadata' => [
                'kind' => 'ticket_purchase_need',
                'ticket_key' => $this->ticket->ticket_key,
                'approved_quote_version_id' => 987,
                'vendor_order_sent' => false,
            ],
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    #[Test]
    public function storage_read_scope_hides_ticket_identifiers_until_ticket_read_is_granted(): void
    {
        Sanctum::actingAs($this->actor, ['storage.purchase.read']);

        $this->getJson(route('api.v1.storage.purchase-orders.show', $this->order))
            ->assertOk()
            ->assertJsonMissingPath('data.lines.0.ticket_id')
            ->assertJsonMissingPath('data.lines.0.ticket_planned_line_id')
            ->assertJsonMissingPath('data.lines.0.ticket')
            ->assertJsonMissingPath('data.lines.0.metadata.ticket_key')
            ->assertJsonMissingPath('data.lines.0.metadata.approved_quote_version_id')
            ->assertJsonPath('data.lines.0.metadata.kind', 'ticket_purchase_need')
            ->assertJsonPath('data.lines.0.metadata.vendor_order_sent', false);

        Sanctum::actingAs($this->actor, ['storage.purchase.read', 'tickets.read']);

        $this->getJson(route('api.v1.storage.purchase-orders.show', $this->order))
            ->assertOk()
            ->assertJsonPath('data.lines.0.ticket_id', $this->ticket->id)
            ->assertJsonPath('data.lines.0.ticket.ticket_key', $this->ticket->ticket_key)
            ->assertJsonPath('data.lines.0.ticket.subject', $this->ticket->subject)
            ->assertJsonPath('data.lines.0.metadata.ticket_key', $this->ticket->ticket_key)
            ->assertJsonPath('data.lines.0.metadata.approved_quote_version_id', 987);
    }

    #[Test]
    public function purchase_order_page_requires_ticket_view_before_showing_ticket_identity(): void
    {
        $this->actor->givePermissionTo('storage.purchase_view');

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-orders.show', $this->order))
            ->assertOk()
            ->assertSee('Linked')
            ->assertDontSee($this->ticket->ticket_key)
            ->assertDontSee($this->ticket->subject)
            ->assertDontSee(route('tech.tickets.show', $this->ticket), false);

        $this->actor->givePermissionTo('ticket.view');

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-orders.show', $this->order))
            ->assertOk()
            ->assertSee($this->ticket->ticket_key)
            ->assertSee(route('tech.tickets.show', $this->ticket), false);
    }
}
