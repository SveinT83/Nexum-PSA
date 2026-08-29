<?php

namespace App\Modules\Integration\Support;

class RmmAlertSeverity
{
    public const LEVELS = ['info', 'warning', 'critical'];

    public static function normalize(mixed $value): string
    {
        $normalized = mb_strtolower(trim((string) $value));

        if (in_array($normalized, ['critical', 'crit', 'fatal', 'emergency', 'high', 'urgent'], true)) {
            return 'critical';
        }

        if (in_array($normalized, ['info', 'informational', 'low', 'notice'], true)) {
            return 'info';
        }

        if (in_array($normalized, ['warning', 'warn', 'medium', 'degraded', 'error', 'failed', 'failing'], true)) {
            return 'warning';
        }

        // A failing check with no portable provider severity is operationally a warning,
        // not an invented critical event.
        return 'warning';
    }
}
