<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Contracts\InspectsTlsCertificates;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Models\IntegrationHubApprovalRequest;
use App\Modules\Integration\Models\IntegrationHubAuditEvent;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Models\IntegrationHubCapabilityBinding;
use App\Modules\Integration\Models\IntegrationHubDomain;
use App\Modules\Integration\Models\IntegrationHubEmergencyControl;
use App\Modules\Integration\Models\IntegrationHubExecution;
use App\Modules\Integration\Models\IntegrationHubExecutionGrant;
use App\Modules\Integration\Models\IntegrationHubSetting;
use App\Modules\Integration\Services\IntegrationHub\ApprovalService;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\Integration\Services\IntegrationHub\DomainNormalizer;
use App\Modules\Integration\Services\IntegrationHub\GrantSigner;
use App\Modules\Integration\Services\IntegrationHub\PleskReadOnlyAdapter;
use App\Modules\Integration\Services\IntegrationHub\TlsTargetResolver;
use App\Modules\UserManagement\Actions\EnsureSystemActor;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class IntegrationHubFoundationTest extends TestCase
{
    use RefreshDatabase;

    private User $serviceActor;

    private AiWorkloadProfile $serviceWorkload;

    private AiWorkloadTokenBinding $serviceBinding;

    private string $serviceToken;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set([
            'integration-hub.installation_key' => 'test-installation',
            'integration-hub.issuer' => 'https://nexum.test/api/v1/integration-hub',
            'integration-hub.audience' => 'https://mcp.test',
            'integration-hub.authorization_server' => 'https://nexum.test',
            'integration-hub.service_actor_key' => 'integration_hub_mcp',
            'integration-hub.active_grant_key_id' => 'test-v1',
            'integration-hub.active_grant_key' => str_repeat('a', 64),
            'integration-hub.previous_grant_key_id' => null,
            'integration-hub.previous_grant_key' => null,
            'integration-hub.plesk.retry_delay_min_ms' => 0,
            'integration-hub.plesk.retry_delay_max_ms' => 0,
        ]);

        foreach (['integration.view', 'client.view', 'integration.ai_audit_view', 'integration.ai_governance_manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        IntegrationHubSetting::current()->forceFill(['enabled' => true])->save();
        $registry = app(CapabilityRegistry::class);
        $registry->sync(true, true);
        $this->serviceActor = app(EnsureSystemActor::class)->handle(
            'integration_hub_mcp',
            'Integration Hub MCP',
            'integration-hub-mcp@system.invalid',
            ['integration.view', 'client.view', 'integration.ai_audit_view'],
        );
        AiDataEgressPolicy::installation()->forceFill([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'identified_business',
            'requests_per_minute' => 600,
            'expires_at' => now()->addDay(),
            'reviewed_by' => $this->serviceActor->id,
            'reviewed_at' => now(),
            'updated_by' => $this->serviceActor->id,
        ])->save();
        $this->serviceWorkload = AiWorkloadProfile::query()->create([
            'name' => 'Integration Hub MCP service',
            'slug' => 'integration-hub-mcp-service',
            'purpose' => 'Execute approved read-only Integration Hub capabilities.',
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'identified_business',
            'abilities' => collect($registry->definitions())->pluck('required_ability')->unique()->values()->all(),
            'allowed_client_ids' => [],
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addDay(),
            'approved_by' => $this->serviceActor->id,
            'approved_at' => now(),
            'created_by' => $this->serviceActor->id,
        ]);
        $serviceToken = $this->serviceActor->createToken('MCP service', ['integration-hub.service']);
        $this->serviceToken = $serviceToken->plainTextToken;
        $this->serviceBinding = AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $serviceToken->accessToken->id,
            'ai_workload_profile_id' => $this->serviceWorkload->id,
            'expires_at' => now()->addDay(),
            'allowed_networks' => [],
            'requests_per_minute' => 600,
            'created_by' => $this->serviceActor->id,
        ]);
    }

    #[Test]
    public function protected_resource_metadata_is_public_and_bounded(): void
    {
        $this->getJson('/.well-known/oauth-protected-resource/api/v1/integration-hub')
            ->assertOk()
            ->assertJsonPath('resource', 'https://mcp.test')
            ->assertJsonPath('authorization_servers.0', 'https://nexum.test')
            ->assertJsonMissing(['active_grant_key', 'serviceToken']);
    }

    #[Test]
    public function interactive_actor_can_issue_a_narrow_grant_and_read_minimal_identity_once(): void
    {
        $actor = $this->actor(['integration.view']);
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');

        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('data.identity.kind', 'interactive')
            ->assertJsonPath('data.identity.actor_id', $actor->id)
            ->assertJsonPath('data.organization.key', 'test-installation')
            ->assertJsonMissing(['grant', 'password', 'token']);

        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertForbidden()
            ->assertJsonPath('reason.code', 'grant_replayed');
    }

    #[Test]
    public function capability_version_negotiation_and_validation_fail_with_the_common_envelope(): void
    {
        $actor = $this->actor(['integration.view', 'client.view']);
        [$client] = $this->clientSite('Versioned client');
        $scope = ['client_ids' => [$client->id]];

        $grant = $this->grant($actor, 'nexum.clients.read', 'integration-hub.clients.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/clients', $grant, ['Accept-Capability-Version' => '1'])
            ->assertOk()->assertHeader('Content-Capability-Version', '1.0')
            ->assertHeader('Vary', 'Accept-Capability-Version')
            ->assertJsonPath('contract.version', '1.0');

        $grant = $this->grant($actor, 'nexum.clients.read', 'integration-hub.clients.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/clients', $grant, ['Accept-Capability-Version' => '2.0'])
            ->assertStatus(409)->assertJsonPath('contract.version', '1.0')
            ->assertJsonPath('reason.code', 'contract_version_unsupported');

        $grant = $this->grant($actor, 'nexum.clients.read', 'integration-hub.clients.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/clients?per_page=999', $grant, ['Accept-Capability-Version' => '1.0'])
            ->assertUnprocessable()->assertJsonPath('contract.version', '1.0')
            ->assertJsonPath('reason.code', 'request_validation_failed')
            ->assertJsonPath('meta.invalid_fields.0', 'per_page')
            ->assertJsonMissing(['errors']);
    }

    #[Test]
    public function broad_tokens_and_missing_permissions_fail_closed(): void
    {
        $actor = $this->actor([]);
        $plain = $actor->createToken('Broad', ['*'])->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.identity.read', 'capability_version' => '1.0',
        ])->assertForbidden()->assertJsonPath('reason.code', 'broad_token_rejected');

        $plain = $actor->createToken('No role permission', ['integration-hub.grants.issue', 'integration-hub.identity.read'])->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.identity.read', 'capability_version' => '1.0',
        ])->assertForbidden()->assertJsonPath('reason.code', 'required_permission_missing');

        $actor = $this->actor(['integration.view']);
        $plain = $actor->createToken('Invalid grant request', ['integration-hub.grants.issue', 'integration-hub.identity.read'])->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.identity.read', 'capability_version' => 'not-a-version',
        ])->assertUnprocessable()->assertJsonPath('contract.version', '1.0')
            ->assertJsonPath('reason.code', 'request_validation_failed')
            ->assertJsonPath('meta.invalid_fields.0', 'capability_version')
            ->assertJsonMissing(['errors']);
    }

    #[Test]
    public function service_identity_requires_a_bound_workload_and_enforces_network_and_data_egress(): void
    {
        $actor = $this->actor(['integration.view']);
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');

        $this->serviceBinding->delete();
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertForbidden()->assertJsonPath('reason.code', 'service_workload_binding_required');

        $serviceTokenId = $this->serviceActor->tokens()->latest('id')->value('id');
        $this->serviceBinding = AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $serviceTokenId,
            'ai_workload_profile_id' => $this->serviceWorkload->id,
            'expires_at' => now()->addDay(),
            'allowed_networks' => ['203.0.113.0/24'],
            'requests_per_minute' => 600,
            'created_by' => $this->serviceActor->id,
        ]);
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertForbidden()->assertJsonPath('reason.code', 'workload_network_not_allowed');

        $this->serviceBinding->forceFill(['allowed_networks' => []])->save();
        AiDataEgressPolicy::installation()->forceFill(['maximum_data_profile' => 'aggregate'])->save();
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertForbidden()->assertJsonPath('reason.code', 'workload_policy_data_profile_exceeds_installation_maximum');

        AiDataEgressPolicy::installation()->forceFill(['maximum_data_profile' => 'identified_business'])->save();
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)->assertOk();
    }

    #[Test]
    public function service_identity_rejects_broad_tokens_passthrough_and_revoked_grants(): void
    {
        $actor = $this->actor(['integration.view']);
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        $broad = $this->serviceActor->createToken('Broad service', ['*']);
        AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $broad->accessToken->id,
            'ai_workload_profile_id' => $this->serviceWorkload->id,
            'expires_at' => now()->addDay(),
            'allowed_networks' => [],
            'requests_per_minute' => 600,
            'created_by' => $this->serviceActor->id,
        ]);

        $this->withHeaders([
            'Authorization' => 'Bearer '.$broad->plainTextToken,
            'X-Nexum-Execution-Grant' => $grant,
        ])->getJson('/api/v1/integration-hub/identity')
            ->assertForbidden()->assertJsonPath('reason.code', 'broad_service_token_rejected');

        $this->withHeaders([
            'Authorization' => 'Bearer '.$this->serviceToken,
            'X-Nexum-Execution-Grant' => $this->serviceToken,
        ])->getJson('/api/v1/integration-hub/identity')
            ->assertForbidden()->assertJsonPath('reason.code', 'token_passthrough_rejected');

        IntegrationHubExecutionGrant::query()->latest('created_at')->firstOrFail()
            ->forceFill(['revoked_at' => now(), 'revocation_reason' => 'test_revocation'])->save();
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertForbidden()->assertJsonPath('reason.code', 'grant_revoked');
    }

    #[Test]
    public function service_workload_rate_limit_is_enforced_independently_of_route_throttling(): void
    {
        $this->serviceBinding->forceFill(['requests_per_minute' => 1])->save();
        $actor = $this->actor(['integration.view']);

        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)->assertOk();

        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertStatus(429)->assertJsonPath('reason.code', 'workload_rate_exceeded')
            ->assertJsonPath('reason.retryable', true);
    }

    #[Test]
    public function bootstrap_and_service_token_commands_fail_safe_and_keep_service_abilities_minimal(): void
    {
        IntegrationHubSetting::current()->forceFill(['enabled' => false])->save();
        IntegrationHubCapability::query()->update(['enabled' => false]);
        config()->set('integration-hub.active_grant_key', null);
        $this->assertSame(1, Artisan::call('integration-hub:bootstrap', ['--enable' => true]));
        $this->assertFalse(IntegrationHubSetting::current()->fresh()->enabled);
        $this->assertSame(0, IntegrationHubCapability::query()->where('enabled', true)->count());

        config()->set('integration-hub.active_grant_key', str_repeat('a', 64));
        $this->assertSame(0, Artisan::call('integration-hub:bootstrap', ['--enable' => true]));
        $this->assertTrue(IntegrationHubSetting::current()->fresh()->enabled);
        $this->assertSame(0, $this->serviceActor->fresh()->getAllPermissions()->count());
        $this->assertSame(0, Artisan::call('integration-hub:bootstrap'));
        $this->assertSame(count(app(CapabilityRegistry::class)->definitions()), IntegrationHubCapability::query()->where('enabled', true)->count());

        $this->assertSame(1, Artisan::call('integration-hub:issue-service-token', [
            'workload' => $this->serviceWorkload->slug,
        ]));
        $this->assertSame(1, Artisan::call('integration-hub:issue-service-token', [
            'workload' => $this->serviceWorkload->slug,
            '--network' => ['127.0.0.1/32', 'not-a-network'],
        ]));
        $this->assertSame(1, Artisan::call('integration-hub:issue-service-token', [
            'workload' => $this->serviceWorkload->slug,
            '--network' => ['127.0.0.1/32'],
            '--allow-any-network' => true,
        ]));
        $this->assertSame(0, Artisan::call('integration-hub:issue-service-token', [
            'workload' => $this->serviceWorkload->slug,
            '--network' => ['127.0.0.1/32'],
            '--rpm' => 17,
            '--days' => 1,
        ]));
        $issuedToken = $this->serviceActor->tokens()->latest('id')->firstOrFail();
        $this->assertSame(['integration-hub.service'], $issuedToken->abilities);
        $binding = AiWorkloadTokenBinding::query()->where('personal_access_token_id', $issuedToken->id)->firstOrFail();
        $this->assertSame(['127.0.0.1/32'], $binding->allowed_networks);
        $this->assertSame(17, $binding->requests_per_minute);
        $this->assertSame(0, Artisan::call('integration-hub:revoke-service-token', [
            'token' => $issuedToken->id,
            '--reason' => 'test_rotation',
        ]));
        $this->assertNotNull($binding->fresh()->revoked_at);

        $unrelated = $this->actor([])->createToken('Unrelated', ['integration-hub.service']);
        $this->assertSame(1, Artisan::call('integration-hub:revoke-service-token', [
            'token' => $unrelated->accessToken->id,
        ]));
    }

    #[Test]
    public function grants_reject_wrong_audience_tampering_expiry_and_key_retirement(): void
    {
        Carbon::setTestNow('2026-08-15 01:00:00');
        $actor = $this->actor(['integration.view']);

        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        config()->set('integration-hub.audience', 'https://different-mcp.test');
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)->assertForbidden()->assertJsonPath('reason.code', 'grant_audience_invalid');

        config()->set('integration-hub.audience', 'https://mcp.test');
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        $tampered = substr($grant, 0, -1).(str_ends_with($grant, 'a') ? 'b' : 'a');
        $this->serviceGet('/api/v1/integration-hub/identity', $tampered)->assertForbidden()->assertJsonPath('reason.code', 'grant_signature_invalid');

        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read', [], 30);
        Carbon::setTestNow(now()->addMinutes(2));
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)->assertForbidden()->assertJsonPath('reason.code', 'grant_expired');

        Carbon::setTestNow('2026-08-15 02:00:00');
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        config()->set(['integration-hub.active_grant_key_id' => 'test-v2', 'integration-hub.active_grant_key' => str_repeat('b', 64)]);
        $this->serviceGet('/api/v1/integration-hub/identity', $grant)->assertForbidden()->assertJsonPath('reason.code', 'grant_key_unknown');

        Carbon::setTestNow();
    }

    #[Test]
    public function grants_reject_early_wrong_tenant_and_scope_broadening_even_when_resigned(): void
    {
        Carbon::setTestNow('2026-08-15 03:00:00');
        $actor = $this->actor(['integration.view', 'client.view']);
        [$client] = $this->clientSite('Grant scope A');
        [$otherClient] = $this->clientSite('Grant scope B');
        $grant = $this->grant($actor, 'nexum.clients.read', 'integration-hub.clients.read', [
            'client_ids' => [$client->id],
        ]);
        $signer = app(GrantSigner::class);
        $verified = $signer->verify($grant);
        $record = IntegrationHubExecutionGrant::query()->latest('created_at')->firstOrFail();

        $earlyClaims = $verified['claims'];
        $earlyClaims['nbf'] = now()->addMinutes(2)->timestamp;
        $early = $signer->sign($earlyClaims);
        $record->forceFill(['claims_digest' => $early['claims_digest'], 'key_id' => $early['key_id']])->save();
        $this->serviceGet('/api/v1/integration-hub/clients', $early['token'])
            ->assertForbidden()->assertJsonPath('reason.code', 'grant_not_yet_valid');

        $wrongTenantClaims = $verified['claims'];
        $wrongTenantClaims['installation'] = 'different-installation';
        $wrongTenant = $signer->sign($wrongTenantClaims);
        $record->forceFill(['claims_digest' => $wrongTenant['claims_digest'], 'key_id' => $wrongTenant['key_id']])->save();
        $this->serviceGet('/api/v1/integration-hub/clients', $wrongTenant['token'])
            ->assertForbidden()->assertJsonPath('reason.code', 'grant_installation_mismatch');

        $broadenedClaims = $verified['claims'];
        $broadenedClaims['scope']['client_ids'][] = $otherClient->id;
        $broadened = $signer->sign($broadenedClaims);
        $record->forceFill(['claims_digest' => $verified['claims_digest'], 'key_id' => $broadened['key_id']])->save();
        $this->serviceGet('/api/v1/integration-hub/clients', $broadened['token'])
            ->assertForbidden()->assertJsonPath('reason.code', 'grant_record_invalid');

        Carbon::setTestNow();
    }

    #[Test]
    public function previous_signing_key_is_accepted_only_during_rotation_overlap(): void
    {
        $actor = $this->actor(['integration.view']);
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        config()->set([
            'integration-hub.active_grant_key_id' => 'test-v2',
            'integration-hub.active_grant_key' => str_repeat('b', 64),
            'integration-hub.previous_grant_key_id' => 'test-v1',
            'integration-hub.previous_grant_key' => str_repeat('a', 64),
        ]);

        $this->serviceGet('/api/v1/integration-hub/identity', $grant)->assertOk();
    }

    #[Test]
    public function client_and_site_queries_filter_scope_before_pagination_and_hide_existence(): void
    {
        [$allowedClient, $allowedSite] = $this->clientSite('Allowed');
        [$hiddenClient, $hiddenSite] = $this->clientSite('Hidden');
        $actor = $this->actor(['client.view']);
        $scope = ['client_ids' => [$allowedClient->id], 'site_ids' => [$allowedSite->id]];

        $grant = $this->grant($actor, 'nexum.clients.read', 'integration-hub.clients.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/clients?per_page=1', $grant)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowedClient->id)
            ->assertJsonPath('meta.pagination.total', 1);

        $grant = $this->grant($actor, 'nexum.clients.read', 'integration-hub.clients.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/clients/'.$hiddenClient->id, $grant)
            ->assertNotFound()->assertJsonPath('reason.code', 'record_not_found_or_out_of_scope');

        $grant = $this->grant($actor, 'nexum.sites.read', 'integration-hub.clients.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/sites/'.$hiddenSite->id, $grant)
            ->assertNotFound()->assertJsonPath('reason.code', 'record_not_found_or_out_of_scope');
    }

    #[Test]
    public function workload_client_allowlist_cannot_be_broadened(): void
    {
        [$allowed] = $this->clientSite('Allowed workload');
        [$hidden] = $this->clientSite('Hidden workload');
        $actor = $this->actor(['client.view']);
        $workload = AiWorkloadProfile::query()->create([
            'name' => 'MCP workload', 'slug' => 'mcp-workload', 'purpose' => 'Read one client.',
            'processing_mode' => 'local_only', 'maximum_data_profile' => 'identified_business',
            'abilities' => ['integration-hub.clients.read'], 'allowed_client_ids' => [$allowed->id],
            'is_approved' => true, 'is_active' => true, 'expires_at' => now()->addDay(),
            'approved_by' => $actor->id, 'approved_at' => now(), 'created_by' => $actor->id,
        ]);
        $token = $actor->createToken('Bound workload', ['integration-hub.grants.issue', 'integration-hub.clients.read']);
        AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addHour(), 'allowed_networks' => [], 'requests_per_minute' => 30,
            'created_by' => $actor->id,
        ]);

        $this->withToken($token->plainTextToken)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.clients.read', 'capability_version' => '1.0',
            'scope' => ['client_ids' => [$hidden->id]],
        ])->assertForbidden()->assertJsonPath('reason.code', 'workload_client_scope_mismatch');

        $response = $this->withToken($token->plainTextToken)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.clients.read', 'capability_version' => '1.0',
        ])->assertCreated();
        $grant = (string) $response->json('data.grant');
        $this->serviceGet('/api/v1/integration-hub/clients', $grant)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $allowed->id);
    }

    #[Test]
    public function explicit_capability_bindings_deny_missing_and_wrong_client_scope(): void
    {
        [$clientOne] = $this->clientSite('One');
        [$clientTwo] = $this->clientSite('Two');
        $actor = $this->actor(['client.view']);
        $capability = IntegrationHubCapability::query()->where('capability_key', 'nexum.clients.read')->firstOrFail();
        $capability->bindings()->delete();

        $plain = $actor->createToken('Missing binding', ['integration-hub.grants.issue', 'integration-hub.clients.read'])->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.clients.read', 'scope' => ['client_ids' => [$clientOne->id]],
        ])->assertForbidden()->assertJsonPath('reason.code', 'capability_binding_missing');

        IntegrationHubCapabilityBinding::query()->create([
            'capability_id' => $capability->id, 'installation_key' => 'test-installation',
            'client_id' => $clientOne->id, 'enabled' => true,
        ]);
        $plain = $actor->createToken('Wrong client binding', ['integration-hub.grants.issue', 'integration-hub.clients.read'])->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => 'nexum.clients.read', 'scope' => ['client_ids' => [$clientTwo->id]],
        ])->assertForbidden()->assertJsonPath('reason.code', 'capability_binding_missing');
    }

    #[Test]
    public function global_emergency_control_invalidates_grants_and_audits_denial(): void
    {
        $actor = $this->actor(['integration.view']);
        $grant = $this->grant($actor, 'nexum.identity.read', 'integration-hub.identity.read');
        IntegrationHubEmergencyControl::query()->create([
            'installation_key' => 'test-installation', 'control_key' => 'global', 'scope_type' => 'global',
            'is_disabled' => true, 'reason_code' => 'operator_stop', 'changed_by' => $actor->id,
            'correlation_id' => fake()->uuid(), 'disabled_at' => now(),
        ]);

        $this->serviceGet('/api/v1/integration-hub/identity', $grant)
            ->assertStatus(503)->assertJsonPath('reason.code', 'emergency_control_active');
        $this->assertDatabaseHas('integration_hub_audit_events', [
            'decision' => 'denied', 'result_status' => 'unavailable', 'reason_code' => 'emergency_control_active',
        ]);
    }

    #[Test]
    public function authorized_operator_can_disable_and_reenable_a_control_with_distinct_audit(): void
    {
        $operator = $this->actor(['integration.ai_governance_manage']);
        $plain = $operator->createToken('Controls', ['integration-hub.controls.manage'])->plainTextToken;
        $this->withToken($plain)->postJson('/api/v1/integration-hub/controls', [
            'scope_type' => 'global', 'disabled' => true, 'reason_code' => 'incident', 'reason_summary' => 'Test incident.',
        ])->assertOk()->assertJsonPath('data.disabled', true);
        $this->assertNotNull(IntegrationHubSetting::current()->fresh()->grants_invalid_before);

        $this->withToken($plain)->postJson('/api/v1/integration-hub/controls', [
            'scope_type' => 'global', 'disabled' => false, 'reason_code' => 'incident_resolved',
        ])->assertOk()->assertJsonPath('data.disabled', false);
        $this->assertDatabaseHas('integration_hub_audit_events', ['actor_id' => $operator->id, 'reason_code' => 'emergency_control_enabled']);
        $event = IntegrationHubAuditEvent::query()->where('reason_code', 'emergency_control_enabled')->latest('id')->firstOrFail();
        $this->assertTrue($event->sanitized_context['previous_disabled']);
        $this->assertFalse($event->sanitized_context['new_disabled']);
        $this->assertSame('incident_resolved', $event->sanitized_context['operator_reason_code']);
    }

    #[Test]
    public function emergency_control_and_readiness_require_an_explicit_narrow_operator_token(): void
    {
        $operator = $this->actor(['integration.ai_governance_manage']);

        $this->actingAs($operator)->getJson('/api/v1/integration-hub/readiness')
            ->assertForbidden()->assertJsonPath('reason.code', 'operator_token_required');

        $broad = $operator->createToken('Broad controls', ['*'])->plainTextToken;
        $this->withToken($broad)->getJson('/api/v1/integration-hub/readiness')
            ->assertForbidden()->assertJsonPath('reason.code', 'broad_token_rejected');

        $narrow = $operator->createToken('Narrow controls', ['integration-hub.controls.manage'])->plainTextToken;
        $this->withToken($narrow)->getJson('/api/v1/integration-hub/readiness')
            ->assertOk()->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.grant_signing_configured', true)
            ->assertJsonMissing(['active_grant_key', 'serviceToken']);
    }

    #[Test]
    public function integration_catalogue_is_sanitized_and_never_invents_health(): void
    {
        [$client, $site] = $this->clientSite('Hosting client');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'provider-secret-value');
        $integration->save();
        $actor = $this->actor(['integration.view']);
        $scope = ['client_ids' => [$client->id], 'site_ids' => [$site->id], 'integration_ids' => [$integration->id], 'environment' => 'test'];

        $grant = $this->grant($actor, 'nexum.integrations.read', 'integration-hub.integrations.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/integrations/'.$integration->id, $grant)
            ->assertOk()->assertJsonPath('status', 'unknown')
            ->assertJsonPath('data.credential.configured', true)
            ->assertJsonMissing(['provider-secret-value', 'server', 'secrets', 'last_error']);

        $integration->forceFill(['health_status' => 'ok', 'health_observed_at' => now(), 'last_successful_observation_at' => now(), 'is_healthy' => true])->save();
        $grant = $this->grant($actor, 'nexum.integrations.read', 'integration-hub.integrations.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/integrations/'.$integration->id.'/health', $grant)
            ->assertOk()->assertJsonPath('status', 'ok');
    }

    #[Test]
    public function integration_catalogue_uses_legacy_health_evidence_and_effective_capability_bindings(): void
    {
        $bookStack = Integration::query()->create([
            'name' => 'BookStack legacy health', 'type' => 'book_stack', 'owner_scope' => 'installation',
            'installation_key' => 'test-installation', 'environment' => 'test',
            'server' => 'https://bookstack.example.test', 'status' => 'active',
            'is_healthy' => false, 'last_sync_at' => now(),
        ]);
        $bookStack->setSecret('token_id', 'configured');
        $bookStack->setSecret('token_secret', 'configured');
        $bookStack->save();
        $actor = $this->actor(['integration.view']);
        $scope = ['integration_ids' => [$bookStack->id], 'environment' => 'test'];
        $grant = $this->grant($actor, 'nexum.integrations.read', 'integration-hub.integrations.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/integrations/'.$bookStack->id.'/health', $grant)
            ->assertOk()->assertJsonPath('status', 'failed')
            ->assertJsonPath('reason.code', 'legacy_provider_health_failed');

        [$client, $site] = $this->clientSite('Binding reflection');
        $plesk = $this->pleskIntegration($client, $site);
        $plesk->setSecret('api_key', 'configured');
        $plesk->save();
        $capability = IntegrationHubCapability::query()->where('capability_key', 'nexum.hosting.sites.inspect')->firstOrFail();
        $capability->bindings()->delete();
        IntegrationHubCapabilityBinding::query()->create([
            'capability_id' => $capability->id,
            'installation_key' => 'test-installation',
            'integration_id' => $plesk->id,
            'environment' => 'test',
            'enabled' => true,
        ]);
        $scope = ['client_ids' => [$client->id], 'site_ids' => [$site->id], 'integration_ids' => [$plesk->id], 'environment' => 'test'];
        $grant = $this->grant($actor, 'nexum.integrations.read', 'integration-hub.integrations.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/integrations/'.$plesk->id, $grant)
            ->assertOk()->assertJsonPath('data.capabilities.0.key', 'nexum.hosting.sites.inspect');
    }

    #[Test]
    public function integration_catalogue_enforces_internal_installation_client_and_site_ownership(): void
    {
        [$client, $site] = $this->clientSite('Visible integration owner');
        [$hiddenClient, $hiddenSite] = $this->clientSite('Hidden integration owner');
        $definitions = [
            ['name' => 'Internal', 'owner_scope' => 'internal'],
            ['name' => 'Installation', 'owner_scope' => 'installation'],
            ['name' => 'Client', 'owner_scope' => 'client', 'client_id' => $client->id],
            ['name' => 'Site', 'owner_scope' => 'site', 'client_id' => $client->id, 'client_site_id' => $site->id],
        ];
        $visible = collect($definitions)->map(function (array $definition): Integration {
            $integration = Integration::query()->create($definition + [
                'type' => 'plesk', 'installation_key' => 'test-installation', 'environment' => 'test',
                'server' => 'https://plesk.example.test:8443', 'status' => 'active',
            ]);
            $integration->setSecret('api_key', 'configured');
            $integration->save();

            return $integration;
        });
        $hidden = $this->pleskIntegration($hiddenClient, $hiddenSite);
        $hidden->setSecret('api_key', 'configured');
        $hidden->save();

        $actor = $this->actor(['integration.view']);
        $scope = [
            'client_ids' => [$client->id], 'site_ids' => [$site->id],
            'integration_ids' => $visible->pluck('id')->all(), 'environment' => 'test',
        ];
        $grant = $this->grant($actor, 'nexum.integrations.read', 'integration-hub.integrations.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/integrations?per_page=10', $grant)
            ->assertOk()->assertJsonCount(4, 'data')
            ->assertJsonMissing(['id' => $hidden->id]);

        $grant = $this->grant($actor, 'nexum.integrations.read', 'integration-hub.integrations.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/integrations/'.$hidden->id, $grant)
            ->assertNotFound()->assertJsonPath('reason.code', 'record_not_found_or_out_of_scope');
    }

    #[Test]
    public function domain_normalization_and_api_use_explicit_client_site_scope(): void
    {
        $normalizer = app(DomainNormalizer::class);
        $this->assertSame('example.com', $normalizer->normalize('EXAMPLE.COM.')['ascii']);
        [$client, $site] = $this->clientSite('Domain client');
        [$otherClient, $otherSite] = $this->clientSite('Other domain client');
        $domain = $this->domain($client, $site, null, 'Example.COM.');
        $other = $this->domain($otherClient, $otherSite, null, 'hidden.example');
        $actor = $this->actor(['client.view']);
        $scope = ['client_ids' => [$client->id], 'site_ids' => [$site->id]];

        $grant = $this->grant($actor, 'nexum.domains.read', 'integration-hub.domains.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/domains', $grant)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $domain->id);

        $grant = $this->grant($actor, 'nexum.domains.read', 'integration-hub.domains.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/domains/'.$other->id, $grant)
            ->assertNotFound()->assertJsonPath('reason.code', 'record_not_found_or_out_of_scope');

        if (function_exists('idn_to_ascii')) {
            $this->assertSame('xn--bcher-kva.de', $normalizer->normalize('BÜCHER.DE.')['ascii']);
        }

        $grant = $this->grant($actor, 'nexum.domains.read', 'integration-hub.domains.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/domains?hostname='.urlencode('https://invalid.example'), $grant)
            ->assertUnprocessable()->assertJsonPath('status', 'failed')->assertJsonPath('reason.code', 'domain_invalid');
    }

    #[Test]
    public function domain_binding_requires_explicit_transfer_for_an_existing_hostname(): void
    {
        [$owner, $ownerSite] = $this->clientSite('Current owner');
        [$nextOwner, $nextSite] = $this->clientSite('Next owner');
        $domain = $this->domain($owner, $ownerSite, null, 'transfer.example');

        $this->assertSame(1, Artisan::call('integration-hub:bind-domain', [
            'hostname' => 'transfer.example', 'client' => $nextOwner->id, 'site' => $nextSite->id,
            '--environment' => 'test',
        ]));
        $this->assertSame($owner->id, $domain->fresh()->client_id);

        $this->assertSame(0, Artisan::call('integration-hub:bind-domain', [
            'hostname' => 'transfer.example', 'client' => $nextOwner->id, 'site' => $nextSite->id,
            '--environment' => 'test', '--transfer' => true,
        ]));
        $transferred = $domain->fresh();
        $this->assertSame($nextOwner->id, $transferred->client_id);
        $this->assertSame($nextSite->id, $transferred->client_site_id);
        $this->assertSame('unknown', $transferred->verification_status);
        $this->assertNull($transferred->observed_at);
        $this->assertSame($owner->id, $transferred->metadata['last_transfer']['from_client_id']);
    }

    #[Test]
    public function domain_contract_surfaces_duplicate_inactive_orphaned_and_stale_mappings(): void
    {
        [$client, $site] = $this->clientSite('Domain lifecycle');
        $domain = $this->domain($client, $site, null, 'lifecycle.example');
        $actor = $this->actor(['client.view']);
        $scope = ['client_ids' => [$client->id], 'site_ids' => [$site->id], 'environment' => 'test'];

        try {
            $this->domain($client, $site, null, 'LIFECYCLE.EXAMPLE.');
            $this->fail('Duplicate normalized domain was created.');
        } catch (\Illuminate\Database\QueryException) {
            $this->assertDatabaseCount('integration_hub_domains', 1);
        }

        $domain->forceFill(['lifecycle_state' => 'inactive'])->save();
        $grant = $this->grant($actor, 'nexum.domains.read', 'integration-hub.domains.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/domains/'.$domain->id, $grant)
            ->assertOk()->assertJsonPath('status', 'unavailable');

        $domain->forceFill(['lifecycle_state' => 'orphaned'])->save();
        $grant = $this->grant($actor, 'nexum.domains.read', 'integration-hub.domains.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/domains/'.$domain->id, $grant)
            ->assertOk()->assertJsonPath('data.lifecycle', 'orphaned');

        $domain->forceFill([
            'lifecycle_state' => 'active', 'verification_status' => 'verified',
            'observed_at' => now()->subSeconds($domain->stale_after_seconds + 1),
        ])->save();
        $grant = $this->grant($actor, 'nexum.domains.read', 'integration-hub.domains.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/domains/'.$domain->id, $grant)
            ->assertOk()->assertJsonPath('status', 'stale');
    }

    #[Test]
    public function plesk_xml_contract_is_read_only_bounded_and_classifies_provider_failures(): void
    {
        [$client, $site] = $this->clientSite('Plesk contract');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'not-returned');
        $integration->save();
        $domain = $this->domain($client, $site, $integration, 'example.com', '42');
        Http::fakeSequence()
            ->push($this->pleskSuccessXml(), 200, ['Content-Type' => 'text/xml'])
            ->push('<not-xml', 200)
            ->push('', 401)
            ->push('', 429)
            ->push('', 429);

        $result = app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain);
        $this->assertSame('ok', $result['status']);
        $this->assertSame('example.com', $result['data']['site']['hostname']);
        $this->assertStringNotContainsString('not-returned', json_encode($result));
        Http::assertSent(function ($request): bool {
            $body = $request->body();

            return $request->url() === 'https://plesk.example.test:8443/enterprise/control/agent.php'
                && str_contains($body, '<webspace><get>')
                && str_contains($body, '<site><get>')
                && ! preg_match('/<(add|set|del|install|remove|generate)>/i', $body);
        });

        $this->assertSame('provider_schema_invalid', app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain)['reason_code']);
        $this->assertSame('provider_authentication_failed', app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain)['reason_code']);
        $this->assertSame('provider_rate_limited', app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain)['reason_code']);

        $this->assertSame(['__construct', 'inspect'], get_class_methods(PleskReadOnlyAdapter::class));
    }

    #[Test]
    public function plesk_client_refuses_redirects_and_wrong_subscription_relationships(): void
    {
        [$client, $site] = $this->clientSite('Plesk relationship');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'not-returned');
        $integration->save();
        $domain = $this->domain($client, $site, $integration, 'example.com', '42');

        Http::fakeSequence()
            ->push('', 302, ['Location' => 'https://redirect.example.test'])
            ->push(str_replace('<webspace-id>42</webspace-id>', '<webspace-id>99</webspace-id>', $this->pleskSuccessXml()), 200, ['Content-Type' => 'text/xml']);
        $result = app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain);
        $this->assertSame('provider_request_rejected', $result['reason_code']);
        Http::assertSentCount(1);

        $result = app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain);
        $this->assertSame('unknown', $result['status']);
        $this->assertSame('provider_site_subscription_mismatch', $result['reason_code']);
    }

    #[Test]
    public function plesk_client_classifies_empty_and_connection_timeout_responses_without_success(): void
    {
        [$client, $site] = $this->clientSite('Plesk empty timeout');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'not-returned');
        $integration->save();
        $domain = $this->domain($client, $site, $integration, 'example.com', '42');
        Http::fakeSequence()
            ->push('', 200, ['Content-Type' => 'text/xml'])
            ->pushFailedConnection('bounded timeout')
            ->pushFailedConnection('bounded timeout');

        $empty = app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain);
        $this->assertSame('failed', $empty['status']);
        $this->assertSame('provider_schema_invalid', $empty['reason_code']);

        $timeout = app(\App\Modules\Integration\Services\IntegrationHub\PleskXmlClient::class)->inspect($integration, $domain);
        $this->assertSame('unavailable', $timeout['status']);
        $this->assertSame('provider_timeout_or_connection_failed', $timeout['reason_code']);
        $this->assertTrue($timeout['retryable']);
        Http::assertSentCount(3);
    }

    #[Test]
    public function tls_target_resolution_rejects_private_reserved_and_documentation_networks(): void
    {
        $resolver = app(TlsTargetResolver::class);
        $this->assertTrue($resolver->isPublicAddress('8.8.8.8'));
        $this->assertTrue($resolver->isPublicAddress('2606:4700:4700::1111'));

        foreach ([
            '0.0.0.0', '10.0.0.1', '100.64.0.1', '127.0.0.1', '169.254.1.1',
            '172.16.0.1', '192.0.2.1', '192.168.1.1', '198.18.0.1', '198.51.100.1',
            '203.0.113.1', '224.0.0.1', '::', '::1', '::ffff:127.0.0.1', '2001:db8::1',
            'fc00::1', 'fe80::1', 'ff02::1',
        ] as $address) {
            $this->assertFalse($resolver->isPublicAddress($address), $address.' must not be a TLS target.');
        }
    }

    #[Test]
    public function plesk_adapter_checks_emergency_controls_and_cancellation_before_provider_access(): void
    {
        [$client, $site] = $this->clientSite('Plesk direct controls');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'not-returned');
        $integration->save();
        $domain = $this->domain($client, $site, $integration, 'example.com', '42');
        Http::fake();
        IntegrationHubEmergencyControl::query()->create([
            'installation_key' => 'test-installation', 'control_key' => 'integration:'.$integration->id,
            'scope_type' => 'integration', 'scope_id' => $integration->id, 'integration_id' => $integration->id,
            'is_disabled' => true, 'reason_code' => 'operator_stop', 'changed_by' => $this->serviceActor->id,
            'correlation_id' => fake()->uuid(), 'disabled_at' => now(),
        ]);

        $result = app(PleskReadOnlyAdapter::class)->inspect($integration, $site, collect([$domain]));
        $this->assertSame('unavailable', $result['status']);
        $this->assertSame('emergency_control_active', $result['reason_code']);
        Http::assertNothingSent();

        IntegrationHubEmergencyControl::query()->delete();
        $execution = IntegrationHubExecution::query()->create([
            'correlation_id' => fake()->uuid(), 'installation_key' => 'test-installation',
            'capability_key' => 'nexum.hosting.sites.inspect', 'capability_version' => '1.0',
            'policy_digest' => hash('sha256', 'policy'), 'status' => 'cancelled', 'cancelled_at' => now(),
        ]);
        $result = app(PleskReadOnlyAdapter::class)->inspect($integration, $site, collect([$domain]), $execution);
        $this->assertSame('execution_cancelled', $result['reason_code']);
        Http::assertNothingSent();
    }

    #[Test]
    public function plesk_adapter_reports_bound_domains_missing_from_provider_as_partial(): void
    {
        [$client, $site] = $this->clientSite('Plesk partial mapping');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'not-returned');
        $integration->save();
        $primary = $this->domain($client, $site, $integration, 'example.com', '42');
        $missing = $this->domain($client, $site, $integration, 'missing.example.com');
        $xml = preg_replace('/<site-alias>.*<\/site-alias>/s', '', $this->pleskSuccessXml());
        Http::fake(['*' => Http::response($xml, 200, ['Content-Type' => 'text/xml'])]);
        $this->app->instance(InspectsTlsCertificates::class, new class implements InspectsTlsCertificates
        {
            public function inspect(string $hostname): array
            {
                return ['status' => 'ok', 'reason_code' => null, 'hostname' => $hostname, 'hostname_verified' => true, 'observed_at' => now()->toIso8601String()];
            }
        });

        $result = app(PleskReadOnlyAdapter::class)->inspect($integration, $site, collect([$primary, $missing]));
        $this->assertSame('partial', $result['status']);
        $this->assertSame('bound_domain_not_observed_by_provider', $result['reason_code']);
        $this->assertSame(1, $result['data']['verification']['missing_bound_domain_count']);
        $this->assertSame('unknown', $missing->fresh()->verification_status);
    }

    #[Test]
    public function hosting_inspection_creates_durable_execution_and_reuses_idempotency_without_second_provider_call(): void
    {
        [$client, $site] = $this->clientSite('Durable hosting');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'never-return-this');
        $integration->save();
        $this->domain($client, $site, $integration, 'example.com', '42');
        $this->domain($client, $site, $integration, 'www.example.com');
        Http::fake(['*' => Http::response($this->pleskSuccessXml(), 200, ['Content-Type' => 'text/xml'])]);
        $this->app->instance(InspectsTlsCertificates::class, new class implements InspectsTlsCertificates
        {
            public function inspect(string $hostname): array
            {
                return ['status' => 'ok', 'reason_code' => null, 'hostname' => $hostname, 'hostname_verified' => true, 'expires_at' => now()->addMonth()->toIso8601String(), 'observed_at' => now()->toIso8601String()];
            }
        });
        $actor = $this->actor(['integration.view']);
        $scope = ['client_ids' => [$client->id], 'site_ids' => [$site->id], 'integration_ids' => [$integration->id], 'environment' => 'test'];

        $grant = $this->grant($actor, 'nexum.hosting.sites.inspect', 'integration-hub.hosting.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/hosting/sites/'.$site->id.'/inspect', $grant, ['Idempotency-Key' => 'stable-inspection'])
            ->assertOk()->assertJsonPath('status', 'ok')->assertJsonPath('data.site.hostname', 'example.com')
            ->assertJsonMissing(['never-return-this']);
        $execution = IntegrationHubExecution::query()->firstOrFail();
        $this->assertSame('completed', $execution->status);
        $this->assertDatabaseCount('integration_hub_execution_steps', 3);

        $grant = $this->grant($actor, 'nexum.hosting.sites.inspect', 'integration-hub.hosting.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/hosting/sites/'.$site->id.'/inspect', $grant, ['Idempotency-Key' => 'stable-inspection'])
            ->assertOk()->assertJsonPath('meta.idempotent_replay', true);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('integration_hub_executions', 1);
    }

    #[Test]
    public function running_idempotent_execution_returns_conflict_without_a_second_provider_call(): void
    {
        [$client, $site] = $this->clientSite('In-progress hosting');
        $integration = $this->pleskIntegration($client, $site);
        $integration->setSecret('api_key', 'never-return-this');
        $integration->save();
        $this->domain($client, $site, $integration, 'example.com', '42');
        $actor = $this->actor(['integration.view']);
        $scope = [
            'installation' => 'test-installation', 'client_ids' => [$client->id],
            'site_ids' => [$site->id], 'integration_ids' => [$integration->id], 'environment' => 'test',
        ];
        $request = Request::create('/api/v1/integration-hub/hosting/sites/'.$site->id.'/inspect', 'GET');
        $request->headers->set('Idempotency-Key', 'running-inspection');
        $request->attributes->set('integration_hub_correlation_id', fake()->uuid());
        $request->attributes->set('integration_hub_claims', [
            'actor' => ['id' => $actor->id], 'workload_id' => null,
            'capability' => ['key' => 'nexum.hosting.sites.inspect', 'version' => '1.0'],
            'scope' => $scope, 'policy_digest' => hash('sha256', 'policy'),
        ]);
        $request->setUserResolver(fn (): User => $this->serviceActor);
        app(\App\Modules\Integration\Services\IntegrationHub\ExecutionRecorder::class)->begin($request, [
            'target_type' => 'client_site', 'target_id' => $site->id,
        ]);
        Http::fake();

        $grant = $this->grant($actor, 'nexum.hosting.sites.inspect', 'integration-hub.hosting.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/hosting/sites/'.$site->id.'/inspect', $grant, ['Idempotency-Key' => 'running-inspection'])
            ->assertStatus(409)->assertJsonPath('reason.code', 'execution_in_progress')
            ->assertJsonPath('reason.retryable', true);
        Http::assertNothingSent();
        $this->assertDatabaseCount('integration_hub_executions', 1);
    }

    #[Test]
    public function execution_and_audit_reads_require_every_populated_scope_dimension_to_match(): void
    {
        [$client, $site] = $this->clientSite('Visible scope');
        [$hiddenClient, $hiddenSite] = $this->clientSite('Hidden scope');
        $integration = $this->pleskIntegration($client, $site);
        $hiddenIntegration = $this->pleskIntegration($hiddenClient, $hiddenSite);
        $base = [
            'correlation_id' => fake()->uuid(), 'installation_key' => 'test-installation',
            'capability_key' => 'nexum.hosting.sites.inspect', 'capability_version' => '1.0',
            'policy_digest' => hash('sha256', 'policy'), 'status' => 'completed', 'result_status' => 'ok',
        ];
        $visibleExecution = IntegrationHubExecution::query()->create($base + [
            'client_id' => $client->id, 'client_site_id' => $site->id, 'integration_id' => $integration->id,
        ]);
        $mixedExecution = IntegrationHubExecution::query()->create(array_merge($base, [
            'correlation_id' => fake()->uuid(), 'client_id' => $client->id,
            'client_site_id' => $site->id, 'integration_id' => $hiddenIntegration->id,
        ]));
        $unscopedExecution = IntegrationHubExecution::query()->create(array_merge($base, ['correlation_id' => fake()->uuid()]));

        $auditBase = [
            'correlation_id' => fake()->uuid(), 'installation_key' => 'test-installation',
            'capability_key' => 'nexum.hosting.sites.inspect', 'capability_version' => '1.0',
            'decision' => 'allowed', 'result_status' => 'ok', 'reason_code' => 'test', 'http_status' => 200,
        ];
        $visibleAudit = IntegrationHubAuditEvent::query()->create($auditBase + [
            'client_id' => $client->id, 'client_site_id' => $site->id, 'integration_id' => $integration->id,
        ]);
        $mixedAudit = IntegrationHubAuditEvent::query()->create(array_merge($auditBase, [
            'correlation_id' => fake()->uuid(), 'client_id' => $client->id,
            'client_site_id' => $site->id, 'integration_id' => $hiddenIntegration->id,
        ]));
        $unscopedAudit = IntegrationHubAuditEvent::query()->create(array_merge($auditBase, ['correlation_id' => fake()->uuid()]));

        $actor = $this->actor(['integration.view', 'integration.ai_audit_view']);
        $scope = ['client_ids' => [$client->id], 'site_ids' => [$site->id], 'integration_ids' => [$integration->id], 'environment' => 'test'];
        $grant = $this->grant($actor, 'nexum.executions.read', 'integration-hub.executions.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/executions', $grant)
            ->assertOk()->assertJsonFragment(['id' => $visibleExecution->id])
            ->assertJsonMissing(['id' => $mixedExecution->id])->assertJsonMissing(['id' => $unscopedExecution->id]);

        $grant = $this->grant($actor, 'nexum.audit.read', 'integration-hub.audit.read', $scope);
        $this->serviceGet('/api/v1/integration-hub/audit-events', $grant)
            ->assertOk()->assertJsonFragment(['id' => $visibleAudit->id])
            ->assertJsonMissing(['id' => $mixedAudit->id])->assertJsonMissing(['id' => $unscopedAudit->id]);
    }

    #[Test]
    public function approvals_enforce_plan_digest_expiry_and_separation_of_duties(): void
    {
        $requester = $this->actor(['integration.view']);
        $reviewer = $this->actor(['integration.view']);
        $execution = IntegrationHubExecution::query()->create([
            'correlation_id' => fake()->uuid(), 'installation_key' => 'test-installation',
            'actor_id' => $requester->id, 'service_actor_id' => $this->serviceActor->id,
            'capability_key' => 'nexum.hosting.sites.inspect', 'capability_version' => '1.0',
            'policy_digest' => hash('sha256', 'policy'), 'status' => 'running',
        ]);
        $digest = hash('sha256', 'immutable plan');
        $approval = app(ApprovalService::class)->request($execution, $requester, $digest, ['installation' => 'test-installation'], 'high');
        $this->assertSame('input_required', $execution->fresh()->status);
        $this->assertSame($digest, $execution->fresh()->plan_digest);

        try {
            app(ApprovalService::class)->request($execution, $requester, $digest, ['installation' => 'test-installation'], 'high');
            $this->fail('Duplicate pending approval was created.');
        } catch (IntegrationHubDeniedException $exception) {
            $this->assertSame('approval_already_pending', $exception->reasonCode);
        }

        try {
            app(ApprovalService::class)->request($execution, $requester, hash('sha256', 'changed plan'), ['installation' => 'test-installation'], 'high');
            $this->fail('Changed plan reused an existing execution.');
        } catch (IntegrationHubDeniedException $exception) {
            $this->assertSame('approval_plan_changed', $exception->reasonCode);
        }

        try {
            app(ApprovalService::class)->decide($approval, $requester, 'approved', $digest);
            $this->fail('Requester approved own work.');
        } catch (IntegrationHubDeniedException $exception) {
            $this->assertSame('approval_separation_of_duties_required', $exception->reasonCode);
        }

        $decision = app(ApprovalService::class)->decide($approval, $reviewer, 'approved', $digest, ['reference' => 'manual-review']);
        $this->assertSame('approved', $decision->decision);
        $this->assertSame('approved', IntegrationHubApprovalRequest::query()->findOrFail($approval->id)->status);
        $this->assertSame('queued', $execution->fresh()->status);

        $expiredExecution = IntegrationHubExecution::query()->create([
            'correlation_id' => fake()->uuid(), 'installation_key' => 'test-installation',
            'actor_id' => $requester->id, 'service_actor_id' => $this->serviceActor->id,
            'capability_key' => 'nexum.hosting.sites.inspect', 'capability_version' => '1.0',
            'policy_digest' => hash('sha256', 'policy'), 'status' => 'running',
        ]);
        $expired = app(ApprovalService::class)->request($expiredExecution, $requester, $digest, ['installation' => 'test-installation'], 'high', 1);
        Carbon::setTestNow(now()->addMinutes(2));
        try {
            app(ApprovalService::class)->decide($expired, $reviewer, 'approved', $digest);
            $this->fail('Expired approval was accepted.');
        } catch (IntegrationHubDeniedException $exception) {
            $this->assertSame('approval_expired_or_decided', $exception->reasonCode);
        } finally {
            Carbon::setTestNow();
        }
    }

    #[Test]
    public function audit_and_execution_summaries_drop_secret_bearing_fields_and_prune_by_retention(): void
    {
        $event = IntegrationHubAuditEvent::query()->create([
            'correlation_id' => fake()->uuid(), 'installation_key' => 'test-installation',
            'decision' => 'allowed', 'result_status' => 'ok', 'reason_code' => 'allowed',
            'http_status' => 200, 'sanitized_context' => ['filter_keys' => ['page']], 'retain_until' => now()->subMinute(),
        ]);
        $this->assertArrayNotHasKey('authorization', $event->sanitized_context);

        Artisan::call('integration-hub:prune');
        $this->assertDatabaseMissing('integration_hub_audit_events', ['id' => $event->id]);

        $scheduled = collect(app(Schedule::class)->events())
            ->first(fn ($scheduledEvent): bool => $scheduledEvent->description === 'integration-hub.audit.prune');
        $this->assertNotNull($scheduled);
        $this->assertSame('0 4 * * *', $scheduled->expression);
    }

    private function actor(array $permissions): User
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        if ($permissions !== []) {
            $actor->givePermissionTo($permissions);
        }

        return $actor;
    }

    /** @return array{Client,ClientSite} */
    private function clientSite(string $name): array
    {
        $client = Client::query()->create(['name' => $name, 'active' => true]);
        $site = ClientSite::query()->create(['client_id' => $client->id, 'name' => $name.' site', 'is_default' => true]);

        return [$client, $site];
    }

    private function pleskIntegration(Client $client, ClientSite $site): Integration
    {
        return Integration::query()->create([
            'name' => 'Plesk test', 'type' => 'plesk', 'owner_scope' => 'site',
            'installation_key' => 'test-installation', 'client_id' => $client->id,
            'client_site_id' => $site->id, 'environment' => 'test',
            'server' => 'https://plesk.example.test:8443', 'status' => 'active',
        ]);
    }

    private function domain(Client $client, ClientSite $site, ?Integration $integration, string $hostname, ?string $reference = null): IntegrationHubDomain
    {
        $normalized = app(DomainNormalizer::class)->normalize($hostname);

        return IntegrationHubDomain::query()->create([
            'installation_key' => 'test-installation', 'client_id' => $client->id,
            'client_site_id' => $site->id, 'integration_id' => $integration?->id,
            'environment' => 'test', 'hostname_ascii' => $normalized['ascii'],
            'hostname_unicode' => $normalized['unicode'], 'provider_reference' => $reference,
            'lifecycle_state' => 'active', 'verification_status' => 'verified',
            'observed_at' => now(), 'last_verified_at' => now(), 'stale_after_seconds' => 900,
        ]);
    }

    /** @param array<string,mixed> $scope */
    private function grant(User $actor, string $capability, string $ability, array $scope = [], int $ttl = 300): string
    {
        $plain = $actor->createToken('Grant issuer '.fake()->uuid(), ['integration-hub.grants.issue', $ability])->plainTextToken;
        $response = $this->withToken($plain)->postJson('/api/v1/integration-hub/grants', [
            'capability_key' => $capability, 'capability_version' => '1.0',
            'ttl_seconds' => $ttl, 'scope' => $scope,
        ])->assertCreated();

        return (string) $response->json('data.grant');
    }

    /** @param array<string,string> $headers */
    private function serviceGet(string $uri, string $grant, array $headers = [])
    {
        return $this->withHeaders(array_merge([
            'Authorization' => 'Bearer '.$this->serviceToken,
            'X-Nexum-Execution-Grant' => $grant,
        ], $headers))->getJson($uri);
    }

    private function pleskSuccessXml(): string
    {
        return <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<packet>
  <webspace><get><result><status>ok</status><id>42</id><data><gen_info><cr_date>2026-01-01</cr_date><name>example.com</name><ascii-name>example.com</ascii-name><status>0</status><owner-id>7</owner-id><htype>vrt_hst</htype></gen_info><hosting/></data></result></get></webspace>
  <site><get><result><status>ok</status><id>43</id><data><gen_info><webspace-id>42</webspace-id><name>example.com</name><ascii-name>example.com</ascii-name><status>0</status><htype>vrt_hst</htype></gen_info><hosting><vrt_hst><property><name>php</name><value>true</value></property><property><name>document_root</name><value>/secret/path</value></property></vrt_hst></hosting></data></result></get></site>
  <site-alias><get><result><status>ok</status><info><status>0</status><site-id>43</site-id><name>www.example.com</name><ascii-name>www.example.com</ascii-name></info></result></get></site-alias>
</packet>
XML;
    }
}
