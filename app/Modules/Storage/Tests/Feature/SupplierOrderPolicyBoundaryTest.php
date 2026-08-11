<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Documentation\Models\Vendor;
use App\Modules\Storage\Actions\EvaluateSupplierOrderImportPolicy;
use App\Modules\Storage\Actions\ResolveEffectivePurchaseOrderAutomationPolicy;
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
use App\Modules\Storage\Support\SupplierOrderPolicyDecision;
use App\Modules\Storage\Support\SupplierOrderProfileDefinitionValidator;
use App\Modules\Storage\Support\SupplierOrderProfileFactoryData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierOrderPolicyBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Warehouse $warehouse;

    private Vendor $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('storage.purchase_manage', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo('storage.purchase_manage');
        $this->warehouse = Warehouse::query()->create([
            'name' => 'Policy Boundary Warehouse',
            'code' => 'POLICY-BOUNDARY',
            'is_active' => true,
        ]);
        $this->supplier = Vendor::query()->create([
            'name' => 'Policy Boundary Supplier',
            'vendor_code' => 'POLICY-BOUNDARY',
            'is_supplier' => true,
            'is_active' => true,
        ]);
    }

    #[Test]
    public function profile_policy_can_narrow_every_supported_automation_dimension(): void
    {
        [$policy, $revision] = $this->policy();
        [$profile, $version] = $this->activeProfile([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'default_outcome' => SupplierOrderPolicyDecision::CREATE_DRAFT,
            'ai_mode' => 'fallback',
            'ai_profile_learning_mode' => 'propose',
            'ai_profile_shadow_samples' => 5,
            'provider_outage_behavior' => 'needs_attention',
            'deterministic_confidence_threshold' => 99,
            'ai_confidence_threshold' => 100,
            'amount_tolerance' => 0.01,
            'max_lines' => 25,
            'max_quantity_per_line' => 20,
            'max_order_total' => 1000,
            'max_new_items' => 1,
            'supplier_bootstrap_mode' => 'existing_only',
            'new_item_mode' => 'review_only',
            'retry_limit' => 1,
            'retry_base_seconds' => 300,
            'ai_timeout_seconds' => 10,
            'ai_max_output_tokens' => 1000,
            'ai_max_cost_per_import' => 0.25,
            'circuit_breaker_failures' => 2,
        ]);
        $import = $this->import($revision, $profile, $version, 'narrow-policy');

        $effective = app(ResolveEffectivePurchaseOrderAutomationPolicy::class)->handle(
            $import,
            $policy,
            $profile,
            $version,
        );

        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_REVIEW, $effective->runtime_mode);
        $this->assertSame(SupplierOrderPolicyDecision::CREATE_DRAFT, $effective->default_outcome);
        $this->assertSame('fallback', $effective->ai_mode);
        $this->assertSame('propose', $effective->ai_profile_learning_mode);
        $this->assertSame(5, $effective->ai_profile_shadow_samples);
        $this->assertSame('needs_attention', $effective->provider_outage_behavior);
        $this->assertSame(99, $effective->deterministic_confidence_threshold);
        $this->assertSame(100, $effective->ai_confidence_threshold);
        $this->assertSame('0.0100', $effective->amount_tolerance);
        $this->assertSame(25, $effective->max_lines);
        $this->assertSame(20, $effective->max_quantity_per_line);
        $this->assertSame('1000.00', $effective->max_order_total);
        $this->assertSame(1, $effective->max_new_items);
        $this->assertSame('existing_only', $effective->supplier_bootstrap_mode);
        $this->assertSame('review_only', $effective->new_item_mode);
        $this->assertSame(1, $effective->retry_limit);
        $this->assertSame(300, $effective->retry_base_seconds);
        $this->assertSame(10, $effective->ai_timeout_seconds);
        $this->assertSame(1000, $effective->ai_max_output_tokens);
        $this->assertSame('0.2500', $effective->ai_max_cost_per_import);
        $this->assertSame(2, $effective->circuit_breaker_failures);
    }

    #[Test]
    public function permissive_profile_values_never_widen_restrictive_global_policy(): void
    {
        [$policy, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'default_outcome' => SupplierOrderPolicyDecision::CREATE_DRAFT,
            'ai_mode' => 'off',
            'ai_profile_learning_mode' => 'off',
            'provider_outage_behavior' => 'needs_attention',
            'deterministic_confidence_threshold' => 100,
            'ai_confidence_threshold' => 100,
            'amount_tolerance' => 0.01,
            'max_lines' => 10,
            'max_quantity_per_line' => 5,
            'max_order_total' => 500,
            'max_new_items' => 0,
            'supplier_bootstrap_mode' => 'existing_only',
            'new_item_mode' => 'review_only',
            'retry_limit' => 1,
        ]);
        [$profile, $version] = $this->activeProfile([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
            'default_outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            'ai_mode' => 'always',
            'ai_profile_learning_mode' => 'auto_activate',
            'provider_outage_behavior' => 'deterministic_only',
            'deterministic_confidence_threshold' => 50,
            'ai_confidence_threshold' => 50,
            'amount_tolerance' => 5,
            'max_lines' => 500,
            'max_quantity_per_line' => 1000,
            'max_order_total' => 500000,
            'max_new_items' => 100,
            'supplier_bootstrap_mode' => 'create_active',
            'new_item_mode' => 'create_active_item',
            'retry_limit' => 20,
        ]);
        $import = $this->import($revision, $profile, $version, 'cannot-widen');

        $effective = app(ResolveEffectivePurchaseOrderAutomationPolicy::class)->handle(
            $import,
            $policy,
            $profile,
            $version,
        );

        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_REVIEW, $effective->runtime_mode);
        $this->assertSame(SupplierOrderPolicyDecision::CREATE_DRAFT, $effective->default_outcome);
        $this->assertSame('off', $effective->ai_mode);
        $this->assertSame('off', $effective->ai_profile_learning_mode);
        $this->assertSame('needs_attention', $effective->provider_outage_behavior);
        $this->assertSame(100, $effective->deterministic_confidence_threshold);
        $this->assertSame(100, $effective->ai_confidence_threshold);
        $this->assertSame('0.0100', $effective->amount_tolerance);
        $this->assertSame(10, $effective->max_lines);
        $this->assertSame(5, $effective->max_quantity_per_line);
        $this->assertSame('500.00', $effective->max_order_total);
        $this->assertSame(0, $effective->max_new_items);
        $this->assertSame('existing_only', $effective->supplier_bootstrap_mode);
        $this->assertSame('review_only', $effective->new_item_mode);
        $this->assertSame(1, $effective->retry_limit);
    }

    #[Test]
    public function effective_policy_snapshot_is_checksummed_and_remains_pinned_across_retry(): void
    {
        [$policy, $revision] = $this->policy();
        [$profile, $version] = $this->activeProfile(['max_lines' => 25]);
        $import = $this->import($revision, $profile, $version, 'pinned-policy');
        $resolver = app(ResolveEffectivePurchaseOrderAutomationPolicy::class);

        $first = $resolver->handle($import, $policy, $profile, $version);
        $pinned = $import->fresh();
        $snapshot = $pinned->effective_policy_snapshot;
        $checksum = $pinned->effective_policy_checksum;
        $this->assertSame(StableJson::checksum($snapshot), $checksum);
        $this->assertSame(25, $first->max_lines);

        $policy->forceFill(['max_lines' => 5, 'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_OFF])->save();
        $profile->forceFill(['policy_overrides' => ['max_lines' => 2]])->save();
        $second = $resolver->handle($pinned->fresh(), $policy->fresh(), $profile->fresh(), $version);

        $this->assertSame(25, $second->max_lines);
        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI, $second->runtime_mode);
        $this->assertSame($snapshot, $pinned->fresh()->effective_policy_snapshot);
        $this->assertSame($checksum, $pinned->fresh()->effective_policy_checksum);

        $tampered = $pinned->fresh();
        $tamperedSnapshot = $tampered->effective_policy_snapshot;
        data_set($tamperedSnapshot, 'policy.max_lines', 999);
        $tampered->forceFill(['effective_policy_snapshot' => $tamperedSnapshot])->save();

        $this->expectException(ValidationException::class);
        $resolver->handle($tampered->fresh(), $policy->fresh(), $profile->fresh(), $version);
    }

    #[Test]
    public function pinned_policy_revision_checksum_is_verified_before_hydration(): void
    {
        [, $revision] = $this->policy();
        DB::table('storage_purchase_order_automation_policy_revisions')
            ->where('id', $revision->id)
            ->update(['checksum' => str_repeat('f', 64)]);

        try {
            app(ResolveEffectivePurchaseOrderAutomationPolicy::class)
                ->fromPinnedRevision($revision->fresh());
            $this->fail('A tampered policy revision was hydrated.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('policy', $exception->errors());
        }
    }

    #[Test]
    public function advanced_rules_can_only_narrow_the_configured_outcome(): void
    {
        [$policy, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            'advanced_rules' => [[
                'fact' => 'order_total',
                'operator' => 'greater_than',
                'value' => 100,
                'outcome' => SupplierOrderPolicyDecision::CREATE_DRAFT,
            ]],
        ]);
        [$profile, $version] = $this->activeProfile();
        $import = $this->import($revision, $profile, $version, 'advanced-rule');

        $decision = $this->evaluate($import, $policy);

        $this->assertSame(SupplierOrderPolicyDecision::CREATE_DRAFT, $decision->outcome);
        $this->assertSame([0], $decision->facts['matched_advanced_rules']);

        $derivedDocument = $import->normalized_document;
        unset($derivedDocument['totals']['total_ex_tax']);
        $import->forceFill(['normalized_document' => $derivedDocument])->save();
        $derived = $this->evaluate($import->fresh(), $policy);

        $this->assertSame(200.0, $derived->facts['order_total']);
        $this->assertSame(0.0, $derived->facts['variance']);
        $this->assertSame(SupplierOrderPolicyDecision::CREATE_DRAFT, $derived->outcome);

        $policy->forceFill([
            'default_outcome' => SupplierOrderPolicyDecision::CREATE_DRAFT,
            'advanced_rules' => [[
                'fact' => 'order_total',
                'operator' => 'greater_than',
                'value' => 100,
                'outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            ]],
        ])->save();
        $notWidened = $this->evaluate($import->fresh(), $policy->fresh());

        $this->assertSame(SupplierOrderPolicyDecision::CREATE_DRAFT, $notWidened->outcome);
    }

    #[Test]
    public function deterministic_success_is_not_lowered_by_absent_ai_confidence(): void
    {
        [$policy, $revision] = $this->policy([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            'ai_mode' => 'off',
        ]);
        [$profile, $version] = $this->activeProfile();
        $import = $this->import($revision, $profile, $version, 'deterministic-confidence');
        $this->assertArrayNotHasKey('ai_result_validity', $import->confidence_dimensions);

        $decision = $this->evaluate($import, $policy);

        $this->assertSame(SupplierOrderPolicyDecision::REGISTER_ORDERED, $decision->outcome);
        $this->assertSame(100, $decision->facts['weakest_critical_confidence']);
        $this->assertNotContains('deterministic_confidence_below_threshold', $decision->reasonCodes);
    }

    private function evaluate(
        PurchaseOrderImport $import,
        PurchaseOrderAutomationPolicy $policy,
    ): SupplierOrderPolicyDecision {
        return app(EvaluateSupplierOrderImportPolicy::class)->handle(
            $import->fresh(['profile', 'profileVersion', 'vendor', 'lines']),
            $policy,
            new CanonicalSupplierOrderValidationResult([], [], [
                'document_identity' => 100,
                'extraction_evidence' => 100,
                'deterministic_validation' => 100,
            ]),
            new SupplierItemResolutionSummary(1, 0, 0, 0, 0, []),
        );
    }

    /** @return array{0: PurchaseOrderAutomationPolicy, 1: PurchaseOrderAutomationPolicyRevision} */
    private function policy(array $overrides = []): array
    {
        $policy = PurchaseOrderAutomationPolicy::query()->create($overrides + [
            'name' => 'Policy boundary test',
            'is_current' => true,
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
            'default_outcome' => SupplierOrderPolicyDecision::REGISTER_ORDERED,
            'automation_user_id' => $this->actor->id,
            'default_warehouse_id' => $this->warehouse->id,
            'ai_mode' => 'always',
            'ai_profile_learning_mode' => 'auto_activate',
            'ai_profile_shadow_samples' => 1,
            'provider_outage_behavior' => 'deterministic_only',
            'deterministic_confidence_threshold' => 95,
            'ai_confidence_threshold' => 98,
            'amount_tolerance' => 1,
            'max_lines' => 100,
            'max_quantity_per_line' => 100,
            'max_order_total' => 10000,
            'max_new_items' => 10,
            'supplier_bootstrap_mode' => 'create_active',
            'new_item_mode' => 'create_active_item',
            'retry_limit' => 5,
            'retry_base_seconds' => 60,
            'ai_timeout_seconds' => 30,
            'ai_max_output_tokens' => 4000,
            'ai_max_cost_per_import' => 1,
            'circuit_breaker_failures' => 5,
            'advanced_rules' => [],
            'revision_number' => 1,
        ]);
        $policy->refresh();
        $snapshot = $policy->revisionSnapshot();
        $revision = $policy->revisions()->create([
            'revision_number' => 1,
            'snapshot' => $snapshot,
            'checksum' => StableJson::checksum($snapshot),
            'reason' => 'Policy boundary test.',
            'created_by' => $this->actor->id,
            'activated_at' => now(),
        ]);

        return [$policy, $revision];
    }

    /** @return array{0: PurchaseOrderImportProfile, 1: PurchaseOrderImportProfileVersion} */
    private function activeProfile(array $overrides = []): array
    {
        $definition = SupplierOrderProfileFactoryData::itegra();
        $profile = PurchaseOrderImportProfile::query()->create([
            'vendor_id' => $this->supplier->id,
            'name' => 'Policy boundary profile',
            'slug' => 'policy-boundary-'.str()->lower(str()->random(8)),
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_ACTIVE,
            'priority' => 10,
            'matching_scope' => SupplierOrderProfileFactoryData::itegraMatchingScope(),
            'policy_overrides' => $overrides,
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

    private function import(
        PurchaseOrderAutomationPolicyRevision $revision,
        PurchaseOrderImportProfile $profile,
        PurchaseOrderImportProfileVersion $version,
        string $key,
    ): PurchaseOrderImport {
        $document = [
            'destination_warehouse_id' => $this->warehouse->id,
            'currency' => 'NOK',
            'totals' => [
                'goods_subtotal' => '200.00',
                'freight' => '0.00',
                'discount' => '0.00',
                'other_charges' => '0.00',
                'total_ex_tax' => '200.00',
            ],
        ];
        $import = PurchaseOrderImport::query()->create([
            'source_domain' => 'email',
            'source_type' => 'policy-boundary-test',
            'source_id' => $key,
            'signal_action_key' => $key,
            'source_action_hash' => hash('sha256', 'action-'.$key),
            'source_fingerprint' => hash('sha256', 'source-'.$key),
            'safe_source_snapshot' => ['subject' => 'Policy boundary test'],
            'trusted_auth_snapshot' => [
                'authentication_passed' => true,
                'aligned' => true,
            ],
            'vendor_id' => $this->supplier->id,
            'profile_id' => $profile->id,
            'profile_version_id' => $version->id,
            'external_order_number' => 'ORDER-'.$key,
            'policy_revision_id' => $revision->id,
            'status' => PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_POLICY,
            'extraction_method' => 'deterministic',
            'normalized_document' => $document,
            'confidence_dimensions' => [
                'source_trust' => 100,
                'document_identity' => 100,
                'extraction_evidence' => 100,
                'item_identity' => 100,
                'deterministic_validation' => 100,
            ],
        ]);
        PurchaseOrderImportLine::query()->create([
            'import_id' => $import->id,
            'position' => 1,
            'supplier_sku' => 'POLICY-ITEM',
            'description' => 'Policy Item',
            'quantity' => 1,
            'mapping_status' => PurchaseOrderImportLine::MAPPING_RESOLVED,
        ]);

        return $import->fresh(['profile', 'profileVersion', 'vendor', 'lines']);
    }
}
