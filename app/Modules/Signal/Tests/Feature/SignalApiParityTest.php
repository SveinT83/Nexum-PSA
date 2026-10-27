<?php

namespace App\Modules\Signal\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Contact\Models\Contact;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRule;
use App\Modules\Signal\Models\SignalRuleExecution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SignalApiParityTest extends TestCase
{
    use RefreshDatabase;

    private static array $contractDocument = [];

    /** Freeze the published contract before migration setup; shared generation may run concurrently. */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$contractDocument = json_decode(file_get_contents(__DIR__.'/../../../../../storage/api-docs/api-docs.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    protected function actor(array $rights, array $scopes = ['*']): User
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach ($rights as $right) {
            Permission::findOrCreate($right, 'web');
        }
        $actor->givePermissionTo($rights);
        $this->app['auth']->forgetGuards();
        $this->withToken($actor->createToken('Signal parity', $scopes)->plainTextToken);

        return $actor;
    }

    protected function signal(array $overrides = []): Signal
    {
        return Signal::create(array_merge(['source_domain' => 'test', 'signal_type' => 'alert',
            'severity' => 'warning', 'confidence' => 95, 'status' => 'new',
            'summary' => 'Signal parity fixture', 'payload' => [], 'occurred_at' => now()], $overrides));
    }

    protected function rule(array $overrides = []): SignalRule
    {
        return SignalRule::create(array_merge(['name' => 'Parity rule', 'is_active' => true,
            'priority' => 100, 'stop_processing' => false, 'conditions' => [],
            'actions' => [['type' => 'emit_signal', 'signal_type' => 'derived']]], $overrides));
    }

    public function test_feed_discovery_matches_browser_filters_and_is_pure(): void
    {
        $actor = $this->actor(['signal.view'], ['signals.read']);
        $recent = $this->signal(['summary' => 'Recent needle']);
        $this->signal(['occurred_at' => now()->subDays(60), 'summary' => 'Old needle']);
        $before = [AiAgent::count(), Permission::count()];
        $this->getJson('/api/v1/signals/context')->assertOk()->assertJsonPath('data.default_range', '30');
        $this->getJson('/api/v1/signals?q=needle&sort=confidence&direction=asc&per_page=1')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $recent->id);
        $this->getJson('/api/v1/signals?range=all&q=needle')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/v1/signals?range=custom&from='.now()->subDay()->format('Y-m-d').'&to='.now()->format('Y-m-d'))
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/signals?sort=payload')->assertUnprocessable();
        $this->getJson('/api/v1/signals?per_page=101')->assertUnprocessable();
        $this->getJson('/api/v1/signals?unsupported=1')->assertUnprocessable();
        $this->assertSame($before, [AiAgent::count(), Permission::count()]);
    }

    public function test_detail_and_execution_history_are_typed_and_parent_bound(): void
    {
        $this->actor(['signal.view'], ['signals.read']);
        $signal = $this->signal(['payload' => ['access_token' => 'never disclose', 'token' => 'never disclose', 'credentials' => 'never disclose', 'value' => 42]]);
        $rule = $this->rule();
        $execution = $signal->executions()->create(['signal_rule_id' => $rule->id, 'attempt' => 1,
            'status' => 'failed', 'actions' => $rule->actions,
            'results' => [['action_index' => 0, 'type' => 'emit_signal', 'status' => 'failed', 'message' => 'SQL secret']],
            'error' => 'SQL secret', 'executed_at' => now()]);
        $this->getJson('/api/v1/signals/'.$signal->id)->assertOk()->assertHeader('ETag')
            ->assertJsonPath('data.payload.access_token', '[redacted]')->assertJsonPath('data.payload.token', '[redacted]')->assertJsonPath('data.payload.credentials', '[redacted]')->assertJsonPath('data.executions_count', 1);
        $this->getJson('/api/v1/signals/'.$signal->id.'/executions')->assertOk()->assertJsonPath('data.0.id', $execution->id);
        $this->getJson('/api/v1/signals/'.$signal->id.'/executions/'.$execution->id)->assertOk()
            ->assertHeader('ETag')->assertDontSee('SQL secret')->assertJsonPath('data.has_retryable_actions', true);
        $other = $this->signal();
        $this->getJson('/api/v1/signals/'.$other->id.'/executions/'.$execution->id)->assertNotFound();
    }

    public function test_rule_read_and_narrow_form_discovery_have_separate_rights(): void
    {
        $this->actor(['signal.rule.manage'], ['signals.rules.write']);
        $this->rule();
        $this->getJson('/api/v1/signals/rules/context')->assertOk()
            ->assertJsonStructure(['data' => ['defaults', 'condition_fields', 'operators', 'action_types', 'portal_roles']]);
        $this->getJson('/api/v1/signals/lookups/actors?per_page=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/signals/lookups/task-templates')->assertOk();
        $this->getJson('/api/v1/signals/lookups/unknown')->assertNotFound();
        $this->getJson('/api/v1/signals')->assertForbidden();
        $this->getJson('/api/v1/signals/rules')->assertForbidden();
    }

    public function test_rules_and_related_records_use_the_same_exact_parent_queries(): void
    {
        $this->actor(['signal.view', 'client.view', 'contact.view'], ['signals.read', 'signals.rules.read']);
        $rule = $this->rule();
        $client = Client::factory()->create();
        $other = Client::factory()->create();
        $contact = Contact::create(['display_name' => 'Parity contact']);
        $mine = $this->signal(['client_id' => $client->id, 'contact_id' => $contact->id]);
        $this->signal(['client_id' => $other->id]);
        $this->getJson('/api/v1/signals/rules')->assertOk()->assertJsonPath('data.0.id', $rule->id);
        $this->getJson('/api/v1/signals/rules/'.$rule->id)->assertOk()->assertHeader('ETag');
        $this->getJson('/api/v1/signals/related/clients/'.$client->id)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->getJson('/api/v1/signals/related/contacts/'.$contact->id)->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_native_permissions_and_current_revocation_are_enforced(): void
    {
        $actor = $this->actor(['signal.view'], ['signals.read']);
        $this->getJson('/api/v1/signals/context')->assertOk();
        $actor->revokePermissionTo('signal.view');
        $this->getJson('/api/v1/signals/context')->assertForbidden();
    }

    public function test_ingest_receipt_is_encrypted_and_replay_is_bound_to_exact_payload_and_token(): void
    {
        $this->actor(['signal.action.execute'], ['signals.create', 'signals.operations.read']);
        $payload = ['source_domain' => 'test', 'signal_type' => 'ingest', 'process_rules' => false,
            'summary' => 'Private operation evidence', 'payload' => ['secret' => 'do not store raw']];
        $first = $this->withHeader('Idempotency-Key', 'ingest-stable-001')->postJson('/api/v1/signals', $payload)->assertCreated();
        $id = $first->json('data.id');
        $operation = $first->json('operation_id');
        $this->postJson('/api/v1/signals', $payload)->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('replayed', true);
        $this->assertSame(1, Signal::count());
        $this->postJson('/api/v1/signals', [...$payload, 'summary' => 'Another body'])->assertConflict();
        $this->getJson('/api/v1/signals/operations/'.$operation)->assertOk()
            ->assertJsonPath('data.result.data.id', $id)->assertJsonPath('data.state', 'completed');
        $raw = \Illuminate\Support\Facades\DB::table('signal_api_commands')->value('result');
        $this->assertStringNotContainsString('Private operation evidence', $raw);
        $this->assertStringNotContainsString('do not store raw', $raw);
        $this->actor(['signal.action.execute'], ['signals.create', 'signals.operations.read']);
        $this->getJson('/api/v1/signals/operations/'.$operation)->assertNotFound();
    }

    public function test_ingest_requires_native_and_action_authority_without_granting_permissions(): void
    {
        $this->actor(['signal.view'], ['signals.create', 'signals.actions.execute']);
        $payload = ['source_domain' => 'test', 'signal_type' => 'ingest'];
        $this->postJson('/api/v1/signals', $payload)->assertForbidden();
        $this->actor(['signal.action.execute'], ['signals.create']);
        $this->postJson('/api/v1/signals', $payload)->assertForbidden();
        $this->postJson('/api/v1/signals', [...$payload, 'process_rules' => false])->assertCreated()
            ->assertJsonPath('data.executions_count', 0);
        $this->postJson('/api/v1/signals', [...$payload, 'process_rules' => false, 'unsupported' => true])->assertUnprocessable();
    }

    public function test_failed_validation_rolls_back_command_and_revoked_rights_deny_receipt(): void
    {
        $actor = $this->actor(['signal.action.execute'], ['signals.create', 'signals.operations.read']);
        $this->withHeader('Idempotency-Key', 'validation-rollback-01')->postJson('/api/v1/signals', ['source_domain' => 'test'])->assertUnprocessable();
        $this->assertDatabaseCount('signal_api_commands', 0);
        $response = $this->postJson('/api/v1/signals', ['source_domain' => 'test', 'signal_type' => 'ingest', 'process_rules' => false])->assertCreated();
        $actor->revokePermissionTo('signal.action.execute');
        $this->getJson('/api/v1/signals/operations/'.$response->json('operation_id'))->assertForbidden();
    }

    public function test_rule_commands_share_validation_audit_replay_and_state_preconditions(): void
    {
        $actor = $this->actor(['signal.rule.manage', 'signal.view'], ['signals.rules.write', 'signals.rules.read']);
        $payload = ['name' => 'API rule', 'priority' => 20, 'is_active' => false, 'stop_processing' => true,
            'conditions' => ['match' => 'all', 'groups' => [['match' => 'any', 'conditions' => [
                ['field' => 'source_domain', 'operator' => 'equals', 'value' => 'test']]]]],
            'actions' => [['type' => 'emit_signal', 'signal_type' => 'derived']]];
        $this->postJson('/api/v1/signals/rules', $payload)->assertStatus(428);
        $created = $this->withHeader('Idempotency-Key', 'rule-create-0001')->postJson('/api/v1/signals/rules', $payload)->assertCreated()
            ->assertJsonPath('data.created_by', $actor->id)->assertJsonPath('data.is_active', false);
        $id = $created->json('data.id');
        $this->postJson('/api/v1/signals/rules', $payload)->assertCreated()->assertJsonPath('replayed', true);
        $read = $this->getJson('/api/v1/signals/rules/'.$id)->assertOk();
        $etag = $read->headers->get('ETag');
        $this->assertSame($etag, $read->json('data.revision'));
        $this->withHeader('Idempotency-Key', 'rule-update-0001')->putJson('/api/v1/signals/rules/'.$id, $payload)->assertStatus(428);
        $this->withHeader('If-Match', $etag)->putJson('/api/v1/signals/rules/'.$id, [...$payload, 'name' => 'Updated'])
            ->assertOk()->assertJsonPath('data.name', 'Updated')->assertJsonPath('data.updated_by', $actor->id);
        $this->withHeader('Idempotency-Key', 'rule-update-0002')->putJson('/api/v1/signals/rules/'.$id, $payload)->assertStatus(412);
        $this->withoutHeader('If-Match')->withHeader('Idempotency-Key', 'rule-invalid-0001')
            ->postJson('/api/v1/signals/rules', [...$payload, 'actions' => [['type' => 'unknown']]])->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'rule-redacted-001')->postJson('/api/v1/signals/rules', [...$payload, 'actions' => [['type' => 'webhook', 'url' => 'https://example.test/hook?token=[redacted]']]])->assertUnprocessable();
        $this->assertSame(1, SignalRule::count());
    }

    public function test_settings_arrays_share_web_normalization_without_creating_ai_agents(): void
    {
        $this->actor(['signal.rule.manage'], ['signals.settings.read', 'signals.settings.write']);
        $before = AiAgent::count();
        $etag = $this->getJson('/api/v1/signals/settings')->assertOk()->assertJsonPath('data.settings.ai_classification_enabled', false)->headers->get('ETag');
        $payload = ['ai_min_confidence' => 70, 'ai_classification_enabled' => false,
            'ai_source_domains' => ['EMAIL', 'test-source'], 'ai_allowed_signal_types' => ['vendor_notification'],
            'ai_stop_ticket_routing_types' => ['vendor_notification'], 'ai_classification_prompt' => 'Use evidence only.'];
        $this->withHeaders(['Idempotency-Key' => 'settings-update-001', 'If-Match' => $etag])
            ->putJson('/api/v1/signals/settings', $payload)->assertOk()
            ->assertJsonPath('data.settings.ai_source_domains', ['email', 'test_source']);
        $this->assertSame($before, AiAgent::count());
        $this->withHeader('Idempotency-Key', 'settings-update-002')->putJson('/api/v1/signals/settings', $payload)->assertStatus(412);
    }

    public function test_rule_configuration_cannot_impersonate_another_actor(): void
    {
        $actor = $this->actor(['signal.rule.manage'], ['signals.rules.write']);
        $other = User::factory()->create();
        $payload = ['name' => 'Forbidden owner', 'priority' => 100, 'conditions' => [],
            'actions' => [['type' => 'task_follow_up', 'actor_id' => $other->id]]];
        $this->withHeader('Idempotency-Key', 'rule-impersonate-01')->postJson('/api/v1/signals/rules', $payload)->assertForbidden();
        $this->assertDatabaseCount('signal_rules', 0);
    }

    public function test_retry_preview_execution_replay_and_full_rerun_preserve_snapshot_identity(): void
    {
        $this->actor(['signal.action.execute'], ['signals.actions.execute']);
        $signal = $this->signal();
        $rule = $this->rule();
        $execution = $signal->executions()->create(['signal_rule_id' => $rule->id, 'attempt' => 1,
            'status' => 'failed', 'actions' => $rule->actions,
            'results' => [['action_index' => 0, 'type' => 'emit_signal', 'status' => 'failed']], 'executed_at' => now()]);
        $url = '/api/v1/signals/'.$signal->id.'/executions/'.$execution->id;
        $before = [Signal::count(), SignalRuleExecution::count()];
        $preview = $this->postJson($url.'/retry-preview', ['mode' => 'failed'])->assertOk()
            ->assertJsonPath('data.actions.0.action_index', 0)->assertJsonPath('data.actions.0.policy.allowed', true);
        $this->assertSame($before, [Signal::count(), SignalRuleExecution::count()]);
        $etag = $preview->headers->get('ETag');
        $first = $this->withHeaders(['Idempotency-Key' => 'retry-failed-0001', 'If-Match' => $etag])
            ->postJson($url.'/retry', ['mode' => 'failed'])->assertOk()
            ->assertJsonPath('data.execution.retry_of_execution_id', $execution->id)->assertJsonPath('data.execution.attempt', 2);
        $this->postJson($url.'/retry', ['mode' => 'failed'])->assertOk()->assertJsonPath('replayed', true)
            ->assertJsonPath('data.execution.id', $first->json('data.execution.id'));
        $this->withHeader('Idempotency-Key', 'retry-stale-0001')->postJson($url.'/retry', ['mode' => 'failed'])->assertStatus(412);
        $this->withoutHeader('If-Match')->postJson($url.'/retry-preview', ['mode' => 'failed'])->assertOk()->assertJsonPath('data.has_work', false);
        $fresh = $this->postJson($url.'/retry-preview', ['mode' => 'all'])->assertOk()->headers->get('ETag');
        $this->withHeaders(['If-Match' => $fresh, 'Idempotency-Key' => 'retry-full-0001'])->postJson($url.'/retry', ['mode' => 'all'])
            ->assertOk()->assertJsonPath('data.execution.attempt', 3)->assertJsonPath('data.execution.results.0.status', 'skipped');
        $this->assertSame(2, Signal::count());
        $other = $this->signal();
        $this->postJson('/api/v1/signals/'.$other->id.'/executions/'.$execution->id.'/retry-preview', ['mode' => 'all'])->assertNotFound();
    }

    public function test_external_actions_are_blocked_by_default_and_webhook_outbox_is_duplicate_safe(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        $this->actor(['signal.action.execute', 'signal.webhook.manage'], ['signals.create', 'signals.actions.execute', 'signals.providers.execute']);
        $this->rule(['actions' => [['type' => 'webhook', 'url' => 'https://example.test/hook']]]);
        $payload = ['source_domain' => 'test', 'signal_type' => 'external'];
        $blocked = $this->withHeader('Idempotency-Key', 'external-blocked-01')->postJson('/api/v1/signals', $payload)->assertCreated()
            ->assertJsonPath('executions.0.status', 'failed');
        $this->assertDatabaseCount('signal_webhook_deliveries', 0);
        config(['signal.api_external_execution_enabled' => true]);
        $this->withHeader('Idempotency-Key', 'external-enabled-01')->postJson('/api/v1/signals', $payload)->assertCreated()
            ->assertJsonPath('executions.0.results.0.status', 'queued');
        $this->postJson('/api/v1/signals', $payload)->assertCreated()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('signal_webhook_deliveries', 1);
    }

    public function test_direct_api_actions_do_not_use_the_rule_owners_privilege_or_other_client_site(): void
    {
        $owner = User::factory()->create();
        $this->actor(['signal.action.execute'], ['signals.create', 'signals.actions.execute']);
        $this->rule(['created_by' => $owner->id, 'actions' => [['type' => 'task_follow_up', 'actor_id' => $owner->id]]]);
        $this->postJson('/api/v1/signals', ['source_domain' => 'test', 'signal_type' => 'task'])->assertCreated()
            ->assertJsonPath('executions.0.status', 'failed');
        $this->assertDatabaseCount('tasks', 0);
    }

    /** Validate actual successful HTTP payloads against the published contract. */
    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if (strtoupper($method) === 'HEAD' || $response->status() < 200 || $response->status() >= 300 || ! str_starts_with($uri, '/api/v1/')) {
            return $response;
        }
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        if (! str_starts_with($route->getActionName(), 'App\\Modules\\Signal\\')) {
            return $response;
        }
        $document = self::$contractDocument;
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertNotNull($operation, $method.' '.$route->uri().' has a published operation');
        $this->assertArrayHasKey($response->status(), $operation['responses']);
        if ($response->status() !== 204) {
            $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
            $this->assertIsArray($schema, 'A successful response must have a typed contract');
            $errors = [];
            $this->checkSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR),
                $schema, $document['components']['schemas'], '$', $errors);
            $this->assertSame([], $errors, $method.' '.$route->uri().' response contract');
        }
        if ($response->headers->has('ETag') && is_string($response->json('data.revision'))) {
            $this->assertSame($response->headers->get('ETag'), $response->json('data.revision'), 'Body revision and ETag must agree');
        }
        self::$observed[strtolower($method).' /'.$route->uri()] = $response->status();

        return $response;
    }

    private static array $observed = [];

    public static function tearDownAfterClass(): void
    {
        if ($path = getenv('SIGNAL_API_EVIDENCE_PATH')) {
            file_put_contents($path, json_encode(self::$observed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        parent::tearDownAfterClass();
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
        foreach (['oneOf', 'anyOf'] as $mode) {
            if (! isset($schema[$mode])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$mode] as $part) {
                $candidateErrors = [];
                $this->checkSchema($value, $part, $schemas, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    $matches++;
                }
            }
            if (($mode === 'oneOf' && $matches !== 1) || ($mode === 'anyOf' && $matches < 1)) {
                $errors[] = $path.' does not match '.$mode.' (matches '.$matches.')';
            }
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
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
            $fields = get_object_vars($value);
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $fields)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($fields as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->checkSchema($child, $schema['additionalProperties'], $schemas, $path.'.'.$field, $errors);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'] ?? [], $schemas, $path.'.'.$index, $errors);
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path.' has too many items';
            }
        }
        if ($type === 'string' && isset($schema['format']) && in_array($schema['format'], ['date-time', 'date'], true)
            && strtotime($value) === false) {
            $errors[] = $path.' invalid '.$schema['format'];
        }
    }

    public function test_head_reads_do_not_write_receipts_and_revoked_tokens_are_denied(): void
    {
        $actor = $this->actor(['signal.view'], ['signals.read']);
        $this->json('HEAD', '/api/v1/signals/context')->assertOk();
        $this->assertDatabaseCount('signal_api_commands', 0);
        $actor->tokens()->delete();
        $this->getJson('/api/v1/signals/context')->assertUnauthorized();
    }

    public function test_missing_provider_scope_and_downstream_rights_block_even_when_switch_is_enabled(): void
    {
        \Illuminate\Support\Facades\Bus::fake();
        config(['signal.api_external_execution_enabled' => true]);
        $this->actor(['signal.action.execute', 'signal.webhook.manage'], ['signals.create', 'signals.actions.execute']);
        $this->rule(['actions' => [['type' => 'webhook', 'url' => 'https://example.test/hook']]]);
        $this->postJson('/api/v1/signals', ['source_domain' => 'test', 'signal_type' => 'external'])->assertCreated()
            ->assertJsonPath('executions.0.status', 'failed');
        $this->assertDatabaseCount('signal_webhook_deliveries', 0);
        $this->actor(['signal.action.execute'], ['signals.create', 'signals.actions.execute', 'signals.providers.execute']);
        $this->postJson('/api/v1/signals', ['source_domain' => 'test', 'signal_type' => 'external'])->assertCreated()
            ->assertJsonPath('executions.0.status', 'failed');
        $this->assertDatabaseCount('signal_webhook_deliveries', 0);
    }

    public function test_api_local_actions_and_portal_invitation_use_authorized_domain_boundaries(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        \Illuminate\Support\Facades\Bus::fake();
        config(['signal.api_external_execution_enabled' => true]);
        $actor = $this->actor(['signal.action.execute', 'contact.update', 'client.update',
            'sales.opportunity_manage', 'ticket.create', 'task.create', 'customer_portal.invite']);
        $client = Client::factory()->create(['active' => true]);
        $site = \App\Models\Clients\ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'API action contact']);
        $contact->emails()->create(['email' => 'signal-test@example.invalid', 'label' => 'work', 'is_primary' => true]);
        \App\Models\Clients\ClientUser::create(['contact_id' => $contact->id, 'client_site_id' => $site->id,
            'name' => 'API action contact', 'email' => 'signal-test@example.invalid', 'active' => true]);
        \App\Modules\Contact\Models\ContactRelation::create(['contact_id' => $contact->id,
            'related_type' => $client->getMorphClass(), 'related_id' => $client->id, 'relation_type' => 'customer', 'is_primary' => true]);
        $actions = [
            ['type' => 'marketing_suppress_contact_email'],
            ['type' => 'tag_contact', 'tag' => 'API contact'],
            ['type' => 'tag_client', 'tag' => 'API client'],
            ['type' => 'sales_follow_up', 'estimated_value_ex_vat' => 12.5, 'needs' => 'Evidence-based follow-up'],
            ['type' => 'ticket_follow_up', 'subject' => 'API synthetic ticket', 'site_id' => $site->id],
            ['type' => 'task_follow_up', 'subject' => 'API synthetic task', 'site_id' => $site->id],
            ['type' => 'portal_invitation', 'role' => 'viewer', 'site_id' => $site->id],
        ];
        $this->rule(['actions' => $actions]);
        $response = $this->withHeader('Idempotency-Key', 'domain-actions-001')->postJson('/api/v1/signals',
            ['source_domain' => 'test', 'signal_type' => 'actions', 'client_id' => $client->id, 'contact_id' => $contact->id])
            ->assertCreated()->assertJsonPath('executions.0.status', 'executed');
        foreach ($actions as $index => $action) {
            $response->assertJsonPath('executions.0.results.'.$index.'.status', 'done');
        }
        $this->assertTrue((bool) $contact->fresh()->do_not_email);
        $this->assertSame($actor->id, \App\Modules\Task\Models\Task::value('created_by'));
        $this->assertDatabaseCount('customer_portal_invitations', 1);
        $this->assertSame('Evidence-based follow-up', \App\Modules\Sales\Models\SalesOpportunity::value('needs'));
    }

    public function test_storage_api_handoff_requires_canonical_mailbox_access_on_execution_and_receipt(): void
    {
        config(['signal.api_external_execution_enabled' => true]);
        $actor = $this->actor(['signal.action.execute', 'storage.purchase_import_execute', 'email.inbox_view']);
        $account = \App\Modules\Email\Models\EmailAccount::create(['address' => 'signal-test@example.invalid',
            'is_active' => true, 'account_kind' => 'personal', 'owner_id' => $actor->id]);
        $message = \App\Modules\Email\Models\EmailMessage::create(['account_id' => $account->id, 'mailbox' => 'INBOX',
            'imap_uid' => 9991, 'message_id' => '<signal-api@example.invalid>', 'subject' => 'Synthetic order',
            'from_email' => 'supplier@example.invalid', 'received_at' => now(), 'state' => 'untriaged']);
        $folder = \App\Modules\Email\Models\EmailFolder::create(['account_id' => $account->id, 'provider' => 'imap', 'path' => 'INBOX', 'name' => 'INBOX', 'role' => 'inbox']);
        \App\Modules\Email\Models\EmailMailboxPlacement::create(['email_message_id' => $message->id,
            'account_id' => $account->id, 'email_folder_id' => $folder->id, 'provider' => 'imap',
            'folder_path' => 'INBOX', 'remote_message_id' => '9991', 'imap_uid' => 9991,
            'local_state' => 'active', 'sync_status' => 'synced']);
        $boundary = new class
        {
            public array $calls = [];

            public function handle($signal, $rule, $action, $key): array
            {
                $this->calls[] = [$action, $key];

                return ['type' => 'storage_supplier_order_import', 'status' => 'queued', 'import_id' => 10, 'idempotency_key' => $key];
            }
        };
        app()->instance(\App\Modules\Storage\Actions\QueueSupplierOrderImport::class, $boundary);
        $rule = $this->rule(['actions' => [['type' => 'storage_supplier_order_import']]]);
        $response = $this->withHeader('Idempotency-Key', 'canonical-storage-01')->postJson('/api/v1/signals', [
            'source_domain' => 'email', 'source_id' => $message->id, 'signal_type' => 'supplier_order_email',
            'payload' => ['email_message_id' => $message->id, 'trusted_auth' => ['forged' => true]],
        ])->assertCreated()->assertJsonPath('executions.0.status', 'executed')
            ->assertJsonPath('executions.0.results.0.status', 'queued');
        $this->assertCount(1, $boundary->calls);
        $this->assertSame($actor->id, $boundary->calls[0][0]['actor_id']);
        $this->assertSame('signal:'.$response->json('data.id').':rule:'.$rule->id.':action:0', $boundary->calls[0][1]);
        $account->update(['is_active' => false]);
        $this->getJson('/api/v1/signals/operations/'.$response->json('operation_id'))->assertForbidden();
        $this->postJson('/api/v1/signals', ['source_domain' => 'email', 'source_id' => $message->id,
            'signal_type' => 'supplier_order_email', 'payload' => ['email_message_id' => $message->id]])->assertConflict();
    }

    public function test_post_commit_handoff_failure_reserves_key_and_requires_reconciliation(): void
    {
        $this->actor(['signal.action.execute'], ['signals.create', 'signals.actions.execute', 'signals.operations.read']);
        $this->rule();
        app()->instance(\App\Modules\Signal\Actions\ExecuteSignalAction::class, new class extends \App\Modules\Signal\Actions\ExecuteSignalAction
        {
            public function handle(Signal $signal, SignalRule $rule, array $action, int $actionIndex = 0): array
            {
                \Illuminate\Support\Facades\DB::afterCommit(static function (): void {
                    throw new \RuntimeException('Private provider handoff evidence');
                });

                return ['type' => 'emit_signal', 'status' => 'queued'];
            }
        });
        $payload = ['source_domain' => 'test', 'signal_type' => 'uncertain'];
        $first = $this->withHeader('Idempotency-Key', 'postcommit-unknown-001')->postJson('/api/v1/signals', $payload)
            ->assertAccepted()->assertJsonPath('state', 'unknown')->assertJsonPath('reconciliation_required', true)->assertJsonPath('local_result.data.signal_type', 'uncertain')
            ->assertDontSee('Private provider handoff evidence');
        $this->postJson('/api/v1/signals', $payload)->assertAccepted()->assertJsonPath('replayed', true);
        $this->assertSame(1, Signal::count());
        $this->getJson('/api/v1/signals/operations/'.$first->json('operation_id'))->assertOk()
            ->assertJsonPath('data.state', 'unknown')->assertJsonPath('data.reconciliation_required', true);
    }

    public function test_array_payloads_and_legacy_execution_shapes_remain_readable(): void
    {
        $this->actor(['signal.action.execute', 'signal.view']);
        $response = $this->postJson('/api/v1/signals', ['source_domain' => 'test', 'signal_type' => 'array-payload',
            'process_rules' => false, 'payload' => [1, 'legacy-list']])->assertCreated()
            ->assertJsonPath('data.payload', [1, 'legacy-list']);
        $signal = Signal::findOrFail($response->json('data.id'));
        $rule = $this->rule();
        $attempt = $signal->executions()->create(['signal_rule_id' => $rule->id, 'attempt' => 1,
            'status' => 'failed', 'actions' => $rule->actions,
            'results' => [['type' => 'emit_signal', 'status' => 'failed']], 'executed_at' => now()]);
        $this->getJson('/api/v1/signals/'.$signal->id.'/executions/'.$attempt->id)->assertOk()
            ->assertJsonPath('data.results.0.action_index', 0);
        $attempt->forceFill(['actions' => null, 'results' => ['legacy failure detail']])->save();
        $this->getJson('/api/v1/signals/'.$signal->id.'/executions/'.$attempt->id)->assertOk()
            ->assertJsonPath('data.actions', [])->assertJsonPath('data.results.0.status', 'unknown');
        $signal->forceFill(['payload' => null])->save();
        $this->getJson('/api/v1/signals/'.$signal->id)->assertOk()->assertJsonPath('data.payload', null);
    }

    public function test_intake_prefill_matches_the_browser_rule_manager_authority(): void
    {
        $actor = $this->actor(['signal.rule.manage'], ['signals.rules.write']);
        $form = \App\Modules\Intake\Models\IntakeForm::create(['name' => 'Synthetic onboarding',
            'slug' => 'signal-api-onboarding', 'status' => 'draft']);
        $this->getJson('/api/v1/signals/rules/context?source_domain=intake&intake_form_id='.$form->id)
            ->assertOk()->assertJsonPath('data.defaults.name', 'After Synthetic onboarding submission')
            ->assertJsonPath('data.defaults.conditions.payload_equals.intake_form_slug', $form->slug)
            ->assertJsonPath('data.defaults.actions.0.type', 'ticket_follow_up');
        $this->getJson('/api/v1/signals')->assertForbidden();
        $this->assertFalse($actor->can('intake.manage'));
    }
}
