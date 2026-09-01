<?php

namespace App\Modules\Ticket\Tests\Unit;

use App\Modules\Ticket\Services\TicketRuleFullRerunPreviewPresenter;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketRuleFullRerunPreviewPresenterTest extends TestCase
{
    #[Test]
    public function it_bounds_every_plan_surface_and_never_returns_action_values(): void
    {
        $privateValue = 'private-preview-value-never-render';
        $actions = [];
        for ($position = 0; $position < 101; $position++) {
            $customField = $position === 0;
            $actions[] = [
                'position' => $position,
                'status' => 'planned',
                'action' => [
                    'type' => $customField ? 'set_custom_field' : 'set_ticket_fields',
                    'input' => ['value' => $privateValue],
                ],
                'changes' => $customField
                    ? ['custom_field.987' => ['before' => null, 'after' => $privateValue]]
                    : ['subject' => ['before' => null, 'after' => $privateValue]],
            ];
        }

        $result = app(TicketRuleFullRerunPreviewPresenter::class)->present([
            'rules' => [[
                'event_sequence' => 1,
                'event_key' => 'ticket.created',
                'order_position' => 1,
                'ticket_rule_id' => 10,
                'rule_version_id' => 11,
                'status' => 'would_change',
                'selected_branch' => 'then',
                'actions' => $actions,
            ]],
            'rules_omitted_count' => 0,
            'counters' => ['actions' => 101, 'loop_blocks' => 1],
            'collisions' => [[
                'target' => 'field:custom_field.987',
                'previous_writer' => [
                    'event_sequence' => 1,
                    'ticket_rule_id' => 10,
                    'rule_version_id' => 11,
                    'action_position' => 0,
                ],
                'new_writer' => [
                    'event_sequence' => 1,
                    'ticket_rule_id' => 12,
                    'rule_version_id' => 13,
                    'action_position' => 1,
                ],
                'resolution' => 'last_planned_writer',
            ]],
            'collisions_omitted_count' => 0,
            'loop_risk' => [
                'risks' => [[
                    'reason_code' => 'repeated_event_fingerprint',
                    'event_key' => 'ticket.updated',
                    'event_sequence' => 2,
                    'chain_depth' => 1,
                    'rule_order_position' => 2,
                    'action_position' => 0,
                ]],
                'risks_omitted_count' => 0,
            ],
            'events' => [],
        ]);

        $this->assertSame(101, $result['planned_action_row_count']);
        $this->assertSame(100, $result['planned_action_displayed_count']);
        $this->assertSame(1, $result['planned_action_omitted_count']);
        $this->assertCount(100, $result['planned_rules'][0]['actions']);
        $this->assertSame(1, $result['planned_rules'][0]['actions_omitted_count']);
        $this->assertSame('set_custom_field', $result['planned_rules'][0]['actions'][0]['type']);
        $this->assertSame('Custom Field (value redacted)', $result['planned_rules'][0]['actions'][0]['target']);
        $this->assertSame('Custom Field (value redacted)', $result['planned_collisions'][0]['target']);
        $this->assertSame('repeated_event_fingerprint', $result['planned_loop_blocks'][0]['reason_code']);
        $this->assertSame(0, $result['planned_collisions_omitted_count']);
        $this->assertSame(0, $result['planned_loop_blocks_omitted_count']);

        $json = json_encode($result, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($privateValue, $json);
        $this->assertStringNotContainsString('987', $json);
    }
}
