<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\ShippingCarrier;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use App\Modules\Storage\Actions\PostPurchaseReceipt;
use App\Modules\Storage\Actions\ReversePurchaseReceipt;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseReceipt;
use App\Modules\Storage\Models\PurchaseShipment;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PurchaseOrderApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Item $item;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->vendor = Vendor::query()->create([
            'name' => 'API Supplier',
            'vendor_code' => 'API-SUPPLIER',
            'is_vendor' => true,
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::query()->create([
            'name' => 'API Warehouse',
            'code' => 'API-MAIN',
            'is_active' => true,
        ]);
        $this->item = Item::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'sku' => 'API-PURCHASE-ITEM',
            'name' => 'API Purchase Item',
            'purchase_price' => 100,
            'vat_rate' => 25,
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function purchase_abilities_and_route_middleware_are_explicit_and_separated(): void
    {
        $catalog = app(ApiAbilityCatalog::class);
        $expectedAccess = [
            'storage.purchase.read' => ApiAbilityCatalog::ACCESS_READ,
            'storage.purchase.manage' => ApiAbilityCatalog::ACCESS_WRITE,
            'storage.purchase.receive' => ApiAbilityCatalog::ACCESS_WRITE,
            'storage.purchase.receive_overage' => ApiAbilityCatalog::ACCESS_WRITE,
            'storage.purchase.reverse' => ApiAbilityCatalog::ACCESS_WRITE,
        ];

        foreach ($expectedAccess as $ability => $access) {
            $this->assertArrayHasKey($ability, $catalog->all());
            $this->assertSame($access, $catalog->all()[$ability]['access']);
        }

        $routeAbilities = [
            'api.v1.storage.purchase-orders.index' => 'storage.purchase.read',
            'api.v1.storage.purchase-orders.show' => 'storage.purchase.read',
            'api.v1.storage.purchase-orders.store' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.update' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.lines.cancel' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.close' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.cancel' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.shipments.store' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.shipments.status.update' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.shipments.trackings.store' => 'storage.purchase.manage',
            'api.v1.storage.purchase-orders.receipts.store' => 'storage.purchase.receive',
            'api.v1.storage.purchase-orders.receipts.reverse' => 'storage.purchase.reverse',
        ];

        foreach ($routeAbilities as $routeName => $ability) {
            $route = Route::getRoutes()->getByName($routeName);
            $this->assertNotNull($route, $routeName);
            $this->assertContains(CheckAbilities::class.':'.$ability, $route->gatherMiddleware(), $routeName);
        }
    }

    #[Test]
    public function read_ability_can_inspect_orders_without_inheriting_mutation_access(): void
    {
        $order = $this->order(2);

        Sanctum::actingAs($this->actor, ['storage.purchase.read']);

        $this->getJson(route('api.v1.storage.purchase-orders.index', ['q' => $order->po_number]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.progress.qty_outstanding', 2);

        $this->getJson(route('api.v1.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertJsonPath('data.po_number', $order->po_number)
            ->assertJsonPath('data.lines.0.qty_outstanding', 2);

        $this->postJson(route('api.v1.storage.purchase-orders.shipments.store', $order), [
            'status' => 'pending',
        ])->assertForbidden();

        $this->postJson(route('api.v1.storage.purchase-orders.receipts.store', $order), [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
            ]],
        ])->assertForbidden();

        Sanctum::actingAs($this->actor, ['storage.purchase.manage']);
        $this->getJson(route('api.v1.storage.purchase-orders.show', $order))->assertForbidden();
    }

    #[Test]
    public function manage_api_uses_shared_order_shipment_tracking_and_lifecycle_actions(): void
    {
        Sanctum::actingAs($this->actor, ['storage.purchase.manage']);

        $create = $this->postJson(route('api.v1.storage.purchase-orders.store'), [
            'po_number' => 'API-PO-MANAGED',
            'vendor_id' => $this->vendor->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'vendor_ref' => 'SUP-100',
            'ordered_at' => '2026-08-04',
            'expected_at' => '2026-08-12',
            'currency' => 'nok',
            'notes' => 'Registered through API.',
            'lines' => [[
                'item_id' => $this->item->id,
                'qty_ordered' => 3,
                'qty_cancelled' => 0,
                'unit_cost' => 100,
                'tax_rate' => 25,
            ]],
        ]);

        $create->assertCreated()
            ->assertJsonPath('data.status', 'ordered')
            ->assertJsonPath('data.currency', 'NOK')
            ->assertJsonPath('data.lines.0.item_name_snapshot', 'API Purchase Item');
        $order = PurchaseOrder::query()->with('lines')->findOrFail($create->json('data.id'));
        $line = $order->lines->first();

        $this->putJson(route('api.v1.storage.purchase-orders.update', $order), [
            'po_number' => $order->po_number,
            'vendor_id' => $this->vendor->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => 'ordered',
            'vendor_ref' => 'SUP-101',
            'ordered_at' => '2026-08-04',
            'expected_at' => '2026-08-13',
            'currency' => 'NOK',
            'notes' => 'Updated through the shared action.',
            'lines' => [[
                'id' => $line->id,
                'item_id' => $this->item->id,
                'qty_ordered' => 3,
                'qty_cancelled' => 0,
                'unit_cost' => 100,
                'tax_rate' => 25,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.vendor_ref', 'SUP-101')
            ->assertJsonPath('data.expected_at', '2026-08-13');

        $carrier = ShippingCarrier::query()->create([
            'code' => 'API-CARRIER',
            'name' => 'API Carrier',
            'lifecycle_state' => ShippingCarrier::LIFECYCLE_ACTIVE,
            'website_url' => 'https://carrier.example.test',
            'tracking_page_url' => 'https://track.example.test',
            'tracking_method' => ShippingCarrier::TRACKING_PROVIDER_GENERATED,
            'allowed_tracking_hosts' => ['track.example.test'],
            'link_visibility' => ShippingCarrier::VISIBILITY_NORMAL,
            'source_url' => 'https://carrier.example.test/tracking-help',
            'verification_state' => ShippingCarrier::VERIFICATION_VERIFIED,
            'verified_at' => '2026-08-04',
        ]);

        $shipmentResponse = $this->postJson(
            route('api.v1.storage.purchase-orders.shipments.store', $order),
            [
                'shipping_carrier_id' => $carrier->id,
                'status' => 'pending',
                'reference' => 'SHIP-API-1',
                'allocations' => [[
                    'purchase_order_line_id' => $line->id,
                    'qty_allocated' => 3,
                ]],
                'trackings' => [[
                    'tracking_number' => 'MASTER-100',
                    'tracking_type' => 'master',
                    'direct_url' => 'https://track.example.test/master/MASTER-100',
                ]],
            ]
        );
        $shipmentResponse->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.allocations.0.qty_allocated', 3)
            ->assertJsonPath('data.allocations.0.qty_received', 0)
            ->assertJsonPath('data.allocations.0.qty_rejected', 0)
            ->assertJsonPath('data.allocations.0.qty_cancelled', 0)
            ->assertJsonPath('data.trackings.0.tracking_number', 'MASTER-100')
            ->assertJsonPath('data.trackings.0.tracking_url', 'https://track.example.test/master/MASTER-100');
        $this->assertArrayNotHasKey('direct_url', $shipmentResponse->json('data.trackings.0'));
        $shipment = PurchaseShipment::query()->findOrFail($shipmentResponse->json('data.id'));

        $this->postJson(route('api.v1.storage.purchase-orders.shipments.trackings.store', [$order, $shipment]), [
            'shipping_carrier_id' => $carrier->id,
            'tracking_number' => 'LAST-MILE-200',
            'tracking_type' => 'last_mile',
            'label' => 'Last mile',
            'direct_url' => 'https://track.example.test/last-mile/LAST-MILE-200',
            'metadata' => ['source' => 'api-test'],
        ])->assertCreated()
            ->assertJsonPath('data.tracking_number', 'LAST-MILE-200')
            ->assertJsonPath('data.tracking_url', 'https://track.example.test/last-mile/LAST-MILE-200')
            ->assertJsonPath('data.metadata.source', 'api-test');

        $this->patchJson(route('api.v1.storage.purchase-orders.shipments.status.update', [$order, $shipment]), [
            'status' => 'in_transit',
            'occurred_at' => '2026-08-05 10:00:00',
            'reason' => 'Carrier collected the shipment.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'in_transit')
            ->assertJsonPath('data.metadata.status_history.0.to', 'in_transit');

        $this->postJson(route('api.v1.storage.purchase-orders.lines.cancel', [$order, $line]), [
            'quantity' => 1,
            'reason' => 'Supplier removed one unit.',
        ])->assertOk()
            ->assertJsonPath('data.lines.0.qty_cancelled', 1)
            ->assertJsonPath('data.progress.qty_outstanding', 2)
            ->assertJsonPath('data.shipments.0.allocations.0.qty_cancelled', 1)
            ->assertJsonPath('data.shipments.0.allocations.0.qty_rejected', 0)
            ->assertJsonPath('data.shipments.0.allocations.0.qty_outstanding', 2);

        $otherOrder = $this->order(1);
        $this->postJson(route('api.v1.storage.purchase-orders.lines.cancel', [$otherOrder, $line]), [
            'quantity' => 1,
            'reason' => 'Wrong nested order must not expose the line.',
        ])->assertNotFound();
        $this->patchJson(route('api.v1.storage.purchase-orders.shipments.status.update', [$otherOrder, $shipment]), [
            'status' => 'delivered',
            'reason' => 'Wrong nested order must not expose the shipment.',
        ])->assertNotFound();

        $closable = $this->order(1);
        $closableLine = $closable->lines->first();
        $closableShipment = $this->postJson(
            route('api.v1.storage.purchase-orders.shipments.store', $closable),
            ['status' => 'pending', 'reference' => 'LATE-TRACKING']
        )->assertCreated();

        app(PostPurchaseReceipt::class)->handle($closable, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $closableLine->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ], $this->actor);

        Sanctum::actingAs($this->actor, ['storage.purchase.read']);
        $received = $this->getJson(route('api.v1.storage.purchase-orders.show', $closable))
            ->assertOk()->assertJsonPath('data.status', 'received');
        $this->assertArrayHasKey('update_status', $received->json('data.shipments.0.links'));
        $this->assertArrayHasKey('append_tracking', $received->json('data.shipments.0.links'));
        $this->assertSame(
            $closableShipment->json('data.id'),
            $received->json('data.shipments.0.id')
        );

        Sanctum::actingAs($this->actor, ['storage.purchase.manage']);

        $closed = $this->postJson(route('api.v1.storage.purchase-orders.close', $closable), [
            'reason' => 'No outstanding goods remain.',
        ])->assertOk()->assertJsonPath('data.status', 'closed');
        $this->assertSame(['self'], array_keys($closed->json('data.links')));
        $this->assertSame([], array_keys($closed->json('data.shipments.0.links')));

        $cancellable = $this->order(1);
        $cancelled = $this->postJson(route('api.v1.storage.purchase-orders.cancel', $cancellable), [
            'reason' => 'External supplier order was cancelled.',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->assertSame(['self'], array_keys($cancelled->json('data.links')));
    }

    #[Test]
    public function read_resource_does_not_advertise_cancellation_after_reversed_receipt_history(): void
    {
        $order = $this->order(1);
        $receipt = app(PostPurchaseReceipt::class)->handle($order, [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $order->lines->first()->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ], $this->actor);

        app(ReversePurchaseReceipt::class)->handle($receipt, [
            'idempotency_token' => (string) Str::uuid(),
            'reason' => 'The delivery was posted against the wrong supplier order.',
        ], $this->actor);

        Sanctum::actingAs($this->actor, ['storage.purchase.read']);
        $response = $this->getJson(route('api.v1.storage.purchase-orders.show', $order))
            ->assertOk()
            ->assertJsonPath('data.status', 'ordered')
            ->assertJsonPath('data.lines.0.qty_received', 0)
            ->assertJsonPath('data.lines.0.qty_cancelled', 0)
            ->assertJsonPath('data.progress.qty_outstanding', 1);

        $this->assertArrayNotHasKey('cancel', $response->json('data.links'));

        Sanctum::actingAs($this->actor, ['storage.purchase.manage']);
        $this->postJson(route('api.v1.storage.purchase-orders.cancel', $order), [
            'reason' => 'Receipt history makes ordinary cancellation invalid.',
        ])->assertUnprocessable();
    }

    #[Test]
    public function receipt_api_is_idempotent_separates_overage_and_creates_guarded_reversals(): void
    {
        $order = $this->order(2);
        $line = $order->lines->first();
        $receiptToken = (string) Str::uuid();
        $receiptPayload = [
            'idempotency_token' => $receiptToken,
            'delivery_note_ref' => 'DN-API-1',
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 1,
                'qty_rejected' => 0,
                'units' => [],
            ]],
        ];

        Sanctum::actingAs($this->actor, ['storage.purchase.receive']);
        $first = $this->postJson(
            route('api.v1.storage.purchase-orders.receipts.store', $order),
            $receiptPayload
        );
        $first->assertCreated()
            ->assertJsonPath('data.receipt_type', 'receipt')
            ->assertJsonPath('data.idempotency_token', $receiptToken)
            ->assertJsonPath('data.lines.0.qty_accepted', 1);
        $receipt = PurchaseReceipt::query()->findOrFail($first->json('data.id'));

        $this->postJson(route('api.v1.storage.purchase-orders.receipts.store', $order), $receiptPayload)
            ->assertOk()
            ->assertJsonPath('data.id', $receipt->id);
        $this->assertSame(1, $this->item->refresh()->qty_on_hand);
        $this->assertSame(1, Movement::query()->where('type', 'receive')->count());

        $overagePayload = [
            'idempotency_token' => (string) Str::uuid(),
            'lines' => [[
                'purchase_order_line_id' => $line->id,
                'qty_accepted' => 2,
                'qty_rejected' => 0,
                'over_receipt_reason' => 'Supplier shipped one extra unit.',
                'units' => [],
            ]],
        ];
        $this->postJson(route('api.v1.storage.purchase-orders.receipts.store', $order), $overagePayload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('lines.0.qty_accepted');
        $this->assertSame(1, $this->item->refresh()->qty_on_hand);

        $sessionUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actingAs($sessionUser)
            ->postJson(route('api.v1.storage.purchase-orders.receipts.store', $order), $overagePayload)
            ->assertUnauthorized();

        Sanctum::actingAs($this->actor, [
            'storage.purchase.receive',
            'storage.purchase.receive_overage',
        ]);
        $this->postJson(route('api.v1.storage.purchase-orders.receipts.store', $order), $overagePayload)
            ->assertCreated()
            ->assertJsonPath('data.lines.0.is_over_receipt', true)
            ->assertJsonPath('data.lines.0.over_receipt_reason', 'Supplier shipped one extra unit.');
        $this->assertSame(3, $this->item->refresh()->qty_on_hand);

        $reversalPayload = [
            'idempotency_token' => (string) Str::uuid(),
            'reason' => 'First received unit was entered against the wrong delivery note.',
        ];
        $this->postJson(
            route('api.v1.storage.purchase-orders.receipts.reverse', [$order, $receipt]),
            $reversalPayload
        )->assertForbidden();

        Sanctum::actingAs($this->actor, ['storage.purchase.reverse']);
        $wrongOrder = $this->order(1);
        $this->postJson(
            route('api.v1.storage.purchase-orders.receipts.reverse', [$wrongOrder, $receipt]),
            $reversalPayload
        )->assertNotFound();
        $reversal = $this->postJson(
            route('api.v1.storage.purchase-orders.receipts.reverse', [$order, $receipt]),
            $reversalPayload
        );
        $reversal->assertCreated()
            ->assertJsonPath('data.receipt_type', 'reversal')
            ->assertJsonPath('data.reversal_of.original_receipt_id', $receipt->id);
        $this->assertArrayNotHasKey('reverse', $reversal->json('data.links'));
        $this->assertSame(2, $this->item->refresh()->qty_on_hand);
        $this->assertSame(PurchaseReceipt::STATUS_REVERSED, $receipt->refresh()->status);

        $this->postJson(
            route('api.v1.storage.purchase-orders.receipts.reverse', [$order, $receipt]),
            $reversalPayload
        )->assertOk()->assertJsonPath('data.id', $reversal->json('data.id'));
        $this->assertSame(3, Movement::query()->count());
    }

    private function order(int $quantity): PurchaseOrder
    {
        $this->sequence++;

        return app(StorePurchaseOrder::class)->handle([
            'po_number' => 'API-ORDER-'.$this->sequence,
            'vendor_id' => $this->vendor->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => '2026-08-04',
            'currency' => 'NOK',
            'lines' => [[
                'item_id' => $this->item->id,
                'qty_ordered' => $quantity,
                'qty_cancelled' => 0,
                'unit_cost' => 100,
                'tax_rate' => 25,
            ]],
        ], $this->actor)->load('lines');
    }
}
