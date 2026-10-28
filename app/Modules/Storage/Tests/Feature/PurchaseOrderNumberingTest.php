<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\AllocatePurchaseOrderNumber;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\UpdatePurchaseOrder;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Canonical identity regressions using synthetic local records and no supplier/provider effects. */
class PurchaseOrderNumberingTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Vendor $vendor;

    private Warehouse $warehouse;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['storage.view', 'storage.purchase_view', 'storage.purchase_manage']);
        $this->vendor = Vendor::query()->create(['name' => 'Numbering fixture supplier', 'vendor_code' => 'NUMBER-FIXTURE',
            'is_vendor' => true, 'is_supplier' => true, 'is_active' => true]);
        $this->warehouse = Warehouse::query()->create(['name' => 'Numbering fixture warehouse', 'code' => 'NUMBER-FIXTURE', 'is_active' => true]);
        $this->item = Item::query()->create(['warehouse_id' => $this->warehouse->id, 'name' => 'Numbering fixture item',
            'sku' => 'NUMBER-FIXTURE', 'can_be_ordered' => true, 'status' => 'active', 'qty_on_hand' => 0, 'qty_reserved' => 0]);
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function payload(): array
    {
        return ['vendor_id' => $this->vendor->id, 'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => 'draft', 'currency' => 'NOK',
            'lines' => [['item_id' => $this->item->id, 'qty_ordered' => 1, 'qty_cancelled' => 0]]];
    }

    private function legacy(string $number): PurchaseOrder
    {
        // Historical fixtures intentionally bypass the new-creation action; their identities must survive.
        return PurchaseOrder::query()->create(['po_number' => $number, 'vendor_id' => $this->vendor->id,
            'deliver_to_warehouse_id' => $this->warehouse->id, 'status' => 'draft', 'currency' => 'NOK',
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id]);
    }

    #[Test]
    public function callers_cannot_select_the_generated_number(): void
    {
        try {
            app(StorePurchaseOrder::class)->handle($this->payload() + ['po_number' => 'CALLER-CHOSEN'], $this->actor);
            $this->fail('A caller selected the canonical number.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('po_number', $error->errors());
        }
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function ordinary_update_rejects_renumbering_but_preserves_an_identical_legacy_echo(): void
    {
        $order = $this->legacy('LEGACY-CUSTOM-IDENTITY');
        $same = app(UpdatePurchaseOrder::class)->handle($order, ['po_number' => $order->po_number, 'notes' => 'Unchanged identity'], $this->actor);
        $this->assertSame('LEGACY-CUSTOM-IDENTITY', $same->po_number);
        try {
            app(UpdatePurchaseOrder::class)->handle($order->fresh(), ['po_number' => 'RENAMED'], $this->actor);
            $this->fail('An ordinary action renamed a legacy identity.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('po_number', $error->errors());
        }
        $this->assertSame('LEGACY-CUSTOM-IDENTITY', $order->fresh()->po_number);
    }

    #[Test]
    public function an_ordinary_model_save_cannot_bypass_identity_immutability(): void
    {
        $order = $this->legacy('LEGACY-MODEL-IDENTITY');
        $order->po_number = 'RENAMED';
        try {
            $order->save();
            $this->fail('An ordinary model save renamed the order.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('po_number', $error->errors());
        }
        $this->assertSame('LEGACY-MODEL-IDENTITY', $order->fresh()->po_number);
    }

    #[Test]
    public function legacy_numeric_and_soft_deleted_identities_cannot_break_new_allocation(): void
    {
        $collision = (string) ((int) PurchaseOrder::withTrashed()->max('id') + 2);
        $legacy = $this->legacy($collision);
        $legacy->delete();
        $created = app(StorePurchaseOrder::class)->handle($this->payload(), $this->actor);
        $this->assertTrue(ctype_digit($created->po_number));
        $this->assertGreaterThan((int) $collision, (int) $created->po_number);
        $this->assertSame($collision, PurchaseOrder::withTrashed()->findOrFail($legacy->id)->po_number);
        $this->assertNotSame($collision, $created->po_number);
    }

    #[Test]
    public function manual_forms_display_authoritative_read_only_numbers_without_number_input(): void
    {
        $this->actingAs($this->actor);
        $this->get(route('tech.storage.purchase-orders.create'))->assertOk()
            ->assertSee('Nexum order number')->assertSee('Assigned when saved')->assertDontSee('name="po_number"', false);
        $order = app(StorePurchaseOrder::class)->handle($this->payload(), $this->actor);
        $this->get(route('tech.storage.purchase-orders.edit', $order))->assertOk()
            ->assertSee('readonly', false)->assertSee('value="'.$order->po_number.'"', false)
            ->assertDontSee('name="po_number"', false)->assertDontSee('Your internal reference for this purchase order.');
    }

    #[Test]
    public function api_creation_generates_and_update_protects_the_same_identity(): void
    {
        Sanctum::actingAs($this->actor, ['storage.purchase.manage']);
        $created = $this->postJson(route('api.v1.storage.purchase-orders.store'), $this->payload())->assertCreated();
        $id = $created->json('data.id');
        $number = $created->json('data.po_number');
        $this->assertTrue(ctype_digit($number));
        $this->putJson(route('api.v1.storage.purchase-orders.update', $id), $this->payload() + ['po_number' => $number])->assertOk()
            ->assertJsonPath('data.po_number', $number);
        $this->putJson(route('api.v1.storage.purchase-orders.update', $id), $this->payload() + ['po_number' => 'RENAMED'])->assertUnprocessable()
            ->assertJsonValidationErrors('po_number');
        $this->postJson(route('api.v1.storage.purchase-orders.store'), $this->payload() + ['po_number' => 'CHOSEN'])->assertUnprocessable()
            ->assertJsonValidationErrors('po_number');
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertDatabaseHas('storage_purchase_orders', ['id' => $id, 'po_number' => $number]);
    }

    #[Test]
    public function failed_line_sync_rolls_back_the_order_and_sequence_together(): void
    {
        $before = (array) DB::table(AllocatePurchaseOrderNumber::TABLE)->where('id', 1)->first();
        $payload = $this->payload();
        $payload['lines'][0]['qty_ordered'] = -1;
        try {
            app(StorePurchaseOrder::class)->handle($payload, $this->actor);
            $this->fail('An invalid line was saved.');
        } catch (ValidationException $error) {
            $this->assertNotEmpty($error->errors());
        }
        $this->assertDatabaseCount('storage_purchase_orders', 0);
        $this->assertSame($before, (array) DB::table(AllocatePurchaseOrderNumber::TABLE)->where('id', 1)->first());
        $created = app(StorePurchaseOrder::class)->handle($this->payload(), $this->actor);
        $this->assertTrue(ctype_digit($created->po_number));
        $this->assertFalse(PurchaseOrder::isPendingNumber($created->po_number));
    }

    #[Test]
    public function allocation_is_idempotent_and_a_deleted_order_does_not_rewind_the_sequence(): void
    {
        $created = app(StorePurchaseOrder::class)->handle($this->payload(), $this->actor);
        $number = $created->po_number;
        $counter = DB::table(AllocatePurchaseOrderNumber::TABLE)->where('id', 1)->value('last_number');
        $again = app(AllocatePurchaseOrderNumber::class)->handle($created->fresh());
        $this->assertSame($number, $again->po_number);
        $this->assertSame($counter, DB::table(AllocatePurchaseOrderNumber::TABLE)->where('id', 1)->value('last_number'));
        $created->delete();
        $next = app(StorePurchaseOrder::class)->handle($this->payload(), $this->actor);
        $this->assertGreaterThan((int) $number, (int) $next->po_number);
        $this->assertSame($number, PurchaseOrder::withTrashed()->findOrFail($created->id)->po_number);
    }

    #[Test]
    public function missing_counter_fails_closed_without_recreating_or_saving_an_order(): void
    {
        DB::table(AllocatePurchaseOrderNumber::TABLE)->delete();
        try {
            app(StorePurchaseOrder::class)->handle($this->payload(), $this->actor);
            $this->fail('A missing counter was silently recreated.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            $this->assertSame(503, $error->getStatusCode());
        }
        $this->assertDatabaseCount('storage_purchase_orders', 0);
        $this->assertDatabaseCount(AllocatePurchaseOrderNumber::TABLE, 0);
    }
}
