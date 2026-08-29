<?php

namespace App\Modules\Ticket\Tests\Unit;

use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TicketRuleDefinitionCanonicalizerTest extends TestCase
{
    private TicketRuleDefinitionCanonicalizer $canonicalizer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->canonicalizer = new TicketRuleDefinitionCanonicalizer(
            new TicketRuleDefinitionRegistry,
        );
    }

    #[Test]
    public function it_converts_every_legacy_operator_and_action_in_order(): void
    {
        $operators = [
            'contains',
            'equals',
            'not_equals',
            'starts_with',
            'ends_with',
            'regex',
            'present',
        ];
        $conditions = array_map(
            fn (string $operator): array => [
                'field' => 'subject',
                'operator' => $operator,
                'value' => $operator === 'regex' ? '^support' : 'support',
            ],
            $operators,
        );
        $actions = [
            ['type' => 'set_ticket_type', 'value' => '11'],
            ['type' => 'set_queue', 'value' => '12'],
            ['type' => 'set_priority', 'value' => '13'],
            ['type' => 'set_sla', 'value' => '14'],
            ['type' => 'set_category', 'value' => '15'],
            ['type' => 'add_tag', 'value' => '16'],
            [
                'type' => 'emit_signal',
                'value' => 'Security Alert',
                'severity' => 'warning',
                'confidence' => '80',
            ],
        ];

        $result = $this->canonicalizer->canonicalize($this->legacy(
            conditions: $conditions,
            actions: $actions,
        ));

        $this->assertSame(TicketRuleDefinitionCanonicalizer::STATUS_VALID, $result['status']);
        $this->assertSame('ticket.created', $result['definition']['trigger']);
        $this->assertSame('ALL', $result['definition']['conditions']['match']);
        $this->assertSame($operators, array_column(
            $result['definition']['conditions']['groups'][0]['conditions'],
            'operator',
        ));
        $this->assertSame(
            ['set_ticket_type', 'set_queue', 'set_priority', 'set_sla', 'set_category', 'add_tag', 'emit_signal'],
            array_column($result['definition']['then_actions'], 'type'),
        );
        $this->assertSame('security_alert', $result['definition']['then_actions'][6]['signal_type']);
        $this->assertSame([], $result['definition']['else_actions']);
        $this->assertTrue($result['definition']['flow']['stop_processing']);
        $this->assertSame(25, $result['definition']['order']['weight']);
    }

    #[Test]
    public function checksum_is_definition_only_and_preserves_list_order(): void
    {
        $first = $this->legacy(actions: [
            ['type' => 'set_queue', 'value' => '2'],
            ['type' => 'set_priority', 'value' => '3'],
        ]);
        $sameDefinitionWithOperationalNoise = $first + [
            'hit_count' => 999,
            'updated_at' => '2099-01-01 00:00:00',
        ];
        $reordered = $first;
        $reordered['actions_json'] = array_reverse($first['actions_json']);
        $differentWeight = $first;
        $differentWeight['weight'] = 26;

        $checksum = $this->canonicalizer->canonicalize($first)['checksum'];

        $this->assertSame(
            $checksum,
            $this->canonicalizer->canonicalize($sameDefinitionWithOperationalNoise)['checksum'],
        );
        $this->assertNotSame($checksum, $this->canonicalizer->canonicalize($reordered)['checksum']);
        $this->assertNotSame($checksum, $this->canonicalizer->canonicalize($differentWeight)['checksum']);
    }

    #[Test]
    public function empty_conditions_and_actions_retain_their_legacy_meaning(): void
    {
        $result = $this->canonicalizer->canonicalize($this->legacy(
            conditions: [],
            actions: [],
        ));

        $this->assertSame(TicketRuleDefinitionCanonicalizer::STATUS_VALID, $result['status']);
        $this->assertSame([], $result['definition']['conditions']['groups'][0]['conditions']);
        $this->assertSame([], $result['definition']['then_actions']);
    }

    #[Test]
    public function unsupported_or_executable_definitions_are_never_silently_converted(): void
    {
        $unknown = $this->legacy(conditions: [[
            'field' => 'arbitrary_database_column',
            'operator' => 'equals',
            'value' => 'secret',
        ]]);
        $executable = $this->legacy(actions: [[
            'type' => 'set_queue',
            'value' => '1',
            'query' => 'SELECT 1',
        ]]);
        $badRegex = $this->legacy(conditions: [[
            'field' => 'subject',
            'operator' => 'regex',
            'value' => '[',
        ]]);

        $this->assertSame(
            TicketRuleDefinitionCanonicalizer::STATUS_AMBIGUOUS,
            $this->canonicalizer->canonicalize($unknown)['status'],
        );
        $this->assertSame(
            TicketRuleDefinitionCanonicalizer::STATUS_INVALID,
            $this->canonicalizer->canonicalize($executable)['status'],
        );
        $this->assertSame(
            'invalid_regular_expression',
            $this->canonicalizer->canonicalize($badRegex)['reason_code'],
        );
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $conditions
     * @param  array<int, array<string, mixed>>|null  $actions
     * @return array<string, mixed>
     */
    private function legacy(?array $conditions = null, ?array $actions = null): array
    {
        return [
            'trigger' => 'on_create',
            'weight' => 25,
            'stop_processing' => true,
            'conditions_json' => $conditions ?? [[
                'field' => 'channel',
                'operator' => 'equals',
                'value' => 'email',
            ]],
            'actions_json' => $actions ?? [[
                'type' => 'set_queue',
                'value' => '2',
            ]],
        ];
    }
}
