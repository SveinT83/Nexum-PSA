<?php

namespace App\Modules\UserManagement\Support;

use App\Models\Core\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Render instants in the viewer's existing timezone without changing UTC storage or durations. */
class UserDateTime
{
    public function timezone(?User $user = null): string
    {
        $user ??= auth()->user();

        // Match the canonical work-plan precedence; a display read never creates a profile.
        return $user?->profile?->timezone
            ?: ($user?->preferences?->timezone ?: config('app.timezone', 'UTC'));
    }

    public function format(?DateTimeInterface $instant, string $format = 'Y-m-d H:i', ?User $user = null): ?string
    {
        return $instant === null ? null : CarbonImmutable::instance($instant)
            ->setTimezone($this->timezone($user))->format($format);
    }
}
