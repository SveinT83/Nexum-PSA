<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\StructuredAiValidationException;

class StructuredAiPayloadGuard
{
    private const BLOCKED_FIELD_NAMES = [
        'authorization',
        'api_key',
        'password',
        'passwd',
        'secret',
        'token',
        'raw_email',
        'raw_eml',
        'raw_body',
        'html',
        'sender_email',
        'recipient_email',
        'payment_method',
        'payment_details',
        'url',
        'tracking_url',
    ];

    private const MAX_DEPTH = 8;

    private const MAX_NODES = 5000;

    private const MAX_STRING_LENGTH = 4000;

    private const MAX_ENCODED_BYTES = 65536;

    public function sanitize(array $payload): array
    {
        $nodes = 0;
        $sanitized = $this->walk($payload, 0, $nodes);
        $encoded = json_encode(
            $sanitized,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
            throw new StructuredAiValidationException('input_payload_too_large');
        }

        return $sanitized;
    }

    private function walk(mixed $value, int $depth, int &$nodes): mixed
    {
        if ($depth > self::MAX_DEPTH || ++$nodes > self::MAX_NODES) {
            throw new StructuredAiValidationException('input_payload_bounds_exceeded');
        }

        if (is_array($value)) {
            $limit = array_is_list($value) ? 250 : 100;
            if (count($value) > $limit) {
                throw new StructuredAiValidationException('input_collection_too_large');
            }

            $sanitized = [];
            foreach ($value as $key => $item) {
                if (is_string($key)) {
                    $normalized = mb_strtolower($key);
                    if (mb_strlen($key) > 80 || in_array($normalized, self::BLOCKED_FIELD_NAMES, true)) {
                        throw new StructuredAiValidationException('input_field_not_allowed');
                    }
                }

                $sanitized[$key] = $this->walk($item, $depth + 1, $nodes);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            if (mb_strlen($value) > self::MAX_STRING_LENGTH) {
                throw new StructuredAiValidationException('input_string_too_long');
            }

            return preg_replace(
                "~https?://[^\s<>\"']+~iu",
                '[REDACTED_URL]',
                $value,
            ) ?? $value;
        }

        if (is_float($value) && ! is_finite($value)) {
            throw new StructuredAiValidationException('input_value_invalid');
        }
        if (! is_int($value) && ! is_float($value) && ! is_bool($value) && $value !== null) {
            throw new StructuredAiValidationException('input_value_invalid');
        }

        return $value;
    }
}
