<?php

namespace App\Modules\Ticket\Tests\Unit;

use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TicketRuleSchema2RegistryTest extends TestCase
{
    private TicketRuleFieldRegistry $fields;

    private TicketRuleTriggerRegistry $triggers;

    private TicketRuleActionProviderRegistry $actions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fields = new TicketRuleFieldRegistry;
        $this->triggers = new TicketRuleTriggerRegistry($this->fields);
        $this->actions = new TicketRuleActionProviderRegistry($this->fields);
    }

    #[Test]
    public function schema_versions_and_published_trigger_keys_are_explicit(): void
    {
        $this->assertSame(1, TicketRuleDefinitionRegistry::LEGACY_COMPATIBILITY_SCHEMA_VERSION);
        $this->assertSame(2, TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION);
        $this->assertSame(
            TicketRuleDefinitionRegistry::LEGACY_COMPATIBILITY_SCHEMA_VERSION,
            TicketRuleDefinitionRegistry::SCHEMA_VERSION,
        );
        $this->assertSame([
            'ticket.created',
            'ticket.updated',
            'ticket.field_changed',
            'ticket.message_added',
            'ticket.tags_changed',
            'ticket.assignment_changed',
            'ticket.custom_fields_changed',
            'ticket.workflow_changed',
            'ticket.workflow_state_changed',
            'ticket.status_changed',
        ], array_keys($this->triggers->definitions()));
        $this->assertFalse(
            $this->triggers->supportsPublishedKey(
                TicketRuleTriggerRegistry::FIELDS_CHANGED_COMPATIBILITY_ALIAS,
            ),
        );

        foreach ($this->triggers->definitions() as $key => $definition) {
            $this->assertSame($key, $definition['key']);
            $this->assertNotSame('', $definition['label']);
            $this->assertSame($key, $definition['capability_key']);
            $this->assertFalse($definition['emits_additional_event']);
            $this->assertSame('object', $definition['filter_schema']['type']);
        }
    }

    #[Test]
    public function action_providers_declare_the_complete_guard_and_audit_contract(): void
    {
        $this->assertSame([
            'set_ticket_fields',
            'set_queue',
            'assign_owner',
            'unassign_owner',
            'rerun_assignment',
            'add_tags',
            'remove_tags',
            'set_custom_field',
            'clear_custom_field',
            'add_internal_note',
            'select_workflow',
            'transition_workflow',
            'switch_workflow',
            'pause_workflow_automation',
            'resume_workflow_automation',
            'emit_signal',
        ], array_keys($this->actions->definitions()));

        foreach ($this->actions->definitions() as $key => $provider) {
            $this->assertSame($key, $provider['capability_key']);
            $this->assertNotSame('', $provider['label']);
            $this->assertNotSame('', $provider['help']);
            $this->assertSame('object', $provider['input_schema']['type']);
            $this->assertIsArray($provider['target_lookup']);
            $this->assertNotSame('', $provider['runtime_permission']);
            $this->assertSame($provider['runtime_permission'], $provider['publication_permission']);
            $this->assertContains($provider['execution_phase'], ['synchronous', 'after_commit']);
            $this->assertNotEmpty($provider['permitted_triggers']);
            $this->assertIsArray($provider['changed_fields']);
            $this->assertNotSame('', $provider['authoritative_mutation']);
            $this->assertTrue($provider['idempotency']['position_keyed']);
            $this->assertIsBool($provider['idempotency']['retryable']);
            $this->assertIsArray($provider['safe_audit_projection']);
            $this->assertIsBool($provider['after_commit']['allowed']);
            $this->assertFalse($provider['after_commit']['raw_payload_persisted']);
            $this->assertSame(
                $this->actions->forbiddenExecutableKeys(),
                $provider['forbidden_executable_keys'],
            );
        }

        $queue = $this->actions->definition(TicketRuleActionProviderRegistry::SET_QUEUE);
        $owner = $this->actions->definition(TicketRuleActionProviderRegistry::ASSIGN_OWNER);
        $signal = $this->actions->definition(TicketRuleActionProviderRegistry::EMIT_SIGNAL);

        $this->assertSame('queue_routing_group', $queue['assignment_concept']);
        $this->assertStringContainsString('routing group', $queue['help']);
        $this->assertSame('individual_owner', $owner['assignment_concept']);
        $this->assertStringContainsString('individual Owner', $owner['help']);
        $this->assertSame('after_commit', $signal['execution_phase']);
        $this->assertTrue($signal['after_commit']['allowed']);
        $this->assertTrue($signal['after_commit']['reconciliation_required_before_retry']);
    }

    #[Test]
    public function action_inputs_are_canonical_and_executable_keys_fail_closed(): void
    {
        $fields = $this->actions->canonicalizeAction([
            'type' => 'set_ticket_fields',
            'input' => [
                'fields' => [
                    'subject' => '  Escalated incident  ',
                    'impact' => '3',
                    'client_id' => null,
                ],
            ],
        ]);
        $tags = $this->actions->canonicalizeAction([
            'type' => 'add_tags',
            'input' => ['tag_ids' => ['3', 2, 3]],
        ]);
        $signal = $this->actions->canonicalizeAction([
            'type' => 'emit_signal',
            'input' => ['signal_type' => 'Security Alert'],
        ]);
        $forbidden = $this->actions->canonicalizeAction([
            'type' => 'set_queue',
            'input' => [
                'queue_id' => 4,
                'nested' => ['query' => 'select 1'],
            ],
        ]);
        $blankNote = $this->actions->canonicalizeAction([
            'type' => 'add_internal_note',
            'input' => ['body' => '   '],
        ]);

        $this->assertTrue($fields['valid']);
        $this->assertSame([
            'client_id' => null,
            'impact' => 3,
            'subject' => 'Escalated incident',
        ], $fields['action']['input']['fields']);
        $this->assertSame([2, 3], $tags['action']['input']['tag_ids']);
        $this->assertSame([
            'signal_type' => 'security_alert',
            'severity' => 'info',
            'confidence' => 100,
            'summary' => null,
            'payload_note' => null,
        ], $signal['action']['input']);
        $this->assertFalse($forbidden['valid']);
        $this->assertSame('forbidden_executable_key', $forbidden['reason_code']);
        $this->assertFalse($blankNote['valid']);
        $this->assertSame('empty_internal_note', $blankNote['reason_code']);
    }

    #[Test]
    public function one_normalized_update_event_can_satisfy_field_and_assignment_relevance(): void
    {
        $fieldFilters = $this->triggers->canonicalizeFilters(
            TicketRuleTriggerRegistry::FIELD_CHANGED,
            ['fields' => ['queue_id', 'priority_id', 'queue_id']],
        );
        $this->assertTrue($fieldFilters['valid']);
        $this->assertSame(['priority_id', 'queue_id'], $fieldFilters['filters']['fields']);

        $update = [
            'event_key' => TicketRuleTriggerRegistry::UPDATED,
            'changed_fields' => ['queue_id', 'owner_id'],
            'before' => ['queue_id' => 1, 'owner_id' => null],
            'after' => ['queue_id' => 2, 'owner_id' => 9],
        ];

        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::FIELD_CHANGED,
            ['fields' => ['queue_id']],
            $update,
        ));
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::FIELD_CHANGED,
            ['fields' => ['description']],
            $update,
        ));
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            ['changes' => ['queue_changed']],
            $update,
        ));
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::ASSIGNMENT_CHANGED,
            ['changes' => ['owner_assigned']],
            $update,
        ));
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::UPDATED,
            [],
            $update,
        ));

        $compatibilityAlias = $update;
        $compatibilityAlias['event_key'] = TicketRuleTriggerRegistry::FIELDS_CHANGED_COMPATIBILITY_ALIAS;
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::FIELD_CHANGED,
            ['fields' => ['queue_id']],
            $compatibilityAlias,
        ));

        $noOp = $update;
        $noOp['changed_fields'] = [];
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::UPDATED,
            [],
            $noOp,
        ));
    }

    #[Test]
    public function workflow_composite_triggers_use_exact_safe_facts(): void
    {
        $workflow = [
            'event_key' => TicketRuleTriggerRegistry::WORKFLOW_CHANGED,
            'changed_fields' => ['workflow_version_id', 'workflow_state_key', 'status_id'],
            'facts' => [
                'workflow_version_id' => 17,
                'workflow_operation' => 'switch',
                'status_id' => 4,
            ],
        ];

        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::WORKFLOW_CHANGED,
            ['workflow_version_ids' => [17], 'operations' => ['switch']],
            $workflow,
        ));
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::WORKFLOW_CHANGED,
            ['operations' => ['transition']],
            $workflow,
        ));

        $workflow['event_key'] = TicketRuleTriggerRegistry::WORKFLOW_STATE_CHANGED;
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::WORKFLOW_STATE_CHANGED,
            ['workflow_version_ids' => [17]],
            $workflow,
        ));

        $workflow['event_key'] = TicketRuleTriggerRegistry::STATUS_CHANGED;
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::STATUS_CHANGED,
            ['status_ids' => [4]],
            $workflow,
        ));
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::STATUS_CHANGED,
            ['status_ids' => [5]],
            $workflow,
        ));
    }

    #[Test]
    public function message_and_tag_relevance_uses_only_safe_metadata_and_actual_deltas(): void
    {
        $message = [
            'event_key' => TicketRuleTriggerRegistry::MESSAGE_ADDED,
            'source_channel' => 'customer_portal',
            'facts' => [
                'message_id' => 42,
                'message_type' => 'customer_reply',
                'body' => 'This raw body is deliberately ignored by relevance.',
            ],
        ];

        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::MESSAGE_ADDED,
            [
                'message_types' => ['customer_reply'],
                'source_channels' => ['customer_portal'],
            ],
            $message,
        ));
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::MESSAGE_ADDED,
            ['source_channels' => ['email']],
            $message,
        ));

        $statusUpdate = $message;
        $statusUpdate['facts']['message_type'] = 'status_update';
        $this->assertSame(
            'public_update',
            $this->triggers->normalizeMessageType('status_update'),
        );
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::MESSAGE_ADDED,
            ['message_types' => ['public_update']],
            $statusUpdate,
        ));

        $tags = [
            'event_key' => TicketRuleTriggerRegistry::TAGS_CHANGED,
            'facts' => [
                'added_tag_ids' => [9, 3],
                'removed_tag_ids' => [5],
            ],
        ];
        $this->assertTrue($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::TAGS_CHANGED,
            ['added_tag_ids' => [3]],
            $tags,
        ));
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::TAGS_CHANGED,
            ['removed_tag_ids' => [8]],
            $tags,
        ));

        $tags['facts']['added_tag_ids'] = [];
        $tags['facts']['removed_tag_ids'] = [];
        $this->assertFalse($this->triggers->isRelevant(
            TicketRuleTriggerRegistry::TAGS_CHANGED,
            [],
            $tags,
        ));
    }
}
