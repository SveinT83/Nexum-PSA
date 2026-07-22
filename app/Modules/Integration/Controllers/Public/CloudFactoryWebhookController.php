<?php

namespace App\Modules\Integration\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Jobs\ProcessCloudFactoryWebhook;
use App\Modules\Integration\Models\CloudFactory\WebhookReceipt;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryAudit;
use App\Modules\Integration\Services\CloudFactory\CloudFactoryIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

class CloudFactoryWebhookController extends Controller
{
    private const MAX_BODY_BYTES = 262144;

    public function __invoke(
        Request $request,
        Integration $integration,
        CloudFactoryAudit $audit,
    ): JsonResponse {
        abort_unless(
            $integration->type === CloudFactoryIntegration::TYPE
                && $integration->status === 'active'
                && data_get($integration->config, 'webhooks_enabled', false),
            404
        );

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $expectedKey = (string) $integration->getSecret('webhook_secret');
        $providedKey = (string) $request->header('X-API-KEY', '');

        if ($expectedKey === '' || $providedKey === '' || ! hash_equals($expectedKey, $providedKey)) {
            $audit->record('webhook.rejected', $integration, metadata: [
                'reason' => 'invalid_api_key',
            ]);

            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $validator = Validator::make($request->json()->all(), [
            'EventKey' => ['required', 'string', 'max:255'],
            'CreatedAt' => ['required', 'date'],
            'SentAt' => ['required', 'date'],
            'PartnerGuid' => ['required', 'uuid'],
        ]);

        if ($validator->fails()) {
            $audit->record('webhook.rejected', $integration, metadata: [
                'reason' => 'invalid_payload',
                'fields' => array_keys($validator->errors()->toArray()),
            ]);

            return response()->json([
                'message' => 'Invalid Cloud Factory notification payload.',
            ], 422);
        }

        $payload = $validator->validated();
        $knownPartnerGuid = collect([
            data_get($integration->config, 'partner.id'),
            data_get($integration->config, 'partner.partnerGuid'),
            data_get($integration->config, 'partner.guid'),
        ])->first(fn (mixed $value): bool => is_string($value) && $value !== '');

        if ($knownPartnerGuid
            && strcasecmp((string) $knownPartnerGuid, (string) $payload['PartnerGuid']) !== 0) {
            $audit->record('webhook.rejected', $integration, metadata: [
                'reason' => 'partner_mismatch',
            ]);

            return response()->json(['message' => 'Unauthorized partner.'], 403);
        }

        $fingerprint = hash('sha256', implode('|', [
            strtolower(trim((string) $payload['EventKey'])),
            trim((string) $payload['CreatedAt']),
            strtolower(trim((string) $payload['PartnerGuid'])),
        ]));

        $receipt = WebhookReceipt::query()->firstOrCreate(
            [
                'integration_id' => $integration->id,
                'fingerprint' => $fingerprint,
            ],
            [
                'event_key' => $payload['EventKey'],
                'partner_guid' => $payload['PartnerGuid'],
                'provider_created_at' => Carbon::parse($payload['CreatedAt'])->utc(),
                'provider_sent_at' => Carbon::parse($payload['SentAt'])->utc(),
                'received_at' => now(),
                'header_valid' => true,
                'processing_state' => 'queued',
                'sanitized_payload' => [
                    'EventKey' => $payload['EventKey'],
                    'CreatedAt' => $payload['CreatedAt'],
                    'SentAt' => $payload['SentAt'],
                    'PartnerGuid' => $payload['PartnerGuid'],
                ],
            ]
        );

        if ($receipt->wasRecentlyCreated) {
            ProcessCloudFactoryWebhook::dispatch($receipt->id);

            $audit->record('webhook.accepted', $integration, subject: $receipt, metadata: [
                'event_key' => $receipt->event_key,
                'fingerprint' => $receipt->fingerprint,
            ]);
        }

        return response()->json([
            'accepted' => true,
            'duplicate' => ! $receipt->wasRecentlyCreated,
        ], 202);
    }
}
