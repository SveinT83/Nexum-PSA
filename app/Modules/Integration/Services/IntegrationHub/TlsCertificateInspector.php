<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Contracts\InspectsTlsCertificates;
use Carbon\CarbonImmutable;

class TlsCertificateInspector implements InspectsTlsCertificates
{
    public function __construct(private TlsTargetResolver $targets) {}

    public function inspect(string $hostname): array
    {
        try {
            $addresses = $this->targets->resolve($hostname);
        } catch (\App\Modules\Integration\Exceptions\IntegrationHubDeniedException $exception) {
            return [
                'status' => $exception->resultStatus,
                'reason_code' => $exception->reasonCode,
                'retryable' => $exception->retryable,
                'hostname' => $hostname,
            ];
        }

        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => true,
            'verify_peer_name' => true,
            'peer_name' => $hostname,
            'SNI_enabled' => true,
            'disable_compression' => true,
        ]]);
        $timeout = min(15, max(1, (int) config('integration-hub.plesk.tls_timeout_seconds', 5)));
        $deadline = microtime(true) + $timeout;
        $socket = false;
        foreach ($addresses as $address) {
            $remaining = max(0.1, $deadline - microtime(true));
            if ($remaining <= 0.1 && microtime(true) >= $deadline) {
                break;
            }
            $target = str_contains($address, ':') ? '['.$address.']' : $address;
            $errorCode = 0;
            $errorMessage = '';
            set_error_handler(static fn (): bool => true);
            try {
                $socket = stream_socket_client('ssl://'.$target.':443', $errorCode, $errorMessage, $remaining, STREAM_CLIENT_CONNECT, $context);
            } finally {
                restore_error_handler();
            }
            if (is_resource($socket)) {
                break;
            }
        }
        if (! is_resource($socket)) {
            return ['status' => 'unavailable', 'reason_code' => 'tls_connection_unavailable', 'retryable' => true, 'hostname' => $hostname];
        }

        $parameters = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;
        $parsed = $certificate ? openssl_x509_parse($certificate, false) : false;
        if (! is_array($parsed) || ! isset($parsed['validFrom_time_t'], $parsed['validTo_time_t'])) {
            return ['status' => 'failed', 'reason_code' => 'tls_certificate_unreadable', 'retryable' => false, 'hostname' => $hostname];
        }

        $validFrom = CarbonImmutable::createFromTimestampUTC((int) $parsed['validFrom_time_t']);
        $expiresAt = CarbonImmutable::createFromTimestampUTC((int) $parsed['validTo_time_t']);
        $now = CarbonImmutable::now('UTC');
        $status = $expiresAt->isPast() ? 'failed' : 'ok';

        return [
            'status' => $status,
            'reason_code' => $status === 'ok' ? null : 'tls_certificate_expired',
            'retryable' => false,
            'hostname' => $hostname,
            'hostname_verified' => true,
            'valid_from' => $validFrom->toIso8601String(),
            'expires_at' => $expiresAt->toIso8601String(),
            'days_remaining' => $now->diffInDays($expiresAt, false),
            'issuer_common_name' => isset($parsed['issuer']['CN']) ? mb_substr((string) $parsed['issuer']['CN'], 0, 191) : null,
            'observed_at' => $now->toIso8601String(),
        ];
    }
}
