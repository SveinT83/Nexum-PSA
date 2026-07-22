<?php

namespace App\Modules\Integration\Services\CloudFactory;

use App\Models\System\Integrations\Integration;

class CloudFactoryIntegration
{
    public const TYPE = 'cloudfactory';

    public const DEFAULT_SERVER = 'https://portal.api.cloudfactory.dk';

    public function getOrCreate(): Integration
    {
        $integration = Integration::query()->firstOrNew(['type' => self::TYPE]);
        $wasPersisted = $integration->exists;

        $integration->forceFill([
            'name' => $integration->name ?: 'Cloud Factory',
            'server' => $integration->server ?: self::DEFAULT_SERVER,
            'status' => $integration->status ?: 'disabled',
            'config' => $this->config($integration),
            'is_healthy' => $wasPersisted ? $integration->is_healthy : false,
        ])->save();

        return $integration;
    }

    public function active(): ?Integration
    {
        return Integration::query()
            ->where('type', self::TYPE)
            ->where('status', 'active')
            ->first();
    }

    public function defaults(): array
    {
        return [
            'sync_enabled' => true,
            'customer_sync_minutes' => 60,
            'subscription_sync_minutes' => 15,
            'catalogue_sync_day' => 1,
            'catalogue_sync_time' => '03:15',
            'pricing_mode' => 'follow_msrp',
            'markup_percent' => 0,
            'default_currency' => 'NOK',
            'default_country_code' => 'NO',
            'default_unit_id' => null,
            'write_scope' => 'test_client',
            'microsoft_billing_cycle_type' => 1,
            'writes_enabled' => false,
            'test_client_id' => null,
            'create_missing_clients' => true,
            'push_client_updates' => true,
            'webhooks_enabled' => false,
            'capabilities' => [],
        ];
    }

    public function config(Integration $integration): array
    {
        return array_replace($this->defaults(), $integration->config ?? []);
    }
}
