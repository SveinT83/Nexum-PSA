<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierOrderWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private Vendor $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        foreach ([
            'storage.view',
            'storage.purchase_view',
            'storage.purchase_manage',
            'storage.purchase_import_view',
            'storage.purchase_receive',
            'storage.pick',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->supplier = Vendor::query()->create([
            'name' => 'Unified Supplier',
            'vendor_code' => 'UNIFIED',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::query()->create([
            'name' => 'Unified Warehouse',
            'code' => 'UNIFIED',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function full_procurement_user_sees_import_order_and_receiving_in_one_deduplicated_list(): void
    {
        $user = $this->userWithPermissions([
            'storage.view',
            'storage.purchase_view',
            'storage.purchase_import_view',
            'storage.purchase_receive',
        ]);
        $order = $this->purchaseOrder('PO-UNIFIED-100', 10, 2);
        $linkedImport = $this->supplierImport('linked-source', [
            'purchase_order_id' => $order->id,
            'external_order_number' => 'SUP-UNIFIED-100',
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
        ]);
        $incomingImport = $this->supplierImport('incoming-source', [
            'external_order_number' => 'SUP-UNIFIED-200',
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'stage' => PurchaseOrderImport::STAGE_ITEM_RESOLUTION,
            'reason_code' => 'item_unresolved',
        ]);

        $response = $this->actingAs($user)->get(route('tech.storage.purchase-orders.index', [
            'sort' => 'order',
            'direction' => 'asc',
        ]));

        $response->assertOk()
            ->assertViewIs('storage::Tech.Storage.purchase-orders.index')
            ->assertViewHas('supplierOrders', fn ($rows): bool => $rows->total() === 2)
            ->assertSee('Supplier Orders')
            ->assertSee('PO-UNIFIED-100')
            ->assertSee('Import #'.$linkedImport->id)
            ->assertSee('Import #'.$incomingImport->id)
            ->assertSee(route('tech.storage.purchase-orders.receive', $order), false)
            ->assertSee('Imports never update stock')
            ->assertSee('<span>Supplier Orders</span>', false)
            ->assertDontSee('<span>Purchase Orders</span>', false)
            ->assertDontSee('<span>Supplier Order Imports</span>', false)
            ->assertDontSee('<span>Receiving</span>', false);

        $this->assertSame(1, substr_count($response->getContent(), 'PO-UNIFIED-100'));
        $this->assertSame(1, substr_count($response->getContent(), 'Import #'.$linkedImport->id));

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-orders.index', ['scope' => 'receiving']))
            ->assertOk()
            ->assertViewHas('supplierOrders', fn ($rows): bool => $rows->total() === 1)
            ->assertSee('PO-UNIFIED-100')
            ->assertDontSee('Import #'.$incomingImport->id);

        foreach ([
            'tech.storage.purchase-order-imports.index',
            'tech.storage.receiving.index',
        ] as $routeName) {
            $this->actingAs($user)
                ->get(route($routeName))
                ->assertOk()
                ->assertViewIs('storage::Tech.Storage.purchase-orders.index')
                ->assertSee('Supplier Orders');
        }
    }

    #[Test]
    public function import_only_user_keeps_import_audit_rows_without_gaining_purchase_order_access(): void
    {
        $user = $this->userWithPermissions([
            'storage.view',
            'storage.purchase_import_view',
        ]);
        $order = $this->purchaseOrder('PO-HIDDEN-100', 4, 0);
        $linkedImport = $this->supplierImport('linked-hidden-source', [
            'purchase_order_id' => $order->id,
            'external_order_number' => 'SUP-HIDDEN-100',
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
        ]);
        $incomingImport = $this->supplierImport('incoming-visible-source', [
            'external_order_number' => 'SUP-VISIBLE-200',
            'status' => PurchaseOrderImport::STATUS_PENDING,
        ]);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-order-imports.index'))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.purchase-orders.index')
            ->assertViewHas('supplierOrders', fn ($rows): bool => $rows->total() === 2)
            ->assertSee('Import #'.$linkedImport->id)
            ->assertSee('Import #'.$incomingImport->id)
            ->assertDontSee('PO-HIDDEN-100')
            ->assertDontSee(route('tech.storage.purchase-orders.show', $order), false)
            ->assertDontSee('>Purchase Orders<', false)
            ->assertDontSee('>Receiving<', false);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-orders.index'))
            ->assertForbidden();
    }

    #[Test]
    public function receiving_only_user_sees_receivable_orders_without_other_procurement_access(): void
    {
        $user = $this->userWithPermissions([
            'storage.view',
            'storage.purchase_receive',
        ]);
        $openOrder = $this->purchaseOrder('PO-RECEIVE-OPEN', 8, 2);
        $completedOrder = $this->purchaseOrder('PO-RECEIVE-COMPLETE', 5, 5);
        $this->supplierImport('hidden-incoming-source', [
            'external_order_number' => 'SUP-HIDDEN-INCOMING',
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
        ]);

        $this->actingAs($user)
            ->get(route('tech.storage.receiving.index'))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.purchase-orders.index')
            ->assertViewHas('supplierOrders', fn ($rows): bool => $rows->total() === 1)
            ->assertSee('PO-RECEIVE-OPEN')
            ->assertSee(route('tech.storage.purchase-orders.receive', $openOrder), false)
            ->assertDontSee('PO-RECEIVE-COMPLETE')
            ->assertDontSee('SUP-HIDDEN-INCOMING')
            ->assertDontSee('href="'.route('tech.storage.purchase-orders.show', $openOrder).'"', false)
            ->assertDontSee(route('tech.storage.purchase-orders.control-slip', $openOrder), false)
            ->assertDontSee(route('tech.storage.purchase-orders.receive', $completedOrder), false);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-orders.index'))
            ->assertForbidden();
    }

    private function userWithPermissions(array $permissions): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function purchaseOrder(string $number, int $ordered, int $received): PurchaseOrder
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $order = PurchaseOrder::query()->create([
            'po_number' => $number,
            'vendor_id' => $this->supplier->id,
            'supplier_name_snapshot' => $this->supplier->name,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => $received > 0
                ? PurchaseOrder::STATUS_PARTIALLY_RECEIVED
                : PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => today(),
            'expected_at' => today()->addDay(),
            'currency' => 'NOK',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $item = Item::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'sku' => 'UNIFIED-ITEM-'.$order->id,
            'name' => 'Unified item',
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'reorder_point' => 0,
            'target_level' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);

        PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'item_name_snapshot' => $item->name,
            'sku_snapshot' => $item->sku,
            'qty_ordered' => $ordered,
            'qty_received' => $received,
            'qty_cancelled' => 0,
            'unit_cost' => 100,
            'tax_rate' => 25,
            'currency' => 'NOK',
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $order;
    }

    private function supplierImport(string $source, array $attributes): PurchaseOrderImport
    {
        return PurchaseOrderImport::query()->create(array_merge([
            'source_domain' => 'email',
            'source_type' => 'email_message',
            'source_id' => $source,
            'source_action_hash' => hash('sha256', 'action-'.$source),
            'source_fingerprint' => hash('sha256', 'source-'.$source),
            'safe_source_snapshot' => ['source' => 'email'],
            'vendor_id' => $this->supplier->id,
            'status' => PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
            'attempt_count' => 1,
        ], $attributes));
    }
}
