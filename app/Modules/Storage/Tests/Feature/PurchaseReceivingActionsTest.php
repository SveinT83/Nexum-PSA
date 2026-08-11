<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\AdjustItemStock;
use App\Modules\Storage\Actions\AppendPurchaseShipmentTracking;
use App\Modules\Storage\Actions\CancelPurchaseOrder;
use App\Modules\Storage\Actions\CancelPurchaseOrderLine;
use App\Modules\Storage\Actions\ClosePurchaseOrder;
use App\Modules\Storage\Actions\DeleteItem;
use App\Modules\Storage\Actions\GuardItemTrackingConfiguration;
use App\Modules\Storage\Actions\PostPurchaseReceipt;
use App\Modules\Storage\Actions\RefreshPurchaseOrderStatus;
use App\Modules\Storage\Actions\RefreshPurchaseShipmentStatus;
use App\Modules\Storage\Actions\ReversePurchaseReceipt;
use App\Modules\Storage\Actions\StoreItem;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\StorePurchaseShipment;
use App\Modules\Storage\Actions\UpdatePurchaseOrder;
use App\Modules\Storage\Actions\UpdatePurchaseShipmentStatus;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseReceiptReversal;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\Room;
use App\Modules\Storage\Models\StockUnit;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class PurchaseReceivingActionsTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private int $orderSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->vendor = Vendor::query()->create([
            'name' => 'Nordic Supplier',
            'is_vendor' => true,
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::query()->create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function new_lines_cannot_start_cancelled_and_order_cancellation_preserves_snapshots(): void
    {
        $item = $this->item(['name' => 'Managed Switch', 'sku' => 'SW-24']);
        ItemVendor::query()->create([
            'item_id' => $item->id,
            'vendor_id' => $this->vendor->id,
            'vendor_sku' => 'SUP-SW-24',
            'currency' => 'EUR',
            'unit_cost' => 129.50,
            'is_primary' => true,
        ]);

        $this->expectValidation(fn () => $this->order([[
            'item_id' => $item->id,
            'qty_ordered' => 4,
            'qty_cancelled' => 4,
            'cancellation_reason' => 'Supplier discontinued the line.',
        ]], ['currency' => 'EUR']));

        $order = $this->order([[
            'item_id' => $item->id,
            'qty_ordered' => 4,
        ]], ['currency' => 'EUR']);

        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->status);
        $this->assertSame('Nordic Supplier', $order->supplier_name_snapshot);
        $this->assertSame('Managed Switch', $order->lines->first()->item_name_snapshot);
        $this->assertSame('SW-24', $order->lines->first()->sku_snapshot);
        $this->assertSame('SUP-SW-24', $order->lines->first()->supplier_sku_snapshot);
        $this->assertSame('EUR', $order->lines->first()->currency);

        $orderLine = $order->lines->first();
        $this->expectValidation(fn () => app(UpdatePurchaseOrder::class)->handle(
            $order,
            ['lines' => [[
                'id' => $orderLine->id,
                'item_id' => $item->id,
                'qty_ordered' => 4,
                'qty_cancelled' => 1,
                'cancellation_reason' => 'Must use the lifecycle action.',
            ]]],
            $this->actor
        ));
        $this->assertSame(0, $orderLine->refresh()->qty_cancelled);

        $cancelled = app(CancelPurchaseOrder::class)->handle(
            $order,
            'The supplier discontinued every outstanding line.',
            $this->actor
        );
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(4, $cancelled->lines->first()->qty_cancelled);

        $otherWarehouse = Warehouse::query()->create([
            'name' => 'Van',
            'code' => 'VAN',
            'is_active' => true,
        ]);
        $wrongItem = $this->item([
            'warehouse_id' => $otherWarehouse->id,
            'name' => 'Wrong Location',
        ]);

        $this->expectValidation(fn () => $this->order([[
            'item_id' => $wrongItem->id,
            'qty_ordered' => 1,
        ]]));
    }

    #[Test]
    public function shipment_allocations_and_tracking_use_immutable_carrier_snapshots(): void
    {
        $carrier = ShippingCarrier::query()->create([
            'code' => 'TEST',
            'name' => 'Test Carrier',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'website_url' => 'https://carrier.example',
            'tracking_page_url' => 'https://track.carrier.example',
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
            'tracking_url_template' => 'https://track.carrier.example/parcel/{tracking_number}',
            'allowed_tracking_hosts' => ['track.carrier.example'],
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'source_url' => 'https://carrier.example/tracking-help',
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'verified_at' => now()->toDateString(),
        ]);
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 4]]);
        $line = $order->lines->first();

        $shipment = app(StorePurchaseShipment::class)->handle($order, [
            'shipping_carrier_id' => $carrier->id,
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'shipped_at' => now(),
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 4,
            ]],
            'trackings' => [[
                'tracking_number' => 'PKG 123',
                'tracking_type' => 'parcel',
            ]],
        ], $this->actor);

        $tracking = $shipment->trackings->first();
        $this->assertSame('TEST', $shipment->carrier_code_snapshot);
        $this->assertSame('Test Carrier', $tracking->carrier_name_snapshot);
        $this->assertSame(
            'https://track.carrier.example/parcel/PKG%20123',
            $tracking->tracking_url
        );
        $this->assertSame(4, $shipment->lines->first()->qty_allocated);

        $this->expectValidation(fn () => app(StorePurchaseShipment::class)->handle($order, [
            'shipping_carrier_id' => $carrier->id,
            'trackings' => [[
                'tracking_number' => 'UNSAFE',
                'direct_url' => 'https://attacker.example/UNSAFE',
            ]],
        ], $this->actor));
        $this->assertSame(1, PurchaseShipment::query()->count());
    }

    #[Test]
    public function partial_receipts_are_atomic_idempotent_and_derive_order_progress(): void
    {
        $firstItem = $this->item(['name' => 'Access Point', 'qty_on_hand' => 1]);
        $secondItem = $this->item(['name' => 'Patch Cable', 'qty_on_hand' => 10]);
        $order = $this->order([
            ['item_id' => $firstItem->id, 'qty_ordered' => 5],
            ['item_id' => $secondItem->id, 'qty_ordered' => 2],
        ]);
        [$firstLine, $secondLine] = $order->lines->values()->all();
        $shipment = app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [
                [
                    'purchase_order_line_id' => $firstLine->id,
                    'qty_allocated' => 5,
                ],
                [
                    'purchase_order_line_id' => $secondLine->id,
                    'qty_allocated' => 2,
                ],
            ],
        ], $this->actor);

        $firstPayload = [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $shipment->id,
            'delivery_note_ref' => 'DN-100',
            'lines' => [
                [
                    'purchase_order_line_id' => $firstLine->id,
                    'qty_accepted' => 2,
                    'qty_rejected' => 0,
                    'units' => [],
                ],
                [
                    'purchase_order_line_id' => $secondLine->id,
                    'qty_accepted' => 0,
                    'qty_rejected' => 1,
                    'discrepancy_note' => 'Damaged connector.',
                    'units' => [],
                ],
            ],
        ];

        $receipt = app(PostPurchaseReceipt::class)
            ->handle($order, $firstPayload, $this->actor);

        $this->assertSame(PurchaseReceipt::STATUS_POSTED, $receipt->status);
        $this->assertSame(3, $firstItem->refresh()->qty_on_hand);
        $this->assertSame(10, $secondItem->refresh()->qty_on_hand);
        $this->assertSame(2, $firstLine->refresh()->qty_received);
        $this->assertSame(0, $secondLine->refresh()->qty_received);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
        $movement = Movement::query()->sole();
        $this->assertSame(PurchaseShipment::STATUS_PARTIALLY_RECEIVED, $shipment->refresh()->status);
        $this->assertSame(2, $movement->qty_delta);
        $this->assertSame($receipt->lines->first()->getMorphClass(), $movement->source_type);

        $retry = app(PostPurchaseReceipt::class)
            ->handle($order, $firstPayload, $this->actor);
        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(1, Movement::query()->count());

        app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $shipment->id,
            'lines' => [
                [
                    'purchase_order_line_id' => $firstLine->id,
                    'qty_accepted' => 3,
                    'qty_rejected' => 0,
                    'units' => [],
                ],
                [
                    'purchase_order_line_id' => $secondLine->id,
                    'qty_accepted' => 1,
                    'qty_rejected' => 0,
                    'units' => [],
                ],
            ],
        ], $this->actor);

        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
        $this->assertSame(PurchaseShipment::STATUS_RECEIVED, $shipment->refresh()->status);
        $this->assertSame(6, $shipment->lines()->sum('qty_received'));
        $this->assertSame(1, $shipment->lines()->sum('qty_rejected'));
        $this->assertSame(5, $firstLine->refresh()->qty_received);
        $this->assertSame(1, $secondLine->refresh()->qty_received);

        $replacement = app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [[
                'purchase_order_line_id' => $secondLine->id,
                'qty_allocated' => 1,
            ]],
        ], $this->actor);
        app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $replacement->id,
            'lines' => [[
                'purchase_order_line_id' => $secondLine->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ], $this->actor);

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
        $this->assertSame(PurchaseShipment::STATUS_RECEIVED, $replacement->refresh()->status);
        $this->assertSame(2, $secondLine->refresh()->qty_received);
    }

    #[Test]
    public function allocated_shipments_require_the_owning_allocation_and_allow_replacement_after_rejection(): void
    {
        $allocatedItem = $this->item(['name' => 'Allocated item']);
        $otherItem = $this->item(['name' => 'Other item']);
        $order = $this->order([
            ['item_id' => $allocatedItem->id, 'qty_ordered' => 2],
            ['item_id' => $otherItem->id, 'qty_ordered' => 1],
        ]);
        [$allocatedLine, $otherLine] = $order->lines->values()->all();
        $shipment = app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [[
                'purchase_order_line_id' => $allocatedLine->id,
                'qty_allocated' => 2,
            ]],
        ], $this->actor);
        $unknownShipment = app(StorePurchaseShipment::class)->handle(
            $order,
            [],
            $this->actor
        );

        $receiptFor = fn (?int $shipmentId, int $lineId, int $accepted, int $rejected): array => [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $shipmentId,
            'lines' => [[
                'purchase_order_line_id' => $lineId,
                'qty_accepted' => $accepted,
                'qty_rejected' => $rejected,
                'units' => [],
            ]],
        ];

        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle(
            $order,
            $receiptFor(null, $allocatedLine->id, 1, 0),
            $this->actor
        ));
        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle(
            $order,
            $receiptFor($unknownShipment->id, $allocatedLine->id, 1, 0),
            $this->actor
        ));
        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle(
            $order,
            $receiptFor($shipment->id, $otherLine->id, 1, 0),
            $this->actor
        ));

        $shipmentOverage = $receiptFor($shipment->id, $allocatedLine->id, 2, 1);
        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle(
            $order,
            $shipmentOverage,
            $this->actor
        ));
        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle(
            $order,
            $shipmentOverage,
            $this->actor,
            true
        ));

        $receipt = app(PostPurchaseReceipt::class)->handle(
            $order,
            $receiptFor($shipment->id, $allocatedLine->id, 1, 1),
            $this->actor
        );

        $shipmentLine = $shipment->lines()->sole();
        $this->assertSame('allocated', $receipt->metadata['shipment_allocation_mode']);
        $this->assertSame(PurchaseShipment::STATUS_RECEIVED, $shipment->refresh()->status);
        $this->assertSame(1, $shipmentLine->refresh()->qty_received);
        $this->assertSame(1, $shipmentLine->qty_rejected);
        $this->assertSame(0, $shipmentLine->qty_outstanding);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);
        $this->assertSame(1, $allocatedLine->refresh()->qty_outstanding);

        $replacement = app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [[
                'purchase_order_line_id' => $allocatedLine->id,
                'qty_allocated' => 1,
            ]],
        ], $this->actor);

        $this->assertSame(1, $replacement->lines->sole()->qty_allocated);
        $this->assertSame(1, $replacement->lines->sole()->qty_outstanding);
    }

    #[Test]
    public function unspecified_shipments_follow_posted_receipt_events_and_restore_manual_status_after_reversal(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 1]]);
        $line = $order->lines->sole();
        $shipment = app(StorePurchaseShipment::class)->handle($order, [
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
        ], $this->actor);

        $this->assertSame(PurchaseShipment::STATUS_IN_TRANSIT, $shipment->status);
        $this->assertNotNull($shipment->shipped_at);

        $receipt = app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $shipment->id,
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 0,
                'qty_rejected' => 1,
                'discrepancy_note' => 'The single parcel unit was damaged.',
                'units' => [],
            ]],
        ], $this->actor);

        $this->assertSame('unspecified', $receipt->metadata['shipment_allocation_mode']);
        $this->assertSame(PurchaseShipment::STATUS_RECEIVED, $shipment->refresh()->status);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->refresh()->status);

        app(ReversePurchaseReceipt::class)->handle($receipt, [
            'idempotency_token' => (string) Str::uuid(),
            'reason' => 'The damage report was attached to the wrong parcel.',
        ], $this->actor);

        $this->assertSame(PurchaseShipment::STATUS_IN_TRANSIT, $shipment->refresh()->status);
        $this->assertSame(PurchaseReceipt::STATUS_REVERSED, $receipt->refresh()->status);

        $deliveredItem = $this->item();
        $deliveredOrder = $this->order([[
            'item_id' => $deliveredItem->id,
            'qty_ordered' => 1,
        ]]);
        $this->expectValidation(fn () => app(StorePurchaseShipment::class)->handle($deliveredOrder, [
            'status' => PurchaseShipment::STATUS_PENDING,
            'shipped_at' => now(),
        ], $this->actor));
        $this->expectValidation(fn () => app(StorePurchaseShipment::class)->handle($deliveredOrder, [
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'delivered_at' => now(),
        ], $this->actor));
        $this->expectValidation(fn () => app(StorePurchaseShipment::class)->handle($deliveredOrder, [
            'status' => PurchaseShipment::STATUS_DELIVERED,
            'shipped_at' => now(),
            'delivered_at' => now()->subMinute(),
        ], $this->actor));

        $deliveredAt = now()->subMinute();
        $delivered = app(StorePurchaseShipment::class)->handle($deliveredOrder, [
            'status' => PurchaseShipment::STATUS_DELIVERED,
            'delivered_at' => $deliveredAt,
        ], $this->actor);

        $this->assertSame(PurchaseShipment::STATUS_DELIVERED, $delivered->status);
        $this->assertTrue($delivered->shipped_at->equalTo($delivered->delivered_at));
    }

    #[Test]
    public function shipment_status_action_rejects_delivery_before_the_shipped_time(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 1]]);
        $shipment = app(StorePurchaseShipment::class)->handle($order, [], $this->actor);
        $shippedAt = now()->subHours(2)->startOfSecond();

        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_IN_TRANSIT,
            $shippedAt,
            'Carrier collected the parcel.',
            $this->actor
        );
        $this->assertTrue($shipment->shipped_at->equalTo($shippedAt));

        $this->expectValidation(fn () => app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_DELIVERED,
            $shippedAt->copy()->subMinute(),
            'Invalid delivery event before collection.',
            $this->actor
        ));
        $shipment->refresh();
        $this->assertSame(PurchaseShipment::STATUS_IN_TRANSIT, $shipment->status);
        $this->assertNull($shipment->delivered_at);
        $this->assertTrue($shipment->shipped_at->equalTo($shippedAt));

        $deliveredAt = $shippedAt->copy()->addHour();
        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_DELIVERED,
            $deliveredAt,
            'Carrier delivered the parcel.',
            $this->actor
        );

        $this->assertSame(PurchaseShipment::STATUS_DELIVERED, $shipment->status);
        $this->assertTrue($shipment->shipped_at->equalTo($shippedAt));
        $this->assertTrue($shipment->delivered_at->equalTo($deliveredAt));
        $this->assertCount(2, $shipment->metadata['status_history']);
    }

    #[Test]
    public function direct_delivery_uses_occurred_at_when_historical_ship_time_is_missing(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 1]]);
        $shipment = app(StorePurchaseShipment::class)->handle($order, [], $this->actor);

        // Imported legacy rows can be in transit without the event timestamp.
        $shipment->forceFill([
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'shipped_at' => null,
            'delivered_at' => null,
        ])->save();

        $occurredAt = now()->subHour()->startOfSecond();
        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_DELIVERED,
            $occurredAt,
            'Imported carrier event confirms delivery.',
            $this->actor
        );

        $this->assertSame(PurchaseShipment::STATUS_DELIVERED, $shipment->status);
        $this->assertTrue($shipment->shipped_at->equalTo($occurredAt));
        $this->assertTrue($shipment->delivered_at->equalTo($occurredAt));
    }

    #[Test]
    public function line_cancellation_terminalizes_partial_shipments_before_pending_shipments(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 10]]);
        $line = $order->lines->sole();
        $partialShipment = app(StorePurchaseShipment::class)->handle($order, [
            'reference' => 'PARTIAL',
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 5,
            ]],
        ], $this->actor);
        $pendingShipment = app(StorePurchaseShipment::class)->handle($order, [
            'reference' => 'PENDING',
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 5,
            ]],
        ], $this->actor);

        app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $partialShipment->id,
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 3,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ], $this->actor);

        app(CancelPurchaseOrderLine::class)->handle(
            $order,
            $line,
            2,
            'Supplier removed the missing units.',
            $this->actor
        );

        $this->assertSame(2, $partialShipment->lines()->sole()->qty_cancelled);
        $this->assertSame(0, $pendingShipment->lines()->sole()->qty_cancelled);
        $this->assertSame(PurchaseShipment::STATUS_RECEIVED, $partialShipment->refresh()->status);
        $this->assertSame(PurchaseShipment::STATUS_PENDING, $pendingShipment->refresh()->status);
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);

        app(CancelPurchaseOrderLine::class)->handle(
            $order,
            $line->refresh(),
            5,
            'Supplier cancelled the replacement balance.',
            $this->actor
        );

        $this->assertSame(5, $pendingShipment->lines()->sole()->qty_cancelled);
        $this->assertSame(PurchaseShipment::STATUS_CANCELLED, $pendingShipment->refresh()->status);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
        $this->assertSame(7, $line->refresh()->qty_cancelled);
    }

    #[Test]
    public function duplicate_serials_are_rejected_before_the_database_unique_constraint(): void
    {
        $item = $this->item([
            'has_serials' => true,
            'track_batch' => true,
        ]);
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 2]]);
        $line = $order->lines->sole();

        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 2,
                'qty_rejected' => 0,
                'units' => [
                    [
                        'serial_no' => 'DUPLICATE-01',
                        'batch_no' => 'BATCH-A',
                        'quantity' => 1,
                    ],
                    [
                        'serial_no' => 'duplicate-01',
                        'batch_no' => 'BATCH-B',
                        'quantity' => 1,
                    ],
                ],
            ]],
        ], $this->actor));

        $this->assertSame(0, PurchaseReceipt::query()->count());
        $this->assertSame(0, StockUnit::query()->count());
        $this->assertSame(0, $item->refresh()->qty_on_hand);
    }

    #[Test]
    public function batch_receipt_does_not_reuse_or_reactivate_a_historical_serial_unit(): void
    {
        $item = $this->item([
            'track_batch' => true,
            'expiry_enabled' => true,
        ]);
        $historicalSerial = StockUnit::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'room_id' => null,
            'box_id' => null,
            'serial_no' => 'HISTORICAL-SERIAL-01',
            'batch_no' => 'SHARED-BATCH',
            'expiry_date' => '2030-12-31',
            'status' => 'consumed',
            'current_qty' => 0,
            'metadata' => ['historical_serial' => true],
        ]);
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 2]]);
        $line = $order->lines->sole();

        $receipt = app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 2,
                'qty_rejected' => 0,
                'units' => [[
                    'batch_no' => 'SHARED-BATCH',
                    'expiry_date' => '2030-12-31',
                    'quantity' => 2,
                ]],
            ]],
        ], $this->actor);

        $historicalSerial->refresh();
        $this->assertSame(0, $historicalSerial->current_qty);
        $this->assertSame('consumed', $historicalSerial->status);
        $this->assertSame(['historical_serial' => true], $historicalSerial->metadata);

        $batchUnit = StockUnit::query()
            ->where('item_id', $item->id)
            ->whereNull('serial_no')
            ->sole();
        $this->assertSame('SHARED-BATCH', $batchUnit->batch_no);
        $this->assertSame('2030-12-31', $batchUnit->expiry_date->toDateString());
        $this->assertSame(2, $batchUnit->current_qty);
        $this->assertSame('available', $batchUnit->status);
        $this->assertSame(
            $batchUnit->id,
            $receipt->lines->sole()->units->sole()->stock_unit_id
        );
        $this->assertSame(2, $item->refresh()->qty_on_hand);
    }

    #[Test]
    public function cancellation_history_is_immutable_and_outstanding_purchase_lines_block_item_deletion(): void
    {
        $firstItem = $this->item(['name' => 'Protected line']);
        $secondItem = $this->item(['name' => 'Retained line']);
        $order = $this->order([
            [
                'item_id' => $firstItem->id,
                'qty_ordered' => 2,
                'metadata' => [
                    'cancellation_history' => [['quantity' => 99]],
                    'kind' => 'forged_kind',
                    'vendor_order_sent' => true,
                    'custom_note' => 'Allowed custom metadata.',
                ],
            ],
            [
                'item_id' => $secondItem->id,
                'qty_ordered' => 1,
            ],
        ]);
        [$firstLine, $secondLine] = $order->lines->values()->all();

        $this->assertArrayNotHasKey('cancellation_history', $firstLine->metadata);
        $this->assertArrayNotHasKey('kind', $firstLine->metadata);
        $this->assertArrayNotHasKey('vendor_order_sent', $firstLine->metadata);
        $this->assertSame('Allowed custom metadata.', $firstLine->metadata['custom_note']);

        $firstLine->forceFill([
            'metadata' => array_replace($firstLine->metadata, [
                'kind' => 'ticket_purchase_need',
                'approved_quote_version_id' => 77,
                'vendor_order_sent' => false,
            ]),
        ])->save();
        app(CancelPurchaseOrderLine::class)->handle(
            $order,
            $firstLine,
            1,
            'Supplier reduced the available quantity.',
            $this->actor
        );
        $cancelledLine = $firstLine->refresh();
        $cancellationReason = $cancelledLine->cancellation_reason;
        $cancellationActorId = $cancelledLine->cancelled_by;

        $this->expectValidation(fn () => app(UpdatePurchaseOrder::class)->handle(
            $order,
            ['lines' => [
                [
                    'id' => $firstLine->id,
                    'item_id' => $firstItem->id,
                    'qty_ordered' => 3,
                    'qty_cancelled' => 1,
                ],
                [
                    'id' => $secondLine->id,
                    'item_id' => $secondItem->id,
                    'qty_ordered' => 1,
                    'qty_cancelled' => 0,
                ],
            ]],
            $this->actor
        ));

        app(UpdatePurchaseOrder::class)->handle(
            $order,
            ['lines' => [
                [
                    'id' => $firstLine->id,
                    'item_id' => $firstItem->id,
                    'qty_ordered' => 2,
                    'qty_cancelled' => 1,
                    'cancellation_reason' => 'Forged cancellation reason.',
                    'metadata' => [
                        'cancellation_history' => [],
                        'kind' => 'forged_update',
                        'approved_quote_version_id' => 999,
                        'vendor_order_sent' => true,
                        'custom_note' => 'Updated custom metadata.',
                    ],
                ],
                [
                    'id' => $secondLine->id,
                    'item_id' => $secondItem->id,
                    'qty_ordered' => 1,
                    'qty_cancelled' => 0,
                ],
            ]],
            $this->actor
        );

        $metadata = $firstLine->refresh()->metadata;
        $this->assertCount(1, $metadata['cancellation_history']);
        $this->assertSame('ticket_purchase_need', $metadata['kind']);
        $this->assertSame(77, $metadata['approved_quote_version_id']);
        $this->assertFalse($metadata['vendor_order_sent']);
        $this->assertSame('Updated custom metadata.', $metadata['custom_note']);
        $this->assertSame($cancellationReason, $firstLine->cancellation_reason);
        $this->assertSame($cancellationActorId, $firstLine->cancelled_by);

        $this->expectValidation(fn () => app(UpdatePurchaseOrder::class)->handle(
            $order,
            ['lines' => [[
                'id' => $secondLine->id,
                'item_id' => $secondItem->id,
                'qty_ordered' => 1,
                'qty_cancelled' => 0,
            ]]],
            $this->actor
        ));

        $deleteItem = $this->item(['name' => 'Awaiting purchase']);
        $deleteOrder = $this->order([[
            'item_id' => $deleteItem->id,
            'qty_ordered' => 1,
        ]]);
        $this->expectInvalidArgument(fn () => app(DeleteItem::class)->handle(
            $deleteItem,
            $this->actor
        ));
        $this->assertNull($deleteItem->refresh()->deleted_at);

        app(CancelPurchaseOrder::class)->handle(
            $deleteOrder,
            'The supplier cancelled the entire order.',
            $this->actor
        );
        app(DeleteItem::class)->handle($deleteItem, $this->actor);
        $this->assertNotNull(Item::withTrashed()->findOrFail($deleteItem->id)->deleted_at);
    }

    #[Test]
    public function reversing_rejections_reconciles_reopened_allocations_after_replacement_or_line_cancellation(): void
    {
        $makeRejectedOrder = function (): array {
            $item = $this->item();
            $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 5]]);
            $line = $order->lines->sole();
            $shipment = app(StorePurchaseShipment::class)->handle($order, [
                'allocations' => [[
                    'purchase_order_line_id' => $line->id,
                    'qty_allocated' => 5,
                ]],
            ], $this->actor);
            $receipt = app(PostPurchaseReceipt::class)->handle($order, [
                'idempotency_token' => (string) Str::uuid(),
                'purchase_shipment_id' => $shipment->id,
                'lines' => [[
                    'purchase_order_line_id' => $line->id,
                    'qty_accepted' => 0,
                    'qty_rejected' => 5,
                    'discrepancy_note' => 'Every delivered unit was rejected.',
                    'units' => [],
                ]],
            ], $this->actor);

            return [$item, $order, $line, $shipment, $receipt];
        };

        [$replacementItem, $replacementOrder, $replacementLine, $originalShipment, $originalReceipt] = $makeRejectedOrder();
        $replacementShipment = app(StorePurchaseShipment::class)->handle($replacementOrder, [
            'allocations' => [[
                'purchase_order_line_id' => $replacementLine->id,
                'qty_allocated' => 5,
            ]],
        ], $this->actor);

        $replacementReversal = app(ReversePurchaseReceipt::class)->handle(
            $originalReceipt,
            [
                'idempotency_token' => (string) Str::uuid(),
                'reason' => 'The rejection event must be removed after replacement was arranged.',
            ],
            $this->actor
        );

        $originalAllocation = $originalShipment->lines()->sole();
        $this->assertSame(0, $originalAllocation->refresh()->qty_rejected);
        $this->assertSame(5, $originalAllocation->qty_cancelled);
        $this->assertSame(0, $originalAllocation->qty_outstanding);
        $this->assertSame(5, $replacementShipment->lines()->sole()->qty_outstanding);
        $this->assertSame(5, $replacementLine->refresh()->qty_outstanding);
        $this->assertSame(
            5,
            $replacementReversal->lines->sole()
                ->metadata['shipment_allocation_reconciliation']['quantity_terminalized']
        );

        [$cancelItem, $cancelOrder, $cancelLine, $cancelShipment, $cancelReceipt] = $makeRejectedOrder();
        app(CancelPurchaseOrderLine::class)->handle(
            $cancelOrder,
            $cancelLine,
            5,
            'Supplier will not replace the rejected units.',
            $this->actor
        );
        app(DeleteItem::class)->handle($cancelItem, $this->actor);
        $this->assertNotNull(Item::withTrashed()->findOrFail($cancelItem->id)->deleted_at);

        $cancelReversal = app(ReversePurchaseReceipt::class)->handle(
            $cancelReceipt,
            [
                'idempotency_token' => (string) Str::uuid(),
                'reason' => 'Remove the rejection while retaining explicit line cancellation.',
            ],
            $this->actor
        );

        $cancelAllocation = $cancelShipment->lines()->sole();
        $this->assertSame(0, $cancelAllocation->refresh()->qty_rejected);
        $this->assertSame(5, $cancelAllocation->qty_cancelled);
        $this->assertSame(0, $cancelAllocation->qty_outstanding);
        $this->assertSame(0, $cancelLine->refresh()->qty_outstanding);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $cancelOrder->refresh()->status);
        $this->assertSame(
            5,
            $cancelReversal->lines->sole()
                ->metadata['shipment_allocation_reconciliation']['quantity_terminalized']
        );
    }

    #[Test]
    public function a_late_serial_validation_failure_rolls_back_every_receipt_effect(): void
    {
        $plain = $this->item(['name' => 'Plain Item', 'qty_on_hand' => 4]);
        $serialized = $this->item([
            'name' => 'Serialized Item',
            'has_serials' => true,
            'qty_on_hand' => 0,
        ]);
        $order = $this->order([
            ['item_id' => $plain->id, 'qty_ordered' => 1],
            ['item_id' => $serialized->id, 'qty_ordered' => 1],
        ]);
        [$plainLine, $serialLine] = $order->lines->values()->all();

        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [
                [
                    'purchase_order_line_id' => $plainLine->id,
                    'qty_accepted' => 1,
                    'qty_rejected' => 0,
                    'units' => [],
                ],
                [
                    'purchase_order_line_id' => $serialLine->id,
                    'qty_accepted' => 1,
                    'qty_rejected' => 0,
                    'units' => [],
                ],
            ],
        ], $this->actor));

        $this->assertSame(4, $plain->refresh()->qty_on_hand);
        $this->assertSame(0, $serialized->refresh()->qty_on_hand);
        $this->assertSame(0, $plainLine->refresh()->qty_received);
        $this->assertSame(0, PurchaseReceipt::query()->count());
        $this->assertSame(0, Movement::query()->count());
        $this->assertSame(0, StockUnit::query()->count());
    }

    #[Test]
    public function over_delivery_requires_authority_and_reason_and_counts_rejections(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 2]]);
        $line = $order->lines->first();
        $payload = [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 2,
                'qty_rejected' => 1,
                'units' => [],
            ]],
        ];

        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)
            ->handle($order, $payload, $this->actor));
        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)
            ->handle($order, $payload, $this->actor, true));

        $payload['lines'][0]['over_receipt_reason'] = 'Supplier included one damaged extra unit.';
        $receipt = app(PostPurchaseReceipt::class)
            ->handle($order, $payload, $this->actor, true);

        $this->assertTrue($receipt->lines->first()->is_over_receipt);
        $this->assertSame(2, $item->refresh()->qty_on_hand);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
    }

    #[Test]
    public function reversal_restores_aggregates_and_units_but_blocks_reserved_stock(): void
    {
        $item = $this->item([
            'name' => 'Expiry Batch',
            'track_batch' => true,
            'expiry_enabled' => true,
        ]);
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 2]]);
        $line = $order->lines->first();

        $receipt = app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 2,
                'qty_rejected' => 0,
                'units' => [[
                    'batch_no' => 'BATCH-1',
                    'expiry_date' => now()->addYear()->toDateString(),
                    'quantity' => 2,
                ]],
            ]],
        ], $this->actor);
        $stockUnit = StockUnit::query()->sole();
        $reversalPayload = [
            'idempotency_token' => (string) Str::uuid(),
            'reason' => 'Receipt posted against the wrong delivery note.',
        ];

        $reversal = app(ReversePurchaseReceipt::class)
            ->handle($receipt, $reversalPayload, $this->actor);

        $this->assertSame(PurchaseReceipt::TYPE_REVERSAL, $reversal->receipt_type);
        $this->assertSame(PurchaseReceipt::STATUS_REVERSED, $receipt->refresh()->status);
        $this->assertSame(0, $item->refresh()->qty_on_hand);
        $this->assertSame(0, $line->refresh()->qty_received);
        $this->assertSame(0, $stockUnit->refresh()->current_qty);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->refresh()->status);
        $this->assertDatabaseHas('storage_movements', [
            'type' => 'receive_reversal',
            'qty_delta' => -2,
        ]);
        $this->assertSame(1, PurchaseReceiptReversal::query()->count());

        $retry = app(ReversePurchaseReceipt::class)
            ->handle($receipt, $reversalPayload, $this->actor);
        $this->assertSame($reversal->id, $retry->id);
        $this->assertSame(2, PurchaseReceipt::query()->count());

        $secondReceipt = app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 2,
                'qty_rejected' => 0,
                'units' => [[
                    'batch_no' => 'BATCH-1',
                    'expiry_date' => now()->addYear()->toDateString(),
                    'quantity' => 2,
                ]],
            ]],
        ], $this->actor);

        // Simulate a non-unit-aware Ticket pick after another identified batch
        // arrived: aggregate stock changes while StockUnit quantities do not.
        $otherBatch = StockUnit::query()->create([
            'item_id' => $item->id,
            'warehouse_id' => $this->warehouse->id,
            'batch_no' => 'BATCH-OTHER',
            'expiry_date' => now()->addYear()->toDateString(),
            'status' => 'available',
            'current_qty' => 1,
        ]);
        $item->forceFill(['qty_on_hand' => 3])->save();
        $item->forceFill(['qty_on_hand' => 2])->save();

        $this->expectValidation(fn () => app(ReversePurchaseReceipt::class)->handle(
            $secondReceipt,
            [
                'idempotency_token' => (string) Str::uuid(),
                'reason' => 'Block reversal against an inconsistent unit ledger.',
            ],
            $this->actor,
        ));
        $this->assertSame(PurchaseReceipt::STATUS_POSTED, $secondReceipt->refresh()->status);

        $otherBatch->forceFill(['current_qty' => 0, 'status' => 'consumed'])->save();
        $item->forceFill(['qty_reserved' => 1])->save();

        $this->expectValidation(fn () => app(ReversePurchaseReceipt::class)->handle(
            $secondReceipt,
            [
                'idempotency_token' => (string) Str::uuid(),
                'reason' => 'Attempt unsafe reversal.',
            ],
            $this->actor,
        ));
        $this->assertSame(PurchaseReceipt::STATUS_POSTED, $secondReceipt->refresh()->status);
        $this->assertSame(2, $item->refresh()->qty_on_hand);
    }

    #[Test]
    public function completed_receipt_retries_remain_idempotent_after_the_order_is_received(): void
    {
        $item = $this->item(['qty_on_hand' => 4]);
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 1]]);
        $line = $order->lines->first();
        $payload = [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ];

        $receipt = app(PostPurchaseReceipt::class)->handle($order, $payload, $this->actor);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);

        $retry = app(PostPurchaseReceipt::class)->handle($order, $payload, $this->actor);

        $this->assertSame($receipt->id, $retry->id);
        $this->assertSame(1, PurchaseReceipt::query()->count());
        $this->assertSame(1, Movement::query()->count());
        $this->assertSame(5, $item->refresh()->qty_on_hand);
    }

    #[Test]
    public function receipt_rejects_an_active_box_when_its_inferred_room_is_inactive(): void
    {
        $room = Room::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'name' => 'Retired room',
            'code' => 'OLD',
            'is_active' => false,
        ]);
        $box = Box::query()->create([
            'uuid' => (string) Str::uuid(),
            'warehouse_id' => $this->warehouse->id,
            'room_id' => $room->id,
            'code_human' => 'OLD-BOX',
            'name' => 'Old box',
            'barcode_value' => 'OLD-BOX',
            'barcode_type' => 'QR',
            'status' => 'in_stock',
            'is_active' => true,
        ]);
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 1]]);
        $line = $order->lines->first();

        $this->expectValidation(fn () => app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'box_id' => $box->id,
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ], $this->actor));

        $this->assertSame(0, PurchaseReceipt::query()->count());
        $this->assertSame(0, Movement::query()->count());
        $this->assertSame(0, $item->refresh()->qty_on_hand);
    }

    #[Test]
    public function operational_order_edits_preserve_supplier_snapshot_and_lock_commercial_currency(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 2]]);
        $line = $order->lines->first();
        app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 2,
            ]],
        ], $this->actor);

        $this->vendor->forceFill(['name' => 'Renamed Live Supplier'])->save();
        $updated = app(UpdatePurchaseOrder::class)->handle($order, [
            'notes' => 'Header note only.',
        ], $this->actor);

        $this->assertSame('Nordic Supplier', $updated->supplier_name_snapshot);
        $this->expectValidation(fn () => app(UpdatePurchaseOrder::class)->handle(
            $updated,
            ['currency' => 'EUR'],
            $this->actor
        ));

        $updated->forceFill(['currency' => 'EUR'])->save();
        $this->expectValidation(fn () => app(UpdatePurchaseOrder::class)->handle(
            $updated,
            [
                'lines' => [[
                    'id' => $line->id,
                    'item_id' => $item->id,
                    'qty_ordered' => 2,
                    'qty_cancelled' => 0,
                ]],
            ],
            $this->actor
        ));
        $this->assertSame('NOK', $line->refresh()->currency);
    }

    #[Test]
    public function draft_orders_stay_draft_and_shipments_do_not_inherit_order_completion(): void
    {
        $draftItem = $this->item();
        $draft = $this->order([[
            'item_id' => $draftItem->id,
            'qty_ordered' => 1,
        ]], [
            'status' => PurchaseOrder::STATUS_DRAFT,
            'ordered_at' => null,
        ]);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $draft->status);

        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 5]]);
        $line = $order->lines->first();
        $shipment = app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 5,
            ]],
        ], $this->actor);
        app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $shipment->id,
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 3,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ], $this->actor);

        // Represent legacy inconsistent cancellation data to prove shipment
        // completion is based on its own allocation, not the parent status.
        $line->forceFill([
            'qty_cancelled' => 2,
            'cancellation_reason' => 'Legacy cancellation',
        ])->save();
        app(RefreshPurchaseOrderStatus::class)->handle($order, $this->actor);
        app(RefreshPurchaseShipmentStatus::class)->handle($shipment, $this->actor);

        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
        $this->assertSame(PurchaseShipment::STATUS_PARTIALLY_RECEIVED, $shipment->refresh()->status);
    }

    #[Test]
    public function lifecycle_actions_enforce_allocations_forward_transitions_and_append_only_tracking(): void
    {
        $carrier = ShippingCarrier::query()->create([
            'code' => 'LIFE',
            'name' => 'Lifecycle Carrier',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'website_url' => 'https://carrier.example',
            'tracking_page_url' => 'https://track.carrier.example',
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
            'tracking_url_template' => 'https://track.carrier.example/{tracking_number}',
            'allowed_tracking_hosts' => ['track.carrier.example'],
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'source_url' => 'https://carrier.example/help',
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'verified_at' => now()->toDateString(),
        ]);
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 3]]);
        $line = $order->lines->first();
        $shipment = app(StorePurchaseShipment::class)->handle($order, [
            'shipping_carrier_id' => $carrier->id,
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 3,
            ]],
        ], $this->actor);

        $this->expectValidation(fn () => app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_DELIVERED,
            now(),
            'Invalid skipped transition.',
            $this->actor
        ));

        $tracking = app(AppendPurchaseShipmentTracking::class)->handle($shipment, [
            'tracking_number' => 'LIFE 123',
            'tracking_type' => 'parcel',
        ], $this->actor);
        $this->assertSame('LIFE 123', $tracking->tracking_number);
        $this->assertSame(
            'https://track.carrier.example/LIFE%20123',
            $tracking->tracking_url
        );

        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_IN_TRANSIT,
            now(),
            'Carrier collected the parcel.',
            $this->actor
        );
        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_DELIVERED,
            now(),
            'Carrier marked the parcel delivered.',
            $this->actor
        );
        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_CANCELLED,
            now(),
            'Shipment record was voided before receiving.',
            $this->actor
        );
        $this->assertCount(3, $shipment->metadata['status_history']);

        app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 0,
                'qty_rejected' => 1,
                'discrepancy_note' => 'Cancelled parcel was recorded as rejected.',
                'units' => [],
            ]],
        ], $this->actor);

        app(CancelPurchaseOrderLine::class)->handle(
            $order,
            $line,
            3,
            'Supplier cancelled the undelivered line.',
            $this->actor
        );
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->refresh()->status);
        $this->assertCount(1, $line->refresh()->metadata['cancellation_history']);

        $closed = app(ClosePurchaseOrder::class)->handle(
            $order,
            'All quantities are accepted or cancelled.',
            $this->actor
        );
        $this->assertSame(PurchaseOrder::STATUS_CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);
        $this->assertCount(1, $closed->metadata['lifecycle_history']);
        $this->expectValidation(fn () => app(AppendPurchaseShipmentTracking::class)->handle(
            $shipment,
            ['tracking_number' => 'TOO-LATE'],
            $this->actor
        ));
    }

    #[Test]
    public function order_and_shipment_cancellation_require_no_active_shipping_or_receipt_events(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 2]]);
        $line = $order->lines->first();
        $shipment = app(StorePurchaseShipment::class)->handle($order, [], $this->actor);

        $this->expectValidation(fn () => app(CancelPurchaseOrder::class)->handle(
            $order,
            'Cancel everything.',
            $this->actor
        ));
        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_CANCELLED,
            now(),
            'Supplier never dispatched it.',
            $this->actor
        );
        $cancelled = app(CancelPurchaseOrder::class)->handle(
            $order,
            'Supplier cancelled the order.',
            $this->actor
        );
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $cancelled->status);
        $this->assertSame(2, $line->refresh()->qty_cancelled);
        $this->assertCount(1, $cancelled->metadata['lifecycle_history']);

        $secondItem = $this->item();
        $secondOrder = $this->order([['item_id' => $secondItem->id, 'qty_ordered' => 1]]);
        $secondLine = $secondOrder->lines->first();
        $secondShipment = app(StorePurchaseShipment::class)->handle($secondOrder, [], $this->actor);
        app(PostPurchaseReceipt::class)->handle($secondOrder, [
            'idempotency_token' => (string) Str::uuid(),
            'purchase_shipment_id' => $secondShipment->id,
            'lines' => [[
                'purchase_order_line_id' => $secondLine->id,
                'qty_accepted' => 0,
                'qty_rejected' => 1,
                'discrepancy_note' => 'Parcel contained a damaged unit.',
                'units' => [],
            ]],
        ], $this->actor);

        $this->expectValidation(fn () => app(UpdatePurchaseShipmentStatus::class)->handle(
            $secondShipment,
            PurchaseShipment::STATUS_CANCELLED,
            now(),
            'Cannot cancel after receipt event.',
            $this->actor
        ));
        $this->expectValidation(fn () => app(CancelPurchaseOrder::class)->handle(
            $secondOrder,
            'Cannot cancel after receipt event.',
            $this->actor
        ));
    }

    #[Test]
    public function balance_writers_and_tracking_configuration_use_fresh_locked_state(): void
    {
        $item = $this->item(['qty_on_hand' => 5]);
        $staleItem = Item::query()->findOrFail($item->id);
        DB::table('storage_items')->where('id', $item->id)->update(['qty_on_hand' => 8]);

        $adjusted = app(AdjustItemStock::class)->setOnHand(
            $staleItem,
            2,
            'physical_count',
            'Counted after a concurrent receipt.',
            $this->actor
        );
        $movement = Movement::query()->latest('id')->firstOrFail();
        $this->assertSame(2, $adjusted->qty_on_hand);
        $this->assertSame(8, $movement->qty_before);
        $this->assertSame(-6, $movement->qty_delta);
        $this->assertSame(2, $movement->qty_after);

        $deleteCandidate = $this->item();
        $staleDeleteCandidate = Item::query()->findOrFail($deleteCandidate->id);
        DB::table('storage_items')
            ->where('id', $deleteCandidate->id)
            ->update(['qty_on_hand' => 1]);
        $this->expectInvalidArgument(fn () => app(DeleteItem::class)->handle(
            $staleDeleteCandidate,
            $this->actor
        ));
        $this->assertNull(Item::withTrashed()->findOrFail($deleteCandidate->id)->deleted_at);

        $tracked = $this->item(['has_serials' => true]);
        $this->expectInvalidArgument(fn () => app(AdjustItemStock::class)->handle(
            $tracked,
            1,
            'generic_adjustment',
            null,
            $this->actor
        ));
        $this->expectValidation(fn () => app(StoreItem::class)->handle([
            'warehouse_id' => $this->warehouse->id,
            'sku' => 'TRACKED-INITIAL',
            'name' => 'Tracked initial item',
            'has_serials' => true,
            'track_batch' => false,
            'expiry_enabled' => false,
            'initial_quantity' => 1,
            'status' => 'active',
        ], $this->actor));

        $reserved = $this->item(['qty_reserved' => 1]);
        $this->expectValidation(fn () => DB::transaction(
            fn () => app(GuardItemTrackingConfiguration::class)
                ->lockAndValidateUpdate($reserved, ['track_batch' => true])
        ));

        $unitBacked = $this->item();
        StockUnit::query()->create([
            'item_id' => $unitBacked->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_no' => 'LEGACY-UNIT',
            'status' => 'available',
            'current_qty' => 1,
        ]);
        $this->expectValidation(fn () => DB::transaction(
            fn () => app(GuardItemTrackingConfiguration::class)
                ->lockAndValidateUpdate($unitBacked, ['expiry_enabled' => true])
        ));
        $this->expectInvalidArgument(fn () => app(AdjustItemStock::class)->handle(
            $unitBacked,
            1,
            'generic_adjustment',
            null,
            $this->actor
        ));
    }

    #[Test]
    public function receipt_schema_rollback_is_allowed_only_while_the_ledger_is_empty(): void
    {
        $migration = require database_path(
            'migrations/2026_08_04_112000_create_storage_purchase_receipts.php'
        );

        $migration->down();
        $this->assertFalse(Schema::hasTable('storage_purchase_receipts'));

        $migration->up();
        $this->assertTrue(Schema::hasTable('storage_purchase_receipts'));
    }

    #[Test]
    public function rollback_guards_refuse_any_receipt_and_operational_procurement_data(): void
    {
        $item = $this->item();
        $order = $this->order([['item_id' => $item->id, 'qty_ordered' => 1]]);
        $line = $order->lines->first();
        app(StorePurchaseShipment::class)->handle($order, [
            'allocations' => [[
                'purchase_order_line_id' => $line->id,
                'qty_allocated' => 1,
            ]],
        ], $this->actor);
        PurchaseReceipt::query()->create([
            'receipt_number' => 'RCV-GUARD',
            'purchase_order_id' => $order->id,
            'receipt_type' => PurchaseReceipt::TYPE_RECEIPT,
            'status' => PurchaseReceipt::STATUS_POSTING,
            'idempotency_token' => (string) Str::uuid(),
            'request_hash' => str_repeat('a', 64),
            'received_at' => now(),
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);

        $receiptMigration = require database_path(
            'migrations/2026_08_04_112000_create_storage_purchase_receipts.php'
        );
        $shipmentMigration = require database_path(
            'migrations/2026_08_04_111000_create_storage_purchase_shipments.php'
        );
        $orderMigration = require database_path(
            'migrations/2026_08_04_110000_extend_storage_purchase_orders_for_receiving.php'
        );

        $this->expectRuntime(fn () => $receiptMigration->down());
        $this->expectRuntime(fn () => $shipmentMigration->down());
        $this->expectRuntime(fn () => $orderMigration->down());
        $this->assertTrue(Schema::hasTable('storage_purchase_receipts'));
        $this->assertTrue(Schema::hasTable('storage_purchase_shipments'));
        $this->assertTrue(Schema::hasColumn('storage_purchase_order_lines', 'qty_cancelled'));
    }

    /** @param array<string, mixed> $attributes */
    private function item(array $attributes = []): Item
    {
        return Item::query()->create($attributes + [
            'warehouse_id' => $this->warehouse->id,
            'sku' => 'SKU-'.Str::upper(Str::random(10)),
            'name' => 'Storage Item',
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'status' => 'active',
            'can_be_ordered' => true,
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     * @param  array<string, mixed>  $overrides
     */
    private function order(array $lines, array $overrides = []): PurchaseOrder
    {
        $this->orderSequence++;

        return app(StorePurchaseOrder::class)->handle($overrides + [
            'po_number' => 'PO-TEST-'.$this->orderSequence,
            'vendor_id' => $this->vendor->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now()->toDateString(),
            'currency' => 'NOK',
            'lines' => $lines,
        ], $this->actor);
    }

    private function expectValidation(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a validation exception.');
        } catch (ValidationException) {
            $this->addToAssertionCount(1);
        }
    }

    private function expectInvalidArgument(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected an invalid-argument exception.');
        } catch (InvalidArgumentException) {
            $this->addToAssertionCount(1);
        }
    }

    private function expectRuntime(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a runtime exception.');
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }
    }
}
