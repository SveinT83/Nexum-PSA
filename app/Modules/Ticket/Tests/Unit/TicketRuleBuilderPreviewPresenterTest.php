<?php

namespace App\Modules\Ticket\Tests\Unit;

use App\Modules\Ticket\Services\TicketRuleBuilderPreviewPresenter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRuleBuilderPreviewPresenterTest extends TestCase
{
    #[Test]
    public function it_exposes_bounded_operational_evidence_without_private_values(): void
    {
        $privateValue = 'private-builder-preview-value-never-render';
        $preview = [
            'mode' => 'draft_preview',
            'terminal_status' => 'would_change',
            'trigger' => 'ticket.created',
            'selected_branch' => 'then',
            'conditions_matched' => true,
            'definition_checksum' => str_repeat('a', 64),
            'published_set_checksum' => str_repeat('b', 64),
            'condition_evidence' => [
                'valid' => true,
                'passed' => true,
                'mode' => 'grouped',
                'root_match' => 'ALL',
                'groups' => [[
                    'position' => 0,
                    'match' => 'ALL',
                    'passed' => true,
                    'rows' => [[
                        'position' => 0,
                        'field' => 'subject',
                        'operator' => 'equals',
                        'value_type' => 'string',
                        'expected' => $privateValue,
                        'actual' => $privateValue,
                        'passed' => true,
                        'reason_code' => null,
                    ]],
                ]],
            ],
            'actions' => [[
                'position' => 0,
                'status' => 'planned',
                'action' => [
                    'type' => 'set_ticket_fields',
                    'input' => ['fields' => ['subject' => $privateValue]],
                ],
                'changes' => [
                    'subject' => ['before' => 'old '.$privateValue, 'after' => $privateValue],
                ],
                'authorization' => ['allowed' => false, 'detail' => $privateValue],
                'reason_code' => 'policy_denied',
            ]],
            'queue_preview_scope' => 'published_rules_only',
            'queue_preview' => [
                'terminal_status' => 'loop_blocked',
                'published_set_checksum' => str_repeat('b', 64),
                'rules' => [[
                    'event_sequence' => 1,
                    'event_key' => 'ticket.updated',
                    'order_position' => 2,
                    'ticket_rule_id' => 20,
                    'rule_version_id' => 21,
                    'status' => 'would_change',
                    'selected_branch' => 'then',
                    'condition_evidence' => [
                        'valid' => true,
                        'passed' => true,
                        'mode' => 'grouped',
                        'root_match' => 'ANY',
                        'groups' => [[
                            'position' => 0,
                            'match' => 'ANY',
                            'passed' => true,
                            'rows' => [[
                                'position' => 0,
                                'field' => 'custom_field.current',
                                'operator' => 'equals',
                                'value_type' => 'text',
                                'expected' => $privateValue,
                                'actual' => $privateValue,
                                'passed' => true,
                            ]],
                        ]],
                    ],
                    'actions' => [[
                        'position' => 0,
                        'status' => 'planned',
                        'action' => [
                            'type' => 'set_custom_field',
                            'input' => ['value' => $privateValue],
                        ],
                        'changes' => [
                            'custom_field.987' => [
                                'before' => null,
                                'after' => $privateValue,
                            ],
                        ],
                    ]],
                ]],
                'rules_omitted_count' => 2,
                'events' => [[
                    'sequence' => 1,
                    'event_key' => 'ticket.updated',
                    'chain_depth' => 1,
                    'status' => 'loop_blocked',
                    'reason_code' => 'repeated_event_fingerprint',
                    'private_fact' => $privateValue,
                ]],
                'derived_events_omitted_count' => 1,
                'collisions' => [[
                    'target' => 'field:subject',
                    'previous_writer' => ['ticket_rule_id' => 20],
                    'new_writer' => ['ticket_rule_id' => 22],
                    'resolution' => 'last_planned_writer',
                    'private_fact' => $privateValue,
                ]],
                'loop_risk' => [
                    'risks' => [[
                        'reason_code' => 'repeated_event_fingerprint',
                        'event_key' => 'ticket.updated',
                        'event_sequence' => 1,
                        'chain_depth' => 1,
                        'rule_order_position' => 2,
                    ]],
                    'risks_omitted_count' => 1,
                ],
                'counters' => [
                    'events' => 2,
                    'evaluated_rules' => 3,
                    'actions' => 1,
                    'loop_blocks' => 1,
                    'failed_executions' => 0,
                ],
                'halted' => true,
                'stopped' => false,
            ],
        ];

        $result = app(TicketRuleBuilderPreviewPresenter::class)->present($preview);

        $this->assertSame('published_rules_only', $result['queue_scope']);
        $this->assertSame('Subject (value redacted)', $result['condition_evidence']['groups'][0]['rows'][0]['field']);
        $this->assertStringStartsWith('Redacted text', $result['condition_evidence']['groups'][0]['rows'][0]['expected']);
        $this->assertSame('Subject (value redacted) would change.', $result['actions'][0]['change_summary'][0]);
        $this->assertSame('policy_denied', $result['policy_outcomes'][0]['reason_code']);
        $this->assertSame('Published rule', $result['queue']['rules'][0]['source_label']);
        $this->assertSame('Custom Field value (redacted)', $result['queue']['rules'][0]['condition_evidence']['groups'][0]['rows'][0]['field']);
        $this->assertSame(
            'Custom Field value (redacted)',
            $result['queue']['rules'][0]['condition_evidence']['groups'][0]['rows'][0]['expected'],
        );
        $this->assertSame('Subject (value redacted)', $result['queue']['collisions'][0]['target']);
        $this->assertSame('repeated_event_fingerprint', $result['queue']['loop_blocks'][0]['reason_code']);
        $this->assertSame(2, $result['queue']['rules_omitted_count']);
        $this->assertSame(1, $result['queue']['events_omitted_count']);
        $this->assertTrue($result['queue']['halted']);
        $this->assertTrue(collect($result['warnings'])->contains('code', 'queue_halted'));
        $this->assertTrue(collect($result['warnings'])->contains('code', 'loop_blocked'));
        $this->assertTrue(collect($result['warnings'])->contains('code', 'display_rows_omitted'));

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($privateValue, $json);
        $this->assertStringNotContainsString('"input"', $json);
        $this->assertStringNotContainsString('987', $json);
    }
}
