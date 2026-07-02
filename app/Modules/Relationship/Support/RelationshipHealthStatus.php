<?php

namespace App\Modules\Relationship\Support;

class RelationshipHealthStatus
{
    public const UNKNOWN = 'unknown';

    public const HEALTHY = 'healthy';

    public const DEGRADED = 'degraded';

    public const FAILING = 'failing';

    public static function values(): array
    {
        return [
            self::UNKNOWN,
            self::HEALTHY,
            self::DEGRADED,
            self::FAILING,
        ];
    }
}
