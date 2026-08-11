<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\CreatePurchaseOrderImport;
use App\Modules\Storage\Actions\CreateSupplierImportedItem;
use App\Modules\Storage\Actions\EvaluateSupplierOrderImportPolicy;
use App\Modules\Storage\Actions\FinalizeImportedPurchaseOrder;
use App\Modules\Storage\Actions\GetCurrentPurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\ProcessPurchaseOrderImport;
use App\Modules\Storage\Actions\ResolveSupplierOrderItems;
use App\Modules\Storage\Actions\StorePurchaseOrder;
use App\Modules\Storage\Actions\UpdatePurchaseOrder;
use App\Modules\Storage\Models\Item;
use App\Modules\Storage\Models\ItemVendor;
use App\Modules\Storage\Models\PurchaseOrder;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicyRevision;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportLine;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportProfileVersion;
use App\Modules\Storage\Models\Warehouse;
use App\Modules\Storage\Support\CanonicalSupplierOrderValidationResult;
use App\Modules\Storage\Support\StableJson;
use App\Modules\Storage\Support\SupplierItemResolutionSummary;
use App\Modules\Storage\Support\SupplierOrderCanonicalValidator;
use App\Modules\Storage\Support\SupplierOrderPolicyDecision;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use App\Modules\Storage\Support\SupplierSkuIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierOrderImportPipelineTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Warehouse $warehouse;

    private Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'storage.purchase_manage',
            'storage.purchase_import_execute',
            'storage.purchase_import_resolve',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_policy_manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo([
            'storage.purchase_manage',
            'storage.purchase_import_execute',
            'storage.purchase_import_resolve',
            'storage.purchase_import_profile_manage',
            'storage.purchase_import_policy_manage',
        ]);
        $this->warehouse = Warehouse::query()->create([
            'name' => 'Supplier Import Warehouse',
            'code' => 'SUP-IMPORT',
            'is_active' => true,
        ]);
        $this->supplier = Vendor::query()->create([
            'name' => 'Itegra',
            'vendor_code' => 'ITEGRA-TEST',
            'is_supplier' => true,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function foundation_is_fail_closed_and_stable_source_actions_are_idempotent(): void
    {
        $effective = app(GetCurrentPurchaseOrderAutomationPolicy::class)->handle();

        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_OFF, $effective['policy']->runtime_mode);
        $this->assertSame(SupplierOrderPolicyDecision::NEEDS_ATTENTION, $effective['policy']->default_outcome);
        $this->assertNull($effective['policy']->automation_user_id);
        $this->assertSame('off', $effective['policy']->ai_mode);
        $this->assertSame('off', data_get($effective['revision']->snapshot, 'ai_profile_learning_mode'));

        $payload = $this->importPayload(
            revision: $effective['revision'],
            actionKey: 'signal-action:stable-1',
            snapshot: $this->sourceSnapshot(),
        );
        $first = app(CreatePurchaseOrderImport::class)->handle($payload);
        $second = app(CreatePurchaseOrderImport::class)->handle($payload);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame($first['import']->id, $second['import']->id);
        $this->assertSame(1, PurchaseOrderImport::query()->count());
        $this->assertNoProcurementOrStockSideEffects();
    }

    #[Test]
    public function an_explicitly_pinned_profile_is_rejected_when_the_source_scope_does_not_match(): void
    {
        [$profile, $version] = $this->activeProfile();
        $definition = $version->definition;
        $version->forceFill([
            'status' => PurchaseOrderImportProfileVersion::STATUS_SUPERSEDED,
        ])->save();
        $definition['match']['senders'] = ['different-supplier@example.test'];
        $mismatchedVersion = PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => 2,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
            'definition' => $definition,
            'checksum' => StableJson::checksum($definition),
            'source' => 'test',
        ]);
        $profile->forceFill([
            'matching_scope' => $definition['match'],
            'active_version_id' => $mismatchedVersion->id,
        ])->save();
        $version = $mismatchedVersion->fresh();
        [, $revision] = $this->policy();
        $created = app(CreatePurchaseOrderImport::class)->handle($this->importPayload(
            revision: $revision,
            actionKey: 'pinned-profile-source-scope-mismatch',
            snapshot: $this->sourceSnapshot(),
            profile: $profile->fresh(),
            version: $version,
        ));

        $before = [
            'items' => Item::query()->count(),
            'item_vendors' => ItemVendor::query()->count(),
            'purchase_orders' => PurchaseOrder::query()->count(),
        ];
        $processed = app(ProcessPurchaseOrderImport::class)->handle($created['import']);

        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $processed->status);
        $this->assertSame('profile_source_scope_mismatch', $processed->reason_code);
        $this->assertSame(PurchaseOrderImport::STAGE_DETECT, $processed->stage);
        $this->assertNull($processed->normalized_document);
        $this->assertNull($processed->vendor_id);
        $this->assertSame(0, $processed->lines()->count());
        $this->assertSame($before, [
            'items' => Item::query()->count(),
            'item_vendors' => ItemVendor::query()->count(),
            'purchase_orders' => PurchaseOrder::query()->count(),
        ]);
        $this->assertNoProcurementOrStockSideEffects();
    }

    #[Test]
    public function perfect_ai_confidence_cannot_bypass_source_or_actor_hard_gates(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
            'default_outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            'automation_user_id' => null,
            'ai_mode' => 'always',
        ]);
        $document = $this->canonicalDocument('AI-HARD-GATE');
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'ai-hard-gate',
            fingerprintSeed: 'ai-hard-gate',
            document: $document,
            trusted: false,
        );
        $import->forceFill([
            'extraction_method' => 'ai',
            'confidence_dimensions' => [
                'source_trust' => 100,
                'document_identity' => 100,
                'extraction_evidence' => 100,
                'item_identity' => 100,
                'deterministic_validation' => 100,
                'ai_result_validity' => 100,
            ],
        ])->save();

        $decision = app(EvaluateSupplierOrderImportPolicy::class)->handle(
            $import->fresh(['profile', 'profileVersion', 'vendor']),
            $policy,
            new CanonicalSupplierOrderValidationResult([], [], [
                'document_identity' => 100,
                'extraction_evidence' => 100,
                'deterministic_validation' => 100,
            ]),
            new SupplierItemResolutionSummary(1, 0, 0, 0, 0, []),
        );

        $this->assertSame(SupplierOrderPolicyDecision::NEEDS_ATTENTION, $decision->outcome);
        $this->assertContains('source_authentication_failed', $decision->reasonCodes);
        $this->assertContains('automation_actor_invalid', $decision->reasonCodes);
        $this->assertSame(100, $decision->facts['weakest_critical_confidence']);
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function duplicate_legacy_supplier_sku_mappings_remain_ambiguous(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'new_item_mode' => 'review_only',
        ]);
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'ambiguous-mapping',
            fingerprintSeed: 'ambiguous-mapping',
            document: $this->canonicalDocument('AMBIGUOUS'),
        );
        $line = $this->importLine($import, mappingStatus: PurchaseOrderImportLine::MAPPING_UNRESOLVED);
        foreach (['AMB-A', 'AMB-B'] as $sku) {
            $item = $this->item($sku);
            ItemVendor::query()->create([
                'item_id' => $item->id,
                'vendor_id' => $this->supplier->id,
                'vendor_sku' => 'SUP-100',
            ]);
        }

        $summary = app(ResolveSupplierOrderItems::class)->handle(
            $import->fresh(['lines', 'profileVersion']),
            $policy,
            $this->actor,
        );

        $this->assertFalse($summary->allResolved());
        $this->assertSame(1, $summary->ambiguous);
        $this->assertContains('supplier_sku_ambiguous', $summary->reasonCodes);
        $this->assertSame(PurchaseOrderImportLine::MAPPING_AMBIGUOUS, $line->refresh()->mapping_status);
        $this->assertNull($line->item_id);
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function distinct_item_creation_is_zero_stock_provenanced_and_retry_idempotent(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'new_item_mode' => 'create_active_item',
            'max_new_items' => 1,
        ]);
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'create-distinct-item',
            fingerprintSeed: 'create-distinct-item',
            document: $this->canonicalDocument('CREATE-ITEM'),
        );
        $line = $this->importLine($import, mappingStatus: PurchaseOrderImportLine::MAPPING_UNRESOLVED);
        $action = app(CreateSupplierImportedItem::class);

        $first = $action->handle($import, $line, $policy, $this->actor);
        $second = $action->handle($import->fresh(), $line->fresh(), $policy, $this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Item::query()->where('created_from_import_id', $import->id)->count());
        $this->assertSame(0, $first->refresh()->qty_on_hand);
        $this->assertTrue($first->can_be_ordered);
        $this->assertSame('required', $first->catalog_review_status);
        $this->assertSame($import->id, data_get($first->source_provenance, 'import_id'));
        $this->assertSame(PurchaseOrderImportLine::MAPPING_RESOLVED, $line->refresh()->mapping_status);
        $this->assertSame($first->id, $line->item_id);
        $this->assertDatabaseCount('storage_item_vendors', 1);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertDatabaseCount('storage_stock_units', 0);
        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function finalization_is_idempotent_and_same_order_resends_never_create_a_second_po(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('FINALIZE-ITEM', quantity: 7);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );
        $document = $this->canonicalDocument('SUPPLIER-ORDER-42', quantity: 2);
        $original = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'finalize-original',
            fingerprintSeed: 'same-source',
            document: $document,
        );
        $this->importLine($original, $item);
        $before = $this->stockSnapshot($item);
        $finalize = app(FinalizeImportedPurchaseOrder::class);

        $firstPo = $finalize->handle($original, $policy, $decision);
        $retryPo = $finalize->handle($original->fresh(), $policy, $decision);

        $this->assertNotNull($firstPo);
        $this->assertSame($firstPo->id, $retryPo?->id);
        $this->assertSame(PurchaseOrder::STATUS_ORDERED, $firstPo->status);
        $this->assertSame('SUPPLIER-ORDER-42', $firstPo->vendor_ref);
        $this->assertSame(2, $firstPo->lines->sole()->qty_ordered);
        $this->assertSame(PurchaseOrderImport::STATUS_IMPORTED, $original->refresh()->status);
        try {
            $this->manualPurchaseOrder(
                item: $item,
                poNumber: 'MANUAL-AFTER-EMAIL',
                vendorRef: ' supplier-order-42 ',
            );
            $this->fail('Expected the email-created supplier order identity to block manual duplication.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vendor_ref', $exception->errors());
        }

        $duplicate = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'finalize-duplicate',
            fingerprintSeed: 'same-source',
            document: $document,
        );
        $this->importLine($duplicate, $item);
        $duplicatePo = $finalize->handle($duplicate, $policy, $decision);

        $this->assertSame($firstPo->id, $duplicatePo?->id);
        $this->assertSame(PurchaseOrderImport::STATUS_DUPLICATE, $duplicate->refresh()->status);
        $this->assertSame('duplicate_supplier_order', $duplicate->reason_code);

        $changed = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'finalize-changed',
            fingerprintSeed: 'changed-source',
            document: $document,
        );
        $this->importLine($changed, $item);
        $this->assertNull($finalize->handle($changed, $policy, $decision));
        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $changed->refresh()->status);
        $this->assertSame('changed_supplier_order_resend', $changed->reason_code);

        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function trusted_email_confirmation_attaches_to_matching_manual_order_without_overwriting_it(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-CONFIRM-ITEM', quantity: 9);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-CONFIRM-1',
            vendorRef: '  supplier-confirm-42  ',
            quantity: 2,
            status: PurchaseOrder::STATUS_DRAFT,
        );
        $originalUpdatedAt = $manual->getRawOriginal('updated_at');
        $originalLineUpdatedAt = $manual->lines->sole()->getRawOriginal('updated_at');
        $before = $this->stockSnapshot($item);
        $document = $this->canonicalDocument('SUPPLIER-CONFIRM-42', quantity: 2);
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-first-exact-confirmation',
            fingerprintSeed: 'manual-first-exact-confirmation',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $confirmed = app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        );
        $manual->refresh();
        $linkedImport = $import->refresh();

        $this->assertSame($manual->id, $confirmed?->id);
        $this->assertSame(PurchaseOrder::STATUS_DRAFT, $manual->status);
        $this->assertSame('supplier-confirm-42', $manual->vendor_ref);
        $this->assertSame('MANUAL-CONFIRM-1', $manual->po_number);
        $this->assertSame($originalUpdatedAt, $manual->getRawOriginal('updated_at'));
        $this->assertSame($originalLineUpdatedAt, $manual->lines->sole()->getRawOriginal('updated_at'));
        $this->assertSame(PurchaseOrderImport::STATUS_IMPORTED, $linkedImport->status);
        $this->assertSame('existing_purchase_order_vendor_confirmed', $linkedImport->reason_code);
        $this->assertSame($manual->id, $linkedImport->purchase_order_id);
        $this->assertSame(
            'supplier_and_supplier_order_number',
            data_get($linkedImport->reason_context, 'matched_by'),
        );
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertSame($before, $this->stockSnapshot($item));

        try {
            app(UpdatePurchaseOrder::class)->handle($manual, [
                'vendor_ref' => 'DIFFERENT-SUPPLIER-ORDER',
                'currency' => 'NOK',
            ], $this->actor);
            $this->fail('Expected vendor-confirmed supplier identity to be immutable.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vendor_ref', $exception->errors());
        }

        $this->assertSame('supplier-confirm-42', $manual->fresh()->vendor_ref);
        $this->assertSame(
            'SUPPLIER-CONFIRM-42',
            $linkedImport->fresh()->external_order_number,
        );
    }

    #[Test]
    public function supplier_freight_can_confirm_a_manual_order_when_the_goods_and_source_total_reconcile(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-FREIGHT-ITEM', quantity: 8);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-FREIGHT-1',
            vendorRef: 'SUPPLIER-FREIGHT-42',
            quantity: 2,
        );
        $before = $this->stockSnapshot($item);
        $document = $this->canonicalDocument('supplier-freight-42', quantity: 2);
        $document['totals']['freight'] = '25.00';
        $document['totals']['total_ex_tax'] = '225.00';
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-first-freight-confirmation',
            fingerprintSeed: 'manual-first-freight-confirmation',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $confirmed = app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        );

        $this->assertSame($manual->id, $confirmed?->id);
        $this->assertSame(PurchaseOrderImport::STATUS_IMPORTED, $import->refresh()->status);
        $this->assertSame('25.00', data_get($document, 'totals.freight'));
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function derivable_source_total_is_compared_with_the_manual_commercial_snapshot(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-DERIVED-TOTAL', quantity: 4);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-DERIVED-1',
            vendorRef: 'SUPPLIER-DERIVED-42',
        );
        $manual->forceFill([
            'metadata' => ['commercial_snapshot' => ['total_ex_tax' => '226.00']],
        ])->save();
        $document = $this->canonicalDocument('supplier-derived-42');
        $document['totals']['freight'] = '25.00';
        $document['totals']['total_ex_tax'] = null;
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-derived-total-mismatch',
            fingerprintSeed: 'manual-derived-total-mismatch',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        ));

        $reviewImport = $import->refresh();
        $this->assertContains(
            'total_ex_tax_differs',
            data_get($reviewImport->reason_context, 'differences', []),
        );
        $this->assertSame($manual->id, data_get(
            $reviewImport->reason_context,
            'candidate_purchase_order_id',
        ));
        $this->assertDatabaseCount('storage_purchase_orders', 1);
    }

    #[Test]
    public function explicit_source_order_date_cannot_confirm_a_manual_order_with_no_order_date(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-MISSING-DATE', quantity: 4);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-MISSING-DATE-1',
            vendorRef: 'SUPPLIER-MISSING-DATE-42',
            status: PurchaseOrder::STATUS_DRAFT,
        );
        $manual->forceFill(['ordered_at' => null])->save();
        $document = $this->canonicalDocument('supplier-missing-date-42');
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-missing-date',
            fingerprintSeed: 'manual-missing-date',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        ));

        $this->assertContains(
            'ordered_date_missing',
            data_get($import->refresh()->reason_context, 'differences', []),
        );
        $this->assertDatabaseCount('storage_purchase_orders', 1);
    }

    #[Test]
    public function manual_tax_snapshot_cannot_mask_a_wrong_supplier_aggregate_tax(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-TAX-MASK', quantity: 4);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-TAX-MASK-1',
            vendorRef: 'SUPPLIER-TAX-MASK-42',
        );
        $manual->lines()->update(['tax_rate' => '25.00']);
        $manual->forceFill([
            'metadata' => ['commercial_snapshot' => ['tax_total' => '60.00']],
        ])->save();
        $document = $this->canonicalDocument('supplier-tax-mask-42');
        $document['lines'][0]['tax_rate'] = '25.00';
        $document['totals']['tax_total'] = '60.00';
        $document['totals']['total_inc_tax'] = '260.00';
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-tax-mask',
            fingerprintSeed: 'manual-tax-mask',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        ));

        $this->assertContains(
            'tax_total_differs',
            data_get($import->refresh()->reason_context, 'differences', []),
        );
        $this->assertSame($manual->id, data_get(
            $import->reason_context,
            'candidate_purchase_order_id',
        ));
    }

    #[Test]
    public function matching_manual_tax_snapshot_verifies_tax_when_source_freight_is_present(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-TAX-FREIGHT', quantity: 4);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-TAX-FREIGHT-1',
            vendorRef: 'SUPPLIER-TAX-FREIGHT-42',
        );
        $manual->lines()->update(['tax_rate' => '25.00']);
        $manual->forceFill([
            'metadata' => [
                'commercial_snapshot' => [
                    'goods_subtotal' => '200.00',
                    'freight' => '25.00',
                    'discount' => '0.00',
                    'other_charges' => '0.00',
                    'tax_total' => '50.00',
                    'total_ex_tax' => '225.00',
                    'total_inc_tax' => '275.00',
                ],
            ],
        ])->save();
        $document = $this->canonicalDocument('supplier-tax-freight-42');
        $document['lines'][0]['tax_rate'] = '25.00';
        $document['totals']['freight'] = '25.00';
        $document['totals']['tax_total'] = '50.00';
        $document['totals']['total_ex_tax'] = '225.00';
        $document['totals']['total_inc_tax'] = '275.00';
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-tax-freight',
            fingerprintSeed: 'manual-tax-freight',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $confirmed = app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        );

        $this->assertSame($manual->id, $confirmed?->id);
        $this->assertSame(PurchaseOrderImport::STATUS_IMPORTED, $import->refresh()->status);
        $this->assertSame('existing_purchase_order_vendor_confirmed', $import->reason_code);
        $this->assertDatabaseCount('storage_purchase_orders', 1);
    }

    #[Test]
    public function finalization_rejects_stale_import_line_projection_before_creating_an_order(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('STALE-PROJECTION-ITEM', quantity: 5);
        $document = $this->canonicalDocument('STALE-PROJECTION-42');
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'stale-line-projection',
            fingerprintSeed: 'stale-line-projection',
            document: $document,
        );
        $this->importLine($import, $item)->forceFill(['line_total' => '201.00'])->save();
        $before = $this->stockSnapshot($item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        try {
            app(FinalizeImportedPurchaseOrder::class)->handle($import, $policy, $decision);
            $this->fail('Expected stale import-line projection to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'source_projection_mismatch',
                json_encode($exception->errors(), JSON_THROW_ON_ERROR),
            );
        }

        $this->assertDatabaseCount('storage_purchase_orders', 0);
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function finalization_rejects_external_order_projection_drift(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('STALE-IDENTITY-PROJECTION', quantity: 5);
        $document = $this->canonicalDocument('SOURCE-IDENTITY-42');
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'stale-identity-projection',
            fingerprintSeed: 'stale-identity-projection',
            document: $document,
        );
        $this->importLine($import, $item);
        $import->forceFill(['external_order_number' => 'OTHER-IDENTITY-42'])->save();
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        try {
            app(FinalizeImportedPurchaseOrder::class)->handle($import, $policy, $decision);
            $this->fail('Expected external-order projection drift to fail closed.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString(
                'source_projection_mismatch',
                json_encode($exception->errors(), JSON_THROW_ON_ERROR),
            );
        }

        $this->assertDatabaseCount('storage_purchase_orders', 0);
    }

    #[Test]
    public function commercial_or_line_total_changes_require_attention_without_overwriting_the_manual_order(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-COMMERCIAL-MISMATCH', quantity: 6);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-COMMERCIAL-1',
            vendorRef: 'SUPPLIER-COMMERCIAL-42',
            quantity: 2,
        );
        $manual->forceFill([
            'metadata' => [
                'commercial_snapshot' => [
                    'goods_subtotal' => '200.00',
                    'freight' => '30.00',
                    'discount' => '0.00',
                    'other_charges' => '0.00',
                    'total_ex_tax' => '230.02',
                ],
            ],
        ])->save();
        $before = $this->stockSnapshot($item);
        $document = $this->canonicalDocument('supplier-commercial-42', quantity: 2);
        $document['lines'][0]['unit_price'] = '100.01';
        $document['lines'][0]['line_total'] = '200.02';
        $document['totals']['goods_subtotal'] = '200.02';
        $document['totals']['freight'] = '25.00';
        $document['totals']['total_ex_tax'] = '225.02';
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-first-commercial-mismatch',
            fingerprintSeed: 'manual-first-commercial-mismatch',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        ));

        $reviewImport = $import->refresh();
        $differences = data_get($reviewImport->reason_context, 'differences', []);
        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $reviewImport->status);
        $this->assertSame(
            'existing_purchase_order_confirmation_mismatch',
            $reviewImport->reason_code,
        );
        $this->assertContains('line_total_differs', $differences);
        $this->assertContains('freight_differs', $differences);
        $this->assertContains('total_ex_tax_differs', $differences);
        $this->assertNull($reviewImport->purchase_order_id);
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertSame('30.00', data_get($manual->fresh()->metadata, 'commercial_snapshot.freight'));
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function mismatching_email_confirmation_needs_attention_without_a_second_order_or_overwrite(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('MANUAL-MISMATCH-ITEM', quantity: 5);
        $manual = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'MANUAL-MISMATCH-1',
            vendorRef: 'SUPPLIER-MISMATCH-42',
            quantity: 1,
        );
        $before = $this->stockSnapshot($item);
        $document = $this->canonicalDocument('supplier-mismatch-42', quantity: 2);
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'manual-first-mismatch',
            fingerprintSeed: 'manual-first-mismatch',
            document: $document,
        );
        $this->importLine($import, $item);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        ));
        $reviewImport = $import->refresh();

        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $reviewImport->status);
        $this->assertSame(
            'existing_purchase_order_confirmation_mismatch',
            $reviewImport->reason_code,
        );
        $this->assertContains(
            'line_quantity_differs',
            data_get($reviewImport->reason_context, 'differences', []),
        );
        $this->assertSame($manual->id, data_get(
            $reviewImport->reason_context,
            'candidate_purchase_order_id',
        ));
        $this->assertNull($reviewImport->purchase_order_id);
        $this->assertNotNull($reviewImport->domain_identity_hash);
        $this->assertSame(1, $manual->fresh()->lines->sole()->qty_ordered);
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function cancelled_and_deleted_matching_orders_are_never_confirmed_or_duplicated(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('HISTORICAL-CONFIRM-ITEM', quantity: 4);
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );
        $before = $this->stockSnapshot($item);

        $cancelled = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'HISTORICAL-CANCELLED',
            vendorRef: 'HISTORICAL-CANCELLED-REF',
        );
        $cancelled->forceFill([
            'status' => PurchaseOrder::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();
        $cancelledImport = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'historical-cancelled',
            fingerprintSeed: 'historical-cancelled',
            document: $this->canonicalDocument('historical-cancelled-ref'),
        );
        $this->importLine($cancelledImport, $item);

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $cancelledImport,
            $policy,
            $decision,
        ));
        $this->assertSame(
            'existing_purchase_order_not_confirmable',
            $cancelledImport->refresh()->reason_code,
        );
        $this->assertSame($cancelled->id, data_get(
            $cancelledImport->reason_context,
            'candidate_purchase_order_id',
        ));

        $deleted = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'HISTORICAL-DELETED',
            vendorRef: 'HISTORICAL-DELETED-REF',
        );
        $deleted->delete();
        $deletedImport = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'historical-deleted',
            fingerprintSeed: 'historical-deleted',
            document: $this->canonicalDocument('historical-deleted-ref'),
        );
        $this->importLine($deletedImport, $item);

        $this->assertNull(app(FinalizeImportedPurchaseOrder::class)->handle(
            $deletedImport,
            $policy,
            $decision,
        ));
        $deletedImport->refresh();
        $this->assertSame('existing_purchase_order_not_confirmable', $deletedImport->reason_code);
        $this->assertTrue((bool) data_get($deletedImport->reason_context, 'candidate_deleted'));
        $this->assertSame(2, PurchaseOrder::withTrashed()->count());
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function supplier_order_identity_is_supplier_scoped_and_reserves_deleted_history(): void
    {
        $item = $this->item('IDENTITY-SCOPE-ITEM');
        $otherSupplier = Vendor::query()->create([
            'name' => 'Other Supplier',
            'vendor_code' => 'OTHER-SUPPLIER',
            'is_supplier' => true,
            'is_active' => true,
        ]);
        $first = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'IDENTITY-A',
            vendorRef: '  0012-order  ',
        );
        $otherSupplierOrder = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'IDENTITY-B',
            vendorRef: '0012-ORDER',
            supplier: $otherSupplier,
        );

        $this->assertSame('0012-ORDER', $first->fresh()->supplier_order_identity_key);
        $this->assertSame(
            '0012-ORDER',
            $otherSupplierOrder->fresh()->supplier_order_identity_key,
        );
        $this->assertNotSame($first->vendor_id, $otherSupplierOrder->vendor_id);
        $this->assertDatabaseCount('storage_purchase_orders', 2);

        try {
            $this->manualPurchaseOrder(
                item: $item,
                poNumber: 'IDENTITY-DUPLICATE-ACTIVE',
                vendorRef: '0012-ORDER',
            );
            $this->fail('Expected the active supplier-order identity to be rejected.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vendor_ref', $exception->errors());
        }

        $first->delete();

        try {
            $this->manualPurchaseOrder(
                item: $item,
                poNumber: 'IDENTITY-DUPLICATE-DELETED',
                vendorRef: '0012-order',
            );
            $this->fail('Expected soft-deleted supplier-order history to stay reserved.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vendor_ref', $exception->errors());
        }

        $this->assertSame(2, PurchaseOrder::withTrashed()->count());
    }

    #[Test]
    public function database_generated_supplier_identity_rejects_raw_insert_and_update_bypasses(): void
    {
        $item = $this->item('IDENTITY-RAW-GUARD-ITEM');
        $first = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'IDENTITY-RAW-A',
            vendorRef: '  RAW-GUARD-42  ',
        );

        try {
            DB::table('storage_purchase_orders')->insert([
                'po_number' => 'IDENTITY-RAW-INSERT',
                'vendor_id' => $this->supplier->id,
                'deliver_to_warehouse_id' => $this->warehouse->id,
                'status' => PurchaseOrder::STATUS_ORDERED,
                'vendor_ref' => 'raw-guard-42',
                'ordered_at' => '2026-08-05',
                'currency' => 'NOK',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->fail('Expected the generated database identity to reject a raw insert.');
        } catch (QueryException) {
            // The generated column derives identity even when action code is bypassed.
        }

        $second = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'IDENTITY-RAW-B',
            vendorRef: 'RAW-UPDATE-SOURCE',
        );
        try {
            DB::table('storage_purchase_orders')
                ->where('id', $second->id)
                ->update(['vendor_ref' => ' raw-guard-42 ']);
            $this->fail('Expected the generated database identity to reject a raw update.');
        } catch (QueryException) {
            // Raw updates cannot provide or suppress the generated identity key.
        }

        $this->assertSame('RAW-UPDATE-SOURCE', $second->fresh()->vendor_ref);
        DB::table('storage_purchase_orders')
            ->where('id', $second->id)
            ->update(['vendor_ref' => ' raw-updated-43 ']);

        $this->assertSame(
            'RAW-UPDATED-43',
            DB::table('storage_purchase_orders')
                ->where('id', $second->id)
                ->value('supplier_order_identity_key'),
        );
        $this->assertSame('RAW-GUARD-42', $first->fresh()->supplier_order_identity_key);
        $this->assertDatabaseCount('storage_purchase_orders', 2);
    }

    #[Test]
    public function canonical_validation_rejects_out_of_range_line_tax_and_negative_aggregate_tax(): void
    {
        [$policy] = $this->policy();
        $document = $this->canonicalDocument('INVALID-TAX-42');
        $document['lines'][0]['tax_rate'] = '125.00';
        $document['totals']['tax_total'] = '-1.00';
        $document['totals']['total_inc_tax'] = '199.00';

        $validation = app(SupplierOrderCanonicalValidator::class)->validate(
            $document,
            $policy,
            $this->sourceSnapshot(),
        );
        $codes = collect($validation->errors)->pluck('code');

        $this->assertFalse($validation->valid());
        $this->assertContains('tax_rate_invalid', $codes);
        $this->assertContains('commercial_value_negative', $codes);
    }

    #[Test]
    public function editing_a_manual_order_cannot_claim_another_orders_supplier_identity(): void
    {
        $item = $this->item('IDENTITY-UPDATE-ITEM');
        $first = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'IDENTITY-UPDATE-A',
            vendorRef: 'UPDATE-REF-A',
        );
        $second = $this->manualPurchaseOrder(
            item: $item,
            poNumber: 'IDENTITY-UPDATE-B',
            vendorRef: 'UPDATE-REF-B',
        );

        try {
            app(UpdatePurchaseOrder::class)->handle($second, [
                'vendor_ref' => ' update-ref-a ',
                'currency' => 'NOK',
            ], $this->actor);
            $this->fail('Expected an update identity collision.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('vendor_ref', $exception->errors());
        }

        $this->assertSame('UPDATE-REF-A', $first->fresh()->vendor_ref);
        $this->assertSame('UPDATE-REF-B', $second->fresh()->vendor_ref);
        $this->assertDatabaseCount('storage_purchase_orders', 2);
    }

    #[Test]
    public function finalization_derives_unit_cost_from_source_line_total_instead_of_catalog_history(): void
    {
        [$profile, $version] = $this->activeProfile();
        [$policy, $revision] = $this->policy();
        $item = $this->item('SOURCE-COST-ITEM');
        $item->forceFill(['purchase_price' => 999])->save();
        $document = $this->canonicalDocument('SOURCE-COST-ORDER', quantity: 2);
        $document['lines'][0]['unit_price'] = null;
        $document['lines'][0]['line_total'] = '246';
        $document['totals']['goods_subtotal'] = '246';
        $document['totals']['total_ex_tax'] = '246';
        $import = $this->manualImport(
            revision: $revision,
            profile: $profile,
            version: $version,
            actionKey: 'source-cost-finalize',
            fingerprintSeed: 'source-cost-finalize',
            document: $document,
        );
        $importLine = $this->importLine($import, $item);
        $importLine->forceFill([
            'unit_price' => null,
            'line_total' => 246,
        ])->save();
        $decision = new SupplierOrderPolicyDecision(
            SupplierOrderPolicyDecision::REGISTER_ORDERED,
            [],
            ['test' => true],
        );

        $purchaseOrder = app(FinalizeImportedPurchaseOrder::class)->handle(
            $import,
            $policy,
            $decision,
        );
        $line = $purchaseOrder?->lines()->firstOrFail();

        $this->assertNotNull($purchaseOrder);
        $this->assertSame('123.00', $line?->unit_cost);
        $this->assertSame('246.0000', data_get($line?->metadata, 'source_line_total'));
        $this->assertSame('line_total_divided_by_quantity', data_get($line?->metadata, 'source_unit_cost_basis'));
        $this->assertDatabaseCount('storage_movements', 0);
    }

    #[Test]
    public function deterministic_pipeline_registers_one_order_and_never_posts_inventory(): void
    {
        [$profile, $version] = $this->activeProfile();
        [, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            'default_outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            'ai_mode' => 'off',
        ]);
        $item = $this->item('NX-SYN-1001', quantity: 11);
        ItemVendor::query()->create([
            'item_id' => $item->id,
            'vendor_id' => $this->supplier->id,
            'vendor_sku' => 'NX-SYN-1001',
        ]);
        $snapshot = $this->sourceSnapshot();
        $created = app(CreatePurchaseOrderImport::class)->handle($this->importPayload(
            revision: $revision,
            actionKey: 'deterministic-end-to-end',
            snapshot: $snapshot,
            profile: $profile,
            version: $version,
        ));
        $before = $this->stockSnapshot($item);
        $process = app(ProcessPurchaseOrderImport::class);

        $processed = $process->handle($created['import']);
        $retried = $process->handle($processed->fresh());
        $duplicateCreated = app(CreatePurchaseOrderImport::class)->handle($this->importPayload(
            revision: $revision,
            actionKey: 'deterministic-end-to-end-duplicate',
            snapshot: $snapshot,
            profile: $profile,
            version: $version,
        ));
        $duplicate = $process->handle($duplicateCreated['import']);

        $this->assertSame(
            PurchaseOrderImport::STATUS_IMPORTED,
            $processed->status,
            StableJson::encode([
                'stage' => $processed->stage,
                'reason' => $processed->reason_context,
                'attempt_stages' => $processed->attempts()->pluck('stage')->all(),
            ]),
        );
        $this->assertSame('deterministic', $processed->extraction_method);
        $this->assertSame('9900000001', $processed->external_order_number);
        $this->assertNotNull($processed->purchase_order_id);
        $this->assertSame($processed->purchase_order_id, $retried->purchase_order_id);
        $this->assertNull($processed->locked_at);
        $finalizeAttempts = $processed->attempts()
            ->where('stage', PurchaseOrderImport::STAGE_FINALIZE)
            ->get();
        $this->assertSame(
            ['processing', PurchaseOrderImport::STATUS_IMPORTED],
            $finalizeAttempts->pluck('status')->all(),
        );
        $this->assertNull($finalizeAttempts->first()->completed_at);
        $this->assertNotNull($finalizeAttempts->last()->completed_at);
        $this->assertSame(PurchaseOrderImport::STATUS_DUPLICATE, $duplicate->status);
        $this->assertSame('duplicate_supplier_order', $duplicate->reason_code);
        $this->assertNull($duplicate->locked_at);
        $this->assertTrue($duplicate->attempts()->where('status', PurchaseOrderImport::STATUS_DUPLICATE)->exists());
        $this->assertDatabaseCount('storage_purchase_orders', 1);
        $this->assertDatabaseHas('storage_purchase_order_import_lines', [
            'import_id' => $processed->id,
            'item_id' => $item->id,
            'mapping_status' => PurchaseOrderImportLine::MAPPING_RESOLVED,
        ]);
        $this->assertSame($before, $this->stockSnapshot($item));
    }

    #[Test]
    public function shadow_pipeline_records_validation_without_creating_supplier_items_or_orders(): void
    {
        [$profile, $version] = $this->activeProfile();
        $profile->forceFill(['vendor_id' => null])->save();
        [, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_SHADOW,
            'supplier_bootstrap_mode' => 'create_active',
            'new_item_mode' => 'create_active_item',
            'max_new_items' => 10,
            'ai_mode' => 'off',
        ]);
        $created = app(CreatePurchaseOrderImport::class)->handle($this->importPayload(
            revision: $revision,
            actionKey: 'shadow-no-domain-writes',
            snapshot: $this->sourceSnapshot(),
            profile: $profile->fresh(),
            version: $version,
        ));
        $before = [
            'vendors' => Vendor::query()->count(),
            'items' => Item::query()->count(),
            'item_vendors' => ItemVendor::query()->count(),
            'purchase_orders' => PurchaseOrder::query()->count(),
        ];

        $processed = app(ProcessPurchaseOrderImport::class)->handle($created['import']);

        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $processed->status);
        $this->assertSame(SupplierOrderPolicyDecision::SHADOW_COMPLETE, $processed->decision);
        $this->assertSame(SupplierOrderPolicyDecision::SHADOW_COMPLETE, $processed->reason_code);
        $this->assertSame(PurchaseOrderImport::STAGE_POLICY, $processed->stage);
        $this->assertSame(1, $processed->lines()->count());
        $this->assertSame(0, data_get($processed->confidence_dimensions, 'item_identity'));
        $this->assertSame('shadow_item_resolution_skipped', data_get(
            $processed->reason_context,
            'item_resolution.reason_codes.0',
        ));
        $this->assertSame($before, [
            'vendors' => Vendor::query()->count(),
            'items' => Item::query()->count(),
            'item_vendors' => ItemVendor::query()->count(),
            'purchase_orders' => PurchaseOrder::query()->count(),
        ]);
        $this->assertDatabaseCount('storage_purchase_receipts', 0);
        $this->assertDatabaseCount('storage_purchase_receipt_lines', 0);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertDatabaseCount('storage_stock_units', 0);
    }

    private function manualPurchaseOrder(
        Item $item,
        string $poNumber,
        ?string $vendorRef,
        int $quantity = 2,
        string $status = PurchaseOrder::STATUS_ORDERED,
        ?Vendor $supplier = null,
    ): PurchaseOrder {
        $supplier ??= $this->supplier;

        return app(StorePurchaseOrder::class)->handle([
            'po_number' => $poNumber,
            'vendor_id' => $supplier->id,
            'deliver_to_warehouse_id' => $this->warehouse->id,
            'status' => $status,
            'vendor_ref' => $vendorRef,
            'ordered_at' => '2026-08-05',
            'currency' => 'NOK',
            'lines' => [[
                'item_id' => $item->id,
                'qty_ordered' => $quantity,
                'supplier_sku' => 'SUP-100',
                'unit_cost' => 100,
                'tax_rate' => null,
            ]],
        ], $this->actor);
    }

    /** @return array{0: PurchaseOrderAutomationPolicy, 1: PurchaseOrderAutomationPolicyRevision} */
    private function policy(array $overrides = []): array
    {
        $policy = PurchaseOrderAutomationPolicy::query()->create($overrides + [
            'name' => 'Supplier import test policy',
            'is_current' => true,
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            'default_outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            'automation_user_id' => $this->actor->id,
            'default_warehouse_id' => $this->warehouse->id,
            'ai_mode' => 'off',
            'supplier_bootstrap_mode' => 'existing_only',
            'new_item_mode' => 'review_only',
            'deterministic_confidence_threshold' => 100,
            'ai_confidence_threshold' => 98,
            'max_new_items' => 0,
            'advanced_rules' => [],
            'revision_number' => 1,
        ]);
        $policy->refresh();
        $snapshot = $policy->revisionSnapshot();
        $revision = $policy->revisions()->create([
            'revision_number' => 1,
            'snapshot' => $snapshot,
            'checksum' => StableJson::checksum($snapshot),
            'reason' => 'Test policy.',
            'created_by' => $this->actor->id,
            'activated_at' => now(),
        ]);

        return [$policy, $revision];
    }

    /** @return array{0: PurchaseOrderImportProfile, 1: PurchaseOrderImportProfileVersion} */
    private function activeProfile(): array
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $profile = PurchaseOrderImportProfile::query()->create([
            'vendor_id' => $this->supplier->id,
            'name' => 'Itegra supplier orders',
            'slug' => 'itegra-'.str()->lower(str()->random(8)),
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
            'priority' => 10,
            'matching_scope' => SupplierOrderProfileFactoryData::itegraMatchingScope(),
            'policy_overrides' => [],
            'health_state' => 'healthy',
        ]);
        $version = PurchaseOrderImportProfileVersion::query()->create([
            'profile_id' => $profile->id,
            'version_number' => 1,
            'schema_version' => SupplierOrderProfileDefinitionValidator::SCHEMA_VERSION,
            'status' => PurchaseOrderImportProfileVersion::STATUS_ACTIVE,
            'definition' => $definition,
            'checksum' => StableJson::checksum($definition),
            'source' => 'test',
        ]);
        $profile->forceFill(['active_version_id' => $version->id])->save();

        return [$profile->fresh(), $version->fresh()];
    }

    private function manualImport(
        PurchaseOrderAutomationPolicyRevision $revision,
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        string $actionKey,
        string $fingerprintSeed,
        array $document,
        bool $trusted = true,
    ): PurchaseOrderImport {
        $snapshot = $this->sourceSnapshot($trusted);
        $snapshot['message_id'] = 'synthetic-'.$fingerprintSeed;
        $payload = $this->importPayload($revision, $actionKey, $snapshot, $profile, $version);
        $import = app(CreatePurchaseOrderImport::class)->handle($payload)['import'];
        $import->forceFill([
            'vendor_id' => $this->supplier->id,
            'external_order_number' => $document['external_order_number'],
            'normalized_document' => $document,
            'extraction_method' => 'deterministic',
            'confidence_dimensions' => [
                'source_trust' => $trusted ? 100 : 0,
                'document_identity' => 100,
                'extraction_evidence' => 100,
                'item_identity' => 100,
                'deterministic_validation' => 100,
            ],
        ])->save();

        return $import->fresh(['profile', 'profileVersion', 'vendor']);
    }

    private function importLine(
        PurchaseOrderImport $import,
        ?Item $item = null,
        string $mappingStatus = PurchaseOrderImportLine::MAPPING_RESOLVED,
    ): PurchaseOrderImportLine {
        $sourceLine = (array) data_get($import->normalized_document, 'lines.0', []);

        return PurchaseOrderImportLine::query()->create([
            'import_id' => $import->id,
            'position' => 1,
            'source_row_identifier' => $sourceLine['source_row_identifier'] ?? 'line-1',
            'supplier_sku' => $sourceLine['supplier_sku'] ?? 'SUP-100',
            'normalized_supplier_sku' => SupplierSkuIdentity::normalize(
                $sourceLine['supplier_sku'] ?? 'SUP-100',
            ),
            'description' => $sourceLine['description'] ?? 'Supplier item',
            'quantity' => $sourceLine['quantity'] ?? 2,
            'unit_price' => $sourceLine['unit_price'] ?? null,
            'line_total' => $sourceLine['line_total'] ?? null,
            'tax_rate' => $sourceLine['tax_rate'] ?? null,
            'currency' => strtoupper((string) ($sourceLine['currency'] ?? 'NOK')),
            'evidence' => $sourceLine['evidence'] ?? $this->lineEvidence(),
            'extracted_fields' => $sourceLine,
            'field_confidence' => $sourceLine['confidence'] ?? [],
            'item_id' => $item?->id,
            'mapping_status' => $mappingStatus,
            'resolution_method' => $item ? 'test_exact' : null,
            'resolved_by' => $item ? $this->actor->id : null,
            'resolved_at' => $item ? now() : null,
        ]);
    }

    private function item(string $sku, int $quantity = 0): Item
    {
        return Item::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'primary_vendor_id' => $this->supplier->id,
            'sku' => $sku,
            'name' => $sku.' Item',
            'qty_on_hand' => $quantity,
            'qty_reserved' => 0,
            'reorder_point' => 0,
            'target_level' => 0,
            'can_be_ordered' => true,
            'status' => 'active',
        ]);
    }

    private function canonicalDocument(string $externalOrder, int $quantity = 2): array
    {
        return [
            'schema_version' => 'storage.supplier_order.v1',
            'document_type' => 'supplier_order_confirmation',
            'supplier' => ['name' => $this->supplier->name],
            'external_order_number' => $externalOrder,
            'ordered_at' => '2026-08-05',
            'ordered_at_provenance' => 'explicit',
            'currency' => 'NOK',
            'buyer_reference' => null,
            'supplier_po_reference' => null,
            'destination_warehouse_id' => $this->warehouse->id,
            'delivery' => [
                'method' => 'Stykkgods NO',
                'address' => null,
                'expected_at' => null,
            ],
            'lines' => [[
                'source_row_identifier' => 'line-1',
                'supplier_sku' => 'SUP-100',
                'description' => 'Supplier item',
                'quantity' => $quantity,
                'unit_price' => '100.00',
                'line_total' => (string) ($quantity * 100),
                'tax_rate' => null,
                'currency' => 'NOK',
                'evidence' => $this->lineEvidence(),
            ]],
            'totals' => [
                'goods_subtotal' => (string) ($quantity * 100),
                'freight' => '0',
                'discount' => '0',
                'other_charges' => '0',
                'tax_total' => null,
                'total_ex_tax' => (string) ($quantity * 100),
                'total_inc_tax' => null,
            ],
            'evidence' => [
                'supplier' => ['name' => $this->anchor('Itegra')],
                'external_order_number' => $this->anchor($externalOrder),
            ],
            'unknown_fields' => [],
        ];
    }

    private function lineEvidence(): array
    {
        return [
            'supplier_sku' => $this->anchor('SUP-100'),
            'description' => $this->anchor('Supplier item'),
            'quantity' => $this->anchor('2'),
            'unit_price' => $this->anchor('100.00'),
            'line_total' => $this->anchor('200.00'),
        ];
    }

    private function anchor(string $quote): array
    {
        return ['block_id' => 'b0001', 'quote' => $quote];
    }

    private function importPayload(
        PurchaseOrderAutomationPolicyRevision $revision,
        string $actionKey,
        array $snapshot,
        ?PurchaseOrderImportProfile $profile = null,
        ?PurchaseOrderImportProfileVersion $version = null,
    ): array {
        return [
            'source_domain' => 'email',
            'source_type' => 'supplier-order-test-fixture',
            'source_id' => $actionKey,
            'signal_action_key' => $actionKey,
            'source_fingerprint' => StableJson::checksum($snapshot),
            'safe_source_snapshot' => $snapshot,
            'trusted_auth_snapshot' => $snapshot['trusted_auth'],
            'profile_id' => $profile?->id,
            'profile_version_id' => $version?->id,
            'policy_revision_id' => $revision->id,
            'status' => PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
            'requested_by' => $this->actor->id,
        ];
    }

    private function sourceSnapshot(bool $trusted = true): array
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
                'authentication_passed' => $trusted,
                'authenticated_supplier_identity' => 'synthetic-fixture@itegra.no',
                'authenticated_supplier_domain' => 'itegra.no',
                'authserv_id' => 'mail.nexum.test',
                'spf' => $trusted ? 'pass' : 'fail',
                'dkim' => $trusted ? 'pass' : 'fail',
                'dmarc' => $trusted ? 'pass' : 'fail',
                'aligned' => $trusted,
            ],
        ];
    }

    private function stockSnapshot(Item $item): array
    {
        return [
            'qty_on_hand' => $item->refresh()->qty_on_hand,
            'receipts' => DB::table('storage_purchase_receipts')->count(),
            'movements' => DB::table('storage_movements')->count(),
            'stock_units' => DB::table('storage_stock_units')->count(),
        ];
    }

    private function assertNoProcurementOrStockSideEffects(): void
    {
        $this->assertDatabaseCount('storage_items', 0);
        $this->assertDatabaseCount('storage_purchase_orders', 0);
        $this->assertDatabaseCount('storage_purchase_receipts', 0);
        $this->assertDatabaseCount('storage_movements', 0);
        $this->assertDatabaseCount('storage_stock_units', 0);
    }
}
