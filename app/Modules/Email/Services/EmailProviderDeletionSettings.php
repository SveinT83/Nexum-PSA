<?php

namespace App\Modules\Email\Services;

use App\Models\Settings\CommonSetting;

class EmailProviderDeletionSettings
{
    public const ENABLED_SETTING = 'provider_deletion_reconciliation_enabled';

    /**
     * Provider-wide inventory is intentionally opt-in. Missing, malformed, or
     * unexpected values all keep reconciliation and cleanup inert.
     */
    public function enabled(): bool
    {
        return CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', self::ENABLED_SETTING)
            ->value('value') === '1';
    }
}
