<?php

namespace App\Modules\Email\Services;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailMessage;

/**
 * Derives canonical authentication facts only from configured receiving infrastructure.
 * Visible sender data and untrusted Authentication-Results never establish trust.
 */
final class TrustedSenderAuthenticationFacts
{
    private const MAX_HEADER_VALUES = 20;

    private const MAX_HEADER_BYTES = 8192;

    private const RESULT_STATUSES = [
        'pass',
        'fail',
        'softfail',
        'neutral',
        'none',
        'temperror',
        'permerror',
        'policy',
    ];

    private ?array $settings = null;

    public function forMessage(EmailMessage $message): array
    {
        $headers = (array) $message->headers_json;
        $trustedAuthservIds = $this->configuredIdentifiers('trusted_authserv_ids');
        $trustedReceivingHops = $this->configuredIdentifiers('trusted_receiving_hops');

        // An authserv ID is still attacker-controlled until the receiving hop that supplied
        // Authentication-Results is explicitly anchored to trusted local infrastructure.
        if ($trustedAuthservIds === [] || $trustedReceivingHops === []) {
            return $this->emptyFacts();
        }

        if (! $this->firstReceivingHopIsTrusted($headers, $trustedReceivingHops)) {
            return $this->emptyFacts();
        }

        foreach ($this->headerValues($headers, 'authentication-results') as $headerValue) {
            $parsed = $this->parseAuthenticationResults($headerValue);

            if ($parsed === null || ! in_array($parsed['authserv_id'], $trustedAuthservIds, true)) {
                continue;
            }

            return $this->canonicalFacts($message, $parsed);
        }

        return $this->emptyFacts();
    }

    private function canonicalFacts(EmailMessage $message, array $parsed): array
    {
        $visibleFromDomain = $this->domainFromIdentity($message->from_email);
        $spf = $parsed['results']['spf'] ?? null;
        $dkim = $parsed['results']['dkim'] ?? null;
        $dmarc = $parsed['results']['dmarc'] ?? null;

        $spfIdentity = $this->property($parsed, 'spf', ['smtp.mailfrom', 'smtp.helo']);
        $dkimIdentity = $this->property($parsed, 'dkim', ['header.i', 'header.d']);
        $dmarcIdentity = $this->property($parsed, 'dmarc', ['header.from']);

        $candidates = [
            $this->identityCandidate($spf === 'pass', $spfIdentity, $visibleFromDomain),
            $this->identityCandidate($dkim === 'pass', $dkimIdentity, $visibleFromDomain),
            $this->identityCandidate($dmarc === 'pass', $dmarcIdentity, $visibleFromDomain),
        ];
        $candidates = array_values(array_filter($candidates));

        $selected = collect($candidates)->first(fn (array $candidate): bool => $candidate['aligned'])
            ?? ($candidates[0] ?? null);

        return [
            'authentication_passed' => in_array('pass', [$spf, $dkim, $dmarc], true),
            'authenticated_supplier_identity' => $selected['identity'] ?? null,
            'authenticated_supplier_domain' => $selected['domain'] ?? null,
            'authserv_id' => $parsed['authserv_id'],
            'spf' => $spf,
            'dkim' => $dkim,
            'dmarc' => $dmarc,
            'aligned' => collect($candidates)->contains(
                fn (array $candidate): bool => $candidate['aligned'],
            ),
        ];
    }

    private function identityCandidate(bool $passed, ?string $identity, ?string $visibleFromDomain): ?array
    {
        if (! $passed || $identity === null) {
            return null;
        }

        $identity = $this->normalizeIdentity($identity);
        $domain = $this->domainFromIdentity($identity);

        if ($identity === null || $domain === null) {
            return null;
        }

        return [
            'identity' => $identity,
            'domain' => $domain,
            'aligned' => $visibleFromDomain !== null
                && $this->domainsAlign($domain, $visibleFromDomain),
        ];
    }

    private function parseAuthenticationResults(string $headerValue): ?array
    {
        $headerValue = $this->boundedHeaderValue($headerValue);
        if ($headerValue === null) {
            return null;
        }

        $segments = preg_split('/\s*;\s*/', $headerValue, 40) ?: [];
        $authservSegment = trim((string) array_shift($segments));
        $authservSegment = preg_replace('/\s*\([^)]*\).*$/', '', $authservSegment) ?? '';
        $authservId = $this->normalizeIdentifier(strtok($authservSegment, " \t") ?: '');

        if ($authservId === null) {
            return null;
        }

        $parsed = [
            'authserv_id' => $authservId,
            'results' => [],
            'properties' => [],
        ];

        foreach ($segments as $segment) {
            if (! preg_match('/^\s*(spf|dkim|dmarc)\s*=\s*([a-z0-9_-]+)/i', $segment, $match)) {
                continue;
            }

            $mechanism = mb_strtolower($match[1]);
            $status = mb_strtolower($match[2]);
            if (! in_array($status, self::RESULT_STATUSES, true)) {
                continue;
            }

            $properties = $this->resultProperties($segment);
            $currentStatus = $parsed['results'][$mechanism] ?? null;

            // Prefer a passing result when a message contains several DKIM signatures.
            if ($currentStatus === null || $status === 'pass' || $currentStatus !== 'pass') {
                $parsed['results'][$mechanism] = $status;
                $parsed['properties'][$mechanism] = $properties;
            }
        }

        return $parsed;
    }

    private function resultProperties(string $segment): array
    {
        preg_match_all(
            '/\b(header\.(?:d|from|i)|smtp\.(?:mailfrom|helo))\s*=\s*(?:"([^"]{0,320})"|([^\s;()]{1,320}))/i',
            $segment,
            $matches,
            PREG_SET_ORDER,
        );

        $properties = [];
        foreach (array_slice($matches, 0, 12) as $match) {
            $name = mb_strtolower($match[1]);
            $value = $match[2] !== '' ? $match[2] : ($match[3] ?? '');
            $value = $this->normalizeIdentity($value);

            if ($value !== null) {
                $properties[$name] = $value;
            }
        }

        return $properties;
    }

    private function property(array $parsed, string $mechanism, array $names): ?string
    {
        foreach ($names as $name) {
            $value = $parsed['properties'][$mechanism][$name] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function firstReceivingHopIsTrusted(array $headers, array $trustedHops): bool
    {
        $received = $this->headerValues($headers, 'received')[0] ?? null;
        if ($received === null) {
            return false;
        }

        $received = $this->boundedHeaderValue($received);
        if ($received === null
            || ! preg_match('/\bby\s+([^\s(;]+)/i', $received, $match)) {
            return false;
        }

        $hop = $this->normalizeIdentifier(trim($match[1], '[]'));

        return $hop !== null && in_array($hop, $trustedHops, true);
    }

    private function headerValues(array $headers, string $wantedName): array
    {
        $values = [];

        foreach ($headers as $name => $value) {
            if (is_string($name) && $this->normalizeHeaderName($name) === $wantedName) {
                $this->appendScalarValues($values, $value);
            } elseif (is_array($value)
                && isset($value['name'])
                && $this->normalizeHeaderName((string) $value['name']) === $wantedName) {
                $this->appendScalarValues($values, $value['value'] ?? $value['values'] ?? null);
            }

            if (count($values) >= self::MAX_HEADER_VALUES) {
                break;
            }
        }

        return array_slice($values, 0, self::MAX_HEADER_VALUES);
    }

    private function appendScalarValues(array &$values, mixed $value): void
    {
        if (is_scalar($value)) {
            $values[] = (string) $value;

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $nested) {
            if (count($values) >= self::MAX_HEADER_VALUES) {
                return;
            }

            if (is_scalar($nested)) {
                $values[] = (string) $nested;
            }
        }
    }

    private function boundedHeaderValue(string $value): ?string
    {
        if (strlen($value) > self::MAX_HEADER_BYTES) {
            return null;
        }

        $value = preg_replace('/\r?\n[ \t]+/', ' ', $value) ?? '';
        if (str_contains($value, "\r") || str_contains($value, "\n")) {
            return null;
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value) ?? '';

        return trim($value) !== '' ? trim($value) : null;
    }

    private function configuredIdentifiers(string $name): array
    {
        $configured = (string) ($this->settings()[$name] ?? '');

        return collect(preg_split('/[\s,;]+/', mb_strtolower($configured)) ?: [])
            ->map(fn (string $identifier): ?string => $this->normalizeIdentifier($identifier))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function settings(): array
    {
        return $this->settings ??= CommonSetting::query()
            ->where('type', 'emailhub')
            ->pluck('value', 'name')
            ->all();
    }

    private function normalizeHeaderName(string $name): string
    {
        return str_replace('_', '-', mb_strtolower(trim($name)));
    }

    private function normalizeIdentifier(string $identifier): ?string
    {
        $identifier = mb_strtolower(rtrim(trim($identifier), '.'));

        if ($identifier === ''
            || strlen($identifier) > 253
            || ! preg_match('/^[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?$/', $identifier)) {
            return null;
        }

        return $identifier;
    }

    private function normalizeIdentity(mixed $identity): ?string
    {
        $identity = mb_strtolower(trim((string) $identity, "<> \t\n\r\0\x0B"));
        if ($identity === '' || strlen($identity) > 320) {
            return null;
        }

        if (str_contains($identity, '@')) {
            [$localPart, $domain] = array_pad(explode('@', $identity, 2), 2, null);
            $domain = $this->normalizeIdentifier((string) $domain);

            return $localPart !== '' && $domain !== null
                ? $localPart.'@'.$domain
                : null;
        }

        return $this->normalizeIdentifier($identity);
    }

    private function domainFromIdentity(mixed $identity): ?string
    {
        $identity = $this->normalizeIdentity($identity);
        if ($identity === null) {
            return null;
        }

        return str_contains($identity, '@')
            ? $this->normalizeIdentifier((string) str($identity)->afterLast('@'))
            : $this->normalizeIdentifier($identity);
    }

    private function domainsAlign(string $authenticatedDomain, string $visibleFromDomain): bool
    {
        return $authenticatedDomain === $visibleFromDomain
            || str_ends_with($authenticatedDomain, '.'.$visibleFromDomain)
            || str_ends_with($visibleFromDomain, '.'.$authenticatedDomain);
    }

    private function emptyFacts(): array
    {
        return [
            'authentication_passed' => false,
            'authenticated_supplier_identity' => null,
            'authenticated_supplier_domain' => null,
            'authserv_id' => null,
            'spf' => null,
            'dkim' => null,
            'dmarc' => null,
            'aligned' => false,
        ];
    }
}
