<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Economy\Models\EconomySetting;
use App\Modules\Storage\Controllers\Admin\InventoryController;
use App\Modules\Storage\Controllers\Tech\BoxController;
use App\Modules\Storage\Controllers\Tech\ItemController;
use App\Modules\Storage\Controllers\Tech\StorageController;
use App\Modules\Storage\Models\Box;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\Movement;
use App\Modules\Storage\Models\Reservation;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class StorageModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        Role::create(['name' => 'Admin']);

        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function tech_user_can_open_storage_index_from_storage_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.storage.index');

        $this->assertSame(StorageController::class . '@index', $route->getActionName());
        $this->assertSame(StorageController::class . '@docs', Route::getRoutes()->getByName('tech.storage.docs')->getActionName());

        $this->actingAs($this->tech)
            ->get(route('tech.storage.index'))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.index')
            ->assertViewHas('items')
            ->assertViewHas('warehouses')
            ->assertSee('Inventory Items')
            ->assertSee('data-bs-target="#storageFiltersCollapse"', false)
            ->assertSee('bi bi-funnel')
            ->assertSee('New Item')
            ->assertSee('New Box')
            ->assertSee('Documentation')
            ->assertSee('Quick Stats')
            ->assertSee('data-bs-target="#storageQuickStatsCollapse"', false)
            ->assertDontSee('href="' . route('tech.admin.settings.storage.inventory') . '"', false);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.docs'))
            ->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8');
    }

    #[Test]
    public function admin_can_open_inventory_settings_and_create_warehouse(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.settings.storage.inventory');

        $this->assertSame(InventoryController::class . '@index', $route->getActionName());
        $this->assertSame(
            InventoryController::class . '@storeWarehouse',
            Route::getRoutes()->getByName('tech.admin.settings.storage.inventory.warehouses.store')->getActionName()
        );
        $this->assertSame(
            InventoryController::class . '@updateDefaultWarehouse',
            Route::getRoutes()->getByName('tech.admin.settings.storage.inventory.default-warehouse.update')->getActionName()
        );

        $this->actingAs($this->admin)
            ->get(route('tech.admin.settings.storage.inventory'))
            ->assertOk()
            ->assertViewIs('storage::Admin.Inventory.index')
            ->assertSee('Inventory Settings')
            ->assertSee('Company Warehouse')
            ->assertSee('Default')
            ->assertSee('Add Warehouse')
            ->assertSee('Create Warehouse');

        $this->assertDatabaseHas('storage_warehouses', [
            'name' => 'Company Warehouse',
            'code' => 'COMPANY',
            'is_active' => true,
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.storage.inventory.warehouses.store'), [
                'name' => 'Main Warehouse',
                'code' => 'MAIN',
            ])
            ->assertRedirect(route('tech.admin.settings.storage.inventory'));

        $this->assertDatabaseHas('storage_warehouses', [
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
    }

    #[Test]
    public function admin_can_change_default_warehouse_and_create_forms_preselect_it(): void
    {
        $company = Warehouse::create([
            'name' => 'Company Warehouse',
            'code' => 'COMPANY',
        ]);
        $van = Warehouse::create([
            'name' => 'Technician Van',
            'code' => 'VAN',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.settings.storage.inventory.default-warehouse.update'), [
                'default_warehouse_id' => $van->id,
            ])
            ->assertRedirect(route('tech.admin.settings.storage.inventory'));

        $payload = json_decode((string) CommonSetting::query()
            ->where('type', 'storage')
            ->where('name', 'inventory_defaults')
            ->value('json'), true);

        $this->assertSame($van->id, $payload['default_warehouse_id']);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.items.create'))
            ->assertOk()
            ->assertSee('value="' . $van->id . '" selected', false)
            ->assertSee('Technician Van');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.boxes.create'))
            ->assertOk()
            ->assertSee('value="' . $van->id . '" selected', false)
            ->assertSee('Technician Van');

        $this->assertNotSame($company->id, $van->id);
    }

    #[Test]
    public function admin_dashboard_links_to_storage_inventory_settings(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.index'))
            ->assertOk()
            ->assertSee('Storage')
            ->assertSee('admin-hub-card', false)
            ->assertSee('card-header bg-body', false)
            ->assertSee('bi bi-box-seam', false)
            ->assertSee(route('tech.admin.settings.storage.inventory'), false)
            ->assertSee('Inventory settings')
            ->assertSee('btn btn-sm btn-outline-secondary', false);
    }

    #[Test]
    public function tech_user_can_create_box_and_item_with_initial_stock(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);

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
        $manufacturer = Vendor::create([
            'name' => 'HP',
            'is_vendor' => true,
            'is_manufacturer' => true,
            'is_active' => true,
        ]);
        $supplier = Vendor::create([
            'name' => 'Dustin',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        $this->assertSame('BOX-ONE', $box->code_human);
        $this->assertSame((string) $box->id, $box->barcode_value);
        $this->assertSame(1, $box->events()->where('type', 'created')->count());

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.store'), [
                'warehouse_id' => $warehouse->id,
                'box_id' => $box->id,
                'sku' => 'fw-100',
                'name' => 'Firewall 100',
                'manufacturer_vendor_id' => $manufacturer->id,
                'manufacturer_part_number' => 'HP-FW-100',
                'primary_vendor_id' => $supplier->id,
                'supplier_sku' => 'DUS-100',
                'supplier_purchase_url' => 'https://www.dustin.no/product/fw-100',
                'supplier_lead_time_days' => 3,
                'supplier_moq' => 1,
                'supplier_pack_size' => 1,
                'purchase_price' => 5000,
                'initial_quantity' => 5,
                'reorder_point' => 2,
                'target_level' => 10,
                'moq' => 1,
                'status' => 'active',
            ])
            ->assertRedirect();

        $item = Item::firstOrFail();

        $this->assertSame('FW-100', $item->sku);
        $this->assertSame('HP', $item->manufacturer);
        $this->assertSame('HP-FW-100', $item->manufacturer_part_number);
        $this->assertSame('Dustin', $item->primaryVendor->name);
        $this->assertSame(5, $item->qty_on_hand);
        $this->assertSame(0, $item->qty_reserved);
        $this->assertSame(5, $item->qty_available);
        $this->assertFalse($item->needs_reorder);

        $movement = Movement::firstOrFail();
        $this->assertSame('receive', $movement->type);
        $this->assertSame(5, $movement->qty_delta);
        $this->assertSame(5, $movement->qty_after);

        $this->assertSame($manufacturer->id, $item->manufacturer_vendor_id);
        $this->assertSame($supplier->id, $item->primary_vendor_id);
        $this->assertDatabaseHas('storage_item_vendors', [
            'item_id' => $item->id,
            'vendor_id' => $item->primary_vendor_id,
            'vendor_sku' => 'DUS-100',
            'purchase_url' => 'https://www.dustin.no/product/fw-100',
            'unit_cost' => '5000.00',
            'is_primary' => true,
        ]);
    }

    #[Test]
    public function authenticated_api_user_can_manage_storage_inventory(): void
    {
        Sanctum::actingAs($this->tech, ['storage.read', 'storage.create', 'storage.update']);

        $warehouseResponse = $this->postJson(route('api.v1.storage.warehouses.store'), [
            'name' => 'API Warehouse',
            'code' => 'api main',
            'address' => 'Main street 1',
        ]);

        $warehouseResponse->assertCreated()
            ->assertJsonPath('data.name', 'API Warehouse')
            ->assertJsonPath('data.code', 'API-MAIN');

        $warehouseId = $warehouseResponse->json('data.id');

        $boxResponse = $this->postJson(route('api.v1.storage.boxes.store'), [
            'warehouse_id' => $warehouseId,
            'code_human' => 'api box',
            'name' => 'API Box',
            'barcode_value' => 'BOX-API-001',
            'barcode_type' => 'CODE128',
            'status' => 'in_stock',
        ]);

        $boxResponse->assertCreated()
            ->assertJsonPath('data.code_human', 'API-BOX')
            ->assertJsonPath('data.barcode_value', 'BOX-API-001');

        $boxId = $boxResponse->json('data.id');

        $itemResponse = $this->postJson(route('api.v1.storage.items.store'), [
            'warehouse_id' => $warehouseId,
            'box_id' => $boxId,
            'sku' => 'api-router',
            'name' => 'API Router',
            'ean_number' => '7090000000012',
            'sale_price' => 1299,
            'initial_quantity' => 4,
            'reorder_point' => 2,
            'target_level' => 8,
            'status' => 'active',
        ]);

        $itemResponse->assertCreated()
            ->assertJsonPath('data.sku', 'API-ROUTER')
            ->assertJsonPath('data.qty_on_hand', 4)
            ->assertJsonPath('data.box.id', $boxId);

        $itemId = $itemResponse->json('data.id');

        $this->getJson(route('api.v1.storage.items.index', ['q' => 'router']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $itemId);

        $this->patchJson(route('api.v1.storage.items.update', $itemId), [
            'name' => 'API Router Updated',
            'should_order' => true,
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'API Router Updated')
            ->assertJsonPath('data.should_order', true);

        $this->postJson(route('api.v1.storage.items.adjust', $itemId), [
            'delta' => -1,
            'reason' => 'api_test',
            'note' => 'Picked by API test.',
        ])
            ->assertOk()
            ->assertJsonPath('data.qty_on_hand', 3);

        $this->assertDatabaseHas('storage_movements', [
            'item_id' => $itemId,
            'type' => 'adjust',
            'qty_delta' => -1,
            'reason' => 'api_test',
        ]);
    }

    #[Test]
    public function storage_read_api_token_cannot_create_or_adjust_inventory(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Read Only Warehouse',
            'code' => 'READ',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'READ-ITEM',
            'name' => 'Read Item',
            'qty_on_hand' => 1,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->tech, ['storage.read']);

        $this->getJson(route('api.v1.storage.items.show', $item))
            ->assertOk()
            ->assertJsonPath('data.sku', 'READ-ITEM');

        $this->postJson(route('api.v1.storage.items.store'), [
            'warehouse_id' => $warehouse->id,
            'sku' => 'DENIED',
            'name' => 'Denied Item',
        ])->assertForbidden();

        $this->postJson(route('api.v1.storage.items.adjust', $item), [
            'delta' => 1,
            'reason' => 'denied',
        ])->assertForbidden();

        $this->deleteJson(route('api.v1.storage.items.destroy', $item))
            ->assertForbidden();
    }

    #[Test]
    public function storage_item_forms_use_economy_default_vat_and_do_not_show_duplicate_supplier_cost(): void
    {
        EconomySetting::query()->create(['default_vat_rate' => 25]);

        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'VAT-ITEM',
            'name' => 'VAT Item',
            'qty_on_hand' => 0,
            'status' => 'active',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.items.create'))
            ->assertOk()
            ->assertSee('name="vat_rate"', false)
            ->assertSee('value="25.00"', false)
            ->assertSee('Documentation')
            ->assertSee('Storage item field guide')
            ->assertSee(route('tech.storage.docs'), false)
            ->assertDontSee('Supplier Cost')
            ->assertDontSee('<h5 class="mb-0">Rules</h5>', false);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.items.edit', $item))
            ->assertOk()
            ->assertSee('name="vat_rate"', false)
            ->assertSee('value="25.00"', false)
            ->assertSee('Documentation')
            ->assertSee('Storage item field guide')
            ->assertSee(route('tech.storage.docs'), false)
            ->assertDontSee('Supplier Cost')
            ->assertDontSee('<h5 class="mb-0">Rules</h5>', false);
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
    public function tech_user_can_set_increase_and_decrease_stock_from_manual_adjustment_form(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'COUNT-01',
            'name' => 'Counted Item',
            'qty_on_hand' => 5,
            'status' => 'active',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.items.show', $item))
            ->assertOk()
            ->assertSee('name="adjustment_mode"', false)
            ->assertSee('Set on-hand to')
            ->assertSee('Increase by')
            ->assertSee('Decrease by');

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.adjust', $item), [
                'adjustment_mode' => 'set',
                'quantity' => 2,
                'reason' => 'inventory_correction',
                'note' => 'Physical count correction.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(2, $item->refresh()->qty_on_hand);
        $this->assertSame(-3, Movement::latest('id')->firstOrFail()->qty_delta);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.adjust', $item), [
                'adjustment_mode' => 'increase',
                'quantity' => 4,
                'reason' => 'manual_intake',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(6, $item->refresh()->qty_on_hand);
        $this->assertSame(4, Movement::latest('id')->firstOrFail()->qty_delta);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.adjust', $item), [
                'adjustment_mode' => 'decrease',
                'quantity' => 2,
                'reason' => 'manual_withdrawal',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(4, $item->refresh()->qty_on_hand);
        $this->assertSame(-2, Movement::latest('id')->firstOrFail()->qty_delta);

        $this->actingAs($this->tech)
            ->post(route('tech.storage.items.adjust', $item), [
                'adjustment_mode' => 'set',
                'quantity' => 4,
                'reason' => 'inventory_correction',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('quantity');
    }

    #[Test]
    public function tech_user_can_soft_delete_zero_stock_storage_item(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'DELETE-EMPTY',
            'name' => 'Delete Empty Item',
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'status' => 'active',
        ]);
        $ticket = $this->createTicket(['ticket_key' => 'TD-2026-999401']);
        $entry = TicketCostEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'storage_item_id' => $item->id,
            'quantity' => 1,
            'item_name' => $item->name,
            'item_sku' => $item->sku,
            'unit_price_ex_vat' => 100,
            'currency' => 'NOK',
            'status' => 'picked',
            'billing_status' => 'invoiced',
            'invoice_text' => $item->name,
        ]);

        $this->actingAs($this->tech)
            ->delete(route('tech.storage.items.destroy', $item))
            ->assertRedirect(route('tech.storage.index'))
            ->assertSessionHas('success', 'Storage item deleted.');

        $this->assertSoftDeleted('storage_items', ['id' => $item->id]);
        $this->assertNull(Item::query()->find($item->id));
        $this->assertSame($item->id, $entry->refresh()->storageItem?->id);
    }

    #[Test]
    public function storage_item_delete_is_blocked_when_stock_or_reservations_exist(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $stocked = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'DELETE-STOCKED',
            'name' => 'Delete Stocked Item',
            'qty_on_hand' => 1,
            'qty_reserved' => 0,
            'status' => 'active',
        ]);
        $reserved = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'DELETE-RESERVED',
            'name' => 'Delete Reserved Item',
            'qty_on_hand' => 0,
            'qty_reserved' => 1,
            'status' => 'active',
        ]);

        $this->actingAs($this->tech)
            ->delete(route('tech.storage.items.destroy', $stocked))
            ->assertRedirect()
            ->assertSessionHasErrors('item');

        $this->actingAs($this->tech)
            ->delete(route('tech.storage.items.destroy', $reserved))
            ->assertRedirect()
            ->assertSessionHasErrors('item');

        $this->assertDatabaseHas('storage_items', ['id' => $stocked->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('storage_items', ['id' => $reserved->id, 'deleted_at' => null]);
    }

    #[Test]
    public function api_user_can_soft_delete_zero_stock_storage_item(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'API Warehouse',
            'code' => 'API',
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'API-DELETE',
            'name' => 'API Delete Item',
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'status' => 'active',
        ]);
        $stocked = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'API-DELETE-STOCKED',
            'name' => 'API Delete Stocked Item',
            'qty_on_hand' => 1,
            'qty_reserved' => 0,
            'status' => 'active',
        ]);

        Sanctum::actingAs($this->tech, ['storage.read', 'storage.update']);

        $this->deleteJson(route('api.v1.storage.items.destroy', $stocked))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('item');

        $this->deleteJson(route('api.v1.storage.items.destroy', $item))
            ->assertNoContent();

        $this->assertSoftDeleted('storage_items', ['id' => $item->id]);
        $this->assertDatabaseHas('storage_items', ['id' => $stocked->id, 'deleted_at' => null]);
    }

    #[Test]
    public function storage_item_and_box_show_routes_are_module_owned(): void
    {
        $this->assertSame(ItemController::class . '@show', Route::getRoutes()->getByName('tech.storage.items.show')->getActionName());
        $this->assertSame(ItemController::class . '@edit', Route::getRoutes()->getByName('tech.storage.items.edit')->getActionName());
        $this->assertSame(ItemController::class . '@update', Route::getRoutes()->getByName('tech.storage.items.update')->getActionName());
        $this->assertSame(ItemController::class . '@destroy', Route::getRoutes()->getByName('tech.storage.items.destroy')->getActionName());
        $this->assertSame(BoxController::class . '@show', Route::getRoutes()->getByName('tech.storage.boxes.show')->getActionName());
        $this->assertSame(StorageController::class . '@picking', Route::getRoutes()->getByName('tech.storage.picking')->getActionName());
        $this->assertSame(StorageController::class . '@pickingDocs', Route::getRoutes()->getByName('tech.storage.picking.docs')->getActionName());
        $this->assertSame(StorageController::class . '@pick', Route::getRoutes()->getByName('tech.storage.picking.pick')->getActionName());
    }

    #[Test]
    public function tech_user_can_edit_storage_item_short_description(): void
    {
        $warehouse = Warehouse::create([
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
        ]);
        $manufacturer = Vendor::create([
            'name' => 'Lenovo',
            'vendor_code' => 'LENOVO',
            'is_manufacturer' => true,
            'is_supplier' => false,
        ]);
        $supplier = Vendor::create([
            'name' => 'Komplett',
            'vendor_code' => 'KOMPLETT',
            'is_manufacturer' => false,
            'is_supplier' => true,
        ]);
        $item = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'USB-CABLE',
            'name' => 'USB Cable',
            'manufacturer_vendor_id' => $manufacturer->id,
            'primary_vendor_id' => $supplier->id,
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
                'manufacturer_vendor_id' => $manufacturer->id,
                'manufacturer_part_number' => 'LEN-USB',
                'primary_vendor_id' => $supplier->id,
                'supplier_sku' => 'K-USB',
                'supplier_purchase_url' => 'https://www.komplett.no/product/usb',
                'supplier_currency' => 'NOK',
                'supplier_lead_time_days' => 2,
                'supplier_moq' => 2,
                'supplier_pack_size' => 1,
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
        $this->assertSame($manufacturer->id, $item->manufacturer_vendor_id);
        $this->assertSame($supplier->id, $item->primary_vendor_id);
        $this->assertSame('Lenovo', $item->manufacturer);
        $this->assertSame('LEN-USB', $item->manufacturer_part_number);
        $this->assertSame('USB cable for ticket invoice text.', $item->short_description);
        $this->assertSame('Internal catalog notes.', $item->long_description);
        $this->assertSame('149.00', $item->sale_price);
        $this->assertTrue($item->has_serials);
        $this->assertDatabaseHas('storage_item_vendors', [
            'item_id' => $item->id,
            'vendor_id' => $supplier->id,
            'vendor_sku' => 'K-USB',
            'purchase_url' => 'https://www.komplett.no/product/usb',
            'unit_cost' => '50.00',
            'is_primary' => true,
        ]);
    }

    #[Test]
    public function tech_user_can_open_picking_list_and_pick_ready_ticket_reservation(): void
    {
        $client = Client::create(['name' => 'Picking Client AS', 'active' => true]);
        $ticket = $this->createTicket([
            'ticket_key' => 'TD-2026-999301',
            'client_id' => $client->id,
            'subject' => 'Needs cable',
        ]);
        $warehouse = Warehouse::create(['name' => 'Main Warehouse', 'code' => 'MAIN']);
        $readyItem = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'READY-USB',
            'name' => 'Ready USB Cable',
            'qty_on_hand' => 5,
            'qty_reserved' => 2,
            'sale_price' => 100,
            'status' => 'active',
        ]);
        $waitingItem = Item::create([
            'warehouse_id' => $warehouse->id,
            'sku' => 'WAIT-DOCK',
            'name' => 'Waiting Dock',
            'qty_on_hand' => 0,
            'qty_reserved' => 1,
            'sale_price' => 500,
            'status' => 'active',
        ]);
        $reservation = Reservation::create([
            'item_id' => $readyItem->id,
            'warehouse_id' => $warehouse->id,
            'qty' => 2,
            'source_type' => 'ticket',
            'source_id' => (string) $ticket->id,
            'strength' => 'hard',
            'status' => 'active',
            'created_by' => $this->tech->id,
        ]);
        $readyEntry = TicketCostEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'storage_item_id' => $readyItem->id,
            'storage_reservation_id' => $reservation->id,
            'quantity' => 2,
            'item_name' => $readyItem->name,
            'item_sku' => $readyItem->sku,
            'unit_price_ex_vat' => 100,
            'currency' => 'NOK',
            'status' => 'reserved',
            'billing_status' => 'pending',
            'invoice_text' => 'Ready USB Cable',
        ]);
        TicketCostEntry::create([
            'ticket_id' => $ticket->id,
            'user_id' => $this->tech->id,
            'storage_item_id' => $waitingItem->id,
            'quantity' => 1,
            'item_name' => $waitingItem->name,
            'item_sku' => $waitingItem->sku,
            'unit_price_ex_vat' => 500,
            'currency' => 'NOK',
            'status' => 'reserved',
            'billing_status' => 'pending',
            'invoice_text' => 'Waiting Dock',
        ]);

        $this->actingAs($this->tech)
            ->get(route('tech.storage.picking'))
            ->assertOk()
            ->assertViewIs('storage::Tech.Storage.picking')
            ->assertSeeInOrder(['READY-USB', 'WAIT-DOCK'])
            ->assertSee('data-bs-target="#pickingFiltersCollapse"', false)
            ->assertSee('bi bi-funnel')
            ->assertSee('How to use the Picking List')
            ->assertSee(route('tech.storage.picking.docs'), false)
            ->assertDontSee('Reserved ticket items, sorted by what can be picked now.')
            ->assertSee('Ready')
            ->assertSee('Waiting for stock');

        $this->actingAs($this->tech)
            ->get(route('tech.storage.picking.docs'))
            ->assertOk()
            ->assertHeader('content-type', 'text/markdown; charset=UTF-8');

        $this->actingAs($this->tech)
            ->post(route('tech.storage.picking.pick', $readyEntry))
            ->assertRedirect(route('tech.storage.picking'));

        $this->assertSame(3, $readyItem->refresh()->qty_on_hand);
        $this->assertSame(0, $readyItem->qty_reserved);
        $this->assertSame('picked', $readyEntry->refresh()->status);
        $this->assertSame('fulfilled', $reservation->refresh()->status);
        $this->assertDatabaseHas('storage_movements', [
            'item_id' => $readyItem->id,
            'type' => 'ticket_pick',
            'qty_delta' => -2,
        ]);
    }

    private function createTicket(array $overrides = []): Ticket
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        return Ticket::create(array_merge([
            'ticket_key' => 'TD-2026-999300',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'updated_by' => $this->tech->id,
            'channel' => 'manual',
            'subject' => 'Storage picking helper subject',
            'is_unread' => false,
        ], $overrides));
    }
}
