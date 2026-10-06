<?php

namespace App\Modules\Workday\Support;

use App\Models\Settings\CommonSetting;

class WorkdaySettings
{
    public function read(): array
    {
        $row = CommonSetting::query()->where('type', 'workday')->where('name', 'manual_workflow')->first();
        $data = json_decode($row?->json ?? '{}', true) ?? [];
        $enabled = (bool) ($data['enabled'] ?? false);

        return ['enabled' => $enabled, 'effective_enabled' => $enabled && (bool) config('workday.enabled'),
            'deployment_enabled' => (bool) config('workday.enabled'), 'retention_years' => 3,
            'version' => (int) ($data['version'] ?? 0)];
    }

    public function enabled(): bool
    {
        // Avoid touching not-yet-migrated storage while deployment activation is off.
        return (bool) config('workday.enabled') && $this->read()['enabled'];
    }
}
