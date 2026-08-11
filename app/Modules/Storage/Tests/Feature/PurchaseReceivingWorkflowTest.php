<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Controllers\Tech\PurchaseReceiptController;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseReceiptUnit;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\StockUnit;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\PurchaseOrderIndexQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseReceivingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private Warehouse $warehouse;

    private Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->grant(
            'storage.purchase_view',
            'storage.purchase_receive',
            'storage.purchase_reverse'
        );

        $this->warehouse = Warehouse::query()->create([
            'name' => 'Receiving Warehouse',
            'code' => 'RCV',
            'is_active' => true,
        ]);
        $this->supplier = Vendor::query()->create([
            'name' => 'Receiving Supplier',
            'vendor_code' => 'RCV-SUP',
            'is_supplier' => true,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function receiving_routes_use_the_storage_receipt_controller(): void
    {
        $this->assertSame(
            PurchaseReceiptController::class.'@index',
            Route::getRoutes()->getByName('tech.storage.receiving.index')->getActionName()
        );
        $this->assertSame(
            PurchaseReceiptController::class.'@store',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.receipts.store')->getActionName()
        );
        $this->assertSame(
            PurchaseReceiptController::class.'@reverse',
            Route::getRoutes()->getByName('tech.storage.receipts.reverse')->getActionName()
        );
    }

    #[Test]
    public function receiving_queue_sorts_every_data_column_and_preserves_the_current_view(): void
    {
        $alphaSupplier = Vendor::query()->create([
            'name' => 'Alpha Receiving Supplier',
            'vendor_code' => 'RCV-SORT-A',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $bravoSupplier = Vendor::query()->create([
            'name' => 'Bravo Receiving Supplier',
            'vendor_code' => 'RCV-SORT-B',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $charlieSupplier = Vendor::query()->create([
            'name' => 'Charlie Receiving Supplier',
            'vendor_code' => 'RCV-SORT-C',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $alphaWarehouse = Warehouse::query()->create([
            'name' => 'Alpha Receiving Warehouse',
            'code' => 'RCV-SORT-WA',
            'is_active' => true,
        ]);
        $bravoWarehouse = Warehouse::query()->create([
            'name' => 'Bravo Receiving Warehouse',
            'code' => 'RCV-SORT-WB',
            'is_active' => true,
        ]);
        $charlieWarehouse = Warehouse::query()->create([
            'name' => 'Charlie Receiving Warehouse',
            'code' => 'RCV-SORT-WC',
            'is_active' => true,
        ]);
        $orders = [];

        foreach ([
            ['A', $alphaSupplier, $alphaWarehouse, '2026-08-10', 0, 1],
            ['B', $bravoSupplier, $bravoWarehouse, '2026-08-12', 1, 2],
            ['C', $charlieSupplier, $charlieWarehouse, null, 2, 3],
        ] as [$suffix, $supplier, $warehouse, $expectedAt, $shipmentCount, $received]) {
            $item = $this->item('RCV-SORT-ITEM-'.$suffix, 'Receiving sort item '.$suffix, [
                'warehouse_id' => $warehouse->id,
            ]);
            $order = $this->placedOrder([['item' => $item, 'quantity' => 10]]);
            $order->update([
                'po_number' => 'PO-RSORT-'.$suffix,
                'vendor_id' => $supplier->id,
                'supplier_name_snapshot' => $supplier->name,
                'deliver_to_warehouse_id' => $warehouse->id,
                'expected_at' => $expectedAt,
            ]);
            $order->lines()->update(['qty_received' => $received]);

            for ($sequence = 1; $sequence <= $shipmentCount; $sequence++) {
                $order->shipments()->create([
                    'reference' => 'RCV-SORT-'.$suffix.'-'.$sequence,
                    'status' => PurchaseShipment::STATUS_PENDING,
                    'created_by' => $this->tech->id,
                    'updated_by' => $this->tech->id,
                ]);
            }

            $orders[$suffix] = $order;
        }

        $charlieWarehouse->delete();

        $assertOrder = function (string $sort, string $direction, array $expected): void {
            $this->actingAs($this->tech)
                ->get(route('tech.storage.receiving.index', ['sort' => $sort, 'direction' => $direction]))
                ->assertOk()
                ->assertSeeInOrder($expected);
        };

        foreach (['order', 'supplier', 'expected', 'shipments', 'received'] as $sort) {
            $assertOrder($sort, 'asc', ['PO-RSORT-A', 'PO-RSORT-B', 'PO-RSORT-C']);
        }
        foreach (['order', 'supplier', 'shipments', 'received'] as $sort) {
            $assertOrder($sort, 'desc', ['PO-RSORT-C', 'PO-RSORT-B', 'PO-RSORT-A']);
        }
        $assertOrder('expected', 'desc', ['PO-RSORT-B', 'PO-RSORT-A', 'PO-RSORT-C']);
        $assertOrder('destination', 'asc', ['PO-RSORT-A', 'PO-RSORT-B', 'PO-RSORT-C']);
        $assertOrder('destination', 'desc', ['PO-RSORT-B', 'PO-RSORT-A', 'PO-RSORT-C']);
        $assertOrder('outstanding', 'asc', ['PO-RSORT-C', 'PO-RSORT-B', 'PO-RSORT-A']);
        $assertOrder('outstanding', 'desc', ['PO-RSORT-A', 'PO-RSORT-B', 'PO-RSORT-C']);

        $this->assertSame(
            ['PO-RSORT-A', 'PO-RSORT-B', 'PO-RSORT-C'],
            app(PurchaseOrderIndexQuery::class)->paginateReceiving([
                'sort' => 'order',
                'direction' => 'invalid',
            ])->pluck('po_number')->all()
        );
        $this->assertSame(
            ['PO-RSORT-A', 'PO-RSORT-B', 'PO-RSORT-C'],
            app(PurchaseOrderIndexQuery::class)->paginateReceiving([
                'sort' => 'po_number; drop table users',
                'direction' => 'desc',
            ])->pluck('po_number')->all()
        );

        $response = $this->actingAs($this->tech)->get(route('tech.storage.receiving.index', [
            'q' => 'PO-RSORT',
            'vendor_id' => $alphaSupplier->id,
            'sort' => 'order',
            'direction' => 'asc',
            'page' => 2,
        ]));
        $response->assertOk()
            ->assertSee('aria-sort="ascending"', false)
            ->assertSee('name="sort" value="order"', false)
            ->assertSee('name="direction" value="asc"', false)
            ->assertSee(e(route('tech.storage.receiving.index', [
                'q' => 'PO-RSORT',
                'vendor_id' => $alphaSupplier->id,
                'sort' => 'order',
                'direction' => 'desc',
            ])), false)
            ->assertSee(e(route('tech.storage.receiving.index', [
                'q' => 'PO-RSORT',
                'vendor_id' => $alphaSupplier->id,
                'sort' => 'progress',
                'direction' => 'asc',
            ])), false);
        $this->assertSame(1, substr_count($response->getContent(), 'aria-sort='));
        $this->assertCount(3, $orders);
    }

    #[Test]
    public function receipt_form_defaults_every_line_to_zero_and_prints_a_control_slip(): void
    {
        [$order] = $this->orderWithTwoLines();
        $lineIds = $order->lines->pluck('id')->values();

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', $order))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.receiving.create')
            ->assertSee('Receive Goods')
            ->assertSeeInOrder([
                'name="lines['.$lineIds[0].'][qty_accepted]"',
                'value="0"',
                'name="lines['.$lineIds[0].'][qty_rejected]"',
                'value="0"',
                'name="lines['.$lineIds[1].'][qty_accepted]"',
                'value="0"',
                'name="lines['.$lineIds[1].'][qty_rejected]"',
                'value="0"',
            ], false)
            ->assertSee('Post Receipt And Update Inventory');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.control-slip', $order))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.receiving.control-slip')
            ->assertSee('Goods Receiving Control Slip')
            ->assertSee('Previously accepted')
            ->assertSee('Discrepancy / damage');
    }

    #[Test]
    public function cancelled_shipments_are_not_offered_or_accepted_for_receiving(): void
    {
        [$order] = $this->orderWithTwoLines();
        $cancelled = $order->shipments()->create([
            'reference' => 'CANCELLED-SHIPMENT',
            'status' => PurchaseShipment::STATUS_CANCELLED,
            'status_changed_at' => now(),
            'status_changed_by' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $active = $order->shipments()->create([
            'reference' => 'ACTIVE-SHIPMENT',
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'status_changed_at' => now(),
            'status_changed_by' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', $order))
            ->assertOk()
            ->assertSee('ACTIVE-SHIPMENT')
            ->assertDontSee('CANCELLED-SHIPMENT');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', [
                'purchaseOrder' => $order,
                'shipment_id' => $cancelled->id,
            ]))
            ->assertStatus(409);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.control-slip', [
                'purchaseOrder' => $order,
                'shipment_id' => $cancelled->id,
            ]))
            ->assertStatus(409);
    }

    #[Test]
    public function shipment_allocations_scope_receiving_fields_and_overage_removes_both_limits(): void
    {
        [$order] = $this->orderWithTwoLines();
        $firstLine = $order->lines->first();
        $secondLine = $order->lines->last();
        $shipmentDefaults = [
            'status' => PurchaseShipment::STATUS_PENDING,
            'status_changed_at' => now(),
            'status_changed_by' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ];
        $allocatedShipment = $order->shipments()->create($shipmentDefaults + [
            'reference' => 'ALLOCATED-SHIPMENT',
        ]);
        $allocatedShipment->lines()->create([
            'purchase_order_line_id' => $firstLine->id,
            'qty_allocated' => 2,
            'qty_received' => 0,
            'qty_rejected' => 0,
            'qty_cancelled' => 0,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $emptyShipment = $order->shipments()->create($shipmentDefaults + [
            'reference' => 'EMPTY-SHIPMENT',
        ]);
        $processedShipment = $order->shipments()->create($shipmentDefaults + [
            'reference' => 'PROCESSED-SHIPMENT',
        ]);
        $processedShipment->lines()->create([
            'purchase_order_line_id' => $secondLine->id,
            'qty_allocated' => 1,
            'qty_received' => 1,
            'qty_rejected' => 0,
            'qty_cancelled' => 0,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $cancelledShipment = $order->shipments()->create(array_merge($shipmentDefaults, [
            'reference' => 'IGNORED-CANCELLED-SHIPMENT',
            'status' => PurchaseShipment::STATUS_CANCELLED,
        ]));
        $cancelledShipment->lines()->create([
            'purchase_order_line_id' => $secondLine->id,
            'qty_allocated' => 1,
            'qty_received' => 0,
            'qty_rejected' => 0,
            'qty_cancelled' => 0,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $unscoped = $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', $order))
            ->assertOk()
            ->assertSee('Lines with active shipment allocation require selecting the shipment that owns the allocation.');

        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-receipt-line-id="'.$firstLine->id.'"[^>]*>.*?<\/tr>/s',
            $unscoped->getContent(),
            $unscopedFirstRow
        ));
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-receipt-line-id="'.$secondLine->id.'"[^>]*>.*?<\/tr>/s',
            $unscoped->getContent(),
            $unscopedSecondRow
        ));
        $this->assertStringContainsString('data-line-receivable="0"', $unscopedFirstRow[0]);
        $this->assertStringContainsString('data-receipt-limit="0"', $unscopedFirstRow[0]);
        $this->assertSame(2, substr_count($unscopedFirstRow[0], 'disabled'));
        $this->assertStringContainsString('data-line-receivable="1"', $unscopedSecondRow[0]);
        $this->assertStringContainsString('data-receipt-limit="3"', $unscopedSecondRow[0]);
        $this->assertSame(0, substr_count($unscopedSecondRow[0], 'disabled'));

        $emptySelection = $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', [
                'purchaseOrder' => $order,
                'shipment_id' => $emptyShipment->id,
            ]))
            ->assertOk();
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-receipt-line-id="'.$firstLine->id.'"[^>]*>.*?<\/tr>/s',
            $emptySelection->getContent(),
            $emptySelectionFirstRow
        ));
        $this->assertStringContainsString('data-line-receivable="0"', $emptySelectionFirstRow[0]);

        $scoped = $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', [
                'purchaseOrder' => $order,
                'shipment_id' => $allocatedShipment->id,
            ]))
            ->assertOk()
            ->assertSee('Only its allocated lines can be posted')
            ->assertSee('This line is not allocated to the selected shipment.');

        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-receipt-line-id="'.$firstLine->id.'"[^>]*>.*?<\/tr>/s',
            $scoped->getContent(),
            $scopedFirstRow
        ));
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-receipt-line-id="'.$secondLine->id.'"[^>]*>.*?<\/tr>/s',
            $scoped->getContent(),
            $scopedSecondRow
        ));
        $this->assertStringContainsString('data-line-receivable="1"', $scopedFirstRow[0]);
        $this->assertStringContainsString('data-receipt-limit="2"', $scopedFirstRow[0]);
        $this->assertSame(2, substr_count($scopedFirstRow[0], 'max="2"'));
        $this->assertSame(0, substr_count($scopedFirstRow[0], 'disabled'));
        $this->assertStringContainsString('data-line-receivable="0"', $scopedSecondRow[0]);
        $this->assertStringContainsString('data-receipt-limit="0"', $scopedSecondRow[0]);
        $this->assertSame(2, substr_count($scopedSecondRow[0], 'disabled'));
        $this->assertMatchesRegularExpression(
            '/id="discrepancy_'.$secondLine->id.'"[^>]*disabled/s',
            $scoped->getContent()
        );

        $this->grant('storage.purchase_receive_overage');
        $overage = $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', [
                'purchaseOrder' => $order,
                'shipment_id' => $allocatedShipment->id,
            ]))
            ->assertOk();
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-receipt-line-id="'.$firstLine->id.'"[^>]*>.*?<\/tr>/s',
            $overage->getContent(),
            $overageFirstRow
        ));
        $this->assertSame(0, substr_count($overageFirstRow[0], ' max="'));
    }

    #[Test]
    public function technician_can_post_one_partial_line_without_receiving_other_lines(): void
    {
        [$order, $firstItem, $secondItem] = $this->orderWithTwoLines();
        $firstLine = $order->lines->first();
        $secondLine = $order->lines->last();

        $response = $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.receipts.store', $order), [
                'idempotency_token' => (string) Str::uuid(),
                'delivery_note_ref' => 'DN-100',
                'received_at' => '2026-08-06 09:30:00',
                'warehouse_id' => $this->warehouse->id,
                'notes' => 'First parcel arrived.',
                'lines' => [
                    [
                        'purchase_order_line_id' => $firstLine->id,
                        'qty_accepted' => 2,
                        'qty_rejected' => 1,
                        'discrepancy_note' => 'One unit has visible damage.',
                    ],
                    [
                        'purchase_order_line_id' => $secondLine->id,
                        'qty_accepted' => 0,
                        'qty_rejected' => 0,
                    ],
                ],
            ]);

        $receipt = PurchaseReceipt::query()
            ->where('receipt_type', PurchaseReceipt::TYPE_RECEIPT)
            ->with('lines')
            ->firstOrFail();

        $response->assertRedirect(route('tech.storage.purchase-orders.show', $order));
        $this->assertSame(PurchaseReceipt::STATUS_POSTED, $receipt->status);
        $this->assertCount(1, $receipt->lines);
        $this->assertSame(2, $receipt->lines->first()->qty_accepted);
        $this->assertSame(1, $receipt->lines->first()->qty_rejected);
        $this->assertSame(2, $firstItem->refresh()->qty_on_hand);
        $this->assertSame(0, $secondItem->refresh()->qty_on_hand);
        $this->assertSame(2, $firstLine->refresh()->qty_received);
        $this->assertSame(0, $secondLine->refresh()->qty_received);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);

        $movement = Movement::query()->where('reason', 'purchase_receipt')->firstOrFail();
        $this->assertSame(2, $movement->qty_delta);
        $this->assertSame(0, $movement->qty_before);
        $this->assertSame(2, $movement->qty_after);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee($receipt->receipt_number)
            ->assertSee('DN-100')
            ->assertSee('Reverse');
    }

    #[Test]
    public function reversal_route_restores_inventory_and_reopens_the_outstanding_quantity(): void
    {
        [$order, $firstItem] = $this->orderWithTwoLines();
        $firstLine = $order->lines->first();

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.receipts.store', $order), [
                'idempotency_token' => (string) Str::uuid(),
                'warehouse_id' => $this->warehouse->id,
                'lines' => [[
                    'purchase_order_line_id' => $firstLine->id,
                    'qty_accepted' => 2,
                    'qty_rejected' => 0,
                ]],
            ])
            ->assertRedirect();

        $receipt = PurchaseReceipt::query()
            ->where('receipt_type', PurchaseReceipt::TYPE_RECEIPT)
            ->firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.storage.receipts.reverse', $receipt), [
                'idempotency_token' => (string) Str::uuid(),
                'reason' => 'Delivery was registered against the wrong order.',
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));

        $this->assertSame(PurchaseReceipt::STATUS_REVERSED, $receipt->refresh()->status);
        $this->assertSame(0, $firstItem->refresh()->qty_on_hand);
        $this->assertSame(0, $firstLine->refresh()->qty_received);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->refresh()->status);
        $this->assertDatabaseHas('storage_purchase_receipts', [
            'purchase_order_id' => $order->id,
            'receipt_type' => PurchaseReceipt::TYPE_REVERSAL,
            'status' => PurchaseReceipt::STATUS_POSTED,
        ]);
        $this->assertDatabaseHas('storage_movements', [
            'item_id' => $firstItem->id,
            'type' => 'receive_reversal',
            'qty_delta' => -2,
        ]);
    }

    #[Test]
    public function failed_receipt_preserves_each_quantity_with_its_original_order_line(): void
    {
        $firstItem = $this->item('PLAIN-ITEM', 'Plain Item');
        $secondItem = $this->item('SERIAL-FAIL', 'Serial Item', [
            'has_serials' => true,
        ]);
        $order = $this->placedOrder([
            ['item' => $firstItem, 'quantity' => 2],
            ['item' => $secondItem, 'quantity' => 1],
        ]);
        $firstLine = $order->lines->first();
        $secondLine = $order->lines->last();

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.receive', $order))
            ->assertOk()
            ->assertSee('name="lines['.$firstLine->id.'][qty_accepted]"', false)
            ->assertSee('name="lines['.$secondLine->id.'][qty_accepted]"', false)
            ->assertDontSee('name="lines[0][qty_accepted]"', false);

        $response = $this->actingAs($this->tech)
            ->from(route('tech.storage.purchase-orders.receive', $order))
            ->post(route('tech.storage.purchase-orders.receipts.store', $order), [
                'idempotency_token' => (string) Str::uuid(),
                'warehouse_id' => $this->warehouse->id,
                'lines' => [
                    $firstLine->id => [
                        'purchase_order_line_id' => $firstLine->id,
                        'qty_accepted' => 0,
                        'qty_rejected' => 0,
                    ],
                    $secondLine->id => [
                        'purchase_order_line_id' => $secondLine->id,
                        'qty_accepted' => 1,
                        'qty_rejected' => 0,
                    ],
                ],
            ]);

        $response
            ->assertRedirect(route('tech.storage.purchase-orders.receive', $order))
            ->assertSessionHasErrors("lines.{$secondLine->id}.units")
            ->assertSessionHasInput("lines.{$firstLine->id}.purchase_order_line_id", $firstLine->id)
            ->assertSessionHasInput("lines.{$firstLine->id}.qty_accepted", 0)
            ->assertSessionHasInput("lines.{$secondLine->id}.purchase_order_line_id", $secondLine->id)
            ->assertSessionHasInput("lines.{$secondLine->id}.qty_accepted", 1);
    }

    #[Test]
    public function serial_batch_and_expiry_inputs_are_transformed_into_receipt_units(): void
    {
        $item = $this->item('SERIAL-ITEM', 'Tracked Hardware', [
            'has_serials' => true,
            'track_batch' => true,
            'expiry_enabled' => true,
        ]);
        $order = $this->placedOrder([
            ['item' => $item, 'quantity' => 2],
        ]);
        $line = $order->lines->first();

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.receipts.store', $order), [
                'idempotency_token' => (string) Str::uuid(),
                'warehouse_id' => $this->warehouse->id,
                'lines' => [[
                    'purchase_order_line_id' => $line->id,
                    'qty_accepted' => 2,
                    'qty_rejected' => 0,
                    'serial_numbers' => "SN-100\nSN-101",
                    'batch_no' => 'BATCH-26',
                    'expiry_date' => '2028-12-31',
                ]],
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));

        $this->assertSame(2, StockUnit::query()->where('item_id', $item->id)->count());
        $this->assertSame(2, PurchaseReceiptUnit::query()->count());
        $this->assertDatabaseHas('storage_stock_units', [
            'item_id' => $item->id,
            'serial_no' => 'SN-100',
            'batch_no' => 'BATCH-26',
            'expiry_date' => '2028-12-31 00:00:00',
            'current_qty' => 1,
        ]);
        $this->assertDatabaseHas('storage_stock_units', [
            'item_id' => $item->id,
            'serial_no' => 'SN-101',
            'batch_no' => 'BATCH-26',
            'expiry_date' => '2028-12-31 00:00:00',
            'current_qty' => 1,
        ]);
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tech->givePermissionTo($permissions);
    }

    private function orderWithTwoLines(): array
    {
        $first = $this->item('ROUTER-01', 'Router');
        $second = $this->item('SWITCH-01', 'Switch');
        $order = $this->placedOrder([
            ['item' => $first, 'quantity' => 5],
            ['item' => $second, 'quantity' => 3],
        ]);

        return [$order, $first, $second];
    }

    private function item(string $sku, string $name, array $overrides = []): Item
    {
        return Item::query()->create($overrides + [
            'warehouse_id' => $this->warehouse->id,
            'sku' => $sku,
            'name' => $name,
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'reorder_point' => 0,
            'target_level' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);
    }

    private function placedOrder(array $lineInputs): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'po_number' => 'PO-RCV-'.str()->random(6),
            'vendor_id' => $this->supplier->id,
            'supplier_name_snapshot' => $this->supplier->name,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'status_changed_at' => now(),
            'status_changed_by' => $this->tech->id,
            'ordered_at' => now()->toDateString(),
            'currency' => 'NOK',
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        foreach ($lineInputs as $input) {
            /** @var Item $item */
            $item = $input['item'];
            $order->lines()->create([
                'item_id' => $item->id,
                'item_name_snapshot' => $item->name,
                'sku_snapshot' => $item->sku,
                'qty_ordered' => $input['quantity'],
                'qty_received' => 0,
                'qty_cancelled' => 0,
                'currency' => 'NOK',
                'created_by' => $this->tech->id,
                'updated_by' => $this->tech->id,
            ]);
        }

        return $order->load('lines');
    }
}
