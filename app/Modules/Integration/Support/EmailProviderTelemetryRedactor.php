<?php

namespace App\Modules\Integration\Support;

use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;

/**
 * Fail-closed Telescope boundary for Email provider configuration material.
 *
 * Request, session, job, exception, and response entries are recursively
 * redacted. Query/model entries capable of containing interpolated endpoint or
 * ciphertext values are discarded completely because replacing individual SQL
 * literals after interpolation is not a reliable security boundary.
 */
final class EmailProviderTelemetryRedactor
{
    public const REDACTED = '[REDACTED]';

    /** @var list<string> */
    private const SENSITIVE_MODEL_MARKERS = [
        'App\\Modules\\Email\\Models\\EmailAccount',
        'App\\Modules\\Integration\\Models\\EmailProviderConnection',
        'App\\Modules\\Integration\\Models\\EmailProviderCredentialVersion',
        'App\\Modules\\Integration\\Models\\EmailProviderMigrationItem',
    ];

    /** @var list<string> */
    private const SENSITIVE_SQL_MARKERS = [
        'email_accounts',
        'integration_email_provider_connections',
        'integration_email_provider_credential_versions',
        'integration_email_provider_migration_items',
    ];

    public static function sanitize(#[\SensitiveParameter] IncomingEntry $entry): bool
    {
        if (self::mustDrop($entry)) {
            return false;
        }

        $entry->content = self::redact($entry->content);

        return true;
    }

    public static function mustDrop(#[\SensitiveParameter] IncomingEntry $entry): bool
    {
        if ($entry->isQuery()) {
            $sql = mb_strtolower((string) ($entry->content['sql'] ?? ''));

            return self::containsAny($sql, self::SENSITIVE_SQL_MARKERS);
        }

        if ($entry->type !== EntryType::MODEL) {
            return false;
        }

        $model = mb_strtolower((string) ($entry->content['model'] ?? ''));

        return self::containsAny($model, self::SENSITIVE_MODEL_MARKERS);
    }

    public static function redact(
        #[\SensitiveParameter] mixed $value,
        ?string $key = null,
    ): mixed {
        if ($key !== null && self::sensitiveKey($key)) {
            return self::REDACTED;
        }

        if (! is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $childKey => $childValue) {
            $redacted[$childKey] = self::redact(
                $childValue,
                is_string($childKey) ? $childKey : null,
            );
        }

        return $redacted;
    }

    private static function sensitiveKey(string $key): bool
    {
        $key = mb_strtolower(trim($key));

        if (preg_match('/(?:^|_)(?:password|secret|token|client_secret|api_key|ciphertext|fingerprint)$/', $key) === 1) {
            return true;
        }

        if (preg_match('/^(?:imap|smtp)_(?:host|port|encryption|username|secret|password|auth_type)$/', $key) === 1) {
            return true;
        }

        return in_array($key, [
            'credentials',
            'credential',
            'credential_payload',
            'endpoint',
            'endpoint_host',
            'original_host',
            'pinned_address',
            'pinned_ip',
            'resolved_address',
            'resolved_addresses',
            'private_cidr',
            'private_endpoint_reason',
            'trusted_private_cidr',
            'trusted_cidr_name',
            'private_trust_reason',
            'trusted_private_reason',
            'trust_mode',
            'installation_policy_id',
            'imap_transport',
            'smtp_transport',
        ], true);
    }

    /** @param list<string> $needles */
    private static function containsAny(string $value, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($value, mb_strtolower($needle))) {
                return true;
            }
        }

        return false;
    }
}
