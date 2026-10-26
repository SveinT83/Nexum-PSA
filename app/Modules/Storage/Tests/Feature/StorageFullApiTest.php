<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\ResolveEffectivePurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\SyncPurchaseOrderImportLines;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\StorageApiCommand;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderDeterministicExtractor;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real bearer HTTP regression coverage of the missing Storage workflows and boundaries. */
class StorageFullApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Vendor $supplier;

    private Warehouse $warehouse;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole(Role::findOrCreate('Tech', 'web'));
        $this->actor->givePermissionTo([
            'storage.view', 'storage.item_manage', 'storage.stock_adjust', 'storage.pick', 'storage.manage_settings',
            'storage.purchase_view', 'storage.purchase_manage', 'storage.purchase_receive', 'storage.purchase_receive_overage',
            'storage.purchase_reverse', 'storage.purchase_import_view', 'storage.purchase_import_resolve',
            'storage.purchase_import_execute', 'storage.purchase_import_profile_manage', 'storage.purchase_import_policy_manage',
            'ticket.view', 'ticket.update',
        ]);
        $this->supplier = Vendor::create(['name' => 'Itegra', 'vendor_code' => 'ITEGRA-API', 'is_supplier' => true, 'is_active' => true,
            'note' => 'INTERNAL-SUPPLIER-NOTE', 'terms' => 'PRIVATE-TERMS']);
        $this->warehouse = Warehouse::create(['name' => 'API receiving', 'code' => 'API-RECEIVING', 'is_active' => true]);
        $this->item = Item::create(['warehouse_id' => $this->warehouse->id, 'sku' => 'NX-SYN-1001', 'name' => 'Nexum synthetic profile fixture',
            'purchase_price' => 100, 'vat_rate' => 25, 'qty_on_hand' => 0, 'qty_reserved' => 0,
            'can_be_ordered' => true, 'status' => 'active']);
        $this->bearer(['*']);
    }

    private function bearer(array $abilities, ?User $actor = null): string
    {
        $actor ??= $this->actor;
        $token = $actor->createToken('Storage API test', $abilities)->plainTextToken;
        Auth::forgetGuards();
        $this->withToken($token);

        return $token;
    }

    private function keyed(?string $key = null): static
    {
        return $this->withHeader('Idempotency-Key', $key ?? (string) Str::uuid());
    }

    #[Test]
    public function every_storage_route_has_explicit_scope_current_actor_guard_and_owned_declaration(): void
    {
        $routes = collect(Route::getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/storage'));
        $this->assertGreaterThanOrEqual(69, $routes->count());
        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains(\App\Modules\Storage\Support\StorageApiAccess::class, $middleware, $route->uri());
            $this->assertTrue(collect($middleware)->contains(fn ($m) => str_contains($m, 'CheckAbilities:')), $route->uri());
            $this->assertContains(\App\Modules\Storage\Actions\ExecuteStorageApiCommand::class, $middleware);
        }
        $this->assertStringNotContainsString('Route::', file_get_contents(app_path('Modules/Storage/api.php')));
    }

    #[Test]
    public function token_scopes_never_replace_current_user_permissions_or_active_status(): void
    {
        $unprivileged = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $unprivileged->assignRole(Role::findOrCreate('Tech', 'web'));
        $this->bearer(['*'], $unprivileged);
        $this->getJson('/api/v1/storage/suppliers')->assertForbidden();
        $this->getJson('/api/v1/storage/items')->assertForbidden();
        $this->keyed()->putJson('/api/v1/storage/settings', ['default_warehouse_id' => $this->warehouse->id])->assertForbidden();
        $this->bearer(['storage.suppliers.read']);
        $this->getJson('/api/v1/storage/suppliers')->assertOk();
        $this->getJson('/api/v1/storage/items')->assertForbidden();
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson('/api/v1/storage/suppliers')->assertForbidden();
        $this->assertSame(0, StorageApiCommand::count());
    }

    #[Test]
    public function supplier_lookup_is_paginated_ambiguous_safe_and_does_not_promote_master_roles(): void
    {
        $other = Vendor::create(['name' => 'Itegra secondary', 'is_supplier' => true, 'is_active' => true]);
        $inactive = Vendor::create(['name' => 'Itegra old', 'is_supplier' => true, 'is_active' => false]);
        $manufacturer = Vendor::create(['name' => 'Only manufacturer', 'is_manufacturer' => true, 'is_supplier' => false, 'is_active' => true]);
        $response = $this->getJson('/api/v1/storage/suppliers?q=Itegra&per_page=1')->assertOk()
            ->assertJsonPath('total', 2)->assertJsonCount(1, 'data');
        $this->assertStringNotContainsString('INTERNAL-SUPPLIER-NOTE', $response->getContent());
        $this->assertStringNotContainsString('PRIVATE-TERMS', $response->getContent());
        $this->getJson('/api/v1/storage/suppliers/'.$this->supplier->id)->assertOk()->assertJsonPath('data.is_supplier', true);
        $this->getJson('/api/v1/storage/suppliers?is_active=0')->assertOk()->assertJsonPath('data.0.id', $inactive->id);
        $this->getJson('/api/v1/storage/suppliers?is_supplier=0&is_manufacturer=1')->assertOk()->assertJsonPath('data.0.id', $manufacturer->id);
        $this->assertFalse($manufacturer->fresh()->is_supplier);
        $this->getJson('/api/v1/storage/suppliers?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/v1/storage/suppliers?is_active=invalid')->assertUnprocessable();
        $this->getJson('/api/v1/storage/suppliers/9999999')->assertNotFound();
        $this->getJson('/api/v1/storage/suppliers?private_note=1')->assertUnprocessable();
    }

    #[Test]
    public function read_context_settings_policy_and_profile_defaults_do_not_create_business_rows(): void
    {
        $before = $this->counts();
        foreach (['context', 'summary', 'settings', 'automation-policy', 'supplier-order-profiles/context', 'supplier-order-profiles', 'picking'] as $path) {
            $this->getJson('/api/v1/storage/'.$path)->assertOk();
        }
        $this->assertSame($before, $this->counts());
        $this->getJson('/api/v1/storage/settings')->assertHeader('ETag');
        $this->getJson('/api/v1/storage/automation-policy')->assertJsonPath('data.runtime_mode', 'off');
        $this->get('/api/v1/storage/docs?topic=picking')->assertOk()->assertHeader('content-type', 'text/markdown; charset=UTF-8');
        $this->getJson('/api/v1/storage/docs?topic=../../.env')->assertNotFound();
    }

    #[Test]
    public function supplier_search_item_creation_order_registration_and_incoming_readback_use_only_http(): void
    {
        $supplierId = $this->getJson('/api/v1/storage/suppliers?q=Itegra')->assertOk()->json('data.0.id');
        $created = $this->keyed()->postJson('/api/v1/storage/items', [
            'warehouse_id' => $this->warehouse->id, 'sku' => 'FULL-API-NEW', 'name' => 'API-only item',
            'primary_vendor_id' => $supplierId, 'supplier_sku' => 'SUP-555', 'purchase_price' => '120.50',
            'supplier_currency' => 'NOK', 'supplier_pack_size' => 2, 'initial_quantity' => 0,
        ])->assertCreated()->assertJsonPath('data.supplier_lines.0.vendor_sku', 'SUP-555');
        $id = $created->json('data.id');
        $order = $this->keyed()->postJson('/api/v1/storage/purchase-orders', [
            'vendor_id' => $supplierId, 'deliver_to_warehouse_id' => $this->warehouse->id, 'status' => 'ordered',
            'vendor_ref' => 'EXTERNAL-API-ONLY-001', 'currency' => 'NOK', 'ordered_at' => '2026-10-26',
            'lines' => [['item_id' => $id, 'qty_ordered' => 3, 'unit_cost' => '120.50']],
        ])->assertCreated();
        $this->getJson('/api/v1/storage/items/'.$id)->assertOk()->assertJsonPath('data.qty_incoming', 3)
            ->assertJsonPath('data.qty_on_hand', 0)->assertJsonPath('data.qty_reserved', 0);
        $this->getJson('/api/v1/storage/items?availability=all&supplier_id='.$supplierId.'&sort=incoming&direction=desc')
            ->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.qty_incoming', 3);
        $this->getJson('/api/v1/storage/supplier-orders?q=EXTERNAL-API-ONLY-001')->assertOk()->assertJsonPath('data.0.purchase_order_id', $order->json('data.id'));
        $this->getJson('/api/v1/storage/receiving')->assertOk()->assertJsonPath('data.0.purchase_order_id', $order->json('data.id'));
        $this->getJson('/api/v1/storage/purchase-orders/'.$order->json('data.id').'/control-slip')->assertOk()->assertJsonPath('data.purchase_order.vendor_ref', 'EXTERNAL-API-ONLY-001');
    }

    #[Test]
    public function keyed_stock_adjustment_replays_once_conflicts_and_preserves_validation_errors(): void
    {
        $key = (string) Str::uuid();
        $payload = ['adjustment_mode' => 'increase', 'quantity' => 4, 'reason' => 'Counted stock'];
        $first = $this->keyed($key)->postJson('/api/v1/storage/items/'.$this->item->id.'/adjust', $payload)
            ->assertOk()->assertJsonPath('data.qty_on_hand', 4)->assertJsonPath('replayed', false);
        $this->keyed($key)->postJson('/api/v1/storage/items/'.$this->item->id.'/adjust', $payload)
            ->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(4, $this->item->fresh()->qty_on_hand);
        $this->assertDatabaseCount('storage_movements', 1);
        $this->keyed($key)->postJson('/api/v1/storage/items/'.$this->item->id.'/adjust', $payload + ['note' => 'Different request'])->assertConflict();
        $this->getJson('/api/v1/storage/operations/'.$first->json('operation_id'))->assertOk()->assertJsonPath('data.state', 'completed');
        $this->keyed()->postJson('/api/v1/storage/items/'.$this->item->id.'/adjust', ['delta' => 0, 'reason' => 'Invalid'])
            ->assertUnprocessable()->assertJsonValidationErrors('delta');
    }

    #[Test]
    public function stale_revisions_refuse_writes_and_new_actions_require_an_idempotency_key(): void
    {
        $revision = $this->getJson('/api/v1/storage/settings')->assertOk()->headers->get('ETag');
        $this->withoutHeader('Idempotency-Key')->putJson('/api/v1/storage/settings', ['default_warehouse_id' => $this->warehouse->id])->assertStatus(428);
        $this->keyed()->withHeader('If-Match', $revision)->putJson('/api/v1/storage/settings', ['default_warehouse_id' => $this->warehouse->id])->assertOk();
        $this->keyed()->withHeader('If-Match', $revision)->putJson('/api/v1/storage/settings', ['default_warehouse_id' => $this->warehouse->id])->assertStatus(412);
        $this->withoutHeader('If-Match');
        $this->keyed()->putJson('/api/v1/storage/settings', ['default_warehouse_id' => $this->warehouse->id, 'actor_id' => 999])->assertUnprocessable();
    }

    #[Test]
    public function command_lookup_and_replay_are_bound_to_current_actor_token_and_original_rights(): void
    {
        $key = (string) Str::uuid();
        $payload = ['delta' => 2, 'reason' => 'Test counted'];
        $id = $this->keyed($key)->postJson('/api/v1/storage/items/'.$this->item->id.'/adjust', $payload)->assertOk()->json('operation_id');
        $this->assertStringNotContainsString('Test counted', (string) DB::table('storage_api_commands')->where('id', $id)->value('result'));
        $this->actor->revokePermissionTo('storage.stock_adjust');
        $this->keyed($key)->postJson('/api/v1/storage/items/'.$this->item->id.'/adjust', $payload)->assertForbidden();
        $this->getJson('/api/v1/storage/operations/'.$id)->assertForbidden();
        $this->actor->givePermissionTo('storage.stock_adjust');
        $this->bearer(['*']);
        $this->getJson('/api/v1/storage/operations/'.$id)->assertNotFound();
    }

    #[Test]
    public function profile_creation_metadata_versions_export_and_import_share_browser_actions(): void
    {
        $profile = $this->createProfile();
        $version = $profile->versions()->firstOrFail();
        $this->keyed()->putJson('/api/v1/storage/supplier-order-profiles/'.$profile->id, [
            'name' => 'API updated profile', 'slug' => $profile->slug, 'description' => 'Current description',
            'matching_scope' => $profile->matching_scope, 'reason' => 'Maintain profile metadata.',
        ])->assertOk()->assertJsonPath('data.name', 'API updated profile');
        $this->assertDatabaseCount('storage_purchase_order_import_profile_metadata_audits', 1);
        $this->keyed()->postJson('/api/v1/storage/supplier-order-profiles/'.$profile->id.'/versions', [
            'definition' => SupplierOrderProfileFactoryData::itegra(), 'parent_version_id' => $version->id,
        ])->assertCreated();
        $export = $this->getJson('/api/v1/storage/supplier-order-profiles/'.$profile->id.'/export')->assertOk()->json();
        $this->keyed()->postJson('/api/v1/storage/supplier-order-profiles/import', ['export' => $export, 'slug' => 'api-imported-profile'])
            ->assertCreated()->assertJsonPath('data.lifecycle_state', 'draft');
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function profile_lifecycle_is_fixture_gated_and_cross_parent_versions_are_rejected(): void
    {
        $profile = $this->createProfile();
        $other = $this->createProfile('another-profile');
        $version = $profile->versions()->firstOrFail();
        $path = '/api/v1/storage/supplier-order-profiles/'.$profile->id;
        $this->keyed()->postJson($path.'/versions/'.$other->versions()->firstOrFail()->id.'/test')->assertNotFound();
        $this->keyed()->postJson($path.'/versions/'.$version->id.'/test')->assertOk()->assertJsonPath('data.validation.valid', false);
        $this->keyed()->postJson($path.'/versions/'.$version->id.'/activate', ['reason' => 'Explicit activation', 'confirm' => true])->assertConflict();
        config(['storage_api.automation_enabled' => true]);
        $this->keyed()->postJson($path.'/versions/'.$version->id.'/activate', ['reason' => 'Explicit activation', 'confirm' => true])->assertUnprocessable();
        $this->keyed()->postJson($path.'/pause', ['reason' => 'Pause for review'])->assertOk()->assertJsonPath('data.lifecycle_state', 'paused');
        $this->keyed()->postJson($path.'/retire', ['reason' => 'Retire unused profile'])->assertOk()->assertJsonPath('data.lifecycle_state', 'retired');
    }

    #[Test]
    public function imports_map_finalize_and_read_back_one_order_without_receiving_stock(): void
    {
        $import = $this->reviewedImport('api-finalize');
        $line = $import->lines()->firstOrFail();
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id;
        $this->keyed()->postJson($path.'/lines/'.$line->id.'/map', ['item_id' => $this->item->id])->assertOk()->assertJsonPath('data.lines.0.item_id', $this->item->id);
        $first = $this->keyed()->postJson($path.'/finalize')->assertOk();
        $this->assertNotNull($first->json('data.purchase_order.id'));
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertSame(0, $this->item->fresh()->qty_on_hand);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->getJson($path)->assertOk()->assertJsonPath('data.purchase_order_id', $first->json('data.purchase_order.id'));
    }

    #[Test]
    public function import_sources_are_sanitized_nested_lines_are_guarded_and_terminal_actions_refuse(): void
    {
        $import = $this->reviewedImport('api-guard', true);
        $other = $this->reviewedImport('api-other');
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id;
        $response = $this->getJson($path)->assertOk();
        foreach (['SECRET-SOURCE-HEADER', '/private/source.eml', '<script>', 'onclick='] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $response->getContent());
        }
        $this->keyed()->postJson($path.'/lines/'.$other->lines()->firstOrFail()->id.'/map', ['item_id' => $this->item->id])->assertNotFound();
        $this->keyed()->postJson($path.'/reject', ['reason' => 'Rejected after controlled review'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->keyed()->postJson($path.'/finalize')->assertUnprocessable();
    }

    #[Test]
    public function retry_preview_is_pure_and_execution_is_default_off_explicit_and_queued(): void
    {
        Queue::fake();
        $import = $this->reviewedImport('api-retry');
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id;
        $before = $this->counts();
        $this->postJson($path.'/retry/preview')->assertOk()->assertJsonPath('data.api_enabled', false);
        $this->assertSame($before, $this->counts());
        $this->keyed()->postJson($path.'/retry', ['confirm' => true])->assertConflict();
        config(['storage_api.automation_enabled' => true]);
        $this->keyed()->postJson($path.'/retry')->assertUnprocessable();
        $this->keyed()->postJson($path.'/retry', ['confirm' => true])->assertAccepted()->assertJsonPath('data.status', 'pending');
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function api_ai_repair_is_default_off_and_requires_both_execution_and_profile_rights(): void
    {
        $import = $this->reviewedImport('api-ai-repair');
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id;
        $this->postJson($path.'/repair/preview')->assertOk()->assertJsonPath('data.api_enabled', false)
            ->assertJsonPath('data.availability.available', false);
        $this->keyed()->postJson($path.'/repair', ['confirm' => true])->assertConflict();
        $this->bearer(['storage.imports.execute']);
        $this->keyed()->postJson($path.'/repair', ['confirm' => true])->assertForbidden();
        $this->assertDatabaseCount('storage_purchase_order_import_repairs', 0);
    }

    #[Test]
    public function protected_fixture_validation_activation_and_rollback_execute_through_api(): void
    {
        $import = $this->reviewedImport('api-fixture');
        $profile = $import->profile;
        $version = $import->profileVersion;
        $path = '/api/v1/storage/supplier-order-profiles/'.$profile->id;
        $this->keyed()->postJson($path.'/fixtures', [
            'fixture_name' => 'Reviewed HTTP fixture', 'profile_version_id' => $version->id, 'purchase_order_import_id' => $import->id,
        ])->assertCreated()->assertJsonPath('data.protected_replay_passed', true);
        $this->keyed()->postJson($path.'/versions/'.$version->id.'/test')->assertOk()->assertJsonPath('data.validation.valid', true);
        config(['storage_api.automation_enabled' => true]);
        $this->keyed()->postJson($path.'/versions/'.$version->id.'/activate', ['reason' => 'Reviewed protected fixture', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.active_version_id', $version->id);
        $this->keyed()->postJson($path.'/versions', ['definition' => SupplierOrderProfileFactoryData::itegra(), 'parent_version_id' => $version->id])->assertCreated();
        $second = $profile->versions()->latest('id')->firstOrFail();
        $this->keyed()->postJson($path.'/versions/'.$second->id.'/test')->assertOk()->assertJsonPath('data.validation.valid', true);
        $this->keyed()->postJson($path.'/versions/'.$second->id.'/activate', ['reason' => 'Activate reviewed second version', 'confirm' => true])->assertOk();
        $this->keyed()->postJson($path.'/versions/'.$version->id.'/rollback', ['reason' => 'Restore verified original version', 'confirm' => true])
            ->assertOk()->assertJsonPath('data.active_version_id', $version->id);
        $this->assertDatabaseCount('storage_movements', 0);
    }

    #[Test]
    public function manual_correction_and_distinct_item_creation_keep_audited_import_state(): void
    {
        $import = $this->reviewedImport('api-correction');
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id;
        $this->keyed()->postJson($path.'/manual-correction', ['correction' => $this->manualPayload($import)])
            ->assertOk()->assertJsonCount(1, 'data.repair_history');
        $this->assertDatabaseCount('storage_purchase_order_import_repairs', 1);
        $line = $import->fresh()->lines()->firstOrFail();
        $this->keyed()->postJson($path.'/lines/'.$line->id.'/create-item', ['mode' => 'create_review_item'])->assertOk();
        $line->refresh();
        $this->assertNotNull($line->item_id);
        $this->assertSame(0, Item::findOrFail($line->item_id)->qty_on_hand);
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function policy_changes_reuse_business_normalization_and_gate_automation_activation(): void
    {
        $payload = ['runtime_mode' => 'off', 'ai_mode' => 'off', 'default_warehouse_id' => $this->warehouse->id,
            'amount_tolerance' => 0.01, 'max_lines' => 250, 'max_quantity_per_line' => 10000, 'max_order_total' => 1000000,
            'max_new_items' => 0, 'supplier_bootstrap_mode' => 'existing_only', 'new_item_mode' => 'review_only',
            'silent_success' => true, 'daily_digest_enabled' => false];
        $this->keyed()->putJson('/api/v1/storage/automation-policy', $payload)->assertOk()->assertJsonPath('data.runtime_mode', 'off');
        $this->getJson('/api/v1/storage/automation-policy')->assertOk()->assertJsonCount(2, 'data.revisions');
        $payload['runtime_mode'] = 'auto_deterministic';
        $this->keyed()->putJson('/api/v1/storage/automation-policy', $payload + ['confirm' => true])->assertConflict();
        config(['storage_api.automation_enabled' => true]);
        $this->keyed()->putJson('/api/v1/storage/automation-policy', $payload)->assertUnprocessable();
        $this->keyed()->putJson('/api/v1/storage/automation-policy', $payload + ['confirm' => true])
            ->assertOk()->assertJsonPath('data.runtime_mode', 'auto_deterministic');
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function picking_http_fulfils_reservation_once_and_returns_typed_queue_data(): void
    {
        $defaults = app(\App\Modules\Ticket\Actions\EnsureTicketDefaults::class)->handle();
        $client = \App\Models\Clients\Client::create(['name' => 'HTTP picking client', 'active' => true]);
        $ticket = \App\Modules\Ticket\Models\Ticket::create([
            'ticket_key' => 'TD-2026-998811', 'client_id' => $client->id,
            'queue_id' => $defaults['queue']->id, 'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug, 'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id, 'owner_id' => $this->actor->id,
            'created_by' => $this->actor->id, 'updated_by' => $this->actor->id,
            'channel' => 'manual', 'subject' => 'HTTP picking', 'is_unread' => false,
        ]);
        $this->item->update(['qty_on_hand' => 5, 'qty_reserved' => 2]);
        $reservation = \App\Modules\Storage\Models\Reservation::create([
            'item_id' => $this->item->id, 'warehouse_id' => $this->warehouse->id, 'qty' => 2,
            'source_type' => 'ticket', 'source_id' => (string) $ticket->id, 'strength' => 'hard',
            'status' => 'active', 'created_by' => $this->actor->id,
        ]);
        $entry = \App\Modules\Ticket\Models\TicketCostEntry::create([
            'ticket_id' => $ticket->id, 'user_id' => $this->actor->id,
            'storage_item_id' => $this->item->id, 'storage_reservation_id' => $reservation->id,
            'quantity' => 2, 'item_name' => $this->item->name, 'item_sku' => $this->item->sku,
            'unit_price_ex_vat' => 100, 'currency' => 'NOK', 'status' => 'reserved',
            'billing_status' => 'pending', 'invoice_text' => 'HTTP picked item',
        ]);
        $this->getJson('/api/v1/storage/picking?status=ready')->assertOk()->assertJsonPath('data.0.id', $entry->id);
        $key = (string) Str::uuid();
        $path = '/api/v1/storage/picking/'.$entry->id.'/pick';
        $this->keyed($key)->postJson($path)->assertOk()->assertJsonPath('data.status', 'picked');
        $this->keyed($key)->postJson($path)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame(3, $this->item->fresh()->qty_on_hand);
        $this->assertSame(0, $this->item->fresh()->qty_reserved);
        $this->assertSame('fulfilled', $reservation->fresh()->status);
        $this->assertDatabaseHas('storage_movements', ['item_id' => $this->item->id, 'type' => 'ticket_pick', 'qty_delta' => -2]);
        $this->keyed()->postJson($path)->assertUnprocessable();
    }

    #[Test]
    public function unknown_provider_claims_are_never_executed_again_and_require_reconciliation(): void
    {
        $import = $this->reviewedImport('api-unknown');
        $key = (string) Str::uuid();
        config(['storage_api.ai_repair_enabled' => true]);
        $this->mock(\App\Modules\Storage\Queries\PurchaseOrderAutomationUiQuery::class)
            ->shouldReceive('forImport')->andReturn(['available' => true, 'reason' => 'Synthetic provider ready', 'workload' => null]);
        $this->mock(\App\Modules\Storage\Actions\RepairPurchaseOrderImportWithAi::class)
            ->shouldReceive('handle')->once()->andThrow(new \RuntimeException('SENSITIVE-PROVIDER-OUTPUT'));
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id.'/repair';
        $first = $this->keyed($key)->postJson($path, ['confirm' => true])->assertAccepted()
            ->assertJsonPath('state', 'unknown')->assertJsonPath('reconciliation_required', true);
        $this->assertStringNotContainsString('SENSITIVE-PROVIDER-OUTPUT', $first->getContent());
        $this->keyed($key)->postJson($path, ['confirm' => true])->assertAccepted()->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/storage/operations/'.$first->json('operation_id'))->assertOk()
            ->assertJsonPath('data.state', 'unknown')->assertJsonPath('data.reconciliation_required', true);
        $this->assertDatabaseCount('storage_purchase_order_import_repairs', 0);
    }

    #[Test]
    public function box_history_warehouse_room_and_carrier_reads_are_safe_and_useful(): void
    {
        $box = \App\Modules\Storage\Models\Box::create(['warehouse_id' => $this->warehouse->id, 'code_human' => 'HTTP-BOX', 'name' => 'HTTP box',
            'uuid' => (string) Str::uuid(), 'barcode_value' => 'HTTP-BOX', 'barcode_type' => 'QR', 'status' => 'in_stock', 'is_active' => true]);
        $this->item->update(['box_id' => $box->id]);
        $this->getJson('/api/v1/storage/boxes/'.$box->id)->assertOk()->assertJsonPath('data.items.0.id', $this->item->id);
        $this->getJson('/api/v1/storage/items/'.$this->item->id.'/movements?sort=SQL-INJECTION')->assertUnprocessable();
        $this->getJson('/api/v1/storage/rooms?warehouse_id='.$this->warehouse->id)->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/storage/carriers')->assertOk();
        $this->getJson('/api/v1/storage/warehouses')->assertOk();
    }

    #[Test]
    public function receiving_only_and_read_only_tokens_keep_their_supported_narrow_workspaces(): void
    {
        $order = app(StorePurchaseOrder::class)->handle([
            'vendor_id' => $this->supplier->id, 'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => 'ordered', 'ordered_at' => '2026-10-26', 'vendor_ref' => 'RECEIVER-ONLY',
            'currency' => 'NOK', 'lines' => [['item_id' => $this->item->id, 'qty_ordered' => 2, 'unit_cost' => 100]],
        ], $this->actor);
        $receiver = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $receiver->givePermissionTo('storage.purchase_receive');
        $this->bearer(['storage.purchase.receive'], $receiver);
        $this->getJson('/api/v1/storage/receiving')->assertOk()->assertJsonPath('data.0.purchase_order_id', $order->id);
        $this->getJson('/api/v1/storage/purchase-orders/'.$order->id.'/receiving-context')->assertOk()
            ->assertJsonPath('data.purchase_order.id', $order->id)->assertJsonPath('data.can_receive_overage', false);
        $this->getJson('/api/v1/storage/purchase-orders/'.$order->id)->assertForbidden();
        $this->bearer(['storage.picking.read']);
        $this->getJson('/api/v1/storage/picking')->assertOk();
        $this->keyed()->postJson('/api/v1/storage/picking/999/pick')->assertForbidden();
        $this->bearer(['storage.profiles.read']);
        $this->getJson('/api/v1/storage/supplier-order-profiles')->assertOk();
        $this->getJson('/api/v1/storage/supplier-order-profiles/context')->assertOk();
        $this->keyed()->postJson('/api/v1/storage/supplier-order-profiles', [])->assertForbidden();
    }

    #[Test]
    public function retained_import_results_recheck_read_authority_and_execution_only_results_are_minimal(): void
    {
        $import = $this->reviewedImport('api-result-authority');
        $line = $import->lines()->firstOrFail();
        $path = '/api/v1/storage/supplier-order-imports/'.$import->id.'/lines/'.$line->id.'/map';
        $key = (string) Str::uuid();
        $first = $this->keyed($key)->postJson($path, ['item_id' => $this->item->id])->assertOk()->assertJsonStructure(['data' => ['source']]);
        $this->actor->revokePermissionTo('storage.purchase_import_view');
        $this->keyed($key)->postJson($path, ['item_id' => $this->item->id])->assertForbidden();
        $this->getJson('/api/v1/storage/operations/'.$first->json('operation_id'))->assertForbidden();
        $this->bearer(['storage.imports.resolve']);
        $this->keyed()->postJson($path, ['item_id' => $this->item->id])->assertOk()
            ->assertJsonMissingPath('data.source')->assertJsonMissingPath('data.document');
    }

    #[Test]
    public function broad_token_cannot_approve_overage_without_the_current_user_permission(): void
    {
        $order = app(StorePurchaseOrder::class)->handle([
            'vendor_id' => $this->supplier->id, 'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => 'ordered', 'ordered_at' => '2026-10-26', 'vendor_ref' => 'USER-OVERAGE-GUARD',
            'currency' => 'NOK', 'lines' => [['item_id' => $this->item->id, 'qty_ordered' => 1, 'unit_cost' => 100]],
        ], $this->actor);
        $this->actor->revokePermissionTo('storage.purchase_receive_overage');
        $this->keyed()->postJson('/api/v1/storage/purchase-orders/'.$order->id.'/receipts', [
            'idempotency_token' => (string) Str::uuid(), 'lines' => [[
                'purchase_order_line_id' => $order->lines()->firstOrFail()->id, 'qty_accepted' => 2, 'qty_rejected' => 0,
                'over_receipt_reason' => 'Extra supplier unit arrived', 'units' => [],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.qty_accepted');
        $this->assertSame(0, $this->item->fresh()->qty_on_hand);
        $this->assertDatabaseCount('storage_purchase_receipts', 0);
    }

    #[Test]
    public function narrow_form_owners_get_context_without_inventory_or_settings_access(): void
    {
        $editor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $editor->givePermissionTo('storage.item_manage');
        $this->bearer(['storage.read', 'storage.suppliers.read', 'storage.update'], $editor);
        $this->getJson('/api/v1/storage/context')->assertOk()->assertJsonPath('data.warehouses.0.id', $this->warehouse->id);
        $this->getJson('/api/v1/storage/items/'.$this->item->id.'/edit-context')->assertOk()->assertJsonPath('data.item.id', $this->item->id);
        $this->getJson('/api/v1/storage/items')->assertForbidden();
        $this->getJson('/api/v1/storage/settings')->assertForbidden();
        $this->getJson('/api/v1/storage/suppliers')->assertOk();
        $this->getJson('/api/v1/storage/lookups/items?per_page=1')->assertOk()->assertJsonCount(1, 'data');
    }

    #[Test]
    public function unsupported_nested_fields_and_malformed_json_never_mutate_domain_state(): void
    {
        $before = $this->counts();
        $this->keyed()->postJson('/api/v1/storage/purchase-orders', [
            'vendor_id' => $this->supplier->id, 'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => 'draft', 'lines' => [['item_id' => $this->item->id, 'qty_ordered' => 1, 'force_receive' => true]],
        ])->assertUnprocessable()->assertJsonValidationErrors('lines.0.force_receive');
        $this->getJson('/api/v1/storage/items?warehouse_id[]=1')->assertUnprocessable();
        $this->getJson('/api/v1/storage/receiving?per_page=7')->assertUnprocessable();
        $this->keyed()->call('PUT', '/api/v1/storage/settings', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_IDEMPOTENCY_KEY' => (string) Str::uuid()], '{')
            ->assertUnprocessable();
        $this->assertSame($before, $this->counts());
    }

    #[Test]
    public function version_and_policy_previews_report_the_exact_target_without_writes(): void
    {
        $profile = $this->createProfile();
        $version = $profile->versions()->firstOrFail();
        $before = $this->counts();
        $this->postJson('/api/v1/storage/supplier-order-profiles/'.$profile->id.'/versions/'.$version->id.'/activate/preview')
            ->assertOk()->assertJsonPath('data.version_id', $version->id)
            ->assertJsonPath('data.version_checksum', $version->checksum)->assertJsonPath('data.requires_fresh_validation', true);
        $this->postJson('/api/v1/storage/automation-policy/preview', [
            'runtime_mode' => 'off', 'ai_mode' => 'off', 'default_warehouse_id' => $this->warehouse->id,
            'amount_tolerance' => 0.01, 'max_lines' => 250, 'max_quantity_per_line' => 10000, 'max_order_total' => 1000000,
            'max_new_items' => 0, 'supplier_bootstrap_mode' => 'existing_only', 'new_item_mode' => 'review_only',
        ])->assertOk()->assertJsonPath('data.proposed_policy.runtime_mode', 'off');
        $this->assertSame($before, $this->counts());
    }

    /** Compare successful HTTP payloads against the independently published Swagger contract. */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if ($response->status() < 200 || $response->status() >= 300 || $response->status() === 204) {
            return $response;
        }
        $route = Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        if (! str_starts_with($route->uri(), 'api/v1/storage')) {
            return $response;
        }
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)];
        $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
        if ($schema) {
            $errors = [];
            $this->checkSchema($response->json(), $schema, $document['components']['schemas'], '$', $errors);
            $this->assertSame([], $errors, $method.' '.$route->uri().' response contract');
        }

        return $response;
    }

    private function checkSchema(mixed $value, array $schema, array $schemas, string $path, array &$errors): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->checkSchema($value, $schemas[basename($schema['$ref'])], $schemas, $path, $errors);

            return;
        }
        foreach ($schema['allOf'] ?? [] as $part) {
            $this->checkSchema($value, $part, $schemas, $path, $errors);
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object', 'array' => is_array($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $valid) {
            $errors[] = $path.' expected '.$type.', got '.get_debug_type($value);

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' outside declared enum';
        }
        if ($type === 'object') {
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $value)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($value as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                }
            }
        } elseif ($type === 'array' && isset($schema['items'])) {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'], $schemas, $path.'.'.$index, $errors);
            }
        }
    }

    private function createProfile(string $slug = 'api-profile'): PurchaseOrderImportProfile
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $response = $this->keyed()->postJson('/api/v1/storage/supplier-order-profiles', [
            'vendor_id' => $this->supplier->id, 'name' => 'API profile', 'slug' => $slug,
            'description' => 'HTTP profile test', 'priority' => 10, 'matching_scope' => $definition['match'],
            'policy_overrides' => [], 'definition' => $definition,
        ])->assertCreated();

        return PurchaseOrderImportProfile::findOrFail($response->json('data.id'));
    }

    private function reviewedImport(string $key, bool $unsafe = false): PurchaseOrderImport
    {
        $profile = $this->createProfile('profile-'.$key);
        $version = $profile->versions()->firstOrFail();
        $policy = PurchaseOrderAutomationPolicy::where('is_current', true)->first();
        if (! $policy) {
            $policy = PurchaseOrderAutomationPolicy::create([
                'name' => 'API review policy', 'is_current' => true, 'runtime_mode' => 'review', 'default_outcome' => 'needs_attention',
                'automation_user_id' => $this->actor->id, 'default_warehouse_id' => $this->warehouse->id,
                'ai_mode' => 'off', 'provider_outage_behavior' => 'needs_attention', 'supplier_bootstrap_mode' => 'existing_only',
                'new_item_mode' => 'review_only', 'advanced_rules' => [], 'revision_number' => 1,
            ])->fresh();
            $snapshot = $policy->revisionSnapshot();
            $policy->revisions()->create(['revision_number' => 1, 'snapshot' => $snapshot, 'checksum' => StableJson::checksum($snapshot),
                'reason' => 'API synthetic review tests', 'created_by' => $this->actor->id, 'activated_at' => now()]);
        }
        $revision = $policy->revisions()->firstOrFail();
        $source = $this->sourceSnapshot();
        if ($unsafe) {
            $source['headers'] = ['Authorization' => 'SECRET-SOURCE-HEADER'];
            $source['raw_path'] = '/private/source.eml';
            $source['body_html'] = '<script>alert(1)</script><p onclick="steal()">Order</p>';
        }

        $source['body_text'] = str_replace('9900000001', '990'.str_pad((string) $profile->id, 7, '0', STR_PAD_LEFT), $source['body_text']);
        $import = PurchaseOrderImport::create([
            'source_domain' => 'email', 'source_type' => 'supplier-order-test', 'source_id' => $key,
            'source_action_hash' => hash('sha256', 'action:'.$key), 'source_fingerprint' => StableJson::checksum($source),
            'safe_source_snapshot' => $source, 'trusted_auth_snapshot' => $source['trusted_auth'],
            'vendor_id' => $this->supplier->id, 'profile_id' => $profile->id, 'profile_version_id' => $version->id,
            'policy_revision_id' => $revision->id, 'status' => 'needs_attention', 'stage' => 'validate',
            'requested_by' => $this->actor->id, 'attempt_count' => 0,
        ]);
        $effective = app(ResolveEffectivePurchaseOrderAutomationPolicy::class)->handle($import, $policy, $profile, $version);
        $extraction = app(SupplierOrderDeterministicExtractor::class)->extract($version, $source);
        $this->assertTrue($extraction->valid(), StableJson::encode($extraction->errors));
        $document = $extraction->document;
        $document['destination_warehouse_id'] = $this->warehouse->id;
        $validation = app(SupplierOrderCanonicalValidator::class)->validate($document, $effective);
        $this->assertTrue($validation->valid(), StableJson::encode($validation->errors));
        $import->forceFill(['external_order_number' => $document['external_order_number'], 'normalized_document' => $document,
            'validation_results' => $validation->toArray(), 'extraction_method' => 'deterministic', 'reason_code' => 'review_required'])->save();
        app(SyncPurchaseOrderImportLines::class)->handle($import, $document);

        return $import->fresh(['lines', 'profile', 'profileVersion', 'policyRevision']);
    }

    private function counts(): array
    {
        return collect(['storage_items', 'vendors', 'storage_warehouses', 'storage_movements', 'storage_purchase_orders',
            'storage_purchase_order_imports', 'storage_purchase_order_automation_policies',
            'storage_purchase_order_automation_policy_revisions', 'common_settings', 'storage_api_commands'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    private function sourceSnapshot(): array
    {
        return [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => 'email',
            'mailbox' => 'orders@nexum.test',
            'subject' => 'Takk for din ordre',
            'from' => ['name' => 'Itegra', 'email' => 'synthetic-fixture@itegra.no'],
            'to' => [['name' => 'Purchasing', 'email' => 'purchasing@example.invalid']],
            'cc' => [],
            'received_at' => '2026-08-05T09:30:00+02:00',
            'body_html' => '',
            'body_text' => <<<'TEXT'
Hei!

Takk for din ordre.

Ordresammendrag:
Ordrenr.: 9900000001 (Se ordrestatus)
Bestiller: Nexum Testbed
Betaling: Kort
Best. Ref:
PO. Ref:
Levering: Stykkgods NO

Nexum synthetic profile fixture
Varenr: (NX-SYN-1001)
1
100,00

Total varer
Frakt
Verdikode
Totalt eks. MVA:
100,00
25,00
0,00
125,00
TEXT,
            'attachments' => [],
            'trusted_auth' => [
                'authentication_passed' => true,
                'authenticated_supplier_identity' => 'itegra.no',
                'authenticated_supplier_domain' => 'itegra.no',
                'authserv_id' => 'mail.test',
                'spf' => 'pass',
                'dkim' => 'pass',
                'dmarc' => 'pass',
                'aligned' => true,
            ],
        ];
    }

    private function manualPayload(PurchaseOrderImport $import): array
    {
        $document = $import->normalized_document;

        return [
            'supplier_name' => data_get($document, 'supplier.name'),
            'external_order_number' => $document['external_order_number'],
            'ordered_at' => substr((string) $document['ordered_at'], 0, 10),
            'currency' => $document['currency'],
            'destination_warehouse_id' => $this->warehouse->id,
            'lines' => collect($document['lines'])->map(fn (array $line): array => [
                'supplier_sku' => $line['supplier_sku'] ?? null,
                'description' => $line['description'] ?? null,
                'quantity' => $line['quantity'],
                'unit_price' => $line['unit_price'] ?? null,
                'line_total' => $line['line_total'],
                'tax_rate' => $line['tax_rate'] ?? null,
            ])->values()->all(),
            'totals' => [
                'freight' => data_get($document, 'totals.freight', 0),
                'discount' => data_get($document, 'totals.discount', 0),
                'other_charges' => data_get($document, 'totals.other_charges', 0),
                'total_ex_tax' => data_get($document, 'totals.total_ex_tax'),
            ],
            'audit_reason' => 'Confirmed the structured facts against the sanitized source.',
        ];
    }
}
