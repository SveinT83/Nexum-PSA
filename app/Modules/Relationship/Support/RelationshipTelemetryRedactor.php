<?php

namespace App\Modules\Relationship\Support;

use Laravel\Telescope\IncomingEntry;

/** Canonical sync evidence stays in its ledger; sensitive diagnostic copies are discarded. */
class RelationshipTelemetryRedactor
{
    public static function sanitize(#[\SensitiveParameter] IncomingEntry $entry): bool
    {
        if (RelationshipApiPrivacy::matches(request())) {
            return false;
        }
        $encoded = json_encode($entry->content, JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return false;
        }
        $text = strtolower($encoded);
        foreach (['nexum_relationships', 'relationship_api_commands', 'relationship_outbound_claims',
            'nexum_sync_links', 'nexum_sync_events', 'nexumrelationship', 'relationshipapicommand',
            '/api/v1/nexum/relationships'] as $marker) {
            if (str_contains($text, $marker)) {
                return false;
            }
        }
        $entry->content = self::redact($entry->content);

        return true;
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && preg_match('/(?:^(?:x[-_]nexum[-_](?:token|signature))$|(?:^|[._-])(?:inbound_token|outbound_token|webhook_secret|plain_inbound_token|inbound_token_hash|outbound_token_encrypted|webhook_secret_encrypted)$)/i', $key)) {
            return '[REDACTED]';
        }
        if (is_array($value)) {
            $result = [];
            foreach ($value as $name => $child) {
                $result[$name] = self::redact($child, is_string($name) ? $name : null);
            }

            return $result;
        }
        if (is_string($value) && in_array(substr(ltrim($value), 0, 1), ['{', '['], true)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return json_encode(self::redact($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        return $value;
    }
}
