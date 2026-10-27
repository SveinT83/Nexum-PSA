<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\CustomerPortalNotification;
use Illuminate\Notifications\DatabaseNotification;

/** One visibility/read boundary for the bell, profile and official API. */
class ManageNotificationReads
{
    public function query(User $user, bool $portal = false)
    {
        $query = $user->notifications();
        if ($portal) {
            $query->where('type', CustomerPortalNotification::class);
        } else {
            if ($user->currentAccessToken() && ! $user->tokenCan('workday-reminders.read')) {
                $query->where('type', '!=', \App\Modules\Notification\Notifications\WorkdayReminderNotification::class);
            } else {
                app(\App\Modules\Workday\Actions\WorkdayReminders::class)->filterNotifications($query, $user);
            }
        }

        return $query;
    }

    public function find(User $user, string $id, bool $portal = false): DatabaseNotification
    {
        return $this->query($user, $portal)->whereKey($id)->firstOrFail();
    }

    public function read(User $user, string $id, bool $portal = false): DatabaseNotification
    {
        $notification = $this->find($user, $id, $portal);
        if ($notification->type === \App\Modules\Notification\Notifications\WorkdayReminderNotification::class) {
            abort_if($user->currentAccessToken() && ! $user->tokenCan('workday-reminders.write'), 403);
        }
        $notification->markAsRead();

        return $notification->fresh();
    }

    public function readAll(User $user, bool $portal = false): int
    {
        $query = $this->query($user, $portal)->whereNull('read_at');
        if ($user->currentAccessToken() && ! $user->tokenCan('workday-reminders.write')) {
            $query->where('type', '!=', \App\Modules\Notification\Notifications\WorkdayReminderNotification::class);
        }

        return $query->update(['read_at' => now()]);
    }

    public function serialize(DatabaseNotification $notification, bool $portal = false): array
    {
        $data = $notification->data;
        if ($portal) {
            $data = array_intersect_key($data, array_flip(['type', 'title', 'body', 'url', 'client_id', 'site_id']));
        }

        return ['id' => $notification->id, 'type' => $notification->type, 'data' => $data,
            'read_at' => $notification->read_at?->toISOString(),
            'created_at' => $notification->created_at?->toISOString(),
            'updated_at' => $notification->updated_at?->toISOString(),
            'links' => ['mark_read' => route('api.v1.notifications.'.($portal ? 'portal.' : '').'read', $notification->id)]];
    }
}
