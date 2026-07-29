<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Services\AiDataEgressPolicyEvaluator;
use App\Modules\Integration\Services\AiPrivacyGateway;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiCoordinatorGovernanceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);
        $permissions = [
            'report.view', 'ticket.view', 'task.view',
            'integration.ai_audit_view', 'integration.ai_policy_manage',
            'integration.ai_governance_manage', 'integration.ai_workload_manage',
        ];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->admin = User::factory()->create([
            'name' => 'Sensitive Technician',
            'email' => 'sensitive.tech@example.test',
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo($permissions);
    }

    #[Test]
    public function admin_can_review_and_revision_the_installation_policy(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.ai.privacy.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.ai.privacy')
            ->assertSee('Installation maximum policy')
            ->assertSee('Metadata-only access audit');

        $this->actingAs($this->admin)
            ->put(route('tech.admin.system.integrations.ai.privacy.policy.update'), [
                'ai_enabled' => '1',
                'privacy_gateway_enabled' => '1',
                'allowed_processing_modes' => ['local_only'],
                'maximum_data_profile' => 'pseudonymized',
                'context_scope' => 'internal_only',
                'maximum_query_days' => 14,
                'maximum_page_size' => 25,
                'maximum_results' => 100,
                'requests_per_minute' => 20,
                'audit_retention_days' => 120,
                'retain_denials' => '1',
                'payload_retention_days' => 5,
                'change_reason' => 'Approve local-only coordinator foundation.',
                'expires_at' => now()->addMonth()->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $policy = AiDataEgressPolicy::installation()->fresh();
        $this->assertTrue($policy->ai_enabled);
        $this->assertFalse($policy->external_processing_enabled);
        $this->assertSame('pseudonymized', $policy->maximum_data_profile);
        $this->assertSame(2, $policy->revision);
        $this->assertSame($this->admin->id, $policy->reviewed_by);
        $this->assertDatabaseHas('ai_data_egress_policy_revisions', [
            'policy_id' => $policy->id,
            'revision' => 2,
            'changed_by' => $this->admin->id,
            'change_reason' => 'Approve local-only coordinator foundation.',
        ]);
    }

    #[Test]
    public function clean_install_defaults_fail_closed_and_lower_policies_cannot_widen_them(): void
    {
        $policy = AiDataEgressPolicy::installation();
        $evaluator = app(AiDataEgressPolicyEvaluator::class);

        $this->assertFalse($policy->ai_enabled);
        $this->assertFalse($policy->external_processing_enabled);
        $this->assertTrue($policy->privacy_gateway_enabled);
        $this->assertFalse($policy->direct_external_enabled);
        $this->assertSame(['local_only'], $policy->allowed_processing_modes);
        $this->assertSame('aggregate', $policy->maximum_data_profile);
        $this->assertSame('ai_disabled', $evaluator->evaluate($policy, 'local_only', 'aggregate')->reasonCode);

        $policy->update(['ai_enabled' => true]);
        $this->assertTrue($evaluator->evaluate($policy->fresh(), 'local_only', 'aggregate')->allowed);
        $this->assertSame(
            'data_profile_exceeds_installation_maximum',
            $evaluator->evaluate($policy->fresh(), 'local_only', 'pseudonymized')->reasonCode,
        );
        $this->assertSame(
            'processing_mode_not_allowed',
            $evaluator->evaluate($policy->fresh(), 'direct_external', 'aggregate')->reasonCode,
        );

        $policy->update(['expires_at' => now()->subMinute()]);
        $this->assertSame(
            'installation_policy_expired',
            $evaluator->evaluate($policy->fresh(), 'local_only', 'aggregate')->reasonCode,
        );
    }

    #[Test]
    public function privacy_gateway_minimizes_fields_and_redacts_secrets_and_identifiers(): void
    {
        $result = app(AiPrivacyGateway::class)->sanitize(
            payload: [
                'messages' => [[
                    'role' => 'user',
                    'content' => 'Email sensitive.tech@example.test and use api_key=very-secret-value',
                    'internal_note' => 'must not leave',
                ]],
                'debug' => ['authorization' => 'Bearer abcdefghijklmnop'],
            ],
            allowedFields: ['messages.role', 'messages.content'],
            configuredIdentifiers: ['Sensitive Technician'],
        );

        $json = json_encode($result->payload);
        $this->assertStringNotContainsString('sensitive.tech@example.test', $json);
        $this->assertStringNotContainsString('very-secret-value', $json);
        $this->assertStringNotContainsString('internal_note', $json);
        $this->assertStringNotContainsString('authorization', $json);
        $this->assertGreaterThanOrEqual(2, $result->redactionCount);
        $this->assertContains('debug', $result->removedFields);
    }

    #[Test]
    public function bound_read_only_workload_can_read_minimized_worklog_and_is_audited(): void
    {
        [$plainToken, $workload] = $this->coordinatorToken([
            'worklog.read',
            'time-entries.read',
            'tickets.read',
            'tasks.read',
        ]);
        $ticket = Ticket::factory()->create([
            'owner_id' => $this->admin->id,
            'subject' => 'Highly sensitive customer outage',
            'description' => 'Never expose this description.',
        ]);
        $ticket->timeEntries()->create([
            'user_id' => $this->admin->id,
            'work_date' => now()->toDateString(),
            'minutes' => 45,
            'billable' => true,
            'note' => 'Secret technician note.',
        ]);

        $technicians = $this->withToken($plainToken)->getJson('/api/v1/worklog/technicians');
        $technicians->assertOk()
            ->assertJsonPath('data.0.total_minutes', 45)
            ->assertJsonPath('data.0.billable_minutes', 45)
            ->assertJsonPath('meta.profile', 'pseudonymized');

        $entries = $this->withToken($plainToken)->getJson('/api/v1/worklog/time-entries');
        $entries->assertOk()
            ->assertJsonPath('data.0.minutes', 45)
            ->assertJsonPath('data.0.source', 'ticket')
            ->assertJsonPath('meta.profile', 'pseudonymized');
        $body = $entries->getContent();
        $this->assertStringNotContainsString($this->admin->name, $body);
        $this->assertStringNotContainsString($this->admin->email, $body);
        $this->assertStringNotContainsString($ticket->subject, $body);
        $this->assertStringNotContainsString('Secret technician note', $body);
        $this->assertStringNotContainsString('description', $body);
        $this->assertStringNotContainsString('note', $body);
        $this->assertMatchesRegularExpression('/tech_[a-f0-9]{12}/', $body);

        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => now()->subDays(10)]);
        $this->withToken($plainToken)->getJson('/api/v1/tickets/stale?stale_days=7')
            ->assertOk()
            ->assertJsonPath('data.0.age_days', 10)
            ->assertJsonPath('meta.profile', 'pseudonymized');
        $this->withToken($plainToken)->getJson('/api/v1/tasks/stale?stale_days=7')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->assertDatabaseHas('ai_access_events', [
            'ai_workload_profile_id' => $workload->id,
            'decision' => 'allowed',
            'reason_code' => 'allowed',
        ]);
        $event = AiAccessEvent::query()->latest()->firstOrFail();
        $this->assertNull(data_get($event->sanitized_filters, 'token'));
        $this->assertNull(data_get($event->sanitized_filters, 'note'));
    }

    #[Test]
    public function unbound_tokens_are_denied_with_a_stable_audit_reason(): void
    {
        $unbound = $this->admin->createToken('Unbound', ['worklog.read']);
        $this->withToken($unbound->plainTextToken)
            ->getJson('/api/v1/worklog/technicians')
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'workload_token_unbound');
        $this->assertDatabaseHas('ai_access_events', ['reason_code' => 'workload_token_unbound']);
    }

    #[Test]
    public function write_capable_workload_tokens_are_denied_with_a_stable_audit_reason(): void
    {
        [$plainToken] = $this->coordinatorToken(['worklog.read', 'tickets.update']);
        $this->withToken($plainToken)
            ->getJson('/api/v1/worklog/technicians')
            ->assertForbidden()
            ->assertJsonPath('reason_code', 'workload_token_has_broad_or_write_scope');
        $this->assertDatabaseHas('ai_access_events', ['reason_code' => 'workload_token_has_broad_or_write_scope']);
    }

    #[Test]
    public function workload_range_and_page_limits_are_enforced_and_audited(): void
    {
        [$plainToken] = $this->coordinatorToken(['time-entries.read']);

        $this->withToken($plainToken)
            ->getJson('/api/v1/worklog/time-entries?date_from=2026-01-01&date_to=2026-03-01')
            ->assertUnprocessable();
        $this->withToken($plainToken)
            ->getJson('/api/v1/worklog/time-entries?per_page=51')
            ->assertUnprocessable();

        $this->assertDatabaseHas('ai_access_events', [
            'decision' => 'denied',
            'reason_code' => 'downstream_rejected',
            'http_status' => 422,
        ]);
    }

    private function coordinatorToken(array $abilities): array
    {
        AiDataEgressPolicy::installation()->update([
            'ai_enabled' => true,
            'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'pseudonymized',
            'maximum_query_days' => 31,
            'maximum_page_size' => 50,
            'maximum_results' => 200,
            'requests_per_minute' => 30,
        ]);
        $workload = AiWorkloadProfile::query()->create([
            'name' => 'Daily coordinator',
            'slug' => 'daily-coordinator-'.str()->random(6),
            'purpose' => 'Coordinate daily work without natural identifiers.',
            'processing_mode' => 'local_only',
            'maximum_data_profile' => 'pseudonymized',
            'abilities' => $abilities,
            'is_approved' => true,
            'is_active' => true,
            'expires_at' => now()->addMonth(),
            'approved_by' => $this->admin->id,
            'approved_at' => now(),
            'created_by' => $this->admin->id,
        ]);
        $token = $this->admin->createToken('Coordinator test', $abilities);
        AiWorkloadTokenBinding::query()->create([
            'personal_access_token_id' => $token->accessToken->id,
            'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addWeek(),
            'allowed_networks' => [],
            'requests_per_minute' => 30,
            'created_by' => $this->admin->id,
        ]);

        return [$token->plainTextToken, $workload];
    }
}
