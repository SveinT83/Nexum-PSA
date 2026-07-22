<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CloudFactoryWebhookRegistration
{
    public function __construct(
        private readonly CloudFactoryApiFactory $apiFactory,
        private readonly CloudFactoryAudit $audit,
    ) {}

    public function enable(Integration $integration, ?string $callbackUrl = null): array
    {
        if ($integration->status !== 'active'
            || ! data_get($integration->config, 'capabilities.notifications', false)) {
            throw ValidationException::withMessages([
                'webhook' => 'An active Cloud Factory account with Partner Admin is required.',
            ]);
        }

        $partnerGuid = $this->partnerGuid($integration);
        $callbackUrl ??= route(
            'api.v1.integrations.cloudfactory.webhook',
            ['integration' => $integration],
            true
        );

        if (parse_url($callbackUrl, PHP_URL_SCHEME) !== 'https') {
            throw ValidationException::withMessages([
                'webhook' => 'The Cloud Factory webhook callback must use HTTPS.',
            ]);
        }

        $secret = $integration->getSecret('webhook_secret');

        if (! $secret) {
            $secret = Str::random(64);
            $integration->setSecret('webhook_secret', $secret);
            $integration->save();
        }

        $api = $this->apiFactory->make($integration);
        $definitions = $this->list($api->get('/notification/Events'));
        $events = collect($definitions)
            ->filter(fn (array $definition): bool => filled($definition['name'] ?? null)
                && collect($definition['supportedTypes'] ?? [])
                    ->contains(fn (mixed $type): bool => strcasecmp((string) $type, 'Webhook') === 0))
            ->pluck('name')
            ->map(fn (mixed $event): string => (string) $event)
            ->unique()
            ->values();

        if ($events->isEmpty()) {
            throw ValidationException::withMessages([
                'webhook' => 'Cloud Factory returned no webhook-capable notification events.',
            ]);
        }

        $existing = collect($this->list($api->get('/notification/WebhookRegistration', [
            'partnerId' => $partnerGuid,
        ])));
        $registrationIds = [];

        foreach ($events as $event) {
            $name = Str::limit('Nexum PSA - '.$event, 200, '');
            $headers = [[
                'key' => 'X-API-KEY',
                'value' => $secret,
            ]];
            $current = $existing->first(
                fn (array $registration): bool => ($registration['event'] ?? null) === $event
                    && ($registration['url'] ?? null) === $callbackUrl
            );

            if ($current && filled($current['id'] ?? null)) {
                $registration = $api->put(
                    '/notification/WebhookRegistration/'.$current['id'],
                    ['name' => $name, 'headers' => $headers]
                );
                $registrationIds[] = (string) ($registration['id'] ?? $current['id']);

                continue;
            }

            $registration = $api->post('/notification/WebhookRegistration', [
                'ownerId' => $partnerGuid,
                'name' => $name,
                'event' => $event,
                'url' => $callbackUrl,
                'headers' => $headers,
            ]);

            if (filled($registration['id'] ?? null)) {
                $registrationIds[] = (string) $registration['id'];
            }
        }

        $config = $integration->config ?? [];
        $config['webhooks_enabled'] = true;
        $config['webhook_url'] = $callbackUrl;
        $config['webhook_events'] = $events->all();
        $config['webhook_registration_ids'] = array_values(array_unique($registrationIds));
        $config['webhooks_registered_at'] = now()->toIso8601String();
        $integration->forceFill(['config' => $config])->save();

        $this->audit->record('webhook.enabled', $integration, metadata: [
            'event_count' => $events->count(),
            'registration_count' => count($registrationIds),
            'url' => $callbackUrl,
        ]);

        return [
            'events' => $events->all(),
            'registrations' => array_values(array_unique($registrationIds)),
            'url' => $callbackUrl,
        ];
    }

    public function disable(Integration $integration): void
    {
        $registrationIds = collect(
            data_get($integration->config, 'webhook_registration_ids', [])
        )->filter()->map(fn (mixed $id): string => (string) $id);
        $errors = [];

        if ($integration->status === 'active') {
            $api = $this->apiFactory->make($integration);

            foreach ($registrationIds as $registrationId) {
                try {
                    $api->delete('/notification/WebhookRegistration/'.$registrationId);
                } catch (Throwable $exception) {
                    $errors[] = $registrationId.': '.$exception->getMessage();
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages([
                'webhook' => 'Cloud Factory could not remove every webhook registration. The shared key remains active.',
            ]);
        }

        $config = $integration->config ?? [];
        $config['webhooks_enabled'] = false;
        $config['webhooks_disabled_at'] = now()->toIso8601String();
        unset(
            $config['webhook_registration_ids'],
            $config['webhook_events'],
            $config['webhooks_registered_at']
        );

        $integration->secrets = collect($integration->secrets ?? [])
            ->except('webhook_secret')
            ->all();
        $integration->forceFill(['config' => $config])->save();

        $this->audit->record('webhook.disabled', $integration, metadata: [
            'registration_count' => $registrationIds->count(),
        ]);
    }

    private function partnerGuid(Integration $integration): string
    {
        $partnerGuid = collect([
            data_get($integration->config, 'partner.id'),
            data_get($integration->config, 'partner.partnerGuid'),
            data_get($integration->config, 'partner.guid'),
        ])->first(fn (mixed $value): bool => is_string($value) && Str::isUuid($value));

        if (! $partnerGuid) {
            throw ValidationException::withMessages([
                'webhook' => 'Cloud Factory did not return a valid Partner GUID. Reconnect the Portal account.',
            ]);
        }

        return $partnerGuid;
    }

    private function list(array $response): array
    {
        if (array_is_list($response)) {
            return array_values(array_filter($response, 'is_array'));
        }

        foreach (['items', 'value', 'data', 'results'] as $key) {
            if (is_array($response[$key] ?? null)) {
                return array_values(array_filter($response[$key], 'is_array'));
            }
        }

        return [];
    }
}
