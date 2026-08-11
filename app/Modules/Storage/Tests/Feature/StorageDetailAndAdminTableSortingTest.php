<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\BoxEvent;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderLine;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseReceiptLine;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\PurchaseShipmentLine;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\StorageInventoryDefaults;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StorageDetailAndAdminTableSortingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Admin', 'web');
        Role::findOrCreate('Tech', 'web');

        foreach ([
            'storage.view',
            'storage.item_manage',
            'storage.manage_settings',
            'storage.purchase_view',
            'storage.purchase_manage',
            'storage.purchase_receive',
            'storage.purchase_reverse',
            'ticket.view',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('storage.manage_settings');

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo([
            'storage.view',
            'storage.item_manage',
            'storage.purchase_view',
            'storage.purchase_manage',
            'storage.purchase_receive',
            'storage.purchase_reverse',
            'ticket.view',
        ]);
    }

    #[Test]
    public function warehouse_settings_sort_every_supported_column_without_changing_the_default_order(): void
    {
        $bravo = $this->warehouse('Bravo Warehouse', 'BRAVO', 'Zulu Road', true);
        $alpha = $this->warehouse('Alpha Warehouse', 'CHARLIE', 'Alpha Road', false);
        $charlie = $this->warehouse('Charlie Warehouse', 'ZULU', null, true);

        $this->item($bravo, 'BRAVO-1', 'Bravo One');
        $this->item($bravo, 'BRAVO-2', 'Bravo Two');
        $this->item($charlie, 'CHARLIE-1', 'Charlie One');
        $this->box($alpha, 'ALPHA-BOX-1');
        $this->box($alpha, 'ALPHA-BOX-2');
        $this->box($charlie, 'CHARLIE-BOX-1');
        app(StorageInventoryDefaults::class)->setDefaultWarehouse($bravo);

        $this->assertWarehouseOrder([], [$alpha, $bravo, $charlie]);
        $this->assertWarehouseOrder(['warehouse_sort' => 'forged'], [$alpha, $bravo, $charlie]);

        foreach ([
            'default' => [$alpha, $charlie, $bravo],
            'name' => [$alpha, $bravo, $charlie],
            'code' => [$bravo, $alpha, $charlie],
            'address' => [$alpha, $bravo, $charlie],
            'items' => [$alpha, $charlie, $bravo],
            'boxes' => [$bravo, $charlie, $alpha],
            'status' => [$alpha, $bravo, $charlie],
        ] as $column => $expected) {
            $this->assertWarehouseOrder([
                'warehouse_sort' => $column,
                'warehouse_direction' => 'asc',
            ], $expected);
        }

        $response = $this->actingAs($this->admin)->get(route('tech.admin.settings.storage.inventory', [
            'warehouse_sort' => 'default',
            'warehouse_direction' => 'desc',
        ]));

        $response->assertOk()
            ->assertViewHas('warehouseSort', 'default')
            ->assertSee('aria-sort="descending"', false)
            ->assertSee('Sort by Name ascending')
            ->assertSee('#warehouses', false);
        $this->assertSame(
            [$bravo->id, $alpha->id, $charlie->id],
            $response->viewData('warehouses')->pluck('id')->all()
        );
    }

    #[Test]
    public function movement_history_sorts_every_supported_column_and_keeps_missing_values_last(): void
    {
        $warehouse = $this->warehouse('Main Warehouse', 'MAIN', null, true);
        $item = $this->item($warehouse, 'MOVEMENT', 'Movement Item');
        $alphaActor = User::factory()->create(['name' => 'Alpha Actor', 'status' => User::STATUS_ACTIVE]);
        $zuluActor = User::factory()->create(['name' => 'Zulu Actor', 'status' => User::STATUS_ACTIVE]);
        $first = $this->movement($item, $alphaActor, [
            'type' => 'alpha_type',
            'qty_before' => 1,
            'qty_delta' => 2,
            'qty_after' => 3,
            'reason' => 'Alpha reason',
            'created_at' => '2026-08-01 08:00:00',
        ]);
        $second = $this->movement($item, $zuluActor, [
            'type' => 'zulu_type',
            'qty_before' => 10,
            'qty_delta' => 20,
            'qty_after' => 30,
            'reason' => 'Zulu reason',
            'created_at' => '2026-08-02 08:00:00',
        ]);
        $missingReason = $this->movement($item, null, [
            'type' => 'zz_missing',
            'qty_before' => 40,
            'qty_delta' => 50,
            'qty_after' => 90,
            'reason' => null,
            'created_at' => '2026-08-03 08:00:00',
        ]);

        $default = $this->movementResponse($item);
        $this->assertSame(
            [$missingReason->id, $second->id, $first->id],
            $default->viewData('movements')->pluck('id')->all()
        );

        foreach (['when', 'type', 'before', 'delta', 'after'] as $column) {
            $response = $this->movementResponse($item, $column, 'asc');
            $this->assertSame(
                [$first->id, $second->id, $missingReason->id],
                $response->viewData('movements')->pluck('id')->all(),
                "Unexpected movement order for {$column}."
            );
        }

        $reason = $this->movementResponse($item, 'reason', 'desc');
        $this->assertSame(
            [$second->id, $first->id, $missingReason->id],
            $reason->viewData('movements')->pluck('id')->all()
        );
        $actor = $this->movementResponse($item, 'actor', 'asc');
        $this->assertSame(
            [$first->id, $missingReason->id, $second->id],
            $actor->viewData('movements')->pluck('id')->all()
        );
        $actor->assertSee('Sort by When descending')
            ->assertSee('#movement-history', false);
    }

    #[Test]
    public function box_contents_and_events_sort_independently(): void
    {
        $warehouse = $this->warehouse('Main Warehouse', 'MAIN', null, true);
        $box = $this->box($warehouse, 'BOX-A');
        $alphaItem = $this->item($warehouse, 'ALPHA-1', 'Alpha Item', $box, 1, 2);
        $zuluItem = $this->item($warehouse, 'ZULU-1', 'Zulu Item', $box, 10, 20);
        $alphaActor = User::factory()->create(['name' => 'Alpha Actor', 'status' => User::STATUS_ACTIVE]);
        $zuluActor = User::factory()->create(['name' => 'Zulu Actor', 'status' => User::STATUS_ACTIVE]);
        $firstEvent = $this->boxEvent($box, $alphaActor, 'alpha_event', '2026-08-01 08:00:00');
        $secondEvent = $this->boxEvent($box, $zuluActor, 'zulu_event', '2026-08-02 08:00:00');

        foreach (['sku', 'name', 'on_hand', 'reserved'] as $column) {
            $response = $this->boxResponse($box, [
                'box_item_sort' => $column,
                'box_item_direction' => 'asc',
            ]);
            $this->assertSame(
                [$alphaItem->id, $zuluItem->id],
                $response->viewData('boxItems')->pluck('id')->all(),
                "Unexpected box-item order for {$column}."
            );
            $this->assertSame(
                [$secondEvent->id, $firstEvent->id],
                $response->viewData('boxEvents')->pluck('id')->all()
            );
        }

        foreach (['when', 'type', 'actor'] as $column) {
            $response = $this->boxResponse($box, [
                'box_event_sort' => $column,
                'box_event_direction' => 'asc',
            ]);
            $this->assertSame(
                [$firstEvent->id, $secondEvent->id],
                $response->viewData('boxEvents')->pluck('id')->all(),
                "Unexpected box-event order for {$column}."
            );
        }

        $independent = $this->boxResponse($box, [
            'box_item_sort' => 'sku',
            'box_item_direction' => 'desc',
            'box_event_sort' => 'type',
            'box_event_direction' => 'asc',
        ]);
        $this->assertSame([$zuluItem->id, $alphaItem->id], $independent->viewData('boxItems')->pluck('id')->all());
        $this->assertSame([$firstEvent->id, $secondEvent->id], $independent->viewData('boxEvents')->pluck('id')->all());
        $independent->assertSee('box_item_sort=sku', false)
            ->assertSee('box_event_sort=type', false)
            ->assertSee('#box-contents', false)
            ->assertSee('#box-events', false)
            ->assertDontSee('Sort by View');
    }

    #[Test]
    public function purchase_order_detail_tables_sort_every_supported_column_independently(): void
    {
        $graph = $this->purchaseOrderGraph();

        foreach (['item', 'supplier_sku', 'ordered', 'received', 'cancelled', 'outstanding', 'expected', 'source'] as $column) {
            $response = $this->purchaseOrderResponse($graph['order'], [
                'order_line_sort' => $column,
                'order_line_direction' => 'asc',
            ]);
            $this->assertSame(
                [$graph['line_a']->id, $graph['line_b']->id],
                $response->viewData('orderLines')->pluck('id')->all(),
                "Unexpected order-line order for {$column}."
            );
        }

        foreach (['item', 'allocated', 'accepted', 'rejected', 'cancelled', 'outstanding'] as $column) {
            $response = $this->purchaseOrderResponse($graph['order'], [
                'shipment_line_sort' => $column,
                'shipment_line_direction' => 'asc',
            ]);
            $shipment = $response->viewData('shipments')->firstWhere('id', $graph['shipment_a']->id);
            $this->assertSame(
                [$graph['shipment_line_a']->id, $graph['shipment_line_b']->id],
                $shipment->lines->pluck('id')->all(),
                "Unexpected shipment-line order for {$column}."
            );
        }

        foreach (['receipt', 'received', 'shipment', 'accepted', 'rejected', 'status', 'actor'] as $column) {
            $response = $this->purchaseOrderResponse($graph['order'], [
                'receipt_sort' => $column,
                'receipt_direction' => 'asc',
            ]);
            $this->assertSame(
                [$graph['receipt_a']->id, $graph['receipt_b']->id],
                $response->viewData('receipts')->pluck('id')->all(),
                "Unexpected receipt order for {$column}."
            );
        }

        $independent = $this->purchaseOrderResponse($graph['order'], [
            'order_line_sort' => 'item',
            'order_line_direction' => 'desc',
            'shipment_line_sort' => 'allocated',
            'shipment_line_direction' => 'asc',
            'receipt_sort' => 'receipt',
            'receipt_direction' => 'desc',
        ]);
        $shipment = $independent->viewData('shipments')->firstWhere('id', $graph['shipment_a']->id);
        $this->assertSame(
            [$graph['line_b']->id, $graph['line_a']->id],
            $independent->viewData('orderLines')->pluck('id')->all()
        );
        $this->assertSame(
            [$graph['shipment_line_a']->id, $graph['shipment_line_b']->id],
            $shipment->lines->pluck('id')->all()
        );
        $this->assertSame(
            [$graph['receipt_b']->id, $graph['receipt_a']->id],
            $independent->viewData('receipts')->pluck('id')->all()
        );
        $independent->assertSee('order_line_sort=item', false)
            ->assertSee('shipment_line_sort=allocated', false)
            ->assertSee('receipt_sort=receipt', false)
            ->assertSee('#order-lines', false)
            ->assertSee('#shipments', false)
            ->assertSee('#receipt-history', false)
            ->assertDontSee('Sort by Action')
            ->assertDontSee('Sort by Reverse');
    }

    #[Test]
    public function editable_and_printed_workflow_tables_do_not_expose_sort_controls(): void
    {
        $graph = $this->purchaseOrderGraph();

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.edit', $graph['order']))
            ->assertOk()
            ->assertDontSee('Sort by Storage item')
            ->assertDontSee('bi-arrow-down-up');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.shipments.create', $graph['order']))
            ->assertOk()
            ->assertDontSee('Sort by Order line')
            ->assertDontSee('Sort by Carrier override')
            ->assertDontSee('bi-arrow-down-up');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', $graph['order']))
            ->assertOk()
            ->assertDontSee('Sort by Accepted now')
            ->assertDontSee('bi-arrow-down-up');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.control-slip', $graph['order']))
            ->assertOk()
            ->assertDontSee('Sort by Item / supplier SKU')
            ->assertDontSee('bi-arrow-down-up');
    }

    /**
     * @param  array<string, string>  $query
     * @param  list<Warehouse>  $expected
     */
    private function assertWarehouseOrder(array $query, array $expected): void
    {
        $response = $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.storage.inventory', $query))
            ->assertOk();

        $this->assertSame(
            collect($expected)->pluck('id')->all(),
            $response->viewData('warehouses')->pluck('id')->all()
        );
    }

    private function movementResponse(Item $item, ?string $sort = null, ?string $direction = null)
    {
        return $this->actingAs($this->tech)
            ->get(route('tech.storage.items.show', array_filter([
                'item' => $item,
                'movement_sort' => $sort,
                'movement_direction' => $direction,
            ])))
            ->assertOk();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function boxResponse(Box $box, array $query)
    {
        return $this->actingAs($this->tech)
            ->get(route('tech.storage.boxes.show', ['box' => $box] + $query))
            ->assertOk();
    }

    /**
     * @param  array<string, string>  $query
     */
    private function purchaseOrderResponse(PurchaseOrder $purchaseOrder, array $query)
    {
        return $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', ['purchaseOrder' => $purchaseOrder] + $query))
            ->assertOk();
    }

    private function warehouse(
        string $name,
        ?string $code,
        ?string $address,
        bool $active
    ): Warehouse {
        return Warehouse::query()->create([
            'name' => $name,
            'code' => $code,
            'address' => $address,
            'is_active' => $active,
        ]);
    }

    private function box(Warehouse $warehouse, string $code): Box
    {
        return Box::query()->create([
            'warehouse_id' => $warehouse->id,
            'code_human' => $code,
            'name' => $code,
            'is_active' => true,
        ]);
    }

    private function item(
        Warehouse $warehouse,
        string $sku,
        string $name,
        ?Box $box = null,
        int $onHand = 0,
        int $reserved = 0
    ): Item {
        return Item::query()->create([
            'warehouse_id' => $warehouse->id,
            'box_id' => $box?->id,
            'sku' => $sku,
            'name' => $name,
            'qty_on_hand' => $onHand,
            'qty_reserved' => $reserved,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array{type: string, qty_before: int, qty_delta: int, qty_after: int, reason: ?string, created_at: string}  $attributes
     */
    private function movement(Item $item, ?User $actor, array $attributes): Movement
    {
        $movement = Movement::query()->create([
            'item_id' => $item->id,
            'actor_id' => $actor?->id,
            'type' => $attributes['type'],
            'qty_before' => $attributes['qty_before'],
            'qty_delta' => $attributes['qty_delta'],
            'qty_after' => $attributes['qty_after'],
            'reason' => $attributes['reason'],
        ]);
        $movement->forceFill([
            'created_at' => $attributes['created_at'],
            'updated_at' => $attributes['created_at'],
        ])->saveQuietly();

        return $movement;
    }

    private function boxEvent(Box $box, User $actor, string $type, string $createdAt): BoxEvent
    {
        $event = BoxEvent::query()->create([
            'box_id' => $box->id,
            'actor_id' => $actor->id,
            'type' => $type,
        ]);
        $event->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $event;
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseOrderGraph(): array
    {
        $warehouse = $this->warehouse('Purchase Warehouse', 'PURCHASE', null, true);
        $vendor = Vendor::query()->create([
            'name' => 'Purchase Supplier',
            'vendor_code' => 'PURCHASE-SUPPLIER',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $itemA = $this->item($warehouse, 'ALPHA-PO', 'Alpha Purchase Item');
        $itemB = $this->item($warehouse, 'ZULU-PO', 'Zulu Purchase Item');
        $order = PurchaseOrder::query()->create([
            'po_number' => 'PO-DETAIL-SORT',
            'vendor_id' => $vendor->id,
            'supplier_name_snapshot' => $vendor->name,
            'deliver_to_warehouse_id' => $warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => '2026-08-01',
            'currency' => 'NOK',
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $lineA = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'item_id' => $itemA->id,
            'item_name_snapshot' => $itemA->name,
            'sku_snapshot' => $itemA->sku,
            'supplier_sku_snapshot' => 'A-SUPPLIER',
            'qty_ordered' => 2,
            'qty_received' => 0,
            'qty_cancelled' => 0,
            'currency' => 'NOK',
            'expected_at' => '2026-08-02',
        ]);
        $lineB = PurchaseOrderLine::query()->create([
            'purchase_order_id' => $order->id,
            'item_id' => $itemB->id,
            'item_name_snapshot' => $itemB->name,
            'sku_snapshot' => $itemB->sku,
            'supplier_sku_snapshot' => 'Z-SUPPLIER',
            'qty_ordered' => 10,
            'qty_received' => 2,
            'qty_cancelled' => 1,
            'currency' => 'NOK',
            'expected_at' => '2026-08-10',
        ]);
        $shipmentA = PurchaseShipment::query()->create([
            'purchase_order_id' => $order->id,
            'reference' => 'A-SHIPMENT',
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipmentB = PurchaseShipment::query()->create([
            'purchase_order_id' => $order->id,
            'reference' => 'Z-SHIPMENT',
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipmentLineA = PurchaseShipmentLine::query()->create([
            'purchase_shipment_id' => $shipmentA->id,
            'purchase_order_line_id' => $lineA->id,
            'qty_allocated' => 2,
            'qty_received' => 1,
            'qty_rejected' => 0,
            'qty_cancelled' => 0,
        ]);
        $shipmentLineB = PurchaseShipmentLine::query()->create([
            'purchase_shipment_id' => $shipmentA->id,
            'purchase_order_line_id' => $lineB->id,
            'qty_allocated' => 10,
            'qty_received' => 2,
            'qty_rejected' => 1,
            'qty_cancelled' => 1,
        ]);
        $alphaActor = User::factory()->create(['name' => 'Alpha Receipt Actor', 'status' => User::STATUS_ACTIVE]);
        $zuluActor = User::factory()->create(['name' => 'Zulu Receipt Actor', 'status' => User::STATUS_ACTIVE]);
        $receiptA = $this->receipt($order, $shipmentA, $warehouse, $alphaActor, [
            'number' => 'GR-A',
            'status' => PurchaseReceipt::STATUS_POSTED,
            'received_at' => '2026-08-03 08:00:00',
            'line' => $lineA,
            'item' => $itemA,
            'shipment_line' => $shipmentLineA,
            'accepted' => 1,
            'rejected' => 0,
        ]);
        $receiptB = $this->receipt($order, $shipmentB, $warehouse, $zuluActor, [
            'number' => 'GR-Z',
            'status' => PurchaseReceipt::STATUS_REVERSED,
            'received_at' => '2026-08-04 08:00:00',
            'line' => $lineB,
            'item' => $itemB,
            'shipment_line' => null,
            'accepted' => 5,
            'rejected' => 2,
        ]);

        return compact(
            'order',
            'lineA',
            'lineB',
            'shipmentA',
            'shipmentB',
            'shipmentLineA',
            'shipmentLineB',
            'receiptA',
            'receiptB'
        ) + [
            'line_a' => $lineA,
            'line_b' => $lineB,
            'shipment_a' => $shipmentA,
            'shipment_line_a' => $shipmentLineA,
            'shipment_line_b' => $shipmentLineB,
            'receipt_a' => $receiptA,
            'receipt_b' => $receiptB,
        ];
    }

    /**
     * @param  array{number: string, status: string, received_at: string, line: PurchaseOrderLine, item: Item, shipment_line: ?PurchaseShipmentLine, accepted: int, rejected: int}  $attributes
     */
    private function receipt(
        PurchaseOrder $order,
        PurchaseShipment $shipment,
        Warehouse $warehouse,
        User $actor,
        array $attributes
    ): PurchaseReceipt {
        $receipt = PurchaseReceipt::query()->create([
            'receipt_number' => $attributes['number'],
            'purchase_order_id' => $order->id,
            'purchase_shipment_id' => $shipment->id,
            'receipt_type' => PurchaseReceipt::TYPE_RECEIPT,
            'status' => $attributes['status'],
            'idempotency_token' => (string) Str::uuid(),
            'request_hash' => hash('sha256', $attributes['number']),
            'received_at' => $attributes['received_at'],
            'warehouse_id' => $warehouse->id,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]);
        PurchaseReceiptLine::query()->create([
            'purchase_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $attributes['line']->id,
            'item_id' => $attributes['item']->id,
            'purchase_shipment_line_id' => $attributes['shipment_line']?->id,
            'qty_accepted' => $attributes['accepted'],
            'qty_rejected' => $attributes['rejected'],
            'qty_on_hand_before' => 0,
            'qty_on_hand_after' => $attributes['accepted'],
            'item_name_snapshot' => $attributes['item']->name,
            'sku_snapshot' => $attributes['item']->sku,
            'currency_snapshot' => 'NOK',
        ]);

        return $receipt;
    }
}
