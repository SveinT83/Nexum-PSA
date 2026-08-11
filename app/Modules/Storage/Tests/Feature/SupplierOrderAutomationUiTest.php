<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Storage\Controllers\Admin\PurchaseOrderAutomationController;
use App\Modules\Storage\Controllers\Admin\PurchaseOrderImportProfileController;
use App\Modules\Storage\Controllers\Tech\PurchaseOrderImportController;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class SupplierOrderAutomationUiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function supplier_import_and_admin_routes_use_storage_module_controllers(): void
    {
        $this->assertSame(
            PurchaseOrderImportController::class.'@index',
            Route::getRoutes()->getByName('tech.storage.purchase-order-imports.index')->getActionName(),
        );
        $this->assertSame(
            PurchaseOrderImportController::class.'@mapLine',
            Route::getRoutes()->getByName('tech.storage.purchase-order-imports.lines.map')->getActionName(),
        );
        $this->assertSame(
            PurchaseOrderImportController::class.'@repair',
            Route::getRoutes()->getByName('tech.storage.purchase-order-imports.repair')->getActionName(),
        );
        $this->assertSame(
            PurchaseOrderAutomationController::class.'@edit',
            Route::getRoutes()->getByName('tech.admin.settings.storage.purchase-order-automation.edit')->getActionName(),
        );
        $this->assertSame(
            PurchaseOrderImportProfileController::class.'@index',
            Route::getRoutes()->getByName('tech.admin.settings.storage.supplier-order-profiles.index')->getActionName(),
        );
        $this->assertSame(
            PurchaseOrderImportProfileController::class.'@importForm',
            Route::getRoutes()->getByName('tech.admin.settings.storage.supplier-order-profiles.import')->getActionName(),
        );
    }

    #[Test]
    public function permission_catalog_and_default_roles_keep_import_governance_separate(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);

        $admin = Role::findByName('Admin');
        $storage = Role::findByName('Storage');
        $tech = Role::findByName('Tech');
        $viewer = Role::findByName('Viewer');

        foreach ([
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_execute',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_policy_manage',
        ] as $permission) {
            $this->assertTrue($admin->hasPermissionTo($permission), $permission);
        }

        foreach ([
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_execute',
        ] as $permission) {
            $this->assertTrue($storage->hasPermissionTo($permission), $permission);
        }

        $this->assertFalse($storage->hasPermissionTo('storage.purchase_import_profile_manage'));
        $this->assertFalse($storage->hasPermissionTo('storage.purchase_import_policy_manage'));

        foreach ([
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_execute',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_policy_manage',
        ] as $permission) {
            $this->assertFalse($tech->hasPermissionTo($permission), 'Tech: '.$permission);
            $this->assertFalse($viewer->hasPermissionTo($permission), 'Viewer: '.$permission);
        }
    }

    #[Test]
    public function supplier_import_routes_fail_closed_when_the_permission_catalog_row_is_missing(): void
    {
        $superuser = Role::findOrCreate('Superuser', 'web');
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole($superuser);

        Permission::query()
            ->where('name', 'storage.purchase_import_view')
            ->where('guard_name', 'web')
            ->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertDatabaseMissing('permissions', [
            'name' => 'storage.purchase_import_view',
            'guard_name' => 'web',
        ]);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-order-imports.index'))
            ->assertForbidden();
    }

    #[Test]
    public function queue_supports_bounded_search_filters_and_permission_gated_mutations(): void
    {
        Queue::fake();
        $alpha = $this->import('alpha-source', [
            'external_order_number' => 'ORDER-ALPHA',
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'reason_code' => 'item_unresolved',
        ]);
        $beta = $this->import('beta-source', [
            'external_order_number' => 'ORDER-BETA',
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
        ]);
        $line = PurchaseOrderImportLine::query()->create([
            'import_id' => $alpha->id,
            'position' => 1,
            'supplier_sku' => 'SUP-100',
            'description' => 'Supplier item',
            'quantity' => 2,
            'unit_price' => 10,
            'line_total' => 20,
            'currency' => 'NOK',
            'mapping_status' => PurchaseOrderImportLine::MAPPING_UNRESOLVED,
        ]);

        $user = $this->userWithPermissions(['storage.purchase_import_view']);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-order-imports.index', [
                'q' => 'ORDER-ALPHA',
                'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                'sort' => 'order',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertSee('ORDER-ALPHA')
            ->assertDontSee('ORDER-BETA')
            ->assertSee('Supplier Orders')
            ->assertDontSee('>Purchase Orders<', false);

        $this->actingAs($user)
            ->post(route('tech.storage.purchase-order-imports.retry', $alpha))
            ->assertForbidden();

        $user->givePermissionTo('storage.purchase_import_resolve');

        $this->actingAs($user)
            ->from(route('tech.storage.purchase-order-imports.show', $alpha))
            ->post(route('tech.storage.purchase-order-imports.lines.map', [$alpha, $line]), [])
            ->assertRedirect()
            ->assertSessionHasErrors('item_id');

        $user->givePermissionTo('storage.purchase_import_execute');

        $this->actingAs($user)
            ->post(route('tech.storage.purchase-order-imports.retry', $alpha))
            ->assertRedirect();

        $this->assertSame(PurchaseOrderImport::STATUS_PENDING, $alpha->fresh()->status);

        $this->actingAs($user)
            ->post(route('tech.storage.purchase-order-imports.repair', $alpha))
            ->assertForbidden();

        $this->assertSame(PurchaseOrderImport::STATUS_IMPORTED, $beta->fresh()->status);
    }

    #[Test]
    public function import_detail_renders_only_safe_source_projection_and_hides_ai_repair_without_full_authority(): void
    {
        $import = $this->import('safe-detail', [
            'safe_source_snapshot' => [
                'schema_version' => 'storage.supplier_order_source.v1',
                'source' => 'email',
                'subject' => 'Safe supplier confirmation',
                'from' => ['name' => 'Supplier', 'email' => 'orders@example.no'],
                'received_at' => '2026-08-05T10:00:00+02:00',
                'body_html' => '<p>Sanitized supplier body</p>',
                'body_text' => 'Sanitized supplier body',
                'attachments' => [],
                'headers' => ['x-secret' => 'TOP-SECRET-RAW-HEADER'],
                'raw' => 'TOP-SECRET-RAW-BODY',
            ],
            'trusted_auth_snapshot' => [
                'authentication_passed' => true,
                'aligned' => true,
                'spf' => 'pass',
                'dkim' => 'pass',
                'dmarc' => 'pass',
                'authenticated_supplier_domain' => 'example.no',
            ],
            'external_order_number' => 'EXT-DETAIL-2004',
            'extraction_method' => 'deterministic',
            'commercial_snapshot' => [
                'goods_subtotal' => '100.00',
                'freight' => '25.00',
                'discount' => '10.00',
                'other_charges' => '5.00',
                'tax_total' => '24.00',
                'total_ex_tax' => '120.00',
                'total_inc_tax' => '144.00',
            ],
            'delivery_snapshot' => [
                'method' => 'Stykkgods NO',
                'address' => [
                    'street' => 'Fictional Avenue 1',
                    'postal_code' => '0001',
                    'city' => 'TESTBY',
                ],
                'expected_at' => '2026-08-12',
            ],
            'effective_policy_snapshot' => [
                'schema_version' => 'storage.effective_purchase_order_policy.v1',
                'test_marker' => 'IMPORT-EFFECTIVE-POLICY',
                'policy' => ['runtime_mode' => 'review', 'max_lines' => 37],
            ],
            'effective_policy_checksum' => 'IMPORT-EFFECTIVE-CHECKSUM',
            'normalized_document' => [
                'external_order_number' => 'EXT-DETAIL-2004',
                'ordered_at' => '2026-08-05',
                'currency' => 'NOK',
                'totals' => ['total_ex_tax' => '120.00'],
                'delivery' => ['method' => 'Stykkgods NO'],
            ],
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
        ]);

        $user = $this->userWithPermissions([
            'storage.purchase_import_view',
            'storage.purchase_import_execute',
        ]);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-order-imports.show', $import))
            ->assertOk()
            ->assertSee('Safe supplier confirmation')
            ->assertSee('Sanitized supplier body')
            ->assertSee('Extracted Source Facts')
            ->assertSee('EXT-DETAIL-2004')
            ->assertSee('100.00 NOK')
            ->assertSee('25.00 NOK')
            ->assertSee('10.00 NOK')
            ->assertSee('5.00 NOK')
            ->assertSee('Stykkgods NO')
            ->assertSee('Fictional Avenue 1')
            ->assertSee('2026-08-12')
            ->assertSee('24.00 NOK')
            ->assertSee('120.00 NOK')
            ->assertSee('144.00 NOK')
            ->assertSee('IMPORT-EFFECTIVE-POLICY')
            ->assertSee('IMPORT-EFFECTIVE-CHECKSUM')
            ->assertDontSee('Trusted Authentication')
            ->assertDontSee('SPF')
            ->assertDontSee('DKIM')
            ->assertDontSee('DMARC')
            ->assertDontSee('Authentication passed')
            ->assertDontSee('Identity aligned')
            ->assertDontSee('TOP-SECRET-RAW-HEADER')
            ->assertDontSee('TOP-SECRET-RAW-BODY')
            ->assertDontSee('Repair with AI')
            ->assertDontSee('Open Original in Inbox');
    }

    #[Test]
    public function purchase_order_detail_is_canonical_and_adds_only_a_permissioned_email_copy(): void
    {
        $vendor = Vendor::query()->create([
            'name' => 'Source Supplier',
            'vendor_code' => 'SOURCE-SUPPLIER',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Source Warehouse',
            'code' => 'SOURCE-WH',
            'is_active' => true,
        ]);
        $purchaseOrder = PurchaseOrder::query()->create([
            'po_number' => 'PO-SOURCE-UI-1',
            'vendor_id' => $vendor->id,
            'deliver_to_warehouse_id' => $warehouse->id,
            'supplier_name_snapshot' => 'Source Supplier',
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => today(),
            'currency' => 'NOK',
        ]);
        $manualOrder = PurchaseOrder::query()->create([
            'po_number' => 'PO-MANUAL-UI-1',
            'vendor_id' => $vendor->id,
            'deliver_to_warehouse_id' => $warehouse->id,
            'supplier_name_snapshot' => 'Source Supplier',
            'status' => PurchaseOrder::STATUS_ORDERED,
            'ordered_at' => today(),
            'currency' => 'NOK',
        ]);
        $this->import('po-source', [
            'purchase_order_id' => $purchaseOrder->id,
            'external_order_number' => 'EXT-SOURCE-1',
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'extraction_method' => 'deterministic',
            'commercial_snapshot' => [
                'goods_subtotal' => '100.00',
                'freight' => '49.00',
                'discount' => '10.00',
                'other_charges' => '2.00',
                'tax_total' => '28.20',
                'total_ex_tax' => '141.00',
                'total_inc_tax' => '169.20',
            ],
            'delivery_snapshot' => [
                'method' => 'DHL Express',
                'address' => 'Source Warehouse, Oslo',
                'expected_at' => '2026-08-14',
            ],
            'normalized_document' => [
                'external_order_number' => 'EXT-SOURCE-1',
                'ordered_at' => '2026-08-05',
                'currency' => 'NOK',
            ],
            'effective_policy_snapshot' => [
                'schema_version' => 'storage.effective_purchase_order_policy.v1',
                'test_marker' => 'PO-EFFECTIVE-POLICY',
                'policy' => ['runtime_mode' => 'auto_deterministic', 'max_lines' => 21],
            ],
            'effective_policy_checksum' => 'PO-EFFECTIVE-CHECKSUM',
            'safe_source_snapshot' => [
                'source' => 'email',
                'subject' => 'Immutable PO confirmation',
                'from' => ['email' => 'orders@example.no'],
                'to' => [['name' => 'Purchasing', 'email' => 'purchasing@example.no']],
                'cc' => [['email' => 'warehouse@example.no']],
                'received_at' => '2026-08-05T10:00:00+02:00',
                'body_html' => '<p>Immutable sanitized PO source</p>',
                'body_text' => '',
                'attachments' => [],
                'headers' => ['x-secret' => 'PO-RAW-HEADER'],
            ],
        ]);

        $user = $this->userWithPermissions(['storage.purchase_view']);

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-orders.show', $purchaseOrder))
            ->assertOk()
            ->assertSee('Order Details')
            ->assertSee('Order Lines')
            ->assertSee('Shipments')
            ->assertSee('Receipt History')
            ->assertDontSee('Email Copy')
            ->assertDontSee('Immutable sanitized PO source');

        $user->givePermissionTo('storage.purchase_import_view');

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-orders.show', $manualOrder))
            ->assertOk()
            ->assertSee('Order Details')
            ->assertSee('Order Lines')
            ->assertSee('Shipments')
            ->assertSee('Receipt History')
            ->assertDontSee('Email Copy');

        $this->actingAs($user)
            ->get(route('tech.storage.purchase-orders.show', $purchaseOrder))
            ->assertOk()
            ->assertSee('Order Details')
            ->assertSee('Order Lines')
            ->assertSee('Shipments')
            ->assertSee('Receipt History')
            ->assertSee('Email Copy')
            ->assertSeeInOrder(['Order Details', 'Order Lines', 'Shipments', 'Receipt History', 'Email Copy'])
            ->assertSee('Received by email')
            ->assertSee('Immutable PO confirmation')
            ->assertSee('orders@example.no')
            ->assertSee('Purchasing purchasing@example.no')
            ->assertSee('warehouse@example.no')
            ->assertSee('Immutable sanitized PO source')
            ->assertDontSee('Supplier Order Source')
            ->assertDontSee('Open Import #')
            ->assertDontSee('Extracted Source Facts')
            ->assertDontSee('EXT-SOURCE-1')
            ->assertDontSee('DHL Express')
            ->assertDontSee('PO-EFFECTIVE-POLICY')
            ->assertDontSee('PO-EFFECTIVE-CHECKSUM')
            ->assertDontSee('Trusted Authentication')
            ->assertDontSee('SPF')
            ->assertDontSee('DKIM')
            ->assertDontSee('DMARC')
            ->assertDontSee('Repair with AI')
            ->assertDontSee('PO-RAW-HEADER')
            ->assertDontSee('Open Original in Inbox');
    }

    #[Test]
    public function admin_policy_and_profile_navigation_uses_dedicated_permissions_and_shows_simple_controls(): void
    {
        $admin = $this->userWithPermissions([
            'storage.purchase_import_policy_manage',
        ], admin: true);

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertOk()
            ->assertSee('Supplier Order Automation')
            ->assertSee('Order handling')
            ->assertSee('Prepare for review (recommended)')
            ->assertSee('AI assistance is not ready yet')
            ->assertDontSee('name="default_outcome"', false)
            ->assertDontSee('name="ai_profile_learning_mode"', false)
            ->assertDontSee('name="ai_profile_shadow_samples"', false)
            ->assertDontSee('name="retry_limit"', false)
            ->assertDontSee('name="circuit_breaker_failures"', false)
            ->assertDontSee('name="advanced_rules"', false)
            ->assertDontSee('name="automation_user_id"', false)
            ->assertDontSee('name="ai_workload_profile_id"', false)
            ->assertDontSee('href="'.route('tech.admin.settings.storage.supplier-order-profiles.index').'"', false);

        $provider = AiProvider::query()->create([
            'name' => 'UI provider',
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-4.1-mini',
            'status' => 'active',
            'is_healthy' => true,
        ]);
        $agent = AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Storage UI Agent',
            'slug' => 'storage-ui-agent-'.Str::lower(Str::random(6)),
            'model' => 'gpt-4.1-mini',
            'instructions' => 'Test Storage agent.',
            'data_sources' => ['example'],
            'allowed_tools' => ['example'],
            'allowed_api_scopes' => ['example.write'],
            'can_execute_actions' => true,
            'is_default' => false,
            'default_domains' => ['storage'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertOk()
            ->assertSee('AI assistance')
            ->assertSee('Storage UI Agent')
            ->assertSee('name="ai_agent_id"', false)
            ->assertSee('The agent cannot use its tools or write from this workflow')
            ->assertDontSee('Advanced settings')
            ->assertDontSee('name="provider_outage_behavior"', false)
            ->assertDontSee('name="deterministic_confidence_threshold"', false)
            ->assertDontSee('name="ai_confidence_threshold"', false)
            ->assertDontSee('name="ai_timeout_seconds"', false)
            ->assertDontSee('name="ai_max_output_tokens"', false)
            ->assertDontSee('name="ai_max_cost_per_import"', false)
            ->assertDontSee('name="ai_consensus_mode"', false)
            ->assertDontSee('name="ai_consensus_workload_profile_id"', false)
            ->assertDontSee('AI assistance is not ready yet')
            ->assertDontSee('Automation user')
            ->assertDontSee('Internal workload')
            ->assertDontSee('Consensus workload');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.index'))
            ->assertForbidden();

        $admin->givePermissionTo('storage.purchase_import_profile_manage');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.index'))
            ->assertOk()
            ->assertSee('Supplier Order Profiles')
            ->assertSee('Import JSON')
            ->assertSee('New Profile');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.create'))
            ->assertOk()
            ->assertSee('ordered_date_fallback')
            ->assertSee('default_warranty_months')
            ->assertViewHas(
                'definition',
                fn (array $definition): bool => app(SupplierOrderProfileDefinitionValidator::class)
                    ->validate($definition)
                    ->valid()
                    && data_get($definition, 'defaults.ordered_date_fallback') === 'received_at'
                    && array_key_exists('item_defaults', $definition),
            );

        $profile = PurchaseOrderImportProfile::query()->create([
            'name' => 'Manual UI Profile',
            'slug' => 'manual-ui-profile',
            'description' => 'Profile lifecycle UI test',
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_DRAFT,
            'priority' => 100,
            'matching_scope' => [],
            'policy_overrides' => [],
            'health_state' => 'unknown',
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.show', $profile))
            ->assertOk()
            ->assertSee('Manual UI Profile')
            ->assertSee('No protected fixtures are available');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.versions.create', $profile))
            ->assertOk()
            ->assertSee('New Version for Manual UI Profile');

        $this->actingAs($admin)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.import'))
            ->assertOk()
            ->assertSee('Import as Draft');
    }

    private function import(string $sourceId, array $overrides = []): PurchaseOrderImport
    {
        return PurchaseOrderImport::query()->create(array_replace([
            'source_domain' => 'email',
            'source_type' => 'email_message',
            'source_id' => $sourceId,
            'source_action_hash' => hash('sha256', 'action:'.$sourceId),
            'source_fingerprint' => hash('sha256', 'source:'.$sourceId),
            'safe_source_snapshot' => [
                'source' => 'email',
                'subject' => 'Supplier order '.$sourceId,
                'from' => ['email' => 'orders@example.no'],
                'body_html' => '',
                'body_text' => 'Safe source',
                'attachments' => [],
            ],
            'trusted_auth_snapshot' => [
                'authentication_passed' => true,
                'aligned' => true,
                'spf' => 'pass',
                'dkim' => 'pass',
                'dmarc' => 'pass',
            ],
            'status' => PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
            'attempt_count' => 0,
        ], $overrides));
    }

    private function userWithPermissions(array $permissions, bool $admin = false): User
    {
        foreach (array_unique([
            ...$permissions,
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_execute',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_policy_manage',
            'storage.purchase_view',
        ]) as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        if ($admin) {
            $adminRole = Role::findOrCreate('Admin', 'web');
            $adminRole->givePermissionTo($permissions);
            $user->assignRole('Admin');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }
}
