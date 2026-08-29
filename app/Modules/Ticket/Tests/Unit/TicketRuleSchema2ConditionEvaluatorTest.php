<?php

namespace App\Modules\Ticket\Tests\Unit;

use App\Modules\Ticket\Services\TicketRuleAuditSanitizer;
use App\Modules\Ticket\Services\TicketRuleSchema2ConditionEvaluator;
use App\Modules\Ticket\Support\TicketRuleFieldRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class TicketRuleSchema2ConditionEvaluatorTest extends TestCase
{
    private TicketRuleSchema2ConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->evaluator = new TicketRuleSchema2ConditionEvaluator(
            new TicketRuleAuditSanitizer,
            new TicketRuleFieldRegistry,
        );
    }

    #[Test]
    public function always_is_explicit_and_invalid_trees_fail_closed(): void
    {
        $always = $this->evaluator->evaluate($this->definition([
            'mode' => 'always',
            'match' => 'ALL',
            'groups' => [],
        ]), []);
        $emptyGrouped = $this->evaluator->evaluate($this->definition([
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [],
        ]), []);
        $legacy = $this->definition([
            'mode' => 'always',
            'match' => 'ALL',
            'groups' => [],
        ]);
        $legacy['schema_version'] = 1;

        $this->assertTrue($always['valid']);
        $this->assertTrue($always['passed']);
        $this->assertSame('always', $always['mode']);
        $this->assertFalse($emptyGrouped['valid']);
        $this->assertSame('grouped_conditions_require_a_group', $emptyGrouped['reason_code']);
        $this->assertSame(
            'unsupported_schema_version',
            $this->evaluator->evaluate($legacy, [])['reason_code'],
        );
    }

    #[Test]
    public function grouped_all_and_any_use_typed_integer_enum_and_list_facts(): void
    {
        $result = $this->evaluator->evaluate($this->definition([
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [
                [
                    'match' => 'ANY',
                    'conditions' => [
                        ['field' => 'impact', 'operator' => 'equals', 'value' => 5],
                        ['field' => 'impact', 'operator' => 'in', 'value' => [2, 3]],
                    ],
                ],
                [
                    'match' => 'ALL',
                    'conditions' => [
                        ['field' => 'message_type', 'operator' => 'not_in', 'value' => ['internal_note']],
                        ['field' => 'tag_ids', 'operator' => 'contains', 'value' => 9],
                        ['field' => 'added_tag_ids', 'operator' => 'intersects', 'value' => [7, 9]],
                    ],
                ],
            ],
        ]), [
            'impact' => 3,
            'message_type' => 'customer_reply',
            'tag_ids' => [2, 9],
            'added_tag_ids' => [9],
        ]);

        $this->assertTrue($result['valid']);
        $this->assertTrue($result['passed']);
        $this->assertTrue($result['groups'][0]['passed']);
        $this->assertTrue($result['groups'][1]['passed']);
        $this->assertFalse($result['groups'][0]['rows'][0]['passed']);
        $this->assertTrue($result['groups'][0]['rows'][1]['passed']);
    }

    #[Test]
    public function string_evidence_is_fingerprinted_instead_of_persisting_private_text(): void
    {
        $privateSubject = 'Customer password reset for Jane Example';
        $privateNeedle = 'Jane Example';
        $result = $this->evaluator->evaluate($this->definition([
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [[
                'match' => 'ALL',
                'conditions' => [[
                    'field' => 'subject',
                    'operator' => 'contains',
                    'value' => $privateNeedle,
                ]],
            ]],
        ]), ['subject' => $privateSubject]);

        $encoded = json_encode($result, JSON_THROW_ON_ERROR);

        $this->assertTrue($result['passed']);
        $this->assertStringNotContainsString($privateSubject, $encoded);
        $this->assertStringNotContainsString($privateNeedle, $encoded);
        $this->assertSame('text', $result['groups'][0]['rows'][0]['actual']['type']);
        $this->assertSame(hash('sha256', $privateSubject), $result['groups'][0]['rows'][0]['actual']['sha256']);
        $this->assertSame(hash('sha256', $privateNeedle), $result['groups'][0]['rows'][0]['expected']['sha256']);
    }

    #[Test]
    public function regex_is_bounded_rejects_invalid_patterns_and_restores_runtime_limits(): void
    {
        $oldBacktrackLimit = ini_get('pcre.backtrack_limit');
        $oldRecursionLimit = ini_get('pcre.recursion_limit');
        $passing = $this->regex('Incident [0-9]+', 'Incident 231');
        $invalid = $this->regex('([unclosed', 'anything');
        $oversizedPattern = $this->regex(str_repeat('x', 1001), 'x');
        $oversizedSubject = $this->regex('x', str_repeat('x', 65537));

        $this->assertTrue($passing['valid']);
        $this->assertTrue($passing['passed']);
        $this->assertFalse($invalid['valid']);
        $this->assertSame('regex_runtime_rejected', $invalid['reason_code']);
        $this->assertSame('regex_pattern_too_large', $oversizedPattern['reason_code']);
        $this->assertSame('regex_subject_too_large', $oversizedSubject['reason_code']);
        $this->assertSame($oldBacktrackLimit, ini_get('pcre.backtrack_limit'));
        $this->assertSame($oldRecursionLimit, ini_get('pcre.recursion_limit'));
    }

    #[Test]
    public function unknown_fields_operators_and_runtime_types_fail_closed(): void
    {
        $unknown = $this->singleCondition('made_up', 'equals', 'x', ['made_up' => 'x']);
        $operator = $this->singleCondition('impact', 'regex', '3', ['impact' => 3]);
        $actualType = $this->singleCondition('impact', 'equals', 3, ['impact' => ['3']]);
        $expectedType = $this->singleCondition('impact', 'equals', 'three', ['impact' => 3]);

        $this->assertSame('unknown_condition_field', $unknown['reason_code']);
        $this->assertSame('unsupported_condition_operator', $operator['reason_code']);
        $this->assertSame('condition_actual_type_invalid', $actualType['reason_code']);
        $this->assertSame('condition_expected_type_invalid', $expectedType['reason_code']);
        $this->assertFalse($actualType['passed']);
        $this->assertFalse($expectedType['valid']);
    }

    /** @return array<string, mixed> */
    private function regex(string $pattern, string $subject): array
    {
        return $this->singleCondition('subject', 'regex', $pattern, ['subject' => $subject]);
    }

    /** @return array<string, mixed> */
    private function singleCondition(string $field, string $operator, mixed $value, array $facts): array
    {
        return $this->evaluator->evaluate($this->definition([
            'mode' => 'grouped',
            'match' => 'ALL',
            'groups' => [[
                'match' => 'ALL',
                'conditions' => [[
                    'field' => $field,
                    'operator' => $operator,
                    'value' => $value,
                ]],
            ]],
        ]), $facts);
    }

    /** @return array<string, mixed> */
    private function definition(array $conditions): array
    {
        return [
            'schema_version' => 2,
            'conditions' => $conditions,
        ];
    }
}
