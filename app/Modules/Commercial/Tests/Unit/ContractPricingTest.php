<?php

namespace App\Modules\Commercial\Tests\Unit;

use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Support\ContractPricing;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class ContractPricingTest extends TestCase
{
    #[Test]
    public function it_calculates_the_exact_supplied_monthly_example_from_livewire_arrays(): void
    {
        $totals = $this->pricing()->calculateTotals([
            $this->line('829.42', 4),
            $this->line('19.00', 11),
            $this->line('109.00', 3),
            $this->line('26.00', 1),
        ]);

        $this->assertSame([
            'minor' => 387968,
            'decimal' => '3879.68',
            'display' => '3 879,68 kr',
        ], $totals['monthly']);
        $this->assertSame(0, $totals['quarterly']['minor']);
        $this->assertSame(0, $totals['yearly']['minor']);
        $this->assertSame(0, $totals['one_time']['minor']);
    }

    #[Test]
    public function it_calculates_contract_item_percent_discount_and_one_time_setup_fee(): void
    {
        $item = new ContractItem([
            'unit_price' => '109.00',
            'quantity' => 3,
            'discount_type' => 'percent',
            'discount_value' => '12.50',
            'setup_fee' => '99.90',
            'billing_interval' => 'monthly',
        ]);

        $line = $this->pricing()->calculateLine($item);

        $this->assertSame(32700, $line['subtotal']['minor']);
        $this->assertSame(4088, $line['discount']['minor']);
        $this->assertSame('286.12', $line['line_total']['decimal']);
        $this->assertSame(28612, $line['cadence_totals']['monthly']['minor']);
        $this->assertSame(9990, $line['cadence_totals']['one_time']['minor']);
        $this->assertFalse($line['included']);
    }

    #[Test]
    public function it_normalizes_annual_and_keeps_all_supported_cadences_separate(): void
    {
        $totals = $this->pricing()->calculateTotals([
            $this->line('100.00', 2, [
                'billing_interval' => 'annual',
                'discount_type' => 'amount',
                'discount_value' => '50.00',
                'setup_fee' => '25.00',
            ]),
            $this->line('30.00', 3, ['billing_interval' => 'quarterly']),
            $this->line('40.00', 1, ['billing_interval' => 'one_time', 'setup_fee' => '10.00']),
        ]);

        $this->assertSame(0, $totals['monthly']['minor']);
        $this->assertSame(9000, $totals['quarterly']['minor']);
        $this->assertSame(15000, $totals['yearly']['minor']);
        $this->assertSame(7500, $totals['one_time']['minor']);
        $this->assertSame('yearly', $this->pricing()->normalizeCadence('annual'));
    }

    #[Test]
    public function it_reports_zero_charge_lines_as_included_and_caps_over_discounts(): void
    {
        $zero = $this->pricing()->calculateLine($this->line('0.00', 3));
        $overDiscounted = $this->pricing()->calculateLine($this->line('25.00', 1, [
            'discount_type' => 'amount',
            'discount_value' => '100.00',
        ]));

        $this->assertTrue($zero['included']);
        $this->assertSame('0.00', $zero['line_total']['decimal']);
        $this->assertSame('0,00 kr', $zero['line_total']['display']);
        $this->assertTrue($overDiscounted['included']);
        $this->assertSame(2500, $overDiscounted['discount']['minor']);
        $this->assertSame(0, $overDiscounted['line_total']['minor']);
    }

    #[Test]
    public function it_rounds_money_and_percentage_discounts_half_up_without_float_arithmetic(): void
    {
        $line = $this->pricing()->calculateLine($this->line('0.05', 1, [
            'discount_type' => 'percent',
            'discount_value' => '10.00',
        ]));
        $roundedUnit = $this->pricing()->calculateLine($this->line('75.555', 2));
        $livewireFloat = $this->pricing()->calculateLine($this->line(829.42, 4));

        $this->assertSame(1, $line['discount']['minor']);
        $this->assertSame(4, $line['line_total']['minor']);
        $this->assertSame(15112, $roundedUnit['line_total']['minor']);
        $this->assertSame(331768, $livewireFloat['line_total']['minor']);
    }

    #[Test]
    public function it_formats_minor_units_without_converting_them_to_floats(): void
    {
        $this->assertSame('0,00 kr', $this->pricing()->formatMinor(0));
        $this->assertSame('12,34 kr', $this->pricing()->formatMinor(1234));
        $this->assertSame('1 234 567,89 kr', $this->pricing()->formatMinor(123456789));
        $this->assertSame('-1 234,56 kr', $this->pricing()->formatMinor(-123456));
    }

    #[Test]
    public function it_rejects_unknown_cadences_and_negative_money(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing()->calculateLine($this->line('-1.00', 1));
    }

    #[Test]
    public function it_rejects_an_unknown_cadence(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->pricing()->normalizeCadence('weekly');
    }

    private function pricing(): ContractPricing
    {
        return new ContractPricing;
    }

    /** @return array<string, mixed> */
    private function line(string|float $unitPrice, int $quantity, array $overrides = []): array
    {
        return array_replace([
            'unit_price' => $unitPrice,
            'quantity' => $quantity,
            'discount_type' => null,
            'discount_value' => '0.00',
            'setup_fee' => '0.00',
            'billing_interval' => 'monthly',
        ], $overrides);
    }
}
