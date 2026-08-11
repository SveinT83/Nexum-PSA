<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiAgentGovernancePolicy;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiModelGovernancePolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InternalAiWorkloadAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        foreach ([
            'integration.ai_audit_view',
            'integration.ai_policy_manage',
            'integration.ai_governance_manage',
            'integration.ai_workload_manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->admin = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo([
            'integration.ai_audit_view',
            'integration.ai_policy_manage',
            'integration.ai_governance_manage',
            'integration.ai_workload_manage',
        ]);
    }

    #[Test]
    public function admin_can_create_a_safe_governed_internal_model_workload(): void
    {
        $agent = $this->governedAgent();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.ai.privacy.workloads.internal.store'), $this->workloadPayload($agent))
            ->assertRedirect()
            ->assertSessionHas('success');

        $workload = AiWorkloadProfile::query()->sole();

        $this->assertSame(AiWorkloadProfile::TYPE_INTERNAL_MODEL, $workload->workload_type);
        $this->assertSame($agent->id, $workload->ai_agent_id);
        $this->assertSame($agent->ai_provider_id, $workload->ai_provider_id);
        $this->assertSame('safe-local-model', $workload->model);
        $this->assertSame('local_only', $workload->processing_mode);
        $this->assertSame('aggregate', $workload->maximum_data_profile);
        $this->assertSame([], $workload->abilities);
        $this->assertTrue($workload->is_approved);
        $this->assertTrue($workload->is_active);
        $this->assertFalse($workload->supportsCoordinatorTokens());
        $this->assertSame($this->admin->id, $workload->approved_by);
        $this->assertSame($this->admin->id, $workload->created_by);
    }

    #[Test]
    public function admin_cannot_create_an_internal_workload_for_a_writing_agent(): void
    {
        $agent = $this->governedAgent([
            'can_execute_actions' => true,
        ]);

        $this->actingAs($this->admin)
            ->from(route('tech.admin.system.integrations.ai.privacy.index'))
            ->post(route('tech.admin.system.integrations.ai.privacy.workloads.internal.store'), $this->workloadPayload($agent))
            ->assertRedirect(route('tech.admin.system.integrations.ai.privacy.index'))
            ->assertSessionHasErrors('ai_agent_id');

        $this->assertDatabaseCount('ai_workload_profiles', 0);
    }

    #[Test]
    public function internal_model_workloads_cannot_issue_tokens_through_the_admin_endpoint(): void
    {
        $workload = $this->createInternalWorkload();

        $this->actingAs($this->admin)
            ->from(route('tech.admin.system.integrations.ai.privacy.index'))
            ->post(route('tech.admin.system.integrations.ai.privacy.workloads.tokens.store', $workload), [
                'name' => 'Forbidden internal token',
                'expires_at' => now()->addWeek()->toDateString(),
                'requests_per_minute' => 30,
            ])
            ->assertRedirect(route('tech.admin.system.integrations.ai.privacy.index'))
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('ai_workload_token_bindings', 0);
    }

    #[Test]
    public function internal_model_workloads_reject_direct_token_binding_attempts(): void
    {
        $workload = $this->createInternalWorkload();
        $token = $this->admin->createToken('Direct binding attempt', []);

        try {
            AiWorkloadTokenBinding::query()->create([
                'personal_access_token_id' => $token->accessToken->id,
                'ai_workload_profile_id' => $workload->id,
                'expires_at' => now()->addWeek(),
                'allowed_networks' => [],
                'requests_per_minute' => 30,
                'created_by' => $this->admin->id,
            ]);

            $this->fail('An internal model workload accepted a direct coordinator-token binding.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'Internal model workloads cannot be bound to coordinator tokens.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('ai_workload_token_bindings', 0);
    }

    private function createInternalWorkload(): AiWorkloadProfile
    {
        $agent = $this->governedAgent();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.ai.privacy.workloads.internal.store'), $this->workloadPayload($agent))
            ->assertRedirect()
            ->assertSessionHas('success');

        return AiWorkloadProfile::query()->sole();
    }

    private function governedAgent(array $overrides = []): AiAgent
    {
        AiDataEgressPolicy::installation()->update([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'aggregate',
        ]);

        $provider = AiProvider::query()->create([
            'name' => 'Safe local provider',
            'provider_key' => 'safe-local-provider',
            'default_model' => 'safe-local-model',
            'status' => 'active',
            'config' => [],
            'secrets' => [],
            'is_healthy' => true,
        ]);
        $agent = AiAgent::query()->create(array_merge([
            'ai_provider_id' => $provider->id,
            'name' => 'Supplier order extractor',
            'slug' => 'supplier-order-extractor',
            'model' => 'safe-local-model',
            'instructions' => 'Return only the approved structured extraction schema.',
            'data_sources' => [],
            'allowed_tools' => [],
            'allowed_api_scopes' => [],
            'can_execute_actions' => false,
            'is_default' => false,
            'default_domains' => [],
            'is_active' => true,
        ], $overrides));

        AiModelGovernancePolicy::query()->create([
            'ai_provider_id' => $provider->id,
            'model' => 'safe-local-model',
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'aggregate',
            'is_approved' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ]);
        AiAgentGovernancePolicy::query()->create([
            'ai_agent_id' => $agent->id,
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'aggregate',
            'is_approved' => true,
            'expires_at' => now()->addMonth(),
            'reviewed_by' => $this->admin->id,
            'reviewed_at' => now(),
        ]);

        return $agent;
    }

    private function workloadPayload(AiAgent $agent): array
    {
        return [
            'name' => 'Supplier order extraction',
            'purpose' => 'Extract a supplier order into the governed canonical schema.',
            'ai_agent_id' => $agent->id,
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'aggregate',
            'expires_at' => now()->addMonth()->toDateString(),
        ];
    }
}
