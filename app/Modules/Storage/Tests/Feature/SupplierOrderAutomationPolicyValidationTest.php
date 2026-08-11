<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiAgentGovernancePolicy;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiProviderGovernanceProfile;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Storage\Actions\GetCurrentPurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\UpdatePurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupplierOrderAutomationPolicyValidationTest extends TestCase
{
    use RefreshDatabase;

    private User $policyManager;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'storage.purchase_import_policy_manage',
            'storage.purchase_manage',
            'documentation.create',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->policyManager = $this->activeUser();
        $this->policyManager->givePermissionTo('storage.purchase_import_policy_manage');
    }

    #[Test]
    public function item_creation_uses_the_protected_system_actor_without_user_selection(): void
    {
        foreach (['create_review_item', 'create_active_item'] as $mode) {
            $policy = $this->update($this->policyData(['new_item_mode' => $mode]));
            $actor = $policy->automationUser;

            $this->assertSame($mode, $policy->new_item_mode);
            $this->assertTrue($actor->isSystemActor());
            $this->assertSame(User::STATUS_DISABLED, $actor->status);
            $this->assertTrue($actor->can('storage.purchase_manage'));
        }
    }

    #[Test]
    public function browser_supplied_automation_user_is_ignored(): void
    {
        $disabledActor = User::factory()->create(['status' => User::STATUS_DISABLED]);
        $disabledActor->givePermissionTo('storage.purchase_manage');

        $policy = $this->update($this->policyData([
            'automation_user_id' => $disabledActor->id,
            'new_item_mode' => 'create_review_item',
        ]));

        $this->assertNotSame($disabledActor->id, $policy->automation_user_id);
        $this->assertTrue($policy->automationUser->isSystemActor());
    }

    #[Test]
    public function repeated_updates_reuse_one_least_privilege_system_actor(): void
    {
        $first = $this->update($this->policyData());
        $second = $this->update($this->policyData());
        $actor = $second->automationUser;

        $this->assertSame($first->automation_user_id, $second->automation_user_id);
        $this->assertSame('storage_supplier_order_automation', $actor->system_actor_key);
        $this->assertSame([], $actor->roles()->pluck('name')->all());
        $this->assertEqualsCanonicalizing([
            'documentation.create',
            'storage.purchase_import_profile_manage',
            'storage.purchase_manage',
        ], $actor->permissions()->pluck('name')->all());
    }

    #[Test]
    public function supplier_bootstrap_uses_the_same_managed_authority(): void
    {
        foreach (['review_candidate', 'create_active'] as $mode) {
            $policy = $this->update($this->policyData(['supplier_bootstrap_mode' => $mode]));

            $this->assertSame($mode, $policy->supplier_bootstrap_mode);
            $this->assertTrue($policy->automationUser->can('documentation.create'));
        }
    }

    #[Test]
    public function managed_ai_workload_isolated_from_storage_agent_capabilities(): void
    {
        [, $agent] = $this->governedWorkload();
        $agent->update([
            'default_domains' => ['storage'],
            'can_execute_actions' => true,
            'data_sources' => ['customer-records'],
            'allowed_tools' => ['write-something'],
            'allowed_api_scopes' => ['orders.write'],
        ]);

        $policy = $this->update($this->policyData([
            'ai_agent_id' => $agent->id,
            'ai_mode' => 'fallback',
        ]));
        $workload = $policy->aiWorkloadProfile;

        $this->assertSame($agent->id, $policy->ai_agent_id);
        $this->assertSame($workload->id, $policy->ai_workload_profile_id);
        $this->assertSame(AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS, $workload->managed_by);
        $this->assertSame([], $workload->abilities);
        $this->assertSame([], $workload->bindings()->get()->all());
        $this->assertSame('privacy_relay', $workload->processing_mode);
        $this->assertSame('pseudonymized', $workload->maximum_data_profile);
    }

    #[Test]
    public function ai_cost_limit_requires_an_explicit_iso_currency(): void
    {
        try {
            $this->update($this->policyData([
                'ai_max_cost_per_import' => 0.25,
                'ai_cost_currency' => null,
            ]));

            $this->fail('A cost limit without currency was accepted.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ai_cost_currency', $exception->errors());
        }

        $policy = $this->update($this->policyData([
            'ai_max_cost_per_import' => 0.25,
            'ai_cost_currency' => 'USD',
        ]));

        $this->assertSame('0.2500', $policy->ai_max_cost_per_import);
        $this->assertSame('USD', $policy->ai_cost_currency);
    }

    #[Test]
    public function required_consensus_uses_a_distinct_governed_workload(): void
    {
        [, $primaryAgent] = $this->governedWorkload();
        $primaryAgent->update(['default_domains' => ['storage']]);
        [, , $secondary] = $this->governedWorkload();
        $first = $this->update($this->policyData([
            'ai_agent_id' => $primaryAgent->id,
            'ai_mode' => 'fallback',
        ]));
        $managedPrimary = $first->aiWorkloadProfile;

        try {
            $this->update($this->policyData([
                'ai_agent_id' => $primaryAgent->id,
                'ai_mode' => 'fallback',
                'ai_consensus_mode' => 'required',
                'ai_consensus_workload_profile_id' => $managedPrimary->id,
            ]));

            $this->fail('The primary workload was accepted as its own consensus verifier.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('ai_consensus_workload_profile_id', $exception->errors());
        }

        $policy = $this->update($this->policyData([
            'ai_agent_id' => $primaryAgent->id,
            'ai_mode' => 'fallback',
            'ai_consensus_mode' => 'required',
            'ai_consensus_workload_profile_id' => $secondary->id,
        ]));

        $this->assertSame('required', $policy->ai_consensus_mode);
        $this->assertSame($secondary->id, $policy->ai_consensus_workload_profile_id);
    }

    #[Test]
    public function ordinary_admin_save_uses_server_owned_defaults_and_ignores_forged_technical_values(): void
    {
        [, $agent, $forgedConsensus] = $this->governedWorkload();
        $agent->update(['default_domains' => ['storage']]);
        $this->policyManager->assignRole(Role::findOrCreate('Admin', 'web'));

        $current = app(GetCurrentPurchaseOrderAutomationPolicy::class)->handle()['policy'];
        $payload = Arr::except($this->policyData([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'ai_agent_id' => $agent->id,
            'ai_mode' => 'fallback',
        ]), [
            'default_outcome',
            'ai_profile_learning_mode',
            'ai_profile_shadow_samples',
            'provider_outage_behavior',
            'deterministic_confidence_threshold',
            'ai_confidence_threshold',
            'ai_timeout_seconds',
            'ai_max_output_tokens',
            'ai_max_cost_per_import',
            'ai_cost_currency',
            'ai_consensus_mode',
            'ai_consensus_workload_profile_id',
            'retry_limit',
            'retry_base_seconds',
            'circuit_breaker_failures',
            'retention_days',
            'advanced_rules',
        ]);
        $payload += [
            'default_outcome' => 'register_ordered',
            'ai_profile_learning_mode' => 'off',
            'ai_profile_shadow_samples' => 25,
            'provider_outage_behavior' => 'deterministic_only',
            'deterministic_confidence_threshold' => 1,
            'ai_confidence_threshold' => 1,
            'ai_timeout_seconds' => 180,
            'ai_max_output_tokens' => 1,
            'ai_max_cost_per_import' => 999,
            'ai_cost_currency' => 'USD',
            'ai_consensus_mode' => 'required',
            'ai_consensus_workload_profile_id' => $forgedConsensus->id,
            'retry_limit' => 20,
            'retry_base_seconds' => 86400,
            'circuit_breaker_failures' => 100,
            'retention_days' => 3650,
            'advanced_rules' => 'forged',
        ];

        $this->actingAs($this->policyManager)
            ->put(route('tech.admin.settings.storage.purchase-order-automation.update'), $payload)
            ->assertRedirect(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertSessionHasNoErrors();

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->sole();
        $this->assertSame($current->revision_number + 1, $policy->revision_number);
        $this->assertSame('needs_attention', $policy->default_outcome);
        $this->assertSame('auto_activate', $policy->ai_profile_learning_mode);
        $this->assertSame(1, $policy->ai_profile_shadow_samples);
        $this->assertSame('needs_attention', $policy->provider_outage_behavior);
        $this->assertSame(100, $policy->deterministic_confidence_threshold);
        $this->assertSame(98, $policy->ai_confidence_threshold);
        $this->assertSame(150, $policy->ai_timeout_seconds);
        $this->assertSame(12000, $policy->ai_max_output_tokens);
        $this->assertNull($policy->ai_max_cost_per_import);
        $this->assertNull($policy->ai_cost_currency);
        $this->assertSame('off', $policy->ai_consensus_mode);
        $this->assertNull($policy->ai_consensus_workload_profile_id);
        $this->assertSame(3, $policy->retry_limit);
        $this->assertSame(60, $policy->retry_base_seconds);
        $this->assertSame(5, $policy->circuit_breaker_failures);
        $this->assertSame(730, $policy->retention_days);
        $this->assertSame([], $policy->advanced_rules);
        $this->assertSame(AiWorkloadProfile::MANAGED_BY_STORAGE_SUPPLIER_ORDERS, $policy->aiWorkloadProfile->managed_by);
    }

    #[Test]
    public function ordinary_admin_save_disables_profile_learning_when_ai_is_off(): void
    {
        $this->policyManager->assignRole(Role::findOrCreate('Admin', 'web'));
        $payload = $this->policyData([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'ai_mode' => 'off',
            'ai_agent_id' => null,
            'ai_profile_learning_mode' => 'auto_activate',
            'ai_profile_shadow_samples' => 25,
        ]);

        $this->actingAs($this->policyManager)
            ->put(route('tech.admin.settings.storage.purchase-order-automation.update'), $payload)
            ->assertRedirect(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertSessionHasNoErrors();

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->sole();
        $this->assertSame('off', $policy->ai_mode);
        $this->assertSame('off', $policy->ai_profile_learning_mode);
        $this->assertSame(1, $policy->ai_profile_shadow_samples);
    }

    #[Test]
    public function automatic_profile_only_submission_with_ai_enabled_is_normalized_to_verified_ai(): void
    {
        [, $agent] = $this->governedWorkload();
        $agent->update(['default_domains' => ['storage']]);
        $warehouse = Warehouse::query()->create([
            'name' => 'Automatic policy warehouse',
            'code' => 'AUTO-POLICY',
            'is_active' => true,
        ]);
        $this->policyManager->assignRole(Role::findOrCreate('Admin', 'web'));

        $this->actingAs($this->policyManager)
            ->put(route('tech.admin.settings.storage.purchase-order-automation.update'), $this->policyData([
                'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
                'default_warehouse_id' => $warehouse->id,
                'ai_mode' => 'fallback',
                'ai_agent_id' => $agent->id,
            ]))
            ->assertRedirect(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertSessionHasNoErrors();

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->sole();
        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI, $policy->runtime_mode);
        $this->assertSame('register_ordered', $policy->default_outcome);
        $this->assertSame('fallback', $policy->ai_mode);
        $this->assertSame('auto_activate', $policy->ai_profile_learning_mode);
        $this->assertSame(1, $policy->ai_profile_shadow_samples);
        $this->assertSame(150, $policy->ai_timeout_seconds);
        $this->assertSame(12000, $policy->ai_max_output_tokens);
    }

    #[Test]
    public function automatic_profile_only_submission_with_ai_off_registers_orders(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Profile-only policy warehouse',
            'code' => 'PROFILE-POLICY',
            'is_active' => true,
        ]);
        $this->policyManager->assignRole(Role::findOrCreate('Admin', 'web'));

        $this->actingAs($this->policyManager)
            ->put(route('tech.admin.settings.storage.purchase-order-automation.update'), $this->policyData([
                'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
                'default_warehouse_id' => $warehouse->id,
                'ai_mode' => 'off',
                'ai_agent_id' => null,
            ]))
            ->assertRedirect(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertSessionHasNoErrors();

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->sole();
        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC, $policy->runtime_mode);
        $this->assertSame('register_ordered', $policy->default_outcome);
        $this->assertSame('off', $policy->ai_mode);
        $this->assertSame('off', $policy->ai_profile_learning_mode);
    }

    #[Test]
    public function verified_ai_submission_with_ai_off_is_normalized_to_profile_only(): void
    {
        $warehouse = Warehouse::query()->create([
            'name' => 'Disabled AI policy warehouse',
            'code' => 'DISABLED-AI-POLICY',
            'is_active' => true,
        ]);
        $this->policyManager->assignRole(Role::findOrCreate('Admin', 'web'));

        $this->actingAs($this->policyManager)
            ->put(route('tech.admin.settings.storage.purchase-order-automation.update'), $this->policyData([
                'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_VERIFIED_AI,
                'default_warehouse_id' => $warehouse->id,
                'ai_mode' => 'off',
                'ai_agent_id' => null,
            ]))
            ->assertRedirect(route('tech.admin.settings.storage.purchase-order-automation.edit'))
            ->assertSessionHasNoErrors();

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->sole();
        $this->assertSame(PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC, $policy->runtime_mode);
        $this->assertSame('register_ordered', $policy->default_outcome);
        $this->assertSame('off', $policy->ai_mode);
    }

    #[Test]
    public function automatic_item_creation_requires_a_positive_new_item_limit(): void
    {
        $this->policyManager->assignRole(Role::findOrCreate('Admin', 'web'));
        $policyCount = PurchaseOrderAutomationPolicy::query()->count();

        $this->actingAs($this->policyManager)
            ->put(route('tech.admin.settings.storage.purchase-order-automation.update'), $this->policyData([
                'new_item_mode' => 'create_active_item',
                'max_new_items' => 0,
            ]))
            ->assertSessionHasErrors('max_new_items');

        $this->assertSame($policyCount, PurchaseOrderAutomationPolicy::query()->count());
    }

    private function activeUser(): User
    {
        return User::factory()->create(['status' => User::STATUS_ACTIVE]);
    }

    /** @return array{0: AiProvider, 1: AiAgent, 2: AiWorkloadProfile} */
    private function governedWorkload(): array
    {
        $provider = AiProvider::query()->create([
            'name' => 'Policy workload '.Str::random(6),
            'provider_key' => 'openai',
            'base_url' => 'https://api.openai.test/v1',
            'default_model' => 'gpt-4.1-mini',
            'status' => 'active',
            'is_healthy' => true,
        ]);
        $provider->setSecret('api_key', 'policy-validation-test-key');
        $provider->save();

        $agent = AiAgent::query()->create([
            'ai_provider_id' => $provider->id,
            'name' => 'Policy supplier extractor '.Str::random(6),
            'slug' => 'policy-supplier-extractor-'.Str::lower(Str::random(6)),
            'model' => 'gpt-4.1-mini',
            'instructions' => 'Extract structured supplier facts without tools or writes.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => false,
            'default_domains' => ['storage'],
            'is_active' => true,
        ]);

        AiDataEgressPolicy::installation()->update([
            'ai_enabled' => true,
            'external_processing_enabled' => true,
            'privacy_gateway_enabled' => true,
            'direct_external_enabled' => false,
            'allowed_processing_modes' => ['privacy_relay'],
            'maximum_data_profile' => 'pseudonymized',
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->policyManager->id,
            'reviewed_at' => now(),
        ]);
        AiProviderGovernanceProfile::query()->create([
            'ai_provider_id' => $provider->id,
            'purpose' => 'Minimized supplier-order extraction.',
            'recipient_name' => $provider->name,
            'processing_regions' => ['EEA'],
            'support_regions' => ['EEA'],
            'dpa_status' => 'approved',
            'dpa_reference' => 'policy-test-dpa',
            'subprocessor_notes' => 'Synthetic test only.',
            'transfer_assessment' => 'No unreviewed transfer.',
            'retention_declaration' => 'No retained test data.',
            'training_declaration' => 'No training on test data.',
            'dpia_status' => 'not_required',
            'dpia_rationale' => 'Synthetic test data only.',
            'allowed_processing_modes' => ['privacy_relay'],
            'maximum_data_profile' => 'pseudonymized',
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->policyManager->id,
            'reviewed_at' => now(),
        ]);
        AiModelGovernancePolicy::query()->create([
            'ai_provider_id' => $provider->id,
            'model' => 'gpt-4.1-mini',
            'processing_mode' => 'privacy_relay',
            'maximum_data_profile' => 'pseudonymized',
            'is_approved' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->policyManager->id,
            'reviewed_at' => now(),
        ]);
        AiAgentGovernancePolicy::query()->create([
            'ai_agent_id' => $agent->id,
            'processing_mode' => 'privacy_relay',
            'maximum_data_profile' => 'pseudonymized',
            'is_approved' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->policyManager->id,
            'reviewed_at' => now(),
        ]);

        $workload = AiWorkloadProfile::query()->create([
            'name' => 'Policy supplier workload '.Str::random(6),
            'slug' => 'policy-supplier-workload-'.Str::lower(Str::random(6)),
            'workload_type' => AiWorkloadProfile::TYPE_INTERNAL_MODEL,
            'purpose' => 'Extract minimized supplier-order facts without writes.',
            'ai_provider_id' => $provider->id,
            'ai_agent_id' => $agent->id,
            'model' => 'gpt-4.1-mini',
            'processing_mode' => 'privacy_relay',
            'maximum_data_profile' => 'pseudonymized',
            'abilities' => [],
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
            'approved_by' => $this->policyManager->id,
            'approved_at' => now(),
            'created_by' => $this->policyManager->id,
        ]);

        return [$provider, $agent, $workload];
    }

    /** @param array<string, mixed> $overrides */
    private function policyData(array $overrides = []): array
    {
        return array_replace([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'default_outcome' => 'needs_attention',
            'default_warehouse_id' => null,
            'ai_agent_id' => null,
            'ai_mode' => 'off',
            'ai_profile_learning_mode' => 'off',
            'ai_profile_shadow_samples' => 3,
            'provider_outage_behavior' => 'needs_attention',
            'deterministic_confidence_threshold' => 100,
            'ai_confidence_threshold' => 100,
            'amount_tolerance' => 0.01,
            'max_lines' => 50,
            'max_quantity_per_line' => 100,
            'max_order_total' => 100000,
            'max_new_items' => 5,
            'supplier_bootstrap_mode' => 'existing_only',
            'new_item_mode' => 'review_only',
            'retry_limit' => 3,
            'retry_base_seconds' => 60,
            'ai_timeout_seconds' => 30,
            'ai_max_output_tokens' => 2000,
            'ai_max_cost_per_import' => null,
            'ai_cost_currency' => null,
            'ai_consensus_mode' => 'off',
            'ai_consensus_workload_profile_id' => null,
            'circuit_breaker_failures' => 5,
            'retention_days' => 730,
            'silent_success' => true,
            'daily_digest_enabled' => false,
            'advanced_rules' => [],
        ], $overrides);
    }

    /** @param array<string, mixed> $data */
    private function update(array $data): PurchaseOrderAutomationPolicy
    {
        return app(UpdatePurchaseOrderAutomationPolicy::class)->handle($data, $this->policyManager);
    }
}
