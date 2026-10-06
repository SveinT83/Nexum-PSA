<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Models\AiAccessEvent;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiWorkloadProfile;
use App\Modules\Integration\Models\AiWorkloadTokenBinding;
use App\Modules\Integration\Services\AiDataEgressPolicyEvaluator;
use App\Modules\Integration\Services\AiPrivacyGateway;
use App\Modules\Task\Actions\StoreTask;
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
    public function privacy_gateway_can_tokenize_commercial_numbers_without_restoring_configured_identifiers(): void
    {
        $result = app(AiPrivacyGateway::class)->sanitize(
            payload: [
                'content' => implode(' ', [
                    'Order 9900000001',
                    'contact sensitive.tech@example.test',
                ]),
            ],
            allowedFields: ['content'],
            configuredIdentifiers: ['sensitive.tech@example.test'],
            tokenizePii: true,
        );

        $content = $result->payload['content'];
        $this->assertStringNotContainsString('9900000001', $content);
        $this->assertStringContainsString('NEXUM_PRIVACY_TOKEN_A', $content);
        $this->assertStringContainsString('[REDACTED]', $content);
        $this->assertStringNotContainsString('sensitive.tech@example.test', $content);
        $this->assertSame(
            ['NEXUM_PRIVACY_TOKEN_A' => '9900000001'],
            $result->tokenMap,
        );
    }

    #[Test]
    public function privacy_gateway_preserves_descendants_of_an_explicitly_allowed_object_path(): void
    {
        $result = app(AiPrivacyGateway::class)->sanitize(
            payload: [
                'tables' => [[
                    'id' => 't1',
                    'rows' => [[
                        'id' => 't1r1',
                        'cells' => [
                            ['column' => 'Varenr', 'value' => 'NX-SYN-1001'],
                            ['column' => 'Antall', 'value' => '1'],
                        ],
                        'internal_note' => 'must not leave',
                    ]],
                ]],
                'current' => [
                    'document' => [
                        'external_order_number' => 'AI-ORDER-100',
                        'supplier' => ['name' => 'Itegra'],
                    ],
                    'internal_audit' => 'must not leave',
                ],
                'expected_scalar' => ['secret_child' => 'must not leave'],
            ],
            allowedFields: ['tables.id', 'tables.rows.id', 'tables.rows.cells.column', 'tables.rows.cells.value', 'current.document.*', 'expected_scalar'],
        );

        $this->assertSame('Varenr', data_get($result->payload, 'tables.0.rows.0.cells.0.column'));
        $this->assertSame('NX-SYN-1001', data_get($result->payload, 'tables.0.rows.0.cells.0.value'));
        $this->assertSame('AI-ORDER-100', data_get($result->payload, 'current.document.external_order_number'));
        $this->assertSame('Itegra', data_get($result->payload, 'current.document.supplier.name'));
        $this->assertSame([], $result->payload['expected_scalar']);
        $this->assertArrayNotHasKey('internal_note', $result->payload['tables'][0]['rows'][0]);
        $this->assertArrayNotHasKey('internal_audit', $result->payload['current']);
        $this->assertContains('tables.rows.internal_note', $result->removedFields);
        $this->assertContains('current.internal_audit', $result->removedFields);
        $this->assertContains('expected_scalar.secret_child', $result->removedFields);
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
        $ticket = Ticket::factory()->state(['work_context_id' => $this->internalContextId()])->create([
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
        $task = app(StoreTask::class)->handle([
            'title' => 'Sensitive Task title',
            'estimated_minutes' => 30,
        ], $this->admin, $ticket);
        $task->timeEntries()->create([
            'user_id' => $this->admin->id,
            'source_type' => 'ticket_time_entry',
            'work_date' => now()->toDateString(),
            'minutes' => 5,
            'billable' => true,
        ]);
        $ticket->timeEntries()->create([
            'task_id' => $task->id,
            'user_id' => $this->admin->id,
            'type' => 'task_billing',
            'work_date' => now()->toDateString(),
            'minutes' => 30,
            'billable' => true,
        ]);

        $technicians = $this->withToken($plainToken)->getJson('/api/v1/worklog/technicians');
        $technicians->assertOk()
            ->assertJsonPath('data.0.total_minutes', 50)
            ->assertJsonPath('data.0.billable_minutes', 50)
            ->assertJsonPath('data.0.entry_count', 2)
            ->assertJsonPath('meta.profile', 'pseudonymized');

        $entries = $this->withToken($plainToken)->getJson('/api/v1/worklog/time-entries');
        $entries->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['minutes' => 45, 'source' => 'ticket'])
            ->assertJsonFragment(['minutes' => 5, 'source' => 'task'])
            ->assertJsonPath('meta.profile', 'pseudonymized');
        $body = $entries->getContent();
        $this->assertStringNotContainsString($this->admin->name, $body);
        $this->assertStringNotContainsString($this->admin->email, $body);
        $this->assertStringNotContainsString($ticket->subject, $body);
        $this->assertStringNotContainsString($task->title, $body);
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

    #[Test]
    public function worklog_reports_uncapped_totals_and_period_partition_recovers_every_entry(): void
    {
        [$token] = $this->coordinatorToken(['time-entries.read']);
        AiDataEgressPolicy::installation()->update(['maximum_results' => 3]);
        $ticket = Ticket::factory()->state(['work_context_id' => $this->internalContextId()])->create(['owner_id' => $this->admin->id]);
        foreach (['2025-09-01', '2025-09-01', '2025-09-02', '2025-09-02', '2025-09-03'] as $date) {
            $ticket->timeEntries()->create([
                'user_id' => $this->admin->id, 'work_date' => $date,
                'minutes' => 10, 'billable' => true,
            ]);
        }
        $range = '/api/v1/worklog/time-entries?date_from=2025-09-01&date_to=2025-09-03&per_page=2';
        $this->withToken($token)->getJson($range)->assertOk()
            ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.available_total', 3)
            ->assertJsonPath('meta.truncated', true)->assertJsonPath('meta.next_page', 2)
            ->assertJsonPath('meta.recovery', 'split_date_range')->assertJsonCount(2, 'data');
        $this->withToken($token)->getJson($range.'&page=2')->assertOk()
            ->assertJsonPath('meta.total', 5)->assertJsonPath('meta.next_page', null)
            ->assertJsonCount(1, 'data');
        $this->withToken($token)->getJson($range.'&page=3')->assertOk()->assertJsonCount(0, 'data');

        // Discard truncated parent rows; only disjoint complete child windows are accepted.
        $aliases = [];
        foreach (['2025-09-01', '2025-09-02', '2025-09-03'] as $date) {
            $response = $this->withToken($token)->getJson('/api/v1/worklog/time-entries?'.http_build_query([
                'date_from' => $date, 'date_to' => $date, 'per_page' => 2,
            ]))->assertOk()->assertJsonPath('meta.truncated', false);
            $aliases = array_merge($aliases, array_column($response->json('data'), 'entry_alias'));
        }
        $this->assertCount(5, $aliases);
        $this->assertCount(5, array_unique($aliases));
    }

    #[Test]
    public function a_dense_single_day_and_truncated_technicians_never_claim_completeness(): void
    {
        [$token] = $this->coordinatorToken(['time-entries.read', 'worklog.read']);
        AiDataEgressPolicy::installation()->update(['maximum_results' => 1]);
        $ticket = Ticket::factory()->state(['work_context_id' => $this->internalContextId()])->create(['owner_id' => $this->admin->id]);
        foreach ([$this->admin, User::factory()->create()] as $user) {
            $ticket->timeEntries()->create([
                'user_id' => $user->id, 'work_date' => '2025-09-01',
                'minutes' => 10, 'billable' => false,
            ]);
        }
        foreach (['time-entries', 'technicians'] as $endpoint) {
            $this->withToken($token)->getJson('/api/v1/worklog/'.$endpoint.'?date_from=2025-09-01&date_to=2025-09-01')
                ->assertOk()->assertJsonPath('meta.total', 2)
                ->assertJsonPath('meta.available_total', 1)->assertJsonPath('meta.returned_count', 1)
                ->assertJsonPath('meta.truncated', true)
                ->assertJsonPath('meta.recovery', 'policy_limit_requires_review')
                ->assertJsonPath('meta.maximum_results', 1)->assertJsonCount(1, 'data');
        }
    }

    #[Test]
    public function inclusive_date_limit_accepts_exact_days_and_rejects_resolved_reverse_ranges(): void
    {
        [$token] = $this->coordinatorToken(['time-entries.read']);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries?date_from=2025-09-01&date_to=2025-10-01')
            ->assertOk()->assertJsonPath('meta.total', 0)->assertJsonPath('meta.truncated', false);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries?date_from=2025-09-01&date_to=2025-10-02')
            ->assertUnprocessable();
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries?date_to=2025-09-07')
            ->assertOk()->assertJsonPath('meta.date_from', '2025-09-01');
        $this->travelTo(now()->setDate(2026, 9, 27));
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries?date_from=2026-09-28')
            ->assertUnprocessable();
    }

    #[Test]
    public function worklog_default_result_ceiling_remains_enforced_across_all_pages(): void
    {
        [$token] = $this->coordinatorToken(['time-entries.read']);
        $ticket = Ticket::factory()->state(['work_context_id' => $this->internalContextId()])->create(['owner_id' => $this->admin->id]);
        $rows = array_fill(0, 201, [
            'ticket_id' => $ticket->id, 'user_id' => $this->admin->id,
            'work_date' => '2025-09-01 00:00:00', 'minutes' => 1, 'billable' => true,
        ]);
        DB::table('ticket_time_entries')->insert($rows);
        $aliases = [];
        for ($page = 1; $page <= 4; $page++) {
            $response = $this->withToken($token)->getJson('/api/v1/worklog/time-entries?'.http_build_query([
                'date_from' => '2025-09-01', 'date_to' => '2025-09-01', 'per_page' => 50, 'page' => $page,
            ]))->assertOk()->assertJsonPath('meta.total', 201)
                ->assertJsonPath('meta.available_total', 200)->assertJsonPath('meta.truncated', true)
                ->assertJsonPath('meta.next_page', $page < 4 ? $page + 1 : null)
                ->assertJsonCount(50, 'data');
            $aliases = array_merge($aliases, array_column($response->json('data'), 'entry_alias'));
        }
        $this->assertCount(200, array_unique($aliases));
    }

    #[Test]
    public function documented_scope_expiry_network_and_disabled_policy_denials_remain_closed(): void
    {
        [$token, $workload] = $this->coordinatorToken(['time-entries.read']);
        $binding = AiWorkloadTokenBinding::query()->where('ai_workload_profile_id', $workload->id)->firstOrFail();
        $this->withToken($token)->getJson('/api/v1/worklog/technicians')
            ->assertForbidden()->assertJsonPath('reason_code', 'required_scope_missing');
        $binding->update(['allowed_networks' => ['192.0.2.1']]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')
            ->assertForbidden()->assertJsonPath('reason_code', 'network_not_allowed');
        $binding->update(['allowed_networks' => [], 'expires_at' => now()->subMinute()]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')
            ->assertForbidden()->assertJsonPath('reason_code', 'workload_token_expired_or_revoked');
        $binding->update(['expires_at' => now()->addDay()]);
        AiDataEgressPolicy::installation()->update(['ai_enabled' => false]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')
            ->assertForbidden()->assertJsonPath('reason_code', 'ai_disabled');
    }

    #[Test]
    public function worklog_requires_both_source_permissions_instead_of_silently_omitting_a_domain(): void
    {
        [$token] = $this->coordinatorToken(['time-entries.read']);
        $this->admin->revokePermissionTo('task.view');
        app('auth')->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertForbidden();
    }

    #[Test]
    public function registered_task_estimates_are_labelled_instead_of_presented_as_measured_time(): void
    {
        [$token] = $this->coordinatorToken(['time-entries.read']);
        $task = app(StoreTask::class)->handle(['title' => 'Estimate basis', 'estimated_minutes' => 30], $this->admin);
        foreach (['estimated', 'manual', 'future_source'] as $source) {
            $task->timeEntries()->create(['user_id' => $this->admin->id, 'work_date' => now()->toDateString(), 'minutes' => 10, 'billable' => false, 'source_type' => $source]);
        }
        $response = $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertOk()->assertJsonCount(3, 'data');
        $bases = array_column($response->json('data'), 'registration_basis');
        sort($bases);
        $this->assertSame(['estimated', 'recorded', 'unknown'], $bases);
    }

    private function internalContextId(): int
    {
        return \App\Modules\WorkContext\Models\WorkContext::query()->firstOrCreate(
            ['type' => 'internal', 'client_id' => null], ['name' => 'Internal', 'is_default' => true],
        )->id;
    }

    #[Test]
    public function installation_context_maximum_and_workload_intersection_are_enforced(): void
    {
        [$token, $workload] = $this->coordinatorToken(['time-entries.read', 'tickets.read', 'tasks.read']);
        $client = \App\Models\Clients\Client::factory()->create();
        $context = \App\Modules\WorkContext\Models\WorkContext::query()->create(['type' => 'client', 'client_id' => $client->id, 'name' => 'Client']);
        $ticket = Ticket::factory()->create(['client_id' => $client->id, 'work_context_id' => $context->id]);
        $ticket->timeEntries()->create(['user_id' => $this->admin->id, 'work_date' => now()->toDateString(), 'minutes' => 15, 'billable' => true]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertOk()->assertJsonCount(0, 'data');
        $task = app(StoreTask::class)->handle(['title' => 'Customer task'], $this->admin, $ticket);
        DB::table('tickets')->where('id', $ticket->id)->update(['updated_at' => now()->subDays(10)]);
        DB::table('tasks')->where('id', $task->id)->update(['updated_at' => now()->subDays(10)]);
        $this->withToken($token)->getJson('/api/v1/tickets/stale')->assertOk()->assertJsonCount(0, 'data');
        $this->withToken($token)->getJson('/api/v1/tasks/stale')->assertOk()->assertJsonCount(0, 'data');
        AiDataEgressPolicy::installation()->update(['context_scope' => 'selected_clients']);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertForbidden()->assertJsonPath('reason_code', 'workload_context_scope_missing');
        $workload->update(['allowed_client_ids' => [$client->id]]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertOk()->assertJsonCount(1, 'data');
        $workload->update(['allowed_work_context_ids' => [$this->internalContextId()]]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertOk()->assertJsonCount(0, 'data');
        AiDataEgressPolicy::installation()->update(['context_scope' => 'selected_work_contexts']);
        $workload->update(['allowed_work_context_ids' => [$context->id]]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertOk()->assertJsonCount(1, 'data');
        $ticket->update(['work_context_id' => $this->internalContextId()]);
        $this->withToken($token)->getJson('/api/v1/worklog/time-entries')->assertOk()->assertJsonCount(0, 'data');
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
