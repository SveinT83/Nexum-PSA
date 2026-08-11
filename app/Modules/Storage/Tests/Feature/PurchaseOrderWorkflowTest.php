<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\StorePurchaseShipment;
use App\Modules\Storage\Actions\UpdatePurchaseOrder;
use App\Modules\Storage\Actions\UpdatePurchaseShipmentStatus;
use App\Modules\Storage\Controllers\Tech\PurchaseOrderController;
use App\Modules\Storage\Controllers\Tech\PurchaseShipmentController;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\PurchaseOrderIndexQuery;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private Warehouse $warehouse;

    private Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        foreach ([
            'storage.view',
            'storage.pick',
            'storage.purchase_view',
            'storage.purchase_manage',
            'storage.purchase_receive',
            'storage.purchase_receive_overage',
            'storage.purchase_reverse',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');

        $this->warehouse = Warehouse::query()->create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);
        $this->supplier = Vendor::query()->create([
            'name' => 'Nordic Supplier',
            'vendor_code' => 'NORDIC',
            'is_supplier' => true,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function purchase_order_routes_use_storage_module_controllers(): void
    {
        $this->assertSame(
            PurchaseOrderController::class.'@index',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.index')->getActionName()
        );
        $this->assertSame(
            PurchaseOrderController::class.'@store',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.store')->getActionName()
        );
        $this->assertSame(
            PurchaseShipmentController::class.'@store',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.shipments.store')->getActionName()
        );
        $this->assertSame(
            PurchaseOrderController::class.'@cancelLine',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.lines.cancel')->getActionName()
        );
        $this->assertSame(
            PurchaseOrderController::class.'@close',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.close')->getActionName()
        );
        $this->assertSame(
            PurchaseOrderController::class.'@cancel',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.cancel')->getActionName()
        );
        $this->assertSame(
            PurchaseShipmentController::class.'@updateStatus',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.shipments.status.update')->getActionName()
        );
        $this->assertSame(
            PurchaseShipmentController::class.'@storeTracking',
            Route::getRoutes()->getByName('tech.storage.purchase-orders.shipments.trackings.store')->getActionName()
        );
    }

    #[Test]
    public function purchase_order_create_form_defaults_ordered_date_to_today(): void
    {
        $this->travelTo(Carbon::parse('2026-08-05 10:00:00'));
        $this->grant('storage.purchase_manage');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.create'))
            ->assertOk()
            ->assertViewHas(
                'purchaseOrder',
                fn (PurchaseOrder $purchaseOrder): bool => $purchaseOrder->ordered_at?->toDateString()
                    === '2026-08-05'
            )
            ->assertSee('value="2026-08-05"', false);
    }

    #[Test]
    public function purchase_order_create_form_uses_the_technicians_local_date_at_the_utc_boundary(): void
    {
        $this->travelTo(Carbon::parse('2026-08-04 22:30:00', 'UTC'));
        $this->grant('storage.purchase_manage');
        UserPreference::query()->create([
            'user_id' => $this->tech->id,
            'timezone' => 'Europe/Oslo',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.create'))
            ->assertOk()
            ->assertViewHas(
                'purchaseOrder',
                fn (PurchaseOrder $purchaseOrder): bool => $purchaseOrder->ordered_at?->toDateString()
                    === '2026-08-05'
            )
            ->assertSee('value="2026-08-05"', false);
    }

    #[Test]
    public function purchase_order_index_sort_links_preserve_the_current_search_and_filters(): void
    {
        $this->grant('storage.purchase_view');
        $parameters = [
            'q' => 'PO-',
            'status' => PurchaseOrder::STATUS_ORDERED,
            'sort' => 'order',
            'direction' => 'asc',
        ];
        $requestParameters = $parameters + ['page' => 2];

        $response = $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.index', $requestParameters));

        $response->assertOk()
            ->assertSee('aria-sort="ascending"', false)
            ->assertSee('name="sort" value="order"', false)
            ->assertSee('name="direction" value="asc"', false)
            ->assertSee(e(route('tech.storage.purchase-orders.index', array_merge(
                $parameters,
                ['sort' => 'order', 'direction' => 'desc']
            ))), false)
            ->assertSee(e(route('tech.storage.purchase-orders.index', array_merge(
                $parameters,
                ['sort' => 'supplier', 'direction' => 'asc']
            ))), false);
        $this->assertSame(1, substr_count($response->getContent(), 'aria-sort='));
    }

    #[Test]
    public function purchase_order_index_sorts_every_visible_column(): void
    {
        $this->grant('storage.purchase_view');
        $item = $this->item('SORT-ITEM', 'Sortable Item');
        $first = $this->placedOrder($item, 'PO-B', 10);
        $second = $this->placedOrder($item, 'PO-A', 10);
        $third = $this->placedOrder($item, 'PO-C', 10);
        $fallbackSupplier = Vendor::query()->create([
            'name' => 'Mike Supplier',
            'vendor_code' => 'MIKE-SORT',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $first->update([
            'supplier_name_snapshot' => '',
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => '2026-08-03',
            'expected_at' => '2026-08-12',
        ]);
        $second->update([
            'supplier_name_snapshot' => 'Alpha Supplier',
            'status' => PurchaseOrder::STATUS_RECEIVED,
            'ordered_at' => '2026-08-01',
            'expected_at' => null,
        ]);
        $third->update([
            'vendor_id' => $fallbackSupplier->id,
            'supplier_name_snapshot' => null,
            'status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED,
            'ordered_at' => null,
            'expected_at' => '2026-08-10',
        ]);
        $first->lines()->update(['qty_received' => 2]);
        $second->lines()->update(['qty_received' => 10]);
        $third->lines()->update(['qty_received' => 5]);

        foreach ([1, 2] as $sequence) {
            $second->shipments()->create([
                'reference' => 'PO-A-SHIPMENT-'.$sequence,
                'status' => PurchaseShipment::STATUS_PENDING,
                'created_by' => $this->tech->id,
                'updated_by' => $this->tech->id,
            ]);
        }
        $first->shipments()->create([
            'reference' => 'PO-B-SHIPMENT-1',
            'status' => PurchaseShipment::STATUS_PENDING,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $assertOrder = function (string $sort, string $direction, array $expected): void {
            $this->actingAs($this->tech)
                ->get(route('tech.storage.purchase-orders.index', [
                    'sort' => $sort,
                    'direction' => $direction,
                ]))
                ->assertOk()
                ->assertSeeInOrder($expected);
        };

        $assertOrder('order', 'asc', ['PO-A', 'PO-B', 'PO-C']);
        $assertOrder('order', 'desc', ['PO-C', 'PO-B', 'PO-A']);
        $assertOrder('supplier', 'asc', ['PO-A', 'PO-C', 'PO-B']);
        $assertOrder('supplier', 'desc', ['PO-B', 'PO-C', 'PO-A']);
        $assertOrder('status', 'asc', ['PO-B', 'PO-C', 'PO-A']);
        $assertOrder('status', 'desc', ['PO-A', 'PO-C', 'PO-B']);
        $assertOrder('expected', 'asc', ['PO-C', 'PO-B', 'PO-A']);
        $assertOrder('expected', 'desc', ['PO-B', 'PO-C', 'PO-A']);
        $assertOrder('progress', 'asc', ['PO-B', 'PO-C', 'PO-A']);
        $assertOrder('progress', 'desc', ['PO-A', 'PO-C', 'PO-B']);
        $assertOrder('outstanding', 'asc', ['PO-A', 'PO-C', 'PO-B']);
        $assertOrder('outstanding', 'desc', ['PO-B', 'PO-C', 'PO-A']);

        $this->assertSame(
            ['PO-A', 'PO-B', 'PO-C'],
            app(PurchaseOrderIndexQuery::class)
                ->paginate(['sort' => 'order', 'direction' => 'not-valid'])
                ->pluck('po_number')
                ->all()
        );

        $first->lines()->update(['qty_received' => 12]);
        $assertOrder('progress', 'desc', ['PO-A', 'PO-B', 'PO-C']);

        PurchaseOrder::query()->create([
            'po_number' => 'PO-D',
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
        $assertOrder('progress', 'asc', ['PO-D', 'PO-C', 'PO-A', 'PO-B']);

        $this->assertSame(4, app(PurchaseOrderIndexQuery::class)
            ->paginate(['sort' => 'po_number; drop table users', 'direction' => 'desc'])
            ->total());
    }

    #[Test]
    public function view_only_technician_sees_purchase_orders_without_mutating_or_receiving_links(): void
    {
        $this->grant('storage.purchase_view');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.index'))
            ->assertOk()
            ->assertSee('Supplier Orders')
            ->assertSee(route('tech.storage.purchase-orders.index'), false)
            ->assertDontSee(route('tech.storage.purchase-orders.create'), false)
            ->assertDontSee(route('tech.storage.receiving.index'), false);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.create'))
            ->assertForbidden();
    }

    #[Test]
    public function purchase_lifecycle_and_shipment_mutations_require_manage_permission(): void
    {
        $this->grant('storage.purchase_view');
        $item = $this->item('PERMISSION-ITEM', 'Permission Item');
        $order = $this->placedOrder($item, 'PO-PERMISSION', 2);
        $line = $order->lines->first();
        $shipment = $order->shipments()->create([
            'reference' => 'PERMISSION-SHIPMENT',
            'status' => PurchaseShipment::STATUS_PENDING,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.lines.cancel', [$order, $line]), [
                'quantity' => 1,
                'reason' => 'Supplier short shipment.',
            ])
            ->assertForbidden();
        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.close', $order), [
                'reason' => 'Order is complete.',
            ])
            ->assertForbidden();
        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.cancel', $order), [
                'reason' => 'Supplier cancelled the order.',
            ])
            ->assertForbidden();
        $this->actingAs($this->tech)
            ->patch(route('tech.storage.purchase-orders.shipments.status.update', [$order, $shipment]), [
                'status' => PurchaseShipment::STATUS_IN_TRANSIT,
                'reason' => 'Supplier confirmed dispatch.',
            ])
            ->assertForbidden();
        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.shipments.trackings.store', [$order, $shipment]), [
                'tracking_number' => 'FORBIDDEN-TRACKING',
                'tracking_type' => 'parcel',
            ])
            ->assertForbidden();

        $this->assertSame(0, $line->refresh()->qty_cancelled);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->refresh()->status);
        $this->assertSame(PurchaseShipment::STATUS_PENDING, $shipment->refresh()->status);
        $this->assertSame(0, $shipment->trackings()->count());
    }

    #[Test]
    public function malformed_nested_purchase_payloads_return_validation_errors_instead_of_type_errors(): void
    {
        $this->grant(
            'storage.purchase_view',
            'storage.purchase_manage',
            'storage.purchase_receive'
        );
        $item = $this->item('MALFORMED-NESTED', 'Malformed Nested Item');
        $order = $this->placedOrder($item, 'PO-MALFORMED-NESTED', 2);
        $line = $order->lines->first();

        $this->actingAs($this->tech)
            ->postJson(route('tech.storage.purchase-orders.store'), [
                'po_number' => 'PO-MALFORMED-CREATE',
                'vendor_id' => $this->supplier->id,
                'deliver_to_warehouse_id' => $this->warehouse->id,
                'status' => PurchaseOrder::STATUS_ORDERED,
                'ordered_at' => now()->toDateString(),
                'currency' => 'NOK',
                'lines' => ['not-an-object'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['lines.0']);

        $this->actingAs($this->tech)
            ->postJson(route('tech.storage.purchase-orders.shipments.store', $order), [
                'status' => PurchaseShipment::STATUS_PENDING,
                'allocations' => ['not-an-object'],
                'trackings' => ['not-an-object'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'allocations.0',
                'trackings.0',
            ]);

        $this->actingAs($this->tech)
            ->postJson(route('tech.storage.purchase-orders.receipts.store', $order), [
                'idempotency_token' => (string) str()->uuid(),
                'warehouse_id' => $this->warehouse->id,
                'lines' => [
                    $line->id => 'not-an-object',
                ],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'lines.'.$line->id,
            ]);

        $this->assertDatabaseMissing('storage_purchase_orders', [
            'po_number' => 'PO-MALFORMED-CREATE',
        ]);
        $this->assertSame(0, $order->shipments()->count());
        $this->assertSame(0, $order->receipts()->count());
    }

    #[Test]
    public function technician_can_register_an_externally_placed_order_with_storage_lines(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('FIREWALL-100', 'Firewall 100');

        $response = $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.store'), [
                'po_number' => 'PO-2026-1001',
                'vendor_id' => $this->supplier->id,
                'deliver_to_warehouse_id' => $this->warehouse->id,
                'status' => 'ordered',
                'vendor_ref' => 'SUP-7788',
                'ordered_at' => '2026-08-04',
                'expected_at' => '2026-08-10',
                'currency' => 'nok',
                'notes' => 'Placed in supplier web shop.',
                'lines' => [[
                    'item_id' => $item->id,
                    'qty_ordered' => 4,
                    'qty_cancelled' => 0,
                    'supplier_sku' => 'SUP-FW-100',
                    'unit_cost' => 2500,
                    'tax_rate' => 25,
                    'expected_at' => '2026-08-10',
                ]],
            ]);

        $order = PurchaseOrder::query()->with('lines')->firstOrFail();

        $response->assertRedirect(route('tech.storage.purchase-orders.show', $order));
        $this->assertSame('ordered', $order->status);
        $this->assertSame('Nordic Supplier', $order->supplier_name_snapshot);
        $this->assertSame('NOK', $order->currency);
        $this->assertSame(4, $order->lines->first()->qty_ordered);
        $this->assertSame('SUP-FW-100', $order->lines->first()->supplier_sku_snapshot);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.purchase-orders.show')
            ->assertSee('PO-2026-1001')
            ->assertSee('4 units outstanding')
            ->assertSee('Add Shipment')
            ->assertDontSee('Receive Goods');
    }

    #[Test]
    public function shared_actions_filter_action_owned_metadata_and_keep_real_history_append_only(): void
    {
        $item = $this->item('METADATA-BOUNDARY', 'Metadata Boundary Item');
        $order = app(StorePurchaseOrder::class)->handle([
            'po_number' => 'PO-METADATA-BOUNDARY',
            'vendor_id' => $this->supplier->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now()->toDateString(),
            'currency' => 'NOK',
            'metadata' => [
                'source' => 'manual-import',
                'lifecycle_history' => [['from' => 'forged', 'to' => 'closed']],
            ],
            'lines' => [[
                'item_id' => $item->id,
                'qty_ordered' => 2,
                'qty_cancelled' => 0,
                'metadata' => [
                    'custom_note' => 'Allowed custom metadata.',
                    'cancellation_history' => [['quantity' => 99]],
                    'quantity_history' => [['qty_ordered_before' => 99]],
                    'kind' => 'forged-kind',
                ],
            ]],
        ], $this->tech);

        $this->assertSame('manual-import', $order->metadata['source']);
        $this->assertArrayNotHasKey('lifecycle_history', $order->metadata);
        $lineMetadata = $order->lines->sole()->metadata;
        $this->assertSame('Allowed custom metadata.', $lineMetadata['custom_note']);
        $this->assertArrayNotHasKey('cancellation_history', $lineMetadata);
        $this->assertArrayNotHasKey('quantity_history', $lineMetadata);
        $this->assertArrayNotHasKey('kind', $lineMetadata);

        $realHistory = [[
            'from' => PurchaseOrder::STATUS_DRAFT,
            'to' => PurchaseOrder::STATUS_ORDERED,
            'reason' => 'Recorded by a lifecycle action.',
            'actor_id' => $this->tech->id,
        ]];
        $order->forceFill([
            'metadata' => [
                'source' => 'manual-import',
                'lifecycle_history' => $realHistory,
            ],
        ])->save();

        $updated = app(UpdatePurchaseOrder::class)->handle($order, [
            'metadata' => [
                'source' => 'manual-update',
                'lifecycle_history' => [['from' => 'forged', 'to' => 'cancelled']],
            ],
        ], $this->tech);

        $this->assertSame('manual-update', $updated->metadata['source']);
        $this->assertSame($realHistory, $updated->metadata['lifecycle_history']);

        $shipment = app(StorePurchaseShipment::class)->handle($updated, [
            'status' => PurchaseShipment::STATUS_PENDING,
            'metadata' => [
                'source' => 'supplier-portal',
                'status_history' => [['from' => 'forged', 'to' => 'received']],
            ],
        ], $this->tech);

        $this->assertSame('supplier-portal', $shipment->metadata['source']);
        $this->assertArrayNotHasKey('status_history', $shipment->metadata);

        $shipment = app(UpdatePurchaseShipmentStatus::class)->handle(
            $shipment,
            PurchaseShipment::STATUS_IN_TRANSIT,
            null,
            'Carrier collected the shipment.',
            $this->tech
        );

        $this->assertCount(1, $shipment->metadata['status_history']);
        $this->assertSame(PurchaseShipment::STATUS_PENDING, $shipment->metadata['status_history'][0]['from']);
        $this->assertSame(PurchaseShipment::STATUS_IN_TRANSIT, $shipment->metadata['status_history'][0]['to']);
    }

    #[Test]
    public function shared_actions_reject_inactive_new_master_data_but_keep_unchanged_historical_references(): void
    {
        $activeItem = $this->item('MASTER-ACTIVE', 'Active Master Item');
        $inactiveItem = $this->item('MASTER-INACTIVE', 'Inactive Master Item', [
            'status' => 'inactive',
        ]);
        $notOrderableItem = $this->item('MASTER-NOT-ORDERABLE', 'Not Orderable Master Item', [
            'can_be_ordered' => false,
        ]);
        $inactiveSupplier = Vendor::query()->create([
            'name' => 'Inactive Supplier',
            'vendor_code' => 'INACTIVE-SUPPLIER',
            'is_supplier' => true,
            'is_active' => false,
        ]);
        $notSupplier = Vendor::query()->create([
            'name' => 'Active Vendor Without Supplier Role',
            'vendor_code' => 'NOT-A-SUPPLIER',
            'is_supplier' => false,
            'is_active' => true,
        ]);
        $inactiveWarehouse = Warehouse::query()->create([
            'name' => 'Inactive Warehouse',
            'code' => 'INACTIVE-WH',
            'is_active' => false,
        ]);
        $lineInput = fn (Item $item): array => [
            'item_id' => $item->id,
            'qty_ordered' => 2,
            'qty_cancelled' => 0,
        ];
        $baseData = [
            'po_number' => 'PO-MASTER-BASE',
            'vendor_id' => $this->supplier->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => now()->toDateString(),
            'currency' => 'NOK',
            'lines' => [$lineInput($activeItem)],
        ];
        $store = app(StorePurchaseOrder::class);

        $this->assertValidationError('vendor_id', fn () => $store->handle(
            array_replace($baseData, [
                'po_number' => 'PO-INACTIVE-SUPPLIER',
                'vendor_id' => $inactiveSupplier->id,
            ]),
            $this->tech
        ));
        $this->assertValidationError('vendor_id', fn () => $store->handle(
            array_replace($baseData, [
                'po_number' => 'PO-NOT-SUPPLIER',
                'vendor_id' => $notSupplier->id,
            ]),
            $this->tech
        ));
        $this->assertValidationError('deliver_to_warehouse_id', fn () => $store->handle(
            array_replace($baseData, [
                'po_number' => 'PO-INACTIVE-WAREHOUSE',
                'deliver_to_warehouse_id' => $inactiveWarehouse->id,
            ]),
            $this->tech
        ));
        $this->assertValidationError('lines.0.item_id', fn () => $store->handle(
            array_replace($baseData, [
                'po_number' => 'PO-INACTIVE-ITEM',
                'lines' => [$lineInput($inactiveItem)],
            ]),
            $this->tech
        ));
        $this->assertValidationError('lines.0.item_id', fn () => $store->handle(
            array_replace($baseData, [
                'po_number' => 'PO-NOT-ORDERABLE-ITEM',
                'lines' => [$lineInput($notOrderableItem)],
            ]),
            $this->tech
        ));

        $order = $store->handle(array_replace($baseData, [
            'po_number' => 'PO-HISTORICAL-MASTER',
        ]), $this->tech);
        $line = $order->lines->sole();

        $this->supplier->update(['is_supplier' => false, 'is_active' => false]);
        $this->warehouse->update(['is_active' => false]);
        $activeItem->update(['status' => 'inactive', 'can_be_ordered' => false]);

        $updated = app(UpdatePurchaseOrder::class)->handle($order, [
            'notes' => 'Historical references retained without reselection.',
            'lines' => [[
                'id' => $line->id,
                'item_id' => $activeItem->id,
                'qty_ordered' => 2,
                'qty_cancelled' => 0,
            ]],
        ], $this->tech);

        $this->assertSame('Historical references retained without reselection.', $updated->notes);
        $this->assertSame($this->supplier->id, $updated->vendor_id);
        $this->assertSame($this->warehouse->id, $updated->deliver_to_warehouse_id);
        $this->assertSame($activeItem->id, $updated->lines->sole()->item_id);
        $this->assertSame('Nordic Supplier', $updated->supplier_name_snapshot);

        $this->assertValidationError('vendor_id', fn () => app(UpdatePurchaseOrder::class)->handle(
            $updated,
            ['vendor_id' => $inactiveSupplier->id],
            $this->tech
        ));
        $this->assertValidationError(
            'deliver_to_warehouse_id',
            fn () => app(UpdatePurchaseOrder::class)->handle(
                $updated,
                ['deliver_to_warehouse_id' => $inactiveWarehouse->id],
                $this->tech
            )
        );
        $this->assertValidationError('lines.0.item_id', fn () => app(UpdatePurchaseOrder::class)->handle(
            $updated,
            ['lines' => [[
                'id' => $line->id,
                'item_id' => $notOrderableItem->id,
                'qty_ordered' => 2,
                'qty_cancelled' => 0,
            ]]],
            $this->tech
        ));
    }

    #[Test]
    public function update_action_accepts_only_editable_input_and_current_lifecycles(): void
    {
        $item = $this->item('UPDATE-LIFECYCLE', 'Update Lifecycle Item');
        $order = $this->placedOrder($item, 'PO-UPDATE-LIFECYCLE', 2);

        $this->assertValidationError('status', fn () => app(UpdatePurchaseOrder::class)->handle(
            $order,
            ['status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED],
            $this->tech
        ));

        $order->forceFill(['status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED])->save();
        $this->assertValidationError('purchase_order', fn () => app(UpdatePurchaseOrder::class)->handle(
            $order,
            ['notes' => 'This derived lifecycle must remain action-owned.'],
            $this->tech
        ));
        $this->assertNull($order->refresh()->notes);
    }

    #[Test]
    public function blank_line_defaults_use_the_selected_purchase_order_supplier(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $primarySupplier = Vendor::query()->create([
            'name' => 'Primary But Unselected Supplier',
            'vendor_code' => 'OTHER-SUP',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $item = $this->item('MULTI-SUPPLIER', 'Multi Supplier Item', [
            'primary_vendor_id' => $primarySupplier->id,
            'purchase_price' => 999,
        ]);
        $item->itemVendors()->create([
            'vendor_id' => $primarySupplier->id,
            'vendor_sku' => 'WRONG-PRIMARY-SKU',
            'currency' => 'NOK',
            'unit_cost' => 999,
            'is_primary' => true,
        ]);
        $item->itemVendors()->create([
            'vendor_id' => $this->supplier->id,
            'vendor_sku' => 'RIGHT-ORDER-SKU',
            'currency' => 'NOK',
            'unit_cost' => 321.50,
            'is_primary' => false,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.create'))
            ->assertOk()
            ->assertSee('WRONG-PRIMARY-SKU')
            ->assertSee('RIGHT-ORDER-SKU');

        $response = $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.store'), [
                'po_number' => 'PO-SUPPLIER-DEFAULTS',
                'vendor_id' => $this->supplier->id,
                'deliver_to_warehouse_id' => $this->warehouse->id,
                'status' => 'ordered',
                'ordered_at' => '2026-08-04',
                'currency' => 'NOK',
                'lines' => [[
                    'item_id' => $item->id,
                    'qty_ordered' => 2,
                    'qty_cancelled' => 0,
                    'supplier_sku' => '',
                    'unit_cost' => '',
                    'tax_rate' => '',
                ]],
            ]);

        $order = PurchaseOrder::query()
            ->where('po_number', 'PO-SUPPLIER-DEFAULTS')
            ->with('lines')
            ->firstOrFail();

        $response->assertRedirect(route('tech.storage.purchase-orders.show', $order));
        $this->assertSame('RIGHT-ORDER-SKU', $order->lines->first()->supplier_sku_snapshot);
        $this->assertSame('321.50', $order->lines->first()->unit_cost);
        $this->assertNotSame('WRONG-PRIMARY-SKU', $order->lines->first()->supplier_sku_snapshot);
    }

    #[Test]
    public function technician_can_cancel_a_partial_remainder_and_then_explicitly_close_the_order(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('PARTIAL-CANCEL', 'Partial Cancellation Item');
        $order = $this->placedOrder($item, 'PO-PARTIAL-CANCEL', 5);
        $line = $order->lines->first();
        $line->forceFill(['qty_received' => 2])->save();
        $order->forceFill(['status' => PurchaseOrder::STATUS_PARTIALLY_RECEIVED])->save();

        $this->actingAs($this->tech)
            ->from(route('tech.storage.purchase-orders.show', $order))
            ->post(route('tech.storage.purchase-orders.close', $order), [
                'reason' => 'Attempted before completion.',
            ])
            ->assertSessionHasErrors('purchase_order');
        $this->assertSame(PurchaseOrder::STATUS_PARTIALLY_RECEIVED, $order->refresh()->status);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('Cancel outstanding')
            ->assertDontSee('Close completed order');

        $response = $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.lines.cancel', [$order, $line]), [
                'quantity' => 3,
                'reason' => 'Supplier confirmed the remainder will not ship.',
            ]);

        $response->assertRedirect(route('tech.storage.purchase-orders.show', $order));
        $line->refresh();
        $order->refresh();
        $this->assertSame(3, $line->qty_cancelled);
        $this->assertSame(0, $line->qty_outstanding);
        $this->assertSame(PurchaseOrder::STATUS_RECEIVED, $order->status);
        $this->assertSame(
            'Supplier confirmed the remainder will not ship.',
            $line->metadata['cancellation_history'][0]['reason']
        );
        $this->assertSame($this->tech->id, $line->metadata['cancellation_history'][0]['actor_id']);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('Close completed order');

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.close', $order), [
                'reason' => 'Accepted and cancelled quantities reconcile.',
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));

        $order->refresh();
        $this->assertSame(PurchaseOrder::STATUS_CLOSED, $order->status);
        $this->assertNotNull($order->closed_at);
        $this->assertSame($this->tech->id, $order->status_changed_by);
        $this->assertSame(
            'Accepted and cancelled quantities reconcile.',
            $order->metadata['lifecycle_history'][0]['reason']
        );
    }

    #[Test]
    public function line_cancellation_reduces_active_shipment_allocations_without_over_cancelling(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('ALLOCATED-CANCEL', 'Allocated Cancellation Item');
        $order = $this->placedOrder($item, 'PO-ALLOCATED-CANCEL', 5);
        $line = $order->lines->first();
        $shipment = $order->shipments()->create([
            'reference' => 'ALLOCATED-SHIPMENT',
            'status' => PurchaseShipment::STATUS_IN_TRANSIT,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipmentLine = $shipment->lines()->create([
            'purchase_order_line_id' => $line->id,
            'qty_allocated' => 4,
            'qty_received' => 0,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->from(route('tech.storage.purchase-orders.show', $order))
            ->post(route('tech.storage.purchase-orders.lines.cancel', [$order, $line]), [
                'quantity' => 2,
                'reason' => 'Supplier removed two units.',
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));

        $line->refresh();
        $this->assertSame(2, $line->qty_cancelled);
        $this->assertSame(1, $shipmentLine->refresh()->qty_cancelled);
        $this->assertSame(3, $shipmentLine->qty_outstanding);
        $this->assertSame(PurchaseShipment::STATUS_PARTIALLY_RECEIVED, $shipment->refresh()->status);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->refresh()->status);
        $this->assertSame(
            1,
            $line->metadata['cancellation_history'][0]['shipment_allocation_cancellations'][0]['quantity']
        );

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('aria-label="Sort by Accepted ascending"', false)
            ->assertSee('aria-label="Sort by Rejected ascending"', false)
            ->assertSee('aria-label="Sort by Cancelled ascending"', false)
            ->assertSee(
                'data-shipment-line-quantity="cancelled">1</td>',
                false
            );
    }

    #[Test]
    public function purchase_order_cancellation_requires_a_reason_and_records_immutable_history(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('ORDER-CANCEL', 'Order Cancellation Item');
        $order = $this->placedOrder($item, 'PO-CANCEL', 2);

        $this->actingAs($this->tech)
            ->from(route('tech.storage.purchase-orders.show', $order))
            ->post(route('tech.storage.purchase-orders.cancel', $order), [
                'reason' => 'No',
            ])
            ->assertSessionHasErrors('reason');
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $order->refresh()->status);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.cancel', $order), [
                'reason' => 'Supplier cancelled before dispatch.',
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));

        $order->refresh();
        $line = $order->lines()->firstOrFail();
        $this->assertSame(PurchaseOrder::STATUS_CANCELLED, $order->status);
        $this->assertSame(2, $line->qty_cancelled);
        $this->assertNotNull($order->cancelled_at);
        $this->assertSame($this->tech->id, $order->status_changed_by);
        $this->assertSame('ordered', $order->metadata['lifecycle_history'][0]['from']);
        $this->assertSame('cancelled', $order->metadata['lifecycle_history'][0]['to']);
        $this->assertSame(
            'Supplier cancelled before dispatch.',
            $order->metadata['lifecycle_history'][0]['reason']
        );
    }

    #[Test]
    public function cancelled_shipments_do_not_reduce_new_shipment_allocation_capacity(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('CANCELLED-ALLOC', 'Cancelled Allocation Item');
        $order = $this->placedOrder($item, 'PO-CANCELLED-ALLOC', 5);
        $line = $order->lines->first();
        $shipment = $order->shipments()->create([
            'reference' => 'CANCELLED-SHIPMENT',
            'status' => PurchaseShipment::STATUS_CANCELLED,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipment->lines()->create([
            'purchase_order_line_id' => $line->id,
            'qty_allocated' => 5,
            'qty_received' => 0,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.shipments.create', $order))
            ->assertOk()
            ->assertSee('data-allocation-used="0"', false)
            ->assertSee('data-allocation-remaining="5"', false)
            ->assertDontSee('data-allocation-used="5"', false);
    }

    #[Test]
    public function shipment_mutation_controls_follow_parent_and_shipment_lifecycle_locks(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('LIFECYCLE-UI', 'Lifecycle UI Item');
        $order = $this->placedOrder($item, 'PO-LIFECYCLE-UI', 1);
        $shipment = $order->shipments()->create([
            'reference' => 'LIFECYCLE-SHIPMENT',
            'status' => PurchaseShipment::STATUS_PENDING,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $order->forceFill(['status' => PurchaseOrder::STATUS_RECEIVED])->save();
        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('Update shipment status')
            ->assertSee('Add tracking number');

        foreach ([PurchaseOrder::STATUS_CLOSED, PurchaseOrder::STATUS_CANCELLED] as $status) {
            $order->forceFill(['status' => $status])->save();

            $this->actingAs($this->tech)
                ->get(route('tech.storage.purchase-orders.show', $order))
                ->assertOk()
                ->assertDontSee('Update shipment status')
                ->assertDontSee('Add tracking number');
        }

        $order->forceFill(['status' => PurchaseOrder::STATUS_RECEIVED])->save();
        $shipment->forceFill(['status' => PurchaseShipment::STATUS_CANCELLED])->save();

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertDontSee('Update shipment status')
            ->assertDontSee('Add tracking number');
    }

    #[Test]
    public function edit_form_locks_currency_and_only_lines_with_immutable_history(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $lockedItem = $this->item('LOCKED-LINE', 'Locked Line');
        $unlockedItem = $this->item('UNLOCKED-LINE', 'Unlocked Line');
        $cancelledItem = $this->item('CANCELLED-LINE', 'Cancelled Line');
        $order = $this->placedOrder($lockedItem, 'PO-EDIT-LOCKS', 2);
        $lockedLine = $order->lines->first();
        $unlockedLine = $order->lines()->create([
            'item_id' => $unlockedItem->id,
            'item_name_snapshot' => $unlockedItem->name,
            'sku_snapshot' => $unlockedItem->sku,
            'qty_ordered' => 3,
            'qty_received' => 0,
            'qty_cancelled' => 0,
            'unit_cost' => 400,
            'tax_rate' => 25,
            'currency' => 'NOK',
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $cancelledLine = $order->lines()->create([
            'item_id' => $cancelledItem->id,
            'item_name_snapshot' => $cancelledItem->name,
            'sku_snapshot' => $cancelledItem->sku,
            'qty_ordered' => 2,
            'qty_received' => 0,
            'qty_cancelled' => 1,
            'cancellation_reason' => 'Supplier removed one unit.',
            'cancelled_at' => now(),
            'unit_cost' => 200,
            'tax_rate' => 25,
            'currency' => 'NOK',
            'metadata' => ['cancellation_history' => [[
                'quantity' => 1,
                'reason' => 'Supplier removed one unit.',
            ]]],
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipment = $order->shipments()->create([
            'reference' => 'LOCKING-SHIPMENT',
            'status' => PurchaseShipment::STATUS_PENDING,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipment->lines()->create([
            'purchase_order_line_id' => $lockedLine->id,
            'qty_allocated' => 1,
            'qty_received' => 0,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $response = $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.edit', $order))
            ->assertOk()
            ->assertSee('Currency is locked after shipment or receipt activity.')
            ->assertSee('Immutable shipment, receipt, or cancellation history locks item, quantity, and commercial fields.');

        $html = $response->getContent();
        $this->assertStringContainsString(
            '<input type="hidden" name="currency" value="NOK">',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="currency"[^>]*data-currency-locked="1"[^>]*disabled/',
            $html
        );
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-line-id="'.$lockedLine->id.'"[^>]*data-line-locked="1"[^>]*>(.*?)<\/tr>/s',
            $html,
            $lockedMatch
        ));
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-line-id="'.$cancelledLine->id.'"[^>]*data-line-locked="1"[^>]*>(.*?)<\/tr>/s',
            $html,
            $cancelledMatch
        ));
        $this->assertSame(1, preg_match(
            '/<tr[^>]*data-line-id="'.$unlockedLine->id.'"[^>]*data-line-locked="0"[^>]*>(.*?)<\/tr>/s',
            $html,
            $unlockedMatch
        ));
        $this->assertStringContainsString(
            'type="hidden" name="lines[0][item_id]" value="'.$lockedItem->id.'"',
            $lockedMatch[0]
        );
        $this->assertMatchesRegularExpression(
            '/<select[^>]*line-item[^>]*disabled/',
            $lockedMatch[0]
        );
        $this->assertDoesNotMatchRegularExpression(
            '/<select[^>]*line-item[^>]*disabled/',
            $unlockedMatch[0]
        );
        $this->assertMatchesRegularExpression(
            '/<select[^>]*line-item[^>]*disabled/',
            $cancelledMatch[0]
        );
    }

    #[Test]
    public function reversed_receipt_history_hides_order_and_shipment_cancellation_controls(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('REVERSED-HISTORY', 'Reversed History Item');
        $order = $this->placedOrder($item, 'PO-REVERSED-HISTORY', 1);
        $order->receipts()->create([
            'receipt_number' => 'RCV-HISTORY-ORDER',
            'receipt_type' => PurchaseReceipt::TYPE_RECEIPT,
            'status' => PurchaseReceipt::STATUS_REVERSED,
            'idempotency_token' => (string) str()->uuid(),
            'request_hash' => str_repeat('a', 64),
            'received_at' => now(),
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertDontSee('Cancel purchase order');

        $shipmentOrder = $this->placedOrder($item, 'PO-REVERSED-SHIPMENT', 1);
        $shipment = $shipmentOrder->shipments()->create([
            'reference' => 'REVERSED-HISTORY-SHIPMENT',
            'status' => PurchaseShipment::STATUS_PENDING,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $shipmentOrder->receipts()->create([
            'receipt_number' => 'RCV-HISTORY-SHIPMENT',
            'purchase_shipment_id' => $shipment->id,
            'receipt_type' => PurchaseReceipt::TYPE_RECEIPT,
            'status' => PurchaseReceipt::STATUS_REVERSED,
            'idempotency_token' => (string) str()->uuid(),
            'request_hash' => str_repeat('b', 64),
            'received_at' => now(),
            'warehouse_id' => $this->warehouse->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $shipmentOrder))
            ->assertOk()
            ->assertSee('Update shipment status')
            ->assertDontSee('<option value="cancelled">Cancelled</option>', false);
    }

    #[Test]
    public function legacy_carriers_remain_selectable_while_inactive_carriers_are_hidden(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('CARRIER-LIFECYCLE', 'Carrier Lifecycle Item');
        $order = $this->placedOrder($item, 'PO-CARRIER-LIFECYCLE', 1);
        $order->shipments()->create([
            'reference' => 'CARRIER-LIFECYCLE-SHIPMENT',
            'status' => PurchaseShipment::STATUS_PENDING,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);
        $this->carrier([
            'name' => 'Legacy Carrier Choice',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_LEGACY,
        ]);
        $this->carrier([
            'name' => 'Inactive Carrier Choice',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_INACTIVE,
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.shipments.create', $order))
            ->assertOk()
            ->assertSee('Legacy Carrier Choice (Legacy)')
            ->assertDontSee('Inactive Carrier Choice');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('Legacy Carrier Choice (Legacy)')
            ->assertDontSee('Inactive Carrier Choice');
    }

    #[Test]
    public function shipment_status_transitions_are_guarded_and_tracking_is_append_only(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('HANDOFF-ITEM', 'Handoff Item');
        $order = $this->placedOrder($item, 'PO-HANDOFF', 2);
        $carrier = $this->carrier(['name' => 'Carrier Before Rename']);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.shipments.store', $order), [
                'shipping_carrier_id' => $carrier->id,
                'reference' => 'HANDOFF-SHIPMENT',
                'status' => PurchaseShipment::STATUS_PENDING,
                'trackings' => [[
                    'shipping_carrier_id' => $carrier->id,
                    'tracking_number' => 'ORIGINAL-100',
                    'tracking_type' => 'master',
                ]],
            ])
            ->assertRedirect();

        $shipment = PurchaseShipment::query()->with('trackings')->firstOrFail();
        $originalTracking = $shipment->trackings->first();
        $this->assertSame('Carrier Before Rename', $originalTracking->carrier_name_snapshot);

        $this->actingAs($this->tech)
            ->from(route('tech.storage.purchase-orders.show', $order))
            ->patch(route('tech.storage.purchase-orders.shipments.status.update', [$order, $shipment]), [
                'status' => PurchaseShipment::STATUS_DELIVERED,
                'occurred_at' => '2026-08-06 10:00:00',
                'reason' => 'Attempted to skip in-transit state.',
            ])
            ->assertSessionHasErrors('status');
        $this->assertSame(PurchaseShipment::STATUS_PENDING, $shipment->refresh()->status);

        $this->actingAs($this->tech)
            ->patch(route('tech.storage.purchase-orders.shipments.status.update', [$order, $shipment]), [
                'status' => PurchaseShipment::STATUS_IN_TRANSIT,
                'occurred_at' => '2026-08-06 10:00:00',
                'reason' => 'Supplier confirmed carrier pickup.',
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));
        $shipment->refresh();
        $this->assertSame(PurchaseShipment::STATUS_IN_TRANSIT, $shipment->status);
        $this->assertSame('pending', $shipment->metadata['status_history'][0]['from']);
        $this->assertSame('in_transit', $shipment->metadata['status_history'][0]['to']);

        $carrier->update(['name' => 'Carrier Current Name']);
        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.shipments.trackings.store', [$order, $shipment]), [
                'shipping_carrier_id' => $carrier->id,
                'tracking_number' => 'LAST-MILE-200',
                'tracking_type' => 'last_mile',
                'label' => 'Local handoff',
            ])
            ->assertRedirect(route('tech.storage.purchase-orders.show', $order));

        $trackings = $shipment->trackings()->orderBy('sort_order')->get();
        $this->assertCount(2, $trackings);
        $this->assertSame('Carrier Before Rename', $trackings->first()->carrier_name_snapshot);
        $this->assertSame('ORIGINAL-100', $trackings->first()->tracking_number);
        $this->assertSame('Carrier Current Name', $trackings->last()->carrier_name_snapshot);
        $this->assertSame('LAST-MILE-200', $trackings->last()->tracking_number);
        $this->assertSame('last_mile', $trackings->last()->tracking_type);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('ORIGINAL-100')
            ->assertSee('LAST-MILE-200')
            ->assertSee('Add tracking number');
    }

    #[Test]
    public function technician_can_register_multiple_tracking_identifiers_and_open_only_safe_links(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('SWITCH-48', '48 Port Switch');
        $order = $this->placedOrder($item, 'PO-TRACKED', 5);
        $carrier = $this->carrier([
            'tracking_url_template' => 'https://track.example.test/parcel/{tracking_number}',
            'tracking_page_url' => 'https://track.example.test/',
            'allowed_tracking_hosts' => ['track.example.test'],
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'link_visibility' => ShippingCarrier::VISIBILITY_AUTHENTICATED,
        ]);

        $response = $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.shipments.store', $order), [
                'shipping_carrier_id' => $carrier->id,
                'reference' => 'SHIP-001',
                'status' => 'in_transit',
                'shipped_at' => '2026-08-05 10:00:00',
                'allocations' => [[
                    'purchase_order_line_id' => $order->lines->first()->id,
                    'qty_allocated' => 3,
                ]],
                'trackings' => [
                    [
                        'shipping_carrier_id' => $carrier->id,
                        'tracking_number' => 'PKG 100',
                        'tracking_type' => 'master',
                        'label' => 'Master',
                        'sort_order' => 0,
                    ],
                    [
                        'shipping_carrier_id' => $carrier->id,
                        'tracking_number' => 'PKG-101',
                        'tracking_type' => 'parcel',
                        'label' => 'Parcel 1',
                        'sort_order' => 1,
                    ],
                ],
            ]);

        $shipment = PurchaseShipment::query()->with('trackings')->firstOrFail();

        $response->assertRedirect(route('tech.storage.purchase-orders.show', $order));
        $this->assertCount(2, $shipment->trackings);
        $this->assertSame(3, $shipment->lines()->firstOrFail()->qty_allocated);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('SHIP-001')
            ->assertSee('PKG 100')
            ->assertSee('PKG-101')
            ->assertSee('href="https://track.example.test/parcel/PKG%20100"', false)
            ->assertSee('Carrier sign-in required');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.index', ['tracking_number' => 'PKG-101']))
            ->assertOk()
            ->assertSee('PO-TRACKED');
    }

    #[Test]
    public function unverified_carrier_template_keeps_tracking_number_as_plain_text(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('AP-01', 'Access Point');
        $order = $this->placedOrder($item, 'PO-PLAIN', 1);
        $carrier = $this->carrier([
            'tracking_url_template' => 'https://unverified.example.test/{tracking_number}',
            'tracking_page_url' => null,
            'allowed_tracking_hosts' => ['unverified.example.test'],
            'tracking_method' => ShippingCarrier::TRACKING_TEMPLATE,
            'verification_state' => ShippingCarrier::VERIFICATION_UNVERIFIED,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.shipments.store', $order), [
                'shipping_carrier_id' => $carrier->id,
                'status' => 'pending',
                'trackings' => [[
                    'tracking_number' => 'PLAIN-123',
                    'tracking_type' => 'parcel',
                ]],
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('PLAIN-123')
            ->assertDontSee('href="https://unverified.example.test/PLAIN-123"', false);
    }

    #[Test]
    public function non_template_snapshot_does_not_activate_a_stale_template_and_explains_recipient_links(): void
    {
        $this->grant('storage.purchase_view', 'storage.purchase_manage');
        $item = $this->item('RECIPIENT-01', 'Recipient Parcel');
        $order = $this->placedOrder($item, 'PO-RECIPIENT', 1);
        $carrier = $this->carrier([
            'tracking_url_template' => 'https://recipient.example.test/{tracking_number}',
            'tracking_page_url' => null,
            'allowed_tracking_hosts' => ['recipient.example.test'],
            'tracking_method' => ShippingCarrier::TRACKING_PROVIDER_GENERATED,
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'link_visibility' => ShippingCarrier::VISIBILITY_RECIPIENT_ONLY,
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.purchase-orders.shipments.store', $order), [
                'shipping_carrier_id' => $carrier->id,
                'status' => 'pending',
                'trackings' => [[
                    'tracking_number' => 'RECIPIENT-123',
                    'tracking_type' => 'parcel',
                ]],
            ])
            ->assertRedirect();

        $this->actingAs($this->tech)
            ->get(route('tech.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertSee('RECIPIENT-123')
            ->assertSee('Recipient details may be required')
            ->assertDontSee('href="https://recipient.example.test/RECIPIENT-123"', false);
    }

    private function assertValidationError(string $key, callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected a validation exception.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    private function grant(string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->tech->givePermissionTo($permissions);
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

    private function placedOrder(Item $item, string $number, int $quantity): PurchaseOrder
    {
        $order = PurchaseOrder::query()->create([
            'po_number' => $number,
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
        $order->lines()->create([
            'item_id' => $item->id,
            'item_name_snapshot' => $item->name,
            'sku_snapshot' => $item->sku,
            'qty_ordered' => $quantity,
            'qty_received' => 0,
            'qty_cancelled' => 0,
            'currency' => 'NOK',
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
        ]);

        return $order->load('lines');
    }

    private function carrier(array $overrides = []): ShippingCarrier
    {
        return ShippingCarrier::query()->create($overrides + [
            'code' => 'test-carrier-'.str()->random(6),
            'name' => 'Test Carrier',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'sort_order' => 10,
            'website_url' => 'https://carrier.example.test',
            'tracking_method' => ShippingCarrier::TRACKING_GENERIC_PAGE,
            'allowed_tracking_hosts' => ['carrier.example.test'],
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'source_url' => 'https://carrier.example.test/source',
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'verified_at' => now()->toDateString(),
        ]);
    }
}
