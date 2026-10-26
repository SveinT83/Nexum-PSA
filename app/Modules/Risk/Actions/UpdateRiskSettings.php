<?php

namespace App\Modules\Risk\Actions;

use App\Modules\Risk\Support\RiskMutex;
use App\Modules\Risk\Support\RiskPayload;
use App\Modules\Risk\Support\RiskPolicy;
use App\Modules\Risk\Support\RiskSettings;

final class UpdateRiskSettings
{
    public function handle(array $data, bool $partial = false): array
    {
        return app(RiskMutex::class)->run(function () use ($data, $partial) {
            RiskPolicy::actor('risk.manage_settings');
            $valid = RiskPayload::settings($data, $partial);
            $settings = app(RiskSettings::class);

            return $settings->update(array_replace($settings->get(), $valid));
        });
    }
}
