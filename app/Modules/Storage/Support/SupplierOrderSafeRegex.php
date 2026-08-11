<?php

namespace App\Modules\Storage\Support;

use InvalidArgumentException;

final class SupplierOrderSafeRegex
{
    public const MAX_PATTERN_LENGTH = 1200;

    /** @return list<string> */
    public function errors(string $pattern, array $requiredCaptures = []): array
    {
        $errors = [];
        if ($pattern === '' || mb_strlen($pattern) > self::MAX_PATTERN_LENGTH || str_contains($pattern, "\0")) {
            $errors[] = 'pattern_length_invalid';

            return $errors;
        }

        $forbidden = [
            '(?R', '(?0', '(?1', '(?2', '(?3', '(?4', '(?5', '(?6', '(?7', '(?8', '(?9',
            '(?&', '(?P>', '(?C', '(*', '(?<=', '(?<!', '\\C', '\\K', '\\g<', '\\k<',
        ];
        foreach ($forbidden as $token) {
            if (str_contains($pattern, $token)) {
                $errors[] = 'pattern_construct_forbidden';
                break;
            }
        }
        if (preg_match('/(?<!\\\\)\\\\[1-9]/', $pattern) === 1) {
            $errors[] = 'pattern_backreference_forbidden';
        }
        if (preg_match('/\((?:[^()\\\\]|\\\\.)*[+*](?:[^()\\\\]|\\\\.)*\)\s*(?:[+*]|\{\d)/', $pattern) === 1) {
            $errors[] = 'pattern_nested_quantifier_forbidden';
        }
        if (preg_match('/(?:\.\*|\.\+)[^)]{0,120}(?:\.\*|\.\+)/', $pattern) === 1) {
            $errors[] = 'pattern_ambiguous_wildcards_forbidden';
        }
        if (substr_count($pattern, '|') > 30) {
            $errors[] = 'pattern_alternation_limit_exceeded';
        }

        if (preg_match_all('/\{(\d+),(\d*)\}/', $pattern, $quantifiers, PREG_SET_ORDER)) {
            foreach ($quantifiers as $quantifier) {
                $minimum = (int) $quantifier[1];
                $maximum = $quantifier[2] === '' ? null : (int) $quantifier[2];
                if ($maximum === null || $minimum > 500 || $maximum > 500 || $minimum > $maximum) {
                    $errors[] = 'pattern_quantifier_unbounded';
                    break;
                }
            }
        }

        $captures = $this->namedCaptures($pattern);
        if (count($captures) > 20 || count($captures) !== count(array_unique($captures))) {
            $errors[] = 'pattern_capture_limit_or_duplicate';
        }
        foreach ($requiredCaptures as $capture) {
            if (! in_array($capture, $captures, true)) {
                $errors[] = 'pattern_required_capture_missing';
                break;
            }
        }

        if (@preg_match($this->compile($pattern), '') === false) {
            $errors[] = 'pattern_compile_failed';
        }

        return array_values(array_unique($errors));
    }

    public function compileOrFail(string $pattern): string
    {
        if ($this->errors($pattern) !== []) {
            throw new InvalidArgumentException('Unsafe supplier-order profile pattern.');
        }

        return $this->compile($pattern);
    }

    /** @return list<string> */
    public function namedCaptures(string $pattern): array
    {
        preg_match_all('/\(\?<([A-Za-z_][A-Za-z0-9_]*)>/', $pattern, $matches);

        return array_values($matches[1] ?? []);
    }

    private function compile(string $pattern): string
    {
        $escaped = str_replace('~', '\\~', $pattern);

        return '~(*LIMIT_MATCH=100000)(*LIMIT_DEPTH=100)'.$escaped.'~imsu';
    }
}
