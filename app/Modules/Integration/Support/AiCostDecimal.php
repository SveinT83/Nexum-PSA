<?php

namespace App\Modules\Integration\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/** Bounded decimal parsing for the nullable DECIMAL(24,12) cost contract. */
final class AiCostDecimal
{
    public static function parse(mixed $value): ?BigDecimal
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }
        $text = trim((string) $value);
        if (strlen($text) > 80 || ! preg_match('/^[+]?\d+(?:\.\d+)?(?:[eE]([+-]?\d{1,2}))?$/', $text, $match)
            || (isset($match[1]) && abs((int) $match[1]) > 36)) {
            return null;
        }
        try {
            $number = BigDecimal::of($text);

            return $number->isNegative() ? null : $number;
        } catch (\Throwable) {
            return null;
        }
    }

    public static function stored(mixed $value): ?string
    {
        $number = self::parse($value);
        if ($number === null) {
            return null;
        }
        $scaled = $number->toScale(12, RoundingMode::HalfUp);
        if ((! $number->isZero() && $scaled->isZero()) || $scaled->isGreaterThanOrEqualTo('1000000000000')) {
            return null;
        }

        return (string) $scaled;
    }

    /** Preserve plain provider precision in sanitized metadata where representable. */
    public static function reported(mixed $value): ?string
    {
        $stored = self::stored($value);
        if ($stored === null || ! self::parse($value)->isEqualTo($stored)) {
            return null;
        }
        $text = trim((string) $value);
        if (preg_match('/^\d+(?:\.\d{1,12})?$/', $text)) {
            return $text;
        }

        return rtrim(rtrim($stored, '0'), '.') ?: '0';
    }

    public static function integer(mixed $value): ?int
    {
        $number = self::parse($value);
        if ($number === null) {
            return null;
        }
        try {
            return $number->toInt();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function currency(?string $value): ?string
    {
        $value = strtoupper(trim($value ?? ''));

        return preg_match('/^[A-Z]{3}$/', $value) ? $value : null;
    }
}
