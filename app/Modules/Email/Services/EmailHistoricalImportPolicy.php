<?php

namespace App\Modules\Email\Services;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailHistoricalImportRun;

/**
 * Resolves the installation-wide historical import ceiling at execution time.
 *
 * A preview is permission to execute only while its exact cap remains inside
 * the current policy. Lowering the setting never silently truncates an
 * already-previewed run.
 */
class EmailHistoricalImportPolicy
{
    public function configuredCap(): int
    {
        $configured = (int) (CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', 'historical_import_max_messages')
            ->value('value') ?? EmailHistoricalImportRun::HARD_CAP);

        if ($configured <= 0) {
            return EmailHistoricalImportRun::HARD_CAP;
        }

        return min($configured, EmailHistoricalImportRun::HARD_CAP);
    }

    public function permits(EmailHistoricalImportRun $run): bool
    {
        return (int) $run->effective_cap >= 1
            && (int) $run->effective_cap <= $this->configuredCap();
    }
}
