<?php

namespace App\Modules\UserManagement\Support;

class UserProfileData
{
    public static function defaultWorkingHours(): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn (string $day) => [$day => [
                'enabled' => in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], true),
                'start' => '08:00',
                'end' => '16:00',
            ]])
            ->all();
    }

    public static function normalizeWorkingHours(array $workingHours): array
    {
        return collect(self::defaultWorkingHours())
            ->mapWithKeys(function (array $defaults, string $day) use ($workingHours) {
                $submitted = $workingHours[$day] ?? [];

                return [$day => [
                    'enabled' => (bool) ($submitted['enabled'] ?? false),
                    'start' => $submitted['start'] ?? $defaults['start'],
                    'end' => $submitted['end'] ?? $defaults['end'],
                ]];
            })
            ->all();
    }
}
