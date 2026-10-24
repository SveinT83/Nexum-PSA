<?php

namespace App\Modules\Nextcloud\Actions;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Nextcloud\Models\NextcloudCalendarMapping;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Browser and API share mapping ownership validation and explicit activation invalidation. */
class ManageNextcloudCalendarMapping
{
    public function validate(Request $request): array
    {
        $data = $request->validate([
            'remote_calendar_id' => ['required', 'string', 'max:500'],
            'remote_display_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'calendar_id' => ['sometimes', 'nullable', 'integer', 'exists:calendars,id'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:'.(new User)->getTable().',id'],
            'sync_direction' => ['required', Rule::in(['two_way', 'pull_only', 'push_only'])],
        ]);
        $path = rawurldecode($data['remote_calendar_id']);
        abort_unless(str_starts_with($path, '/remote.php/dav/calendars/') && str_ends_with($path, '/')
            && ! preg_match('~[?#\\\\\x00-\x20]|/\.\.?/~', $path), 422, 'Use an exact relative CalDAV calendar collection path.');
        abort_if(empty($data['calendar_id']) !== empty($data['user_id']), 422, 'Select both local calendar and owner, or leave both unassigned.');
        if (! empty($data['calendar_id'])) {
            $calendar = Calendar::findOrFail($data['calendar_id']);
            $user = User::findOrFail($data['user_id']);
            abort_unless($calendar->is_active && $calendar->type === 'personal' && $calendar->owner_type === User::class
                && (int) $calendar->owner_id === (int) $user->id && $user->isActive() && ! $user->isSystemActor(), 422, 'Select the active owner personal calendar.');
        }

        return $data;
    }

    public function upsert(Request $request, NextcloudConnection $connection): NextcloudCalendarMapping
    {
        $data = $this->validate($request);

        return DB::transaction(function () use ($connection, $data) {
            $connection = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            CalendarApiAccess::idle($connection);
            $old = $connection->calendarMappings()->where('remote_calendar_id', $data['remote_calendar_id'])->first();
            abort_if(DB::table('nextcloud_calendar_sync_locks')->whereIn('calendar_id', array_filter([$old?->calendar_id, $data['calendar_id'] ?? null]))
                ->where('lease_until', '>', now())->exists(), 409, 'A target Calendar is currently leased.');
            $mapping = $connection->calendarMappings()->updateOrCreate(['remote_calendar_id' => $data['remote_calendar_id']], [
                'calendar_id' => $data['calendar_id'] ?? null, 'user_id' => $data['user_id'] ?? null,
                'remote_display_name' => $data['remote_display_name'] ?? null, 'sync_direction' => $data['sync_direction'], 'is_active' => true,
            ]);
            if ($mapping->wasRecentlyCreated || $mapping->wasChanged()) {
                $this->pause($connection);
            }

            return $mapping;
        });
    }

    public function remove(NextcloudCalendarMapping $mapping): NextcloudConnection
    {
        return DB::transaction(function () use ($mapping) {
            $connection = NextcloudConnection::lockForUpdate()->findOrFail($mapping->connection_id);
            CalendarApiAccess::idle($connection);
            abort_if(DB::table('nextcloud_calendar_sync_locks')->where('calendar_id', $mapping->calendar_id)->where('lease_until', '>', now())->exists(), 409, 'The target Calendar is currently leased.');
            $mapping->delete();
            $this->pause($connection);

            return $connection;
        });
    }

    private function pause(NextcloudConnection $connection): void
    {
        $connection->forceFill(['settings' => array_replace($connection->settings ?? [], ['automatic_calendar_sync_enabled' => false])])->save();
    }
}
