<?php

namespace App\Modules\Integration\Services;

use App\Modules\Integration\Exceptions\EmailProviderSecurityException;
use App\Modules\Integration\Support\EmailProviderEndpoint;

final class EmailProviderEndpointPolicy
{
    /** @var array<string, array<int, string>> */
    private const STANDARD_ENDPOINTS = [
        'imap' => [
            993 => 'implicit_tls',
            143 => 'starttls',
        ],
        'smtp' => [
            465 => 'implicit_tls',
            587 => 'starttls',
        ],
    ];

    public function normalize(string $protocol, #[\SensitiveParameter] string $host, int $port, string $transport): EmailProviderEndpoint
    {
        $protocol = strtolower(trim($protocol));

        if (! in_array($protocol, ['imap', 'smtp'], true)) {
            throw new EmailProviderSecurityException('protocol_not_supported');
        }

        $host = $this->normalizeHost($host);
        $transport = $this->normalizeTransport($protocol, $port, $transport);
        $standardTransport = self::STANDARD_ENDPOINTS[$protocol][$port] ?? null;
        $policy = $standardTransport !== null
            ? [
                'transport' => $standardTransport,
                'identifier' => "standard.{$protocol}.{$port}.{$standardTransport}",
            ]
            : $this->additionalPolicy($protocol, $port);

        if ($policy === null) {
            throw new EmailProviderSecurityException('port_not_allowed');
        }

        if ($policy['transport'] !== $transport) {
            throw new EmailProviderSecurityException('transport_mismatch');
        }

        return new EmailProviderEndpoint(
            $protocol,
            $host,
            $port,
            $transport,
            $policy['identifier'],
        );
    }

    public function normalizeHost(#[\SensitiveParameter] string $host): string
    {
        if ($host === ''
            || preg_match('/[\x00-\x20\x7f]/', $host) === 1
            || str_contains($host, '://')
            || str_contains($host, '/')
            || str_contains($host, '?')
            || str_contains($host, '#')
            || str_contains($host, '@')
            || str_contains($host, '*')
            || str_contains($host, '%')
            || str_starts_with($host, '[')
            || str_ends_with($host, ']')) {
            throw new EmailProviderSecurityException('host_syntax_invalid');
        }

        $ipBytes = @inet_pton($host);
        if ($ipBytes !== false) {
            return strtolower((string) inet_ntop($ipBytes));
        }

        $ascii = idn_to_ascii($host, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46, $info);

        if ($ascii === false || (($info['errors'] ?? 0) !== 0)) {
            throw new EmailProviderSecurityException('host_idna_invalid');
        }

        $ascii = strtolower(rtrim($ascii, '.'));

        if ($ascii === '' || strlen($ascii) > 253) {
            throw new EmailProviderSecurityException('host_length_invalid');
        }

        foreach (explode('.', $ascii) as $label) {
            if ($label === ''
                || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $label) !== 1) {
                throw new EmailProviderSecurityException('host_label_invalid');
            }
        }

        return $ascii;
    }

    private function normalizeTransport(string $protocol, int $port, string $transport): string
    {
        $transport = strtolower(trim($transport));

        if (in_array($transport, ['implicit_tls', 'ssl', 'smtps', 'imaps'], true)) {
            return 'implicit_tls';
        }

        if (in_array($transport, ['starttls'], true)) {
            return 'starttls';
        }

        // Existing Email forms use `tls` for STARTTLS SMTP and sometimes for
        // IMAP. The port disambiguates it under the fixed security matrix.
        if ($transport === 'tls') {
            return in_array($port, [465, 993], true) ? 'implicit_tls' : 'starttls';
        }

        throw new EmailProviderSecurityException($protocol.'_transport_invalid');
    }

    /** @return array{transport: string, identifier: string}|null */
    private function additionalPolicy(string $protocol, int $port): ?array
    {
        $entries = config('email_provider_security.additional_endpoints', []);
        $names = [];
        $endpointKeys = [];
        $matched = null;

        foreach (is_array($entries) ? $entries : [] as $entry) {
            if (! is_array($entry)) {
                throw new EmailProviderSecurityException('endpoint_allowlist_invalid');
            }

            $name = strtolower(trim((string) ($entry['name'] ?? '')));
            $entryProtocol = strtolower(trim((string) ($entry['protocol'] ?? '')));
            $entryPort = (int) ($entry['port'] ?? 0);
            $entryTransport = strtolower(trim((string) ($entry['transport'] ?? '')));

            if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,79}$/', $name) !== 1
                || ! in_array($entryProtocol, ['imap', 'smtp'], true)
                || $entryPort < 1
                || $entryPort > 65535
                || ! in_array($entryTransport, ['implicit_tls', 'starttls'], true)) {
                throw new EmailProviderSecurityException('endpoint_allowlist_invalid');
            }

            $endpointKey = $entryProtocol.':'.$entryPort;
            if (isset($names[$name]) || isset($endpointKeys[$endpointKey])) {
                throw new EmailProviderSecurityException('endpoint_allowlist_duplicate');
            }
            $names[$name] = true;
            $endpointKeys[$endpointKey] = true;

            if ($entryProtocol === $protocol && $entryPort === $port) {
                $matched = [
                    'transport' => $entryTransport,
                    'identifier' => 'installation.'.$name,
                ];
            }
        }

        return $matched;
    }
}
