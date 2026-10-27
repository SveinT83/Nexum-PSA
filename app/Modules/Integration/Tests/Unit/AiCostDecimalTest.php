<?php

namespace App\Modules\Integration\Tests\Unit;

use App\Modules\Integration\Support\AiCostDecimal;
use App\Modules\Integration\Support\AiModelUsage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AiCostDecimalTest extends TestCase
{
    public static function amounts(): array
    {
        return [
            ['0', '0.000000000000'], ['0.000000000001', '0.000000000001'],
            ['1e-12', '0.000000000001'], ['1e-20', null],
            ['99999999999.000000000001', '99999999999.000000000001'],
            ['1e12', null], ['1e99', null], ['-1', null], [INF, null],
            [NAN, null], [[], null], ['not-a-cost', null],
        ];
    }

    #[Test, DataProvider('amounts')]
    public function cost_precision_and_unknown_are_explicit(mixed $raw, ?string $expected): void
    {
        $this->assertSame($expected, AiCostDecimal::stored($raw));
    }

    #[Test]
    public function provider_usage_preserves_zero_and_rejects_fractional_or_overflowed_tokens(): void
    {
        $usage = AiModelUsage::fromOpenAiCompatible(['usage' => [
            'input_tokens' => '0.5', 'output_tokens' => '1e30', 'cost' => '0', 'currency' => 'usd',
        ]]);
        $this->assertNull($usage->inputTokens);
        $this->assertNull($usage->outputTokens);
        $this->assertNull($usage->totalTokens);
        $this->assertSame('0', $usage->providerReportedCost);
        $this->assertSame('USD', $usage->costCurrency);
        $this->assertNull(AiCostDecimal::integer('9223372036854775808'));
        $this->assertNull(AiCostDecimal::reported('0.1000000000001'));
        $this->assertSame('0.100000000000', AiCostDecimal::reported('0.100000000000'));
    }
}
