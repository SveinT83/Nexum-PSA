<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Queries\PurchaseOrderIndexQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOrderProvenanceListTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Vendor $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech', 'web');
        Permission::findOrCreate('storage.purchase_view', 'web');

        $this->user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->user->assignRole('Tech');
        $this->user->givePermissionTo('storage.purchase_view');

        $this->supplier = Vendor::query()->create([
            'name' => 'Provenance Supplier',
            'vendor_code' => 'PROVENANCE',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::query()->create([
            'name' => 'Provenance Warehouse',
            'code' => 'PROVENANCE',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function purchase_order_list_distinguishes_manual_email_and_vendor_confirmed_orders(): void
    {
        $manual = $this->purchaseOrder('PO-PROVENANCE-100');
        $email = $this->purchaseOrder('PO-PROVENANCE-200', [
            'created_from' => 'supplier_order_email_import',
        ]);
        $confirmed = $this->purchaseOrder('PO-PROVENANCE-300');

        $this->linkSupplierEmail($email, 'email-created');
        $this->linkSupplierEmail($confirmed, 'vendor-confirmed');

        $response = $this->actingAs($this->user)->get(route('tech.storage.purchase-orders.index', [
            'sort' => 'order',
            'direction' => 'asc',
        ]));

        $response->assertOk()
            ->assertSeeInOrder([
                'title="Registered manually"',
                'class="bi bi-person"',
                'PO-PROVENANCE-100',
                'title="Created from supplier email"',
                'class="bi bi-envelope"',
                'PO-PROVENANCE-200',
                'title="Manually registered; confirmed by supplier email"',
                'class="bi bi-envelope-check"',
                'PO-PROVENANCE-300',
            ], false)
            ->assertSee('<span class="visually-hidden">Registered manually: </span>', false)
            ->assertSee('<span class="visually-hidden">Created from supplier email: </span>', false)
            ->assertSee('<span class="visually-hidden">Manually registered; confirmed by supplier email: </span>', false);

        $orders = app(PurchaseOrderIndexQuery::class)
            ->paginate(['sort' => 'order', 'direction' => 'asc'])
            ->getCollection();

        $this->assertCount(3, $orders);
        $this->assertTrue($orders->every(
            fn (PurchaseOrder $order): bool => $order->relationLoaded('supplierOrderImport')
        ));
        $this->assertNull($orders->firstWhere('id', $manual->id)?->supplierOrderImport);
    }

    /** @param array<string, mixed>|null $metadata */
    private function purchaseOrder(string $number, ?array $metadata = null): PurchaseOrder
    {
        return PurchaseOrder::query()->create([
            'po_number' => $number,
            'vendor_id' => $this->supplier->id,
            'supplier_name_snapshot' => $this->supplier->name,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => today(),
            'currency' => 'NOK',
            'metadata' => $metadata,
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    private function linkSupplierEmail(PurchaseOrder $purchaseOrder, string $source): PurchaseOrderImport
    {
        return PurchaseOrderImport::query()->create([
            'source_domain' => 'email',
            'source_type' => 'email_message',
            'source_id' => $source,
            'source_action_hash' => hash('sha256', 'action-'.$source),
            'source_fingerprint' => hash('sha256', 'source-'.$source),
            'safe_source_snapshot' => ['source' => 'email'],
            'purchase_order_id' => $purchaseOrder->id,
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'stage' => PurchaseOrderImport::STAGE_FINALIZE,
        ]);
    }
}
