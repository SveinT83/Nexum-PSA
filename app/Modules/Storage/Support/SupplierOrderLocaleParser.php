<?php

namespace App\Modules\Storage\Support;

use Carbon\CarbonImmutable;
use Throwable;

class SupplierOrderLocaleParser
{
    /** @param array<string, mixed> $locale */
    public function decimal(mixed $value, array $locale): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $decimalSeparator = (string) ($locale['decimal_separator'] ?? '.');
        $thousandsSeparators = (array) ($locale['thousands_separators'] ?? [' ', ',']);
        $raw = trim((string) $value);
        $negative = str_starts_with($raw, '(') && str_ends_with($raw, ')');
        $raw = trim($raw, "() \t\n\r\0\x0B");
        $raw = str_replace(["\u{00A0}", "\u{202F}"], ' ', $raw);
        $raw = preg_replace('/[^0-9,\.\-+ ]/u', '', $raw) ?? '';

        foreach ($thousandsSeparators as $separator) {
            if (! is_string($separator) || $separator === $decimalSeparator) {
                continue;
            }
            $raw = str_replace($separator, '', $raw);
        }
        $raw = str_replace(' ', '', $raw);
        if ($decimalSeparator !== '.') {
            $raw = str_replace($decimalSeparator, '.', $raw);
        }

        if (preg_match('/^[+-]?[0-9]+(?:\.[0-9]+)?$/', $raw) !== 1) {
            return null;
        }

        $isNegative = $negative || str_starts_with($raw, '-');
        $raw = ltrim($raw, '+-');
        $sign = $isNegative ? '-' : '';
        [$whole, $fraction] = array_pad(explode('.', $raw, 2), 2, null);
        $whole = ltrim($whole, '0');
        $whole = $whole === '' ? '0' : $whole;
        $fraction = $fraction === null ? null : rtrim($fraction, '0');

        return $sign.$whole.($fraction === null || $fraction === '' ? '' : '.'.$fraction);
    }

    /** @param array<string, mixed> $locale */
    public function integer(mixed $value, array $locale): ?int
    {
        $decimal = $this->decimal($value, $locale);
        if ($decimal === null || str_contains($decimal, '.')) {
            return null;
        }

        $integer = filter_var($decimal, FILTER_VALIDATE_INT);

        return $integer === false ? null : $integer;
    }

    /** @param array<string, mixed> $locale */
    public function date(mixed $value, array $locale): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        foreach ((array) ($locale['date_formats'] ?? []) as $format) {
            if (! is_string($format)) {
                continue;
            }

            try {
                $date = CarbonImmutable::createFromFormat('!'.$format, $value);
                if ($date && $date->format($format) === $value) {
                    return $date->format('Y-m-d');
                }
            } catch (Throwable) {
                // Try the next allowlisted locale format.
            }
        }

        return null;
    }

    public function receivedDate(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }
}
