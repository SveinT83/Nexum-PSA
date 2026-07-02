<?php

namespace App\Modules\Relationship\Support;

class RelationshipType
{
    public const CUSTOMER_PROVIDER = 'customer_provider';

    public static function values(): array
    {
        return [
            self::CUSTOMER_PROVIDER,
        ];
    }
}
