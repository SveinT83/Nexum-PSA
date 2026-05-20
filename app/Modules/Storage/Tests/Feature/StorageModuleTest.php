<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Storage\Controllers\Tech\BoxController;
use App\Modules\Storage\Controllers\Tech\ItemController;
use App\Modules\Storage\Controllers\Tech\StorageController;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StorageModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function tech_user_can_open_storage_index_from_storage_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.storage.index');

        $this->assertSame(StorageController::class . '@index', $route->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.storage.index'))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.index')
            ->assertViewHas('items')
            ->assertViewHas('warehouses');
    }

    #[Test]
    public function tech_user_can_create_warehouse_box_and_item_with_initial_stock(): void
    {
        $this->actingAs($this->tech)
            ->post(route('tech.storage.warehouses.store'), [
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
            ])
            ->assertRedirect(route('tech.storage.index'));

        $warehouse = Warehouse::firstOrFail();

        $this->actingAs($this->tech)
            ->post(route('tech.storage.boxes.store'), [
                'warehouse_id' => $warehouse->id,
                'code_human' => 'box one',
                'name' => 'Box One',
                'barcode_type' => 'QR',
                'status' => 'in_stock',
                'is_active' => '1',
            ])
            ->assertRedirect();

        $box = Box::firstOrFail();

        $this->assertSame('BOX-ONE', $box->code_human);
        $this->assertSame((string) $box->id, $box->barcode_value);
        $this->assertSame(1, $box->events()->where('type', 'created')->count());

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.store'), [
                'warehouse_id' => $warehouse->id,
                'box_id' => $box->id,
                'sku' => 'fw-100',
                'name' => 'Firewall 100',
                'initial_quantity' => 5,
                'reorder_point' => 2,
                'target_level' => 10,
                'moq' => 1,
                'status' => 'active',
            ])
            ->assertRedirect();

        $item = Item::firstOrFail();

        $this->assertSame('FW-100', $item->sku);
        $this->assertSame(5, $item->qty_on_hand);
        $this->assertSame(0, $item->qty_reserved);
        $this->assertSame(5, $item->qty_available);
        $this->assertFalse($item->needs_reorder);

        $movement = Movement::firstOrFail();
        $this->assertSame('receive', $movement->type);
        $this->assertSame(5, $movement->qty_delta);
        $this->assertSame(5, $movement->qty_after);
    }

    #[Test]
    public function tech_user_can_adjust_stock_but_not_below_zero(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'AP-01',
            'name' => 'Access Point',
            'qty_on_hand' => 2,
            'status' => 'active',
        ]);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.adjust', $item), [
                'delta' => 3,
                'reason' => 'manual_intake',
                'note' => 'Received from shelf count',
            ])
            ->assertRedirect();

        $this->assertSame(5, $item->refresh()->qty_on_hand);
        $this->assertSame('adjust', Movement::latest('id')->firstOrFail()->type);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.adjust', $item), [
                'delta' => -10,
                'reason' => 'inventory_correction',
                'note' => 'Bad count',
            ])
            ->assertSessionHasErrors('delta');

        $this->assertSame(5, $item->refresh()->qty_on_hand);
    }

    #[Test]
    public function storage_item_and_box_show_routes_are_module_owned(): void
    {
        $this->assertSame(ItemController::class . '@show', Route::getRoutes()->getByName('tech.storage.items.show')->getActionName());
        $this->assertSame(ItemController::class . '@edit', Route::getRoutes()->getByName('tech.storage.items.edit')->getActionName());
        $this->assertSame(ItemController::class . '@update', Route::getRoutes()->getByName('tech.storage.items.update')->getActionName());
        $this->assertSame(BoxController::class . '@show', Route::getRoutes()->getByName('tech.storage.boxes.show')->getActionName());
    }

    #[Test]
    public function tech_user_can_edit_storage_item_short_description(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'USB-CABLE',
            'name' => 'USB Cable',
            'qty_on_hand' => 2,
            'status' => 'active',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.items.edit', $item))
            ->assertOk()
            ->assertSee('Short Description');

        $this->actingAs($this->tech)
            ->patch(route('tech.storage.items.update', $item), [
                'warehouse_id' => $warehouse->id,
                'box_id' => '',
                'sku' => 'usb-cable',
                'name' => 'USB Cable',
                'short_description' => 'USB cable for ticket invoice text.',
                'long_description' => 'Internal catalog notes.',
                'ean_number' => '123',
                'purchase_price' => 50,
                'markup_percent' => 20,
                'sale_price' => 149,
                'vat_rate' => 25,
                'reorder_point' => 1,
                'target_level' => 5,
                'lead_time_days' => 2,
                'moq' => 1,
                'has_serials' => '1',
                'status' => 'active',
            ])
            ->assertRedirect(route('tech.storage.items.show', $item));

        $item->refresh();

        $this->assertSame('USB-CABLE', $item->sku);
        $this->assertSame('USB cable for ticket invoice text.', $item->short_description);
        $this->assertSame('Internal catalog notes.', $item->long_description);
        $this->assertSame('149.00', $item->sale_price);
        $this->assertTrue($item->has_serials);
    }
}
