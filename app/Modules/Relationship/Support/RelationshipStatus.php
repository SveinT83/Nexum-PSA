<?php

namespace App\Modules\Relationship\Support;

class RelationshipStatus
{
    public const DRAFT = 'draft';

    public const ACTIVE = 'active';

    public const PAUSED = 'paused';

    public const DISABLED = 'disabled';

    public static function values(): array
    {
        return [
            self::DRAFT,
            self::ACTIVE,
            self::PAUSED,
            self::DISABLED,
        ];
    }
}
