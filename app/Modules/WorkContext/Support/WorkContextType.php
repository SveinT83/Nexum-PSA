<?php

namespace App\Modules\WorkContext\Support;

final class WorkContextType
{
    public const INTERNAL = 'internal';
    public const CLIENT = 'client';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return [
            self::INTERNAL,
            self::CLIENT,
        ];
    }

    public static function isSupported(string $type): bool
    {
        return in_array($type, self::values(), true);
    }
}
