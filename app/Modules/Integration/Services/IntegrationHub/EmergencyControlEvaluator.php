<?php

namespace App\Modules\Integration\Services\IntegrationHub;

use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Models\IntegrationHubEmergencyControl;
use App\Modules\Integration\Models\IntegrationHubSetting;

class EmergencyControlEvaluator
{
    /** @param array<string, mixed> $scope */
    public function assertEnabled(IntegrationHubCapability $capability, array $scope): void
    {
        if (! IntegrationHubSetting::current()->enabled) {
            throw new IntegrationHubDeniedException('hub_disabled', 'Integration Hub is disabled.', 503, 'unavailable');
        }

        $keys = [
            'global',
            self::capabilityKey($capability->capability_key, $capability->contract_version),
        ];
        foreach ($scope['integration_ids'] ?? [] as $id) {
            $keys[] = 'integration:'.$id;
        }
        foreach ($scope['client_ids'] ?? [] as $id) {
            $keys[] = 'client:'.$id;
        }
        foreach ($scope['site_ids'] ?? [] as $id) {
            $keys[] = 'site:'.$id;
        }

        $control = IntegrationHubEmergencyControl::query()
            ->where('installation_key', (string) config('integration-hub.installation_key'))
            ->where('is_disabled', true)
            ->whereIn('control_key', array_values(array_unique($keys)))
            ->orderByRaw("CASE scope_type WHEN 'global' THEN 1 WHEN 'capability' THEN 2 WHEN 'integration' THEN 3 WHEN 'client' THEN 4 ELSE 5 END")
            ->first();

        if ($control) {
            throw new IntegrationHubDeniedException(
                'emergency_control_active',
                'Integration Hub access is disabled for this scope.',
                503,
                'unavailable',
            );
        }
    }

    public static function capabilityKey(string $key, string $version): string
    {
        return 'capability:'.$key.'@'.$version;
    }
}
