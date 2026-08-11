<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Support\AiPrivacyGatewayResult;
use RuntimeException;

class AiPrivacyGateway
{
    private const SECRET_PATTERNS = [
        '/\bBearer\s+[A-Za-z0-9._~+\/-]+=*\b/i',
        '/\b(?:api[_ -]?key|password|passwd|secret|token)\s*[:=]\s*[^\s,;]+/i',
        '/\bsk-[A-Za-z0-9_-]{16,}\b/',
    ];

    private const PII_PATTERNS = [
        '/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i',
        '/(?<!\d)(?:\+?\d[\d ()-]{7,}\d)(?!\d)/',
    ];

    /**
     * Minimize structured data, redact deterministic patterns, then validate the result.
     */
    public function sanitize(
        array $payload,
        array $allowedFields,
        array $configuredIdentifiers = [],
        ?callable $localRewriter = null,
        bool $tokenizePii = false,
    ): AiPrivacyGatewayResult {
        $removedFields = [];
        $minimized = $this->filterFields($payload, $allowedFields, '', $removedFields);
        $redactionCount = 0;
        $tokenMap = [];
        if ($tokenizePii) {
            $reverseTokenMap = [];
            $minimized = $this->tokenizePii(
                $minimized,
                $configuredIdentifiers,
                $tokenMap,
                $reverseTokenMap,
                $redactionCount,
            );
        }
        $sanitized = $this->redact($minimized, $configuredIdentifiers, $redactionCount);

        if ($localRewriter) {
            try {
                $rewritten = $localRewriter($sanitized);
            } catch (\Throwable $exception) {
                throw new RuntimeException('privacy_gateway_local_rewrite_failed', previous: $exception);
            }

            if (! is_array($rewritten)) {
                throw new RuntimeException('privacy_gateway_local_rewrite_invalid');
            }

            $sanitized = $this->redact($rewritten, $configuredIdentifiers, $redactionCount);
        }

        $encoded = json_encode($sanitized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if ($this->containsBlockedContent($encoded, $configuredIdentifiers)) {
            throw new RuntimeException('privacy_gateway_post_validation_failed');
        }

        return new AiPrivacyGatewayResult(
            payload: $sanitized,
            redactionCount: $redactionCount,
            removedFields: array_values(array_unique($removedFields)),
            fingerprint: hash('sha256', $encoded),
            tokenMap: $tokenMap,
        );
    }

    private function filterFields(array $value, array $allowedFields, string $path, array &$removed): array
    {
        $result = [];

        foreach ($value as $key => $item) {
            $itemPath = $path === '' ? (string) $key : $path.'.'.$key;
            $isListItem = is_int($key);
            $allowed = $isListItem || collect($allowedFields)->contains(
                fn (string $field): bool => $this->fieldAllowsPath($field, $itemPath),
            );

            if (! $allowed) {
                $removed[] = $itemPath;

                continue;
            }

            $result[$key] = is_array($item)
                ? $this->filterFields($item, $allowedFields, $isListItem ? $path : $itemPath, $removed)
                : $item;
        }

        return $result;
    }

    /**
     * Parent prefixes remain traversable, while only a terminal .* explicitly permits every
     * descendant of an object path. An exact scalar path never implicitly opens a subtree.
     */
    private function fieldAllowsPath(string $field, string $itemPath): bool
    {
        if ($field === $itemPath || str_starts_with($field, $itemPath.'.')) {
            return true;
        }
        if (! str_ends_with($field, '.*')) {
            return false;
        }

        $subtree = substr($field, 0, -2);

        return $itemPath === $subtree || str_starts_with($itemPath, $subtree.'.');
    }

    /**
     * Replace phone-like and numeric commercial evidence with opaque in-memory tokens.
     * The caller may restore these only after the external response has returned.
     * Explicit configured identifiers remain redacted and are never tokenized.
     *
     * @param  array<string, string>  $tokenMap
     * @param  array<string, string>  $reverseTokenMap
     */
    private function tokenizePii(
        mixed $value,
        array $identifiers,
        array &$tokenMap,
        array &$reverseTokenMap,
        int &$count,
    ): mixed {
        if (is_array($value)) {
            $tokenized = [];
            foreach ($value as $key => $item) {
                $tokenized[$key] = $this->tokenizePii(
                    $item,
                    $identifiers,
                    $tokenMap,
                    $reverseTokenMap,
                    $count,
                );
            }

            return $tokenized;
        }

        if (! is_string($value)) {
            return $value;
        }

        foreach (self::PII_PATTERNS as $pattern) {
            $value = preg_replace_callback(
                $pattern,
                function (array $matches) use ($identifiers, &$tokenMap, &$reverseTokenMap, &$count): string {
                    $original = $matches[0];
                    $isConfiguredIdentifier = collect($identifiers)
                        ->filter(fn (mixed $identifier): bool => is_string($identifier) && $identifier !== '')
                        ->contains(fn (string $identifier): bool => str_contains(
                            mb_strtolower($original),
                            mb_strtolower($identifier),
                        ) || str_contains(
                            mb_strtolower($identifier),
                            mb_strtolower($original),
                        ));
                    if ($isConfiguredIdentifier) {
                        return $original;
                    }
                    if (isset($reverseTokenMap[$original])) {
                        return $reverseTokenMap[$original];
                    }

                    $token = 'NEXUM_PRIVACY_TOKEN_'.$this->tokenSuffix(count($tokenMap));
                    $tokenMap[$token] = $original;
                    $reverseTokenMap[$original] = $token;
                    $count++;

                    return $token;
                },
                $value,
            ) ?? $value;
        }

        return $value;
    }

    private function tokenSuffix(int $index): string
    {
        $suffix = '';
        do {
            $suffix = chr(65 + ($index % 26)).$suffix;
            $index = intdiv($index, 26) - 1;
        } while ($index >= 0);

        return $suffix;
    }

    private function redact(mixed $value, array $identifiers, int &$count): mixed
    {
        if (is_array($value)) {
            $redacted = [];
            foreach ($value as $key => $item) {
                $redacted[$key] = $this->redact($item, $identifiers, $count);
            }

            return $redacted;
        }

        if (! is_string($value)) {
            return $value;
        }

        foreach (array_merge(self::SECRET_PATTERNS, self::PII_PATTERNS) as $pattern) {
            $value = preg_replace($pattern, '[REDACTED]', $value, -1, $replacements) ?? $value;
            $count += $replacements;
        }

        foreach (array_filter(array_map('strval', $identifiers)) as $identifier) {
            $value = str_ireplace($identifier, '[REDACTED]', $value, $replacements);
            $count += $replacements;
        }

        return $value;
    }

    private function containsBlockedContent(string $payload, array $identifiers): bool
    {
        foreach (array_merge(self::SECRET_PATTERNS, self::PII_PATTERNS) as $pattern) {
            if (preg_match($pattern, $payload) === 1) {
                return true;
            }
        }

        return collect($identifiers)->filter()->contains(fn (string $identifier): bool => str_contains(mb_strtolower($payload), mb_strtolower($identifier)));
    }
}
