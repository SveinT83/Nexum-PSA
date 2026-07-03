<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\NexumSyncLink;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class NexumRelationshipHttpClient
{
    public function __construct(private readonly RecordSyncEvent $events) {}

    public function post(
        NexumRelationship $relationship,
        string $endpoint,
        array $payload,
        string $capability,
        ?NexumSyncLink $link = null,
        string $eventType = 'outbound_sync'
    ): array {
        if (! $relationship->hasOutboundCredentials()) {
            return $this->failure($relationship, $link, $capability, $eventType, 'missing_credentials', 'Remote URL, outbound token, and webhook secret are required before outbound sync can run.');
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = (string) now()->timestamp;
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) $relationship->webhook_secret_encrypted);
        $url = rtrim((string) $relationship->remote_base_url, '/').'/api/v1/nexum/relationships/'.ltrim($endpoint, '/');
        $checksum = hash('sha256', (string) $body);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'X-Nexum-Relationship' => (string) ($relationship->remote_instance_id ?: $relationship->id),
                'X-Nexum-Token' => (string) $relationship->outbound_token_encrypted,
                'X-Nexum-Timestamp' => $timestamp,
                'X-Nexum-Signature' => $signature,
            ])
                ->timeout(15)
                ->withBody((string) $body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            return $this->failure($relationship, $link, $capability, $eventType, 'connection_failed', $exception->getMessage(), $checksum);
        }

        if (! $response->successful()) {
            $message = $response->json('message') ?: 'Remote Nexum returned HTTP '.$response->status().'.';

            return $this->failure($relationship, $link, $capability, $eventType, 'remote_http_'.$response->status(), $message, $checksum, [
                'status' => $response->status(),
            ]);
        }

        $relationship->markSyncSuccess();
        $link?->markSynced();

        $this->events->handle($relationship, [
            'sync_link_id' => $link?->id,
            'direction' => 'outbound',
            'capability' => $capability,
            'local_type' => $link?->local_type,
            'local_id' => $link?->local_id,
            'remote_type' => $link?->remote_type,
            'remote_id' => $link?->remote_id,
            'event_type' => $eventType,
            'payload_checksum' => $checksum,
            'outcome' => 'synced',
            'metadata' => [
                'endpoint' => Str::limit($endpoint, 255, ''),
                'status' => $response->status(),
            ],
        ]);

        return [
            'ok' => true,
            'status' => $response->status(),
            'data' => $response->json() ?? [],
            'message' => null,
        ];
    }

    private function failure(
        NexumRelationship $relationship,
        ?NexumSyncLink $link,
        string $capability,
        string $eventType,
        string $code,
        string $message,
        ?string $checksum = null,
        array $metadata = []
    ): array {
        $relationship->markSyncFailure($message);
        $link?->markFailed($message);

        $this->events->handle($relationship, [
            'sync_link_id' => $link?->id,
            'direction' => 'outbound',
            'capability' => $capability,
            'local_type' => $link?->local_type,
            'local_id' => $link?->local_id,
            'remote_type' => $link?->remote_type,
            'remote_id' => $link?->remote_id,
            'event_type' => $eventType,
            'payload_checksum' => $checksum,
            'outcome' => 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'metadata' => $metadata,
        ]);

        return [
            'ok' => false,
            'status' => $metadata['status'] ?? null,
            'data' => [],
            'message' => $message,
        ];
    }
}
