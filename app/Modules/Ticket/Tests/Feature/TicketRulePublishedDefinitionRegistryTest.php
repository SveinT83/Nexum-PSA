<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRulePublishedDefinitionRegistryTest extends TestCase
{
    private TicketRuleDefinitionRegistry $compatibility;

    private TicketRuleFieldRegistry $fields;

    private TicketRuleTriggerRegistry $triggers;

    private TicketRuleActionProviderRegistry $actions;

    private TicketRulePublishedDefinitionValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->compatibility = new TicketRuleDefinitionRegistry;
        $this->fields = new TicketRuleFieldRegistry;
        $this->triggers = new TicketRuleTriggerRegistry($this->fields);
        $this->actions = new TicketRuleActionProviderRegistry($this->fields);
        $this->validator = new TicketRulePublishedDefinitionValidator(
            $this->compatibility,
            $this->fields,
            $this->triggers,
            $this->actions,
        );
    }

    #[Test]
    public function compatibility_schema_one_is_valid_stored_history_but_cannot_be_newly_published(): void
    {
        $legacy = (new TicketRuleDefinitionCanonicalizer($this->compatibility))->canonicalize([
            'trigger' => 'on_create',
            'weight' => 10,
            'stop_processing' => true,
            'conditions_json' => [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
            'actions_json' => [[
                'type' => 'set_queue',
                'value' => 3,
            ]],
        ]);

        $stored = $this->validator->validateStored($legacy['definition']);
        $publication = $this->validator->validateForPublication($legacy['definition']);

        $this->assertSame(TicketRulePublishedDefinitionValidator::STATUS_VALID, $stored['status']);
        $this->assertSame(1, $stored['schema_version']);
        $this->assertFalse($stored['publishable']);
        $this->assertSame($legacy['definition'], $stored['definition']);
        $this->assertSame($legacy['checksum'], $stored['checksum']);
        $this->assertSame(TicketRulePublishedDefinitionValidator::STATUS_INVALID, $publication['status']);
        $this->assertSame(
            'legacy_compatibility_schema_is_not_publishable',
            $publication['reason_code'],
        );

        $unsafeSignalMetadata = $legacy['definition'];
        $unsafeSignalMetadata['then_actions'][] = [
            'type' => 'emit_signal',
            'signal_type' => 'security_alert',
            'severity' => 'warning',
            'confidence' => 80,
            'summary' => ['raw' => 'not scalar'],
            'payload_note' => null,
        ];
        $unsafeResult = $this->validator->validateStored($unsafeSignalMetadata);

        $this->assertSame(
            TicketRulePublishedDefinitionValidator::STATUS_INVALID,
            $unsafeResult['status'],
        );
        $this->assertSame(
            'invalid_compatibility_actions',
            $unsafeResult['reason_code'],
        );
    }

    #[Test]
    public function schema_two_definition_is_canonicalized_without_reinterpreting_legacy_shape(): void
    {
        $stored = $this->validator->validateStored($this->definition());

        $this->assertSame(TicketRulePublishedDefinitionValidator::STATUS_VALID, $stored['status']);
        $this->assertSame(2, $stored['schema_version']);
        $this->assertFalse($stored['publishable']);
        $this->assertSame(
            ['priority_id', 'queue_id'],
            $stored['definition']['trigger_filters']['fields'],
        );
        $this->assertSame(
            [1, 2],
            $stored['definition']['conditions']['groups'][0]['conditions'][0]['value'],
        );
        $this->assertSame(
            [2, 9],
            $stored['definition']['conditions']['groups'][0]['conditions'][2]['value'],
        );
        $this->assertSame(
            7,
            $stored['definition']['then_actions'][0]['input']['queue_id'],
        );
        $this->assertSame(
            'security_alert',
            $stored['definition']['else_actions'][0]['input']['signal_type'],
        );
        $this->assertStringContainsString('Ticket field changed', $stored['summary']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $stored['checksum']);

        $wrongSchema = $this->definition();
        $wrongSchema['schema_version'] = 1;
        $wrongSchemaResult = $this->validator->validateStored($wrongSchema);
        $this->assertSame(
            'invalid_compatibility_definition_shape',
            $wrongSchemaResult['reason_code'],
        );
    }

    #[Test]
    public function publication_requires_independent_trigger_and_action_capability_gates(): void
    {
        $this->assertFalse($this->triggers->enabled(TicketRuleTriggerRegistry::FIELD_CHANGED));
        $this->assertFalse($this->actions->enabled(TicketRuleActionProviderRegistry::SET_QUEUE));

        $disabledTrigger = $this->validator->validateForPublication($this->definition());
        $this->assertSame('trigger_capability_disabled', $disabledTrigger['reason_code']);

        config()->set('ticket_rules.capabilities.triggers', [
            TicketRuleTriggerRegistry::FIELD_CHANGED => true,
        ]);
        $disabledAction = $this->validator->validateForPublication($this->definition());
        $this->assertSame('action_capability_disabled', $disabledAction['reason_code']);

        config()->set('ticket_rules.capabilities.actions', [
            TicketRuleActionProviderRegistry::SET_QUEUE => true,
            TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE => true,
            TicketRuleActionProviderRegistry::EMIT_SIGNAL => true,
        ]);
        $enabled = $this->validator->validateForPublication($this->definition());

        $this->assertSame(TicketRulePublishedDefinitionValidator::STATUS_VALID, $enabled['status']);
        $this->assertTrue($enabled['publishable']);
    }

    #[Test]
    public function executable_keys_alias_triggers_and_unknown_schemas_fail_closed(): void
    {
        $executable = $this->definition();
        $executable['then_actions'][0]['input']['query'] = 'select 1';
        $this->assertSame(
            'forbidden_executable_key',
            $this->validator->validateStored($executable)['reason_code'],
        );

        $pluralAlias = $this->definition();
        $pluralAlias['trigger'] = TicketRuleTriggerRegistry::FIELDS_CHANGED_COMPATIBILITY_ALIAS;
        $this->assertSame(
            'unknown_trigger',
            $this->validator->validateStored($pluralAlias)['reason_code'],
        );

        $unknownSchema = $this->definition();
        $unknownSchema['schema_version'] = 99;
        $this->assertSame(
            'unsupported_schema_version',
            $this->validator->validateStored($unknownSchema)['reason_code'],
        );
    }

    #[Test]
    public function conditionless_rules_require_explicit_always_mode(): void
    {
        $always = $this->definition();
        $always['trigger'] = TicketRuleTriggerRegistry::CREATED;
        $always['trigger_filters'] = [];
        $always['conditions'] = [
            'mode' => 'always',
            'match' => 'ALL',
            'groups' => [],
        ];
        $always['then_actions'] = [];
        $always['else_actions'] = [];

        $this->assertSame(
            TicketRulePublishedDefinitionValidator::STATUS_VALID,
            $this->validator->validateStored($always)['status'],
        );

        $accidental = $always;
        $accidental['conditions']['mode'] = 'grouped';
        $this->assertSame(
            'grouped_conditions_require_a_group',
            $this->validator->validateStored($accidental)['reason_code'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(): array
    {
        return [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::FIELD_CHANGED,
            'trigger_filters' => [
                'fields' => ['queue_id', 'priority_id', 'queue_id'],
            ],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ANY',
                    'conditions' => [
                        [
                            'field' => 'priority_id',
                            'operator' => 'in',
                            'value' => ['2', 1, 2],
                        ],
                        [
                            'field' => 'channel',
                            'operator' => 'equals',
                            'value' => 'email',
                        ],
                        [
                            'field' => 'tag_ids',
                            'operator' => 'intersects',
                            'value' => ['9', 2, 9],
                        ],
                    ],
                ]],
            ],
            'then_actions' => [
                [
                    'type' => TicketRuleActionProviderRegistry::SET_QUEUE,
                    'input' => ['queue_id' => '7'],
                ],
                [
                    'type' => TicketRuleActionProviderRegistry::ADD_INTERNAL_NOTE,
                    'input' => ['body' => 'Rule matched the approved routing criteria.'],
                ],
            ],
            'else_actions' => [[
                'type' => TicketRuleActionProviderRegistry::EMIT_SIGNAL,
                'input' => ['signal_type' => 'Security Alert'],
            ]],
            'flow' => [
                'stop_processing' => false,
            ],
            'order' => [
                'weight' => '20',
            ],
        ];
    }
}
