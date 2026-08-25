<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\ContractItem;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;
use OverflowException;

final class ContractPricing
{
    private const CADENCES = [
        'monthly',
        'quarterly',
        'yearly',
        'one_time',
    ];

    /**
     * Calculate one contract snapshot line without binary floating-point arithmetic.
     *
     * @return array{
     *     cadence: string,
     *     included: bool,
     *     quantity: int,
     *     unit_price: array{minor: int, decimal: string, display: string},
     *     subtotal: array{minor: int, decimal: string, display: string},
     *     discount: array{minor: int, decimal: string, display: string},
     *     line_total: array{minor: int, decimal: string, display: string},
     *     setup_fee: array{minor: int, decimal: string, display: string},
     *     cadence_totals: array<string, array{minor: int, decimal: string, display: string}>
     * }
     */
    public function calculateLine(ContractItem|array $item): array
    {
        $this->assertNokSaleCurrency($item);
        $quantity = $this->quantity($this->value($item, 'quantity', 1));
        $unitPrice = $this->money($this->value($item, 'unit_price', 0), 'unit_price');
        $setupFee = $this->money($this->value($item, 'setup_fee', 0), 'setup_fee');

        $this->assertNotNegative($unitPrice, 'unit_price');
        $this->assertNotNegative($setupFee, 'setup_fee');

        // Contract prices are stored as two-decimal snapshot values before quantity is applied.
        $subtotal = $unitPrice->multipliedBy($quantity);
        $discount = $this->discount($item, $subtotal);
        $lineTotal = $subtotal->minus($discount);

        if ($lineTotal->isNegative()) {
            $lineTotal = BigDecimal::zero()->toScale(2);
        }

        $cadenceValue = $this->value($item, 'billing_interval', 'monthly');
        if ($cadenceValue !== null && ! is_string($cadenceValue)) {
            throw new InvalidArgumentException('billing_interval must be a string.');
        }

        $cadence = $this->normalizeCadence($cadenceValue);
        $lineTotalMinor = $this->toMinor($lineTotal);
        $setupFeeMinor = $this->toMinor($setupFee);
        $cadenceMinors = array_fill_keys(self::CADENCES, 0);
        $cadenceMinors[$cadence] = $lineTotalMinor;
        $cadenceMinors['one_time'] = $this->checkedAdd(
            $cadenceMinors['one_time'],
            $setupFeeMinor,
        );

        return [
            'cadence' => $cadence,
            'included' => $lineTotalMinor === 0 && $setupFeeMinor === 0,
            'quantity' => $quantity,
            'unit_price' => $this->amount($this->toMinor($unitPrice)),
            'subtotal' => $this->amount($this->toMinor($subtotal)),
            'discount' => $this->amount($this->toMinor($discount)),
            'line_total' => $this->amount($lineTotalMinor),
            'setup_fee' => $this->amount($setupFeeMinor),
            'cadence_totals' => $this->amounts($cadenceMinors),
        ];
    }

    /**
     * Aggregate exact positive line charges into their customer-facing cadences.
     *
     * @param  iterable<ContractItem|array<string, mixed>>  $items
     * @return array<string, array{minor: int, decimal: string, display: string}>
     */
    public function calculateTotals(iterable $items): array
    {
        $totals = array_fill_keys(self::CADENCES, 0);

        foreach ($items as $item) {
            $line = $this->calculateLine($item);

            foreach (self::CADENCES as $cadence) {
                $totals[$cadence] = $this->checkedAdd(
                    $totals[$cadence],
                    $line['cadence_totals'][$cadence]['minor'],
                );
            }
        }

        return $this->amounts($totals);
    }

    /**
     * Normalize the one supported legacy alias while keeping one canonical cadence vocabulary.
     */
    public function normalizeCadence(?string $cadence): string
    {
        $normalized = strtolower(trim($cadence ?? ''));

        if ($normalized === '') {
            return 'monthly';
        }

        if ($normalized === 'annual') {
            return 'yearly';
        }

        if (! in_array($normalized, self::CADENCES, true)) {
            throw new InvalidArgumentException("Unsupported billing cadence [{$cadence}].");
        }

        return $normalized;
    }

    /**
     * Format an already calculated minor-unit value for Norwegian customer presentation.
     */
    public function formatMinor(int $minor, string $currency = 'NOK'): string
    {
        $decimal = $this->decimalFromMinor($minor);
        $negative = str_starts_with($decimal, '-');
        $unsigned = $negative ? substr($decimal, 1) : $decimal;
        [$major, $cents] = explode('.', $unsigned, 2);
        $groups = str_split(strrev($major), 3);
        $groupedMajor = strrev(implode(' ', $groups));
        $currency = strtoupper(trim($currency)) ?: 'NOK';
        $suffix = $currency === 'NOK' ? 'kr' : $currency;

        return ($negative ? '-' : '').$groupedMajor.','.$cents.' '.$suffix;
    }

    private function assertNokSaleCurrency(ContractItem|array $item): void
    {
        $value = $this->value($item, 'price_currency', 'NOK');

        if ($value !== null && ! is_string($value)) {
            throw new InvalidArgumentException('price_currency must be a three-letter currency code.');
        }

        $currency = strtoupper(trim((string) ($value ?: 'NOK')));

        if ($currency !== 'NOK') {
            throw new InvalidArgumentException(
                'Contract sale currency must be NOK. Mixed or foreign-currency totals are not supported.'
            );
        }
    }

    private function discount(ContractItem|array $item, BigDecimal $subtotal): BigDecimal
    {
        $typeValue = $this->value($item, 'discount_type');
        $type = is_string($typeValue) ? strtolower(trim($typeValue)) : $typeValue;

        if ($type === null || $type === '') {
            return BigDecimal::zero()->toScale(2);
        }

        if (! is_string($type) || ! in_array($type, ['amount', 'percent'], true)) {
            throw new InvalidArgumentException('discount_type must be amount, percent, or null.');
        }

        $value = $this->decimal($this->value($item, 'discount_value', 0), 'discount_value')
            ->toScale(2, RoundingMode::HALF_UP);
        $this->assertNotNegative($value, 'discount_value');

        $discount = $type === 'percent'
            ? $subtotal->multipliedBy($value)->dividedBy(100, 2, RoundingMode::HALF_UP)
            : $value;

        if ($discount->compareTo($subtotal) > 0) {
            return $subtotal;
        }

        return $discount->toScale(2, RoundingMode::HALF_UP);
    }

    private function money(mixed $value, string $field): BigDecimal
    {
        return $this->decimal($value, $field)->toScale(2, RoundingMode::HALF_UP);
    }

    private function decimal(mixed $value, string $field): BigDecimal
    {
        if ($value === null || $value === '') {
            return BigDecimal::zero();
        }

        if ($value instanceof BigDecimal) {
            return $value;
        }

        // Some unsaved Livewire rows contain PHP floats. Convert only at the input boundary;
        // every calculation below remains an exact Brick Math decimal operation.
        if (is_float($value)) {
            if (! is_finite($value)) {
                throw new InvalidArgumentException("{$field} must be a finite decimal value.");
            }

            $value = json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
        } elseif (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException("{$field} must be a decimal string or number.");
        }

        $value = trim($value);
        if (! preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $value)) {
            throw new InvalidArgumentException("{$field} must use an unambiguous decimal value.");
        }

        return BigDecimal::of($value);
    }

    private function quantity(mixed $value): int
    {
        if (is_int($value)) {
            $quantity = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', trim($value))) {
            $quantity = filter_var(trim($value), FILTER_VALIDATE_INT);
        } else {
            throw new InvalidArgumentException('quantity must be a non-negative integer.');
        }

        if ($quantity === false || $quantity < 0) {
            throw new InvalidArgumentException('quantity must be a non-negative integer.');
        }

        return $quantity;
    }

    private function value(ContractItem|array $item, string $key, mixed $default = null): mixed
    {
        if (is_array($item)) {
            return array_key_exists($key, $item) ? $item[$key] : $default;
        }

        return $item->getAttribute($key) ?? $default;
    }

    private function assertNotNegative(BigDecimal $value, string $field): void
    {
        if ($value->isNegative()) {
            throw new InvalidArgumentException("{$field} cannot be negative.");
        }
    }

    private function toMinor(BigDecimal $value): int
    {
        $minor = $value
            ->toScale(2, RoundingMode::HALF_UP)
            ->getUnscaledValue();

        if ($minor->compareTo(PHP_INT_MAX) > 0 || $minor->compareTo(PHP_INT_MIN) < 0) {
            throw new OverflowException('Calculated money value exceeds the supported integer range.');
        }

        return (int) (string) $minor;
    }

    private function checkedAdd(int $left, int $right): int
    {
        if ($right > 0 && $left > PHP_INT_MAX - $right) {
            throw new OverflowException('Calculated money total exceeds the supported integer range.');
        }

        return $left + $right;
    }

    /** @return array{minor: int, decimal: string, display: string} */
    private function amount(int $minor): array
    {
        return [
            'minor' => $minor,
            'decimal' => $this->decimalFromMinor($minor),
            'display' => $this->formatMinor($minor),
        ];
    }

    /**
     * @param  array<string, int>  $minors
     * @return array<string, array{minor: int, decimal: string, display: string}>
     */
    private function amounts(array $minors): array
    {
        return array_map(fn (int $minor): array => $this->amount($minor), $minors);
    }

    /**
     * Return the canonical two-decimal representation for an exact minor-unit value.
     */
    public function decimalFromMinor(int $minor): string
    {
        $minorString = (string) $minor;
        $negative = str_starts_with($minorString, '-');
        $unsigned = $negative ? substr($minorString, 1) : $minorString;
        $padded = str_pad($unsigned, 3, '0', STR_PAD_LEFT);
        $major = substr($padded, 0, -2);
        $cents = substr($padded, -2);

        return ($negative ? '-' : '').$major.'.'.$cents;
    }
}
