<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleConditionEvaluator;
use App\Modules\Ticket\Services\TicketRuleFrozenPublishedSet;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleEventEnvelope;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRuleEventConditionContractTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function created_event_is_normalized_minimized_and_deterministic(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $initiator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor = app(TicketRuleAutomationActor::class)->resolve();
        $ticket = Ticket::factory()->create([
            'channel' => 'email',
            'subject' => 'Private customer subject',
            'description' => 'Secret body and token value',
        ]);
        $context = [
            'channel' => 'email',
            'subject' => 'Private customer subject',
            'description' => 'Secret body and token value',
            'from_domain' => 'example.test',
            '_source_action' => 'Inbound Email Import',
            '_created_by_id' => 999,
            '_skip_initial_description_note' => true,
            '_sla_planned_start_at' => now()->addDay(),
        ];

        $first = TicketRuleEventEnvelope::created(
            $ticket,
            $context,
            $initiator,
            $actor,
            (string) Str::uuid(),
        );
        $second = TicketRuleEventEnvelope::created(
            $ticket,
            $context,
            $initiator,
            $actor,
            (string) Str::uuid(),
        );

        $this->assertSame(TicketRuleDefinitionRegistry::TRIGGER_CREATED, $first->eventKey);
        $this->assertSame('email', $first->sourceChannel);
        $this->assertSame('Inbound_Email_Import', $first->sourceAction);
        $this->assertSame(['created'], $first->changedFields);
        $this->assertSame([], $first->before);
        $this->assertSame('user', $first->initiatorType);
        $this->assertSame($initiator->id, $first->initiatorId);
        $this->assertSame($actor->id, $first->automationActorId);
        $this->assertSame(0, $first->chainDepth);
        $this->assertNotSame($first->correlationUuid, $second->correlationUuid);
        $this->assertSame($first->fingerprint, $second->fingerprint);
        $this->assertSame($first->idempotencyKey, $second->idempotencyKey);

        $this->assertSame('Private customer subject', $first->facts['subject']);
        $this->assertSame('example.test', $first->facts['from_domain']);
        $this->assertArrayNotHasKey('_created_by_id', $first->facts);
        $this->assertArrayNotHasKey('_skip_initial_description_note', $first->facts);
        $this->assertArrayNotHasKey('_sla_planned_start_at', $first->facts);
        $this->assertSame('text', $first->after['subject']['type']);
        $this->assertSame(
            hash('sha256', 'Private customer subject'),
            $first->after['subject']['sha256'],
        );
        $this->assertStringNotContainsString(
            'Secret body',
            json_encode($first->persistence(), JSON_THROW_ON_ERROR),
        );
        $this->assertArrayNotHasKey('facts', $first->persistence());
    }

    #[Test]
    public function explicit_delivery_identity_changes_idempotency_without_changing_event_facts(): void
    {
        app(EnsureTicketDefaults::class)->handle();
        $actor = app(TicketRuleAutomationActor::class)->resolve();
        $ticket = Ticket::factory()->create(['channel' => 'api']);
        $base = [
            'channel' => 'api',
            'subject' => $ticket->subject,
            'description' => $ticket->description,
        ];

        $first = TicketRuleEventEnvelope::created(
            $ticket,
            $base + ['_delivery_key' => 'delivery-one'],
            null,
            $actor,
        );
        $duplicate = TicketRuleEventEnvelope::created(
            $ticket,
            $base + ['_delivery_key' => 'delivery-one'],
            null,
            $actor,
        );
        $second = TicketRuleEventEnvelope::created(
            $ticket,
            $base + ['_delivery_key' => 'delivery-two'],
            null,
            $actor,
        );

        $this->assertSame($first->fingerprint, $second->fingerprint);
        $this->assertSame($first->idempotencyKey, $duplicate->idempotencyKey);
        $this->assertNotSame($first->idempotencyKey, $second->idempotencyKey);
        $this->assertArrayNotHasKey('_delivery_key', $first->facts);
    }

    #[Test]
    public function default_created_idempotency_survives_mutable_rule_field_changes(): void
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();
        $actor = app(TicketRuleAutomationActor::class)->resolve();
        $ticket = Ticket::factory()->create([
            'channel' => 'api',
            'queue_id' => $defaults['queue']->id,
        ]);
        $context = [
            'channel' => 'api',
            'subject' => $ticket->subject,
            'description' => $ticket->description,
        ];
        $first = TicketRuleEventEnvelope::created($ticket, $context, null, $actor);
        $alternateQueue = TicketQueue::query()->create([
            'name' => 'Event idempotency queue',
            'slug' => 'event-idempotency-queue',
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 95,
        ]);

        $ticket->forceFill([
            'queue_id' => $alternateQueue->id,
            'subject' => 'Mutated by an earlier compatible action',
        ])->save();
        $second = TicketRuleEventEnvelope::created(
            $ticket->refresh(),
            $context,
            null,
            $actor,
        );

        $this->assertNotSame($first->fingerprint, $second->fingerprint);
        $this->assertSame($first->idempotencyKey, $second->idempotencyKey);
    }

    #[Test]
    public function grouped_all_and_any_conditions_return_bounded_safe_evidence(): void
    {
        $definition = $this->definition([
            [
                'match' => 'ALL',
                'conditions' => [
                    ['field' => 'channel', 'operator' => 'equals', 'value' => 'EMAIL'],
                    ['field' => 'subject', 'operator' => 'contains', 'value' => 'urgent'],
                ],
            ],
            [
                'match' => 'ANY',
                'conditions' => [
                    ['field' => 'description', 'operator' => 'contains', 'value' => 'missing'],
                    ['field' => 'from_domain', 'operator' => 'equals', 'value' => 'example.test'],
                ],
            ],
        ], 'ALL');
        $facts = [
            'channel' => 'email',
            'subject' => 'Urgent request from a customer',
            'body' => 'Fallback body value',
            'from_domain' => 'example.test',
        ];

        $result = app(TicketRuleConditionEvaluator::class)->evaluate($definition, $facts);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['passed']);
        $this->assertSame('ALL', $result['root_match']);
        $this->assertTrue($result['groups'][0]['passed']);
        $this->assertTrue($result['groups'][1]['passed']);
        $this->assertSame('text', $result['groups'][0]['rows'][1]['actual']['type']);
        $this->assertStringNotContainsString(
            'Urgent request',
            json_encode($result, JSON_THROW_ON_ERROR),
        );

        $facts['from_domain'] = 'other.test';
        $failed = app(TicketRuleConditionEvaluator::class)->evaluate($definition, $facts);
        $this->assertFalse($failed['passed']);

        $definition['conditions']['match'] = 'ANY';
        $any = app(TicketRuleConditionEvaluator::class)->evaluate($definition, $facts);
        $this->assertTrue($any['passed']);
    }

    #[Test]
    public function legacy_contains_including_an_empty_expected_value_matches_exactly_as_before(): void
    {
        $evaluator = app(TicketRuleConditionEvaluator::class);
        $contains = $this->definition([[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'subject',
                'operator' => 'contains',
                'value' => 'SERVER',
            ]],
        ]]);
        $empty = $contains;
        $empty['conditions']['groups'][0]['conditions'][0]['value'] = '';

        $this->assertTrue($evaluator->evaluate($contains, ['subject' => 'Server offline'])['passed']);
        $this->assertTrue($evaluator->evaluate($empty, ['subject' => 'Anything'])['passed']);
        $this->assertTrue($evaluator->evaluate($empty, ['subject' => ''])['passed']);
    }

    #[Test]
    public function unknown_condition_contracts_fail_closed_without_evaluating_rows(): void
    {
        $definition = $this->definition([[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'raw_message_body',
                'operator' => 'contains',
                'value' => 'secret',
            ]],
        ]]);

        $result = app(TicketRuleConditionEvaluator::class)->evaluate(
            $definition,
            ['raw_message_body' => 'secret'],
        );

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['passed']);
        $this->assertSame('unknown_condition_field', $result['reason_code']);
        $this->assertSame([], $result['groups']);
    }

    #[Test]
    public function oversized_regex_pattern_and_subject_fail_closed_with_safe_evidence(): void
    {
        $evaluator = app(TicketRuleConditionEvaluator::class);
        $patternDefinition = $this->definition([[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'subject',
                'operator' => 'regex',
                'value' => str_repeat('a', 1001),
            ]],
        ]]);
        $pattern = $evaluator->evaluate($patternDefinition, ['subject' => 'anything']);

        $this->assertFalse($pattern['valid']);
        $this->assertFalse($pattern['passed']);
        $this->assertSame('regex_pattern_too_large', $pattern['reason_code']);
        $this->assertSame(
            'regex_pattern_too_large',
            $pattern['groups'][0]['rows'][0]['reason_code'],
        );

        $subjectDefinition = $this->definition([[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'subject',
                'operator' => 'regex',
                'value' => '^a+$',
            ]],
        ]]);
        $subject = $evaluator->evaluate(
            $subjectDefinition,
            ['subject' => str_repeat('a', 65537)],
        );

        $this->assertFalse($subject['valid']);
        $this->assertSame('regex_subject_too_large', $subject['reason_code']);
    }

    #[Test]
    public function catastrophic_regex_is_bounded_and_reports_runtime_limit_evidence(): void
    {
        $definition = $this->definition([[
            'match' => 'ALL',
            'conditions' => [[
                'field' => 'subject',
                'operator' => 'regex',
                'value' => '^(a+)+$',
            ]],
        ]]);
        $result = app(TicketRuleConditionEvaluator::class)->evaluate(
            $definition,
            ['subject' => str_repeat('a', 50000).'X'],
        );

        $this->assertFalse($result['valid']);
        $this->assertFalse($result['passed']);
        $this->assertSame('regex_runtime_limit_exceeded', $result['reason_code']);
    }

    #[Test]
    public function captured_published_set_is_ordered_and_remains_frozen_for_the_root(): void
    {
        $older = $this->publishRule($this->definition(), 20, 'Older rule');
        $first = app(TicketRuleFrozenPublishedSet::class)->capture();

        $newer = $this->publishRule($this->definition(), 10, 'Newer rule');
        $second = app(TicketRuleFrozenPublishedSet::class)->capture();

        $this->assertSame([$older->id], $first['version_ids']);
        $this->assertSame([$newer->id, $older->id], $second['version_ids']);
        $this->assertSame([$older->id], $first['versions']->pluck('id')->all());
        $this->assertNotSame($first['checksum'], $second['checksum']);
    }

    /**
     * @param  list<array<string, mixed>>  $groups
     * @return array<string, mixed>
     */
    private function definition(array $groups = [], string $rootMatch = 'ALL'): array
    {
        return [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => [
                'match' => $rootMatch,
                'groups' => $groups,
            ],
            'then_actions' => [],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function publishRule(array $definition, int $weight, string $name): TicketRuleVersion
    {
        $definition['order']['weight'] = $weight;
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Slice 2 contract test.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $weight,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'weight' => $weight,
            'stop_processing' => false,
            'name' => $name,
            'description' => 'Slice 2 contract test.',
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'provenance' => TicketRuleVersion::PROVENANCE_LEGACY_BACKFILL,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);

        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);

        return $version->refresh();
    }
}
