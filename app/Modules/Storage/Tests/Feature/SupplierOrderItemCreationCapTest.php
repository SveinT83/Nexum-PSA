<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\ResolveSupplierOrderItems;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierOrderItemCreationCapTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Vendor $supplier;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('storage.purchase_manage', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo('storage.purchase_manage');
        $this->supplier = Vendor::query()->create([
            'name' => 'Creation Cap Supplier',
            'vendor_code' => 'CREATION-CAP',
            'is_supplier' => true,
            'is_vendor' => true,
            'is_active' => true,
        ]);
        $this->warehouse = Warehouse::query()->create([
            'name' => 'Creation Cap Warehouse',
            'code' => 'CREATION-CAP',
            'is_active' => true,
        ]);
    }

    #[Test]
    public function zero_cap_blocks_item_creation_before_any_master_data_write(): void
    {
        $import = $this->import(['SKU-ZERO']);

        $summary = $this->resolve($import, 0);

        $this->assertSame(0, $summary->created);
        $this->assertSame(1, $summary->unresolved);
        $this->assertContains('new_item_limit_exceeded', $summary->reasonCodes);
        $this->assertDatabaseCount('storage_items', 0);
        $this->assertDatabaseCount('storage_item_vendors', 0);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertSame(
            PurchaseOrderImportLine::MAPPING_UNRESOLVED,
            $import->lines()->sole()->mapping_status,
        );
    }

    #[Test]
    public function cap_overshoot_is_atomic_and_does_not_create_the_first_item(): void
    {
        $import = $this->import(['SKU-FIRST', 'SKU-SECOND']);

        $summary = $this->resolve($import, 1);

        $this->assertSame(0, $summary->created);
        $this->assertSame(2, $summary->unresolved);
        $this->assertContains('new_item_limit_exceeded', $summary->reasonCodes);
        $this->assertDatabaseCount('storage_items', 0);
        $this->assertDatabaseCount('storage_item_vendors', 0);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertSame(
            [PurchaseOrderImportLine::MAPPING_UNRESOLVED],
            $import->lines()->pluck('mapping_status')->unique()->values()->all(),
        );
    }

    #[Test]
    public function duplicate_source_lines_consume_one_distinct_item_cap_slot(): void
    {
        $import = $this->import(['SKU-SHARED', 'SKU-SHARED']);

        $summary = $this->resolve($import, 1);

        $this->assertTrue($summary->allResolved());
        $this->assertSame(1, $summary->created);
        $this->assertSame(2, $summary->resolved);
        $this->assertDatabaseCount('storage_items', 1);
        $this->assertDatabaseCount('storage_item_vendors', 1);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertSame(1, $import->lines()->pluck('item_id')->unique()->count());
        $this->assertTrue(Item::query()->sole()->can_be_ordered);
    }

    /** @param list<string> $supplierSkus */
    private function import(array $supplierSkus): PurchaseOrderImport
    {
        $seed = implode('|', $supplierSkus);
        $import = PurchaseOrderImport::query()->create([
            'source_domain' => 'email',
            'source_type' => 'email_message',
            'source_id' => hash('sha256', $seed),
            'signal_action_key' => 'cap-test-'.hash('sha256', $seed),
            'source_action_hash' => hash('sha256', 'action:'.$seed),
            'source_fingerprint' => hash('sha256', 'source:'.$seed),
            'safe_source_snapshot' => ['body_text' => 'Synthetic supplier order'],
            'vendor_id' => $this->supplier->id,
            'normalized_document' => [
                'destination_warehouse_id' => $this->warehouse->id,
                'currency' => 'NOK',
            ],
            'status' => PurchaseOrderImport::STATUS_PROCESSING,
            'stage' => PurchaseOrderImport::STAGE_ITEM_RESOLUTION,
            'attempt_count' => 1,
        ]);

        foreach ($supplierSkus as $index => $supplierSku) {
            PurchaseOrderImportLine::query()->create([
                'import_id' => $import->id,
                'position' => $index + 1,
                'supplier_sku' => $supplierSku,
                'normalized_supplier_sku' => $supplierSku,
                'description' => 'Synthetic Item '.($index + 1),
                'quantity' => 1,
                'unit_price' => 100,
                'line_total' => 100,
                'currency' => 'NOK',
                'mapping_status' => PurchaseOrderImportLine::MAPPING_UNRESOLVED,
            ]);
        }

        return $import->fresh(['lines']);
    }

    private function resolve(PurchaseOrderImport $import, int $maxNewItems): \App\Modules\Storage\Support\SupplierItemResolutionSummary
    {
        $policy = new PurchaseOrderAutomationPolicy([
            'new_item_mode' => 'create_active_item',
            'max_new_items' => $maxNewItems,
        ]);

        return app(ResolveSupplierOrderItems::class)->handle($import, $policy, $this->actor);
    }
}
