<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Nextcloud\Models\NextcloudCalendarMapping;
use App\Modules\Nextcloud\Models\NextcloudConnection;

/** Shared API/job authorization is always checked against the current actor and mapping owner. */
class CalendarApiAccess
{
    public static function actor(User $actor, string $permission = 'nextcloud.sync'): User
    {
        $actor = User::query()->findOrFail($actor->id);
        abort_unless($actor->isActive() && $actor->hasAnyRole(['Admin', 'Superuser']) && $actor->can($permission), 403);

        return $actor;
    }

    public static function mapping(User $actor, NextcloudCalendarMapping $mapping): void
    {
        $calendar = $mapping->calendar;
        abort_unless($mapping->is_active && $calendar?->is_active && $calendar->type === 'personal'
            && $calendar->owner_type === User::class && (int) $calendar->owner_id === (int) $mapping->user_id
            && $mapping->user?->isActive() && ! $mapping->user->isSystemActor()
            && app(CalendarVisibility::class)->canManageCalendar($actor, $calendar), 403, 'Mapping ownership is not eligible.');
    }

    public static function revision(NextcloudConnection $connection): string
    {
        $connection = $connection->fresh();

        return hash('sha256', json_encode([
            $connection->only(['id', 'scope', 'mode', 'base_url', 'service_username', 'is_active', 'supports_managed_writes', 'settings']),
            // A credential rotation invalidates pending consent without exposing the credential.
            hash('sha256', (string) $connection->getRawOriginal('service_password')),
            $connection->calendarMappings()->orderBy('id')->get(['id', 'connection_id', 'calendar_id', 'user_id', 'remote_calendar_id', 'sync_direction', 'is_active'])->toArray(),
        ]));
    }

    public static function idle(NextcloudConnection $connection): void
    {
        abort_if(\Illuminate\Support\Facades\DB::table('nextcloud_calendar_sync_states')->where('connection_id', $connection->id)->where('lease_until', '>', now())->exists(), 409, 'Calendar operation already queued or running.');
    }
}
