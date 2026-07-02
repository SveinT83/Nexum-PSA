<?php

namespace App\Modules\Relationship\Support;

class RelationshipDirection
{
    public const WE_ARE_PROVIDER = 'we_are_provider';

    public const WE_USE_PROVIDER = 'we_use_provider';

    public const COLLABORATION = 'collaboration';

    public static function labels(): array
    {
        return [
            self::WE_ARE_PROVIDER => 'We are provider for a client',
            self::WE_USE_PROVIDER => 'We use an upstream provider',
            self::COLLABORATION => 'Collaboration',
        ];
    }

    public static function values(): array
    {
        return array_keys(self::labels());
    }
}
