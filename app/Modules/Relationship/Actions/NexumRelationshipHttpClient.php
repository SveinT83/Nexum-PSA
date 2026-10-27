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

        $target = parse_url((string) $relationship->remote_base_url);
        if (($target['scheme'] ?? '') !== 'https' || ! empty($target['user']) || ! empty($target['pass'])
            || isset($target['query']) || isset($target['fragment'])) {
            return $this->failure($relationship, $link, $capability, $eventType, 'invalid_destination', 'A trusted HTTPS destination without URL credentials, query or fragment is required.');
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
                ->withoutRedirecting()
                ->timeout(15)
                ->withBody((string) $body, 'application/json')
                ->post($url);
        } catch (ConnectionException $exception) {
            return $this->failure($relationship, $link, $capability, $eventType, 'connection_failed', 'Remote connection did not return a verified result.', $checksum);
        }

        if (! $response->successful()) {
            $message = 'Remote Nexum returned HTTP '.$response->status().'.';

            return $this->failure($relationship, $link, $capability, $eventType, 'remote_http_'.$response->status(), $message, $checksum, [
                'status' => $response->status(),
            ]);
        }

        $contentCapability = in_array($capability, [
            \App\Modules\Relationship\Support\RelationshipCapability::DOCUMENTATION_SYNC,
            \App\Modules\Relationship\Support\RelationshipCapability::KNOWLEDGE_SYNC,
        ], true);
        $decoded = $response->json();
        $remote = is_array($decoded) ? ($decoded['data'] ?? $decoded) : null;
        if ($contentCapability && is_array($remote) && ($remote['conflict'] ?? false) === true) {
            $link?->markConflict('Remote content requires conflict review.');
            $relationship->markSyncFailure('Remote content requires conflict review.');
            $this->events->handle($relationship, [
                'sync_link_id' => $link?->id, 'direction' => 'outbound', 'capability' => $capability,
                'event_type' => $eventType, 'payload_checksum' => $checksum, 'outcome' => 'conflict',
                'error_code' => 'remote_content_conflict', 'metadata' => ['status' => $response->status()],
            ]);

            return ['ok' => true, 'status' => $response->status(), 'data' => $decoded, 'message' => null];
        }
        $identity = is_array($remote) ? ($remote['remote_id'] ?? $remote['id'] ?? null) : null;
        if ($contentCapability && ! ((is_int($identity) && $identity > 0)
            || (is_string($identity) && trim($identity) !== '' && strlen($identity) <= 255))) {
            return $this->failure($relationship, $link, $capability, $eventType, 'invalid_remote_result',
                'Remote content outcome requires reconciliation.', $checksum, ['status' => $response->status()]);
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
        $uncertainContent = in_array($capability, [
            \App\Modules\Relationship\Support\RelationshipCapability::DOCUMENTATION_SYNC,
            \App\Modules\Relationship\Support\RelationshipCapability::KNOWLEDGE_SYNC,
        ], true) && ($code === 'connection_failed' || $code === 'invalid_remote_result' || ($metadata['status'] ?? 0) >= 500);
        $relationship->markSyncFailure($message);
        if ($uncertainContent && $link) {
            $link->forceFill(['sync_status' => 'unresolved', 'last_error' => $message])->save();
        } else {
            $link?->markFailed($message);
        }

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
            'outcome' => $uncertainContent ? 'unresolved' : 'failed',
            'error_code' => $code,
            'error_message' => $message,
            'metadata' => $metadata,
        ]);

        return [
            'ok' => false,
            'unresolved' => $uncertainContent,
            'status' => $metadata['status'] ?? null,
            'data' => [],
            'message' => $message,
        ];
    }
}
