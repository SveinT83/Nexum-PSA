<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\CreateItemForPurchaseOrderImportLine;
use App\Modules\Storage\Actions\CreateProtectedSupplierOrderProfileFixtureFromImport;
use App\Modules\Storage\Actions\ManuallyFinalizePurchaseOrderImport;
use App\Modules\Storage\Actions\MapSupplierOrderImportLine;
use App\Modules\Storage\Actions\RejectPurchaseOrderImport;
use App\Modules\Storage\Actions\ResolveEffectivePurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\SyncPurchaseOrderImportLines;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicyRevision;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileFixture;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderDeterministicExtractor;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierOrderManualCorrectionAndFixtureTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Vendor $supplier;

    private Warehouse $warehouse;

    private PurchaseOrderAutomationPolicy $policy;

    private PurchaseOrderAutomationPolicyRevision $revision;

    private PurchaseOrderImportProfile $profile;

    private PurchaseOrderImportProfileVersion $version;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_execute',
            'storage.purchase_manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo([
            'storage.purchase_import_view',
            'storage.purchase_import_resolve',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_execute',
            'storage.purchase_manage',
        ]);
        $this->actor->assignRole(Role::findOrCreate('Admin', 'web'));

        $this->warehouse = Warehouse::query()->create([
            'name' => 'Reviewed Imports Warehouse',
            'code' => 'REVIEWED-IMPORTS',
            'is_active' => true,
        ]);
        $this->supplier = Vendor::query()->create([
            'name' => 'Itegra',
            'vendor_code' => 'ITEGRA-REVIEWED',
            'is_supplier' => true,
            'is_active' => true,
        ]);

        [$this->profile, $this->version] = $this->activeProfile('reviewed-itegra');
        [$this->policy, $this->revision] = $this->policy();
    }

    #[Test]
    public function manual_correction_records_immutable_audit_and_changes_only_the_import_proposal(): void
    {
        $import = $this->reviewedImport('manual-happy');
        $sourceBefore = $import->safe_source_snapshot;
        $sourceFingerprintBefore = $import->source_fingerprint;
        $countsBefore = $this->domainCounts();
        $payload = $this->manualPayload($import);
        $payload['lines'][0]['quantity'] = 2;
        $payload['lines'][0]['unit_price'] = '100';
        $payload['lines'][0]['line_total'] = '200';
        $payload['totals']['freight'] = '25';
        $payload['totals']['total_ex_tax'] = '225';
        $payload['audit_reason'] = 'Confirmed quantity and totals against the supplier confirmation.';

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-order-imports.show', $import))
            ->assertOk()
            ->assertSee('Correct Manually')
            ->assertSee('name="correction[lines][0][quantity]"', false)
            ->assertSee('name="correction[audit_reason]"', false)
            ->assertDontSee('name="corrected_document"', false);

        $this->actingAs($this->actor)
            ->from(route('tech.storage.purchase-order-imports.show', $import))
            ->post(route('tech.storage.purchase-order-imports.correct-manually', $import), [
                'correction' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $import->fresh(['lines', 'repairs']);
        $repair = $fresh->repairs->sole();

        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $fresh->status);
        $this->assertSame(PurchaseOrderImport::STAGE_VALIDATE, $fresh->stage);
        $this->assertSame('manual_review', $fresh->extraction_method);
        $this->assertSame('manual_repair_ready_for_reprocess', $fresh->reason_code);
        $this->assertSame($sourceBefore, $fresh->safe_source_snapshot);
        $this->assertSame($sourceFingerprintBefore, $fresh->source_fingerprint);
        $this->assertNull($fresh->purchase_order_id);
        $this->assertSame('2.0000', $fresh->lines->sole()->quantity);
        $this->assertSame('200.0000', $fresh->lines->sole()->line_total);

        $this->assertNull($repair->ai_execution_uuid);
        $this->assertSame(PurchaseOrderImportRepair::STATUS_READY_FOR_REPROCESS, $repair->status);
        $this->assertSame('manual', data_get($repair->decision_summary, 'method'));
        $this->assertSame($payload['audit_reason'], data_get($repair->decision_summary, 'reason'));
        $this->assertSame($sourceFingerprintBefore, data_get($repair->decision_summary, 'source_fingerprint'));
        $this->assertSame(
            '9900000001',
            data_get($repair->decision_summary, 'before_document.external_order_number'),
        );
        $this->assertSame(
            $sourceFingerprintBefore,
            data_get($repair->corrected_document, 'manual_review.source_fingerprint'),
        );
        $this->assertSame(
            $sourceFingerprintBefore,
            data_get($repair->corrected_document, 'lines.0.evidence.quantity.source_fingerprint'),
        );
        $this->assertSame(
            StableJson::checksum($repair->corrected_document),
            $repair->corrected_document_checksum,
        );
        $this->assertSame($countsBefore, $this->domainCounts());

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-order-imports.show', $fresh))
            ->assertOk()
            ->assertSee('Repair Audit History')
            ->assertSee($payload['audit_reason']);
    }

    #[Test]
    public function manual_correction_can_add_remove_and_reindex_lines(): void
    {
        $import = $this->reviewedImport('manual-line-structure');
        $sourceBefore = $import->safe_source_snapshot;
        $countsBefore = $this->domainCounts();
        $payload = $this->manualPayload($import);
        $payload['lines'] = [
            [
                'supplier_sku' => 'ADDED-001',
                'description' => 'First manually added line',
                'quantity' => 1,
                'unit_price' => '100',
                'line_total' => '100',
                'tax_rate' => null,
            ],
            [
                'supplier_sku' => 'ADDED-002',
                'description' => 'Second manually added line',
                'quantity' => 2,
                'unit_price' => '50',
                'line_total' => '100',
                'tax_rate' => null,
            ],
        ];
        $payload['totals'] = [
            'freight' => '0',
            'discount' => '0',
            'other_charges' => '0',
            'total_ex_tax' => '200',
        ];
        $payload['audit_reason'] = 'Removed the parsed line and added the two confirmed supplier lines.';

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-order-imports.show', $import))
            ->assertOk()
            ->assertSee('id="add-manual-correction-line"', false)
            ->assertSee('id="manual-correction-line-template"', false)
            ->assertSee('data-remove-manual-line', false)
            ->assertSee('const reindexLines', false)
            ->assertSee('Remove line 1');

        $this->actingAs($this->actor)
            ->from(route('tech.storage.purchase-order-imports.show', $import))
            ->post(route('tech.storage.purchase-order-imports.correct-manually', $import), [
                'correction' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $import->fresh(['lines', 'repairs']);

        $this->assertSame(['ADDED-001', 'ADDED-002'], $fresh->lines->pluck('supplier_sku')->all());
        $this->assertSame([1, 2], $fresh->lines->pluck('position')->all());
        $this->assertNotContains('NX-SYN-1001', $fresh->lines->pluck('supplier_sku')->all());
        $this->assertSame(['ADDED-001', 'ADDED-002'], collect($fresh->normalized_document['lines'])->pluck('supplier_sku')->all());
        $this->assertSame($sourceBefore, $fresh->safe_source_snapshot);
        $this->assertCount(2, $fresh->repairs->sole()->corrected_document['lines']);
        $this->assertSame($countsBefore, $this->domainCounts());
    }

    #[Test]
    public function manual_correction_rejects_bounded_request_and_pinned_arithmetic_failures_without_mutation(): void
    {
        $import = $this->reviewedImport('manual-invalid');
        $originalChecksum = StableJson::checksum($import->normalized_document);
        $countsBefore = $this->domainCounts();
        $payload = $this->manualPayload($import);
        $payload['lines'][0]['quantity'] = '1.5';

        $this->actingAs($this->actor)
            ->from(route('tech.storage.purchase-order-imports.show', $import))
            ->post(route('tech.storage.purchase-order-imports.correct-manually', $import), [
                'correction' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('correction.lines.0.quantity');

        $payload = $this->manualPayload($import);
        $payload['lines'][0]['quantity'] = 2;
        $payload['lines'][0]['unit_price'] = '100';
        $payload['lines'][0]['line_total'] = '201';
        $payload['totals']['freight'] = '0';
        $payload['totals']['total_ex_tax'] = '201';

        $this->actingAs($this->actor)
            ->from(route('tech.storage.purchase-order-imports.show', $import))
            ->post(route('tech.storage.purchase-order-imports.correct-manually', $import), [
                'correction' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('correction.document');

        $fresh = $import->fresh();
        $this->assertSame($originalChecksum, StableJson::checksum($fresh->normalized_document));
        $this->assertSame(0, $fresh->repairs()->count());
        $this->assertSame($countsBefore, $this->domainCounts());
    }

    #[Test]
    public function manual_correction_requires_resolution_permission_and_locks_terminal_imports(): void
    {
        $import = $this->reviewedImport('manual-locks');
        $payload = $this->manualPayload($import);
        $viewer = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $viewer->givePermissionTo('storage.purchase_import_view');

        $this->actingAs($viewer)
            ->post(route('tech.storage.purchase-order-imports.correct-manually', $import), [
                'correction' => $payload,
            ])
            ->assertForbidden();

        $import->forceFill(['status' => PurchaseOrderImport::STATUS_IMPORTED])->save();

        $this->actingAs($this->actor)
            ->from(route('tech.storage.purchase-order-imports.show', $import))
            ->post(route('tech.storage.purchase-order-imports.correct-manually', $import), [
                'correction' => $payload,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('correction');

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-order-imports.show', $import))
            ->assertOk()
            ->assertDontSee('Correct Manually');

        $this->assertDatabaseCount('storage_purchase_order_import_repairs', 0);
    }

    #[Test]
    public function processing_import_rejects_every_manual_mutation_inside_the_import_lock(): void
    {
        $import = $this->reviewedImport('processing-manual-mutations');
        $line = $import->lines->sole();
        $item = Item::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'primary_vendor_id' => $this->supplier->id,
            'sku' => 'PROCESSING-GUARD',
            'name' => 'Processing Guard Item',
            'qty_on_hand' => 0,
            'qty_reserved' => 0,
            'reorder_point' => 0,
            'target_level' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);
        $import->forceFill([
            'status' => PurchaseOrderImport::STATUS_PROCESSING,
            'locked_at' => now(),
        ])->save();
        $countsBefore = $this->domainCounts();

        $this->assertValidationError(
            fn () => app(MapSupplierOrderImportLine::class)->handle($line, $item, $this->actor),
            'item_id',
        );
        $this->assertValidationError(
            fn () => app(CreateItemForPurchaseOrderImportLine::class)->handle(
                $line,
                $this->actor,
                'create_active_item',
            ),
            'item',
        );
        $this->assertValidationError(
            fn () => app(RejectPurchaseOrderImport::class)->handle(
                $import,
                $this->actor,
                'Do not race the active worker.',
            ),
            'import',
        );
        $this->assertValidationError(
            fn () => app(ManuallyFinalizePurchaseOrderImport::class)->handle($import, $this->actor),
            'import',
        );

        $this->actingAs($this->actor)
            ->get(route('tech.storage.purchase-order-imports.show', $import))
            ->assertOk()
            ->assertDontSee('Finalize Order')
            ->assertDontSee('Reject Import')
            ->assertDontSee('Correct Manually')
            ->assertDontSee('name="item_id"', false);

        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $import->fresh()->status);
        $this->assertSame($line->mapping_status, $line->fresh()->mapping_status);
        $this->assertNull($line->fresh()->item_id);
        $this->assertDatabaseCount('storage_item_vendors', 0);
        $this->assertSame($countsBefore, $this->domainCounts());
    }

    #[Test]
    public function mutable_import_can_be_mapped_and_finalized_atomically_without_receiving_stock(): void
    {
        $import = $this->reviewedImport('manual-finalize-positive');
        $line = $import->lines->sole();
        $item = Item::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'primary_vendor_id' => $this->supplier->id,
            'sku' => 'MANUAL-FINALIZE',
            'name' => 'Manual Finalize Item',
            'qty_on_hand' => 7,
            'qty_reserved' => 0,
            'reorder_point' => 0,
            'target_level' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);

        $this->policy->forceFill(['max_order_total' => 100])->save();

        $mapped = app(MapSupplierOrderImportLine::class)->handle($line, $item, $this->actor);
        $purchaseOrder = app(ManuallyFinalizePurchaseOrderImport::class)->handle(
            $import->fresh(),
            $this->actor,
        );

        $this->assertSame($item->id, $mapped->item_id);
        $this->assertSame('resolved', $mapped->mapping_status);
        $this->assertSame('ordered', $purchaseOrder->status);
        $this->assertSame('2026-08-05', $purchaseOrder->ordered_at?->toDateString());
        $this->assertSame($purchaseOrder->id, $import->fresh()->purchase_order_id);
        $this->assertSame(PurchaseOrderImport::STATUS_IMPORTED, $import->fresh()->status);
        $this->assertSame(7, $item->fresh()->qty_on_hand);
        $this->assertDatabaseCount('storage_purchase_receipts', 0);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertDatabaseCount('storage_stock_units', 0);
    }

    #[Test]
    public function manual_finalization_rejects_a_tampered_effective_policy_checksum(): void
    {
        $import = $this->reviewedImport('manual-finalize-tampered-policy');
        $import->forceFill([
            'effective_policy_checksum' => str_repeat('0', 64),
        ])->save();

        $this->assertValidationError(
            fn () => app(ManuallyFinalizePurchaseOrderImport::class)->handle(
                $import->fresh(),
                $this->actor,
            ),
            'effective_policy',
        );

        $this->assertNull($import->fresh()->purchase_order_id);
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function protected_fixture_is_sanitized_idempotent_and_replayed_before_success(): void
    {
        $import = $this->reviewedImport('fixture-happy', unsafeSource: true);
        $countsBefore = $this->domainCounts();

        $this->assertSame(
            'App\\Modules\\Storage\\Controllers\\Tech\\PurchaseOrderImportController@correctManually',
            Route::getRoutes()->getByName('tech.storage.purchase-order-imports.correct-manually')->getActionName(),
        );
        $this->assertSame(
            'App\\Modules\\Storage\\Controllers\\Admin\\PurchaseOrderImportProfileController@storeFixture',
            Route::getRoutes()->getByName('tech.admin.settings.storage.supplier-order-profiles.fixtures.store')->getActionName(),
        );

        $this->actingAs($this->actor)
            ->get(route('tech.admin.settings.storage.supplier-order-profiles.show', $this->profile))
            ->assertOk()
            ->assertSee('Add Protected Fixture from Reviewed Import')
            ->assertSee('Save Fixture and Run Fresh Replay')
            ->assertSee('#'.$import->id)
            ->assertDontSee('raw_path')
            ->assertDontSee('RAW-AUTHORIZATION');

        $fixturePayload = [
            'fixture_name' => 'Reviewed Itegra confirmation',
            'profile_version_id' => $this->version->id,
            'purchase_order_import_id' => $import->id,
        ];
        $this->actingAs($this->actor)
            ->post(
                route('tech.admin.settings.storage.supplier-order-profiles.fixtures.store', $this->profile),
                $fixturePayload,
            )
            ->assertRedirect()
            ->assertSessionHas('success');

        $fixture = PurchaseOrderImportProfileFixture::query()->sole();
        $safeSource = $fixture->safe_source_snapshot;
        $this->assertTrue($fixture->is_protected);
        $this->assertSame('reviewed_import', $fixture->fixture_type);
        $this->assertSame('passed', $fixture->last_result);
        $this->assertArrayNotHasKey('headers', $safeSource);
        $this->assertArrayNotHasKey('raw_path', $safeSource);
        $this->assertStringNotContainsString('<script', (string) $safeSource['body_html']);
        $this->assertStringNotContainsString('onclick', (string) $safeSource['body_html']);
        $this->assertStringNotContainsString('href=', (string) $safeSource['body_html']);
        $this->assertSame(
            $import->source_fingerprint,
            data_get($safeSource, 'fixture_provenance.immutable_source_fingerprint'),
        );
        $this->assertSame($import->id, data_get($safeSource, 'fixture_provenance.source_import_id'));
        $this->assertSame(StableJson::checksum($safeSource), $fixture->source_checksum);
        $this->assertSame(
            StableJson::checksum($fixture->expected_document),
            $fixture->expected_checksum,
        );
        $this->assertArrayNotHasKey('evidence', $fixture->expected_document);
        $this->assertArrayNotHasKey('manual_review', $fixture->expected_document);
        $this->assertSame($countsBefore, $this->domainCounts());

        $this->actingAs($this->actor)
            ->post(
                route('tech.admin.settings.storage.supplier-order-profiles.fixtures.store', $this->profile),
                $fixturePayload,
            )
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertDatabaseCount('storage_purchase_order_import_profile_fixtures', 1);

        $brokenDefinition = SupplierOrderProfileFactoryData::itegra();
        $brokenDefinition['fields']['supplier.name']['value'] = 'Wrong Supplier';
        $brokenVersion = $this->profile->versions()->create([
            'version_number' => 2,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_DRAFT,
            'definition' => $brokenDefinition,
            'checksum' => StableJson::checksum($brokenDefinition),
            'source' => 'test',
            'created_by' => $this->actor->id,
        ]);

        $this->actingAs($this->actor)
            ->post(
                route('tech.admin.settings.storage.supplier-order-profiles.fixtures.store', $this->profile),
                array_replace($fixturePayload, [
                    'profile_version_id' => $brokenVersion->id,
                ]),
            )
            ->assertRedirect()
            ->assertSessionHas('warning')
            ->assertSessionMissing('success');

        $this->assertSame('failed', $fixture->fresh()->last_result);
        $this->assertDatabaseCount('storage_purchase_order_import_profile_fixtures', 1);
        $this->assertSame($countsBefore, $this->domainCounts());
    }

    #[Test]
    public function protected_fixture_requires_permission_and_profile_ownership_without_side_effects(): void
    {
        $import = $this->reviewedImport('fixture-guards');
        $countsBefore = $this->domainCounts();
        $unauthorized = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $action = app(CreateProtectedSupplierOrderProfileFixtureFromImport::class);

        try {
            $action->handle(
                $this->profile,
                $this->version,
                $import,
                'Unauthorized fixture',
                $unauthorized,
            );
            $this->fail('An unauthorized user created a protected fixture.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('fixture', $exception->errors());
        }

        [$otherProfile, $otherVersion] = $this->activeProfile('other-profile');
        try {
            $action->handle(
                $otherProfile,
                $otherVersion,
                $import,
                'Wrong profile fixture',
                $this->actor,
            );
            $this->fail('A cross-profile import created a protected fixture.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('purchase_order_import_id', $exception->errors());
        }
        $import->forceFill(['status' => PurchaseOrderImport::STATUS_PENDING])->save();
        $this->assertValidationError(
            fn () => $action->handle(
                $this->profile,
                $this->version,
                $import,
                'Unreviewed fixture',
                $this->actor,
            ),
            'purchase_order_import_id',
        );

        $this->assertDatabaseCount('storage_purchase_order_import_profile_fixtures', 0);
        $this->assertSame($countsBefore, $this->domainCounts());
    }

    /** @return array{0: PurchaseOrderImportProfile, 1: PurchaseOrderImportProfileVersion} */
    private function activeProfile(string $slug): array
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $profile = PurchaseOrderImportProfile::query()->create([
            'vendor_id' => $this->supplier->id,
            'name' => str($slug)->replace('-', ' ')->title(),
            'slug' => $slug,
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
            'priority' => 10,
            'matching_scope' => SupplierOrderProfileFactoryData::itegraMatchingScope(),
            'policy_overrides' => [],
            'health_state' => 'healthy',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $version = PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => 1,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
            'definition' => $definition,
            'checksum' => StableJson::checksum($definition),
            'source' => 'test',
            'created_by' => $this->actor->id,
        ]);
        $profile->forceFill(['active_version_id' => $version->id])->save();

        return [$profile->fresh(), $version->fresh()];
    }

    /** @return array{0: PurchaseOrderAutomationPolicy, 1: PurchaseOrderAutomationPolicyRevision} */
    private function policy(): array
    {
        $policy = PurchaseOrderAutomationPolicy::query()->create([
            'name' => 'Manual review test policy',
            'is_current' => true,
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'default_outcome' => 'needs_attention',
            'automation_user_id' => $this->actor->id,
            'default_warehouse_id' => $this->warehouse->id,
            'ai_mode' => 'off',
            'provider_outage_behavior' => 'needs_attention',
            'supplier_bootstrap_mode' => 'existing_only',
            'new_item_mode' => 'review_only',
            'advanced_rules' => [],
            'revision_number' => 1,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ])->fresh();
        $snapshot = $policy->revisionSnapshot();
        $revision = $policy->revisions()->create([
            'revision_number' => 1,
            'snapshot' => $snapshot,
            'checksum' => StableJson::checksum($snapshot),
            'reason' => 'Manual correction and fixture tests.',
            'created_by' => $this->actor->id,
            'activated_at' => now(),
        ]);

        return [$policy, $revision];
    }

    private function reviewedImport(string $key, bool $unsafeSource = false): PurchaseOrderImport
    {
        $source = $this->sourceSnapshot();
        if ($unsafeSource) {
            $source['body_html'] = '<script>alert(1)</script><p onclick="steal()">'
                .'<a href="https://tracker.invalid/secret">Order confirmation</a></p>';
            $source['headers'] = ['Authorization' => 'RAW-AUTHORIZATION'];
            $source['raw_path'] = '/mail/private/source.eml';
        }

        $import = PurchaseOrderImport::query()->create([
            'source_domain' => 'email',
            'source_type' => 'supplier-order-test',
            'source_id' => $key,
            'source_action_hash' => hash('sha256', 'action:'.$key),
            'source_fingerprint' => StableJson::checksum($source),
            'safe_source_snapshot' => $source,
            'trusted_auth_snapshot' => $source['trusted_auth'],
            'vendor_id' => $this->supplier->id,
            'profile_id' => $this->profile->id,
            'profile_version_id' => $this->version->id,
            'policy_revision_id' => $this->revision->id,
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'stage' => PurchaseOrderImport::STAGE_VALIDATE,
            'requested_by' => $this->actor->id,
            'attempt_count' => 0,
        ]);
        $effective = app(ResolveEffectivePurchaseOrderAutomationPolicy::class)->handle(
            $import,
            $this->policy,
            $this->profile,
            $this->version,
        );
        $extraction = app(SupplierOrderDeterministicExtractor::class)->extract($this->version, $source);
        $this->assertTrue($extraction->valid(), StableJson::encode($extraction->errors));
        $document = $extraction->document;
        $document['destination_warehouse_id'] = $this->warehouse->id;
        $validation = app(SupplierOrderCanonicalValidator::class)->validate($document, $effective);
        $this->assertTrue($validation->valid(), StableJson::encode($validation->errors));

        $import->forceFill([
            'external_order_number' => $document['external_order_number'],
            'normalized_document' => $document,
            'validation_results' => $validation->toArray(),
            'extraction_method' => 'deterministic',
            'reason_code' => 'review_required',
        ])->save();
        app(SyncPurchaseOrderImportLines::class)->handle($import, $document);

        return $import->fresh(['lines', 'profile', 'profileVersion', 'policyRevision']);
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function sourceSnapshot(): array
    {
        return [
            'schema_version' => 'storage.supplier_order_source.v1',
            'source' => 'email',
            'mailbox' => 'orders@nexum.test',
            'message_id' => '<reviewed-import@nexum.test>',
            'subject' => 'Takk for din ordre',
            'from' => ['name' => 'Itegra', 'email' => 'synthetic-fixture@itegra.no'],
            'to' => [['name' => 'Orders', 'email' => 'orders@nexum.test']],
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
                'authenticated_supplier_identity' => 'synthetic-fixture@itegra.no',
                'authenticated_supplier_domain' => 'itegra.no',
                'authserv_id' => 'mail.nexum.test',
                'spf' => 'pass',
                'dkim' => 'pass',
                'dmarc' => 'pass',
                'aligned' => true,
            ],
        ];
    }

    private function assertValidationError(callable $callback, string $key): void
    {
        try {
            $callback();
            $this->fail('Expected the manual import mutation to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($key, $exception->errors());
        }
    }

    /** @return array<string, int> */
    private function domainCounts(): array
    {
        return [
            'vendors' => DB::table('vendors')->count(),
            'items' => DB::table('storage_items')->count(),
            'purchase_orders' => DB::table('storage_purchase_orders')->count(),
            'purchase_receipts' => DB::table('storage_purchase_receipts')->count(),
            'movements' => DB::table('storage_movements')->count(),
            'stock_units' => DB::table('storage_stock_units')->count(),
        ];
    }
}
