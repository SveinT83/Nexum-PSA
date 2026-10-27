<?php

namespace App\Modules\Notification\Support;

use Illuminate\Http\Request;

/** Transport/credential inputs and diagnostic SQL never belong in ordinary telemetry. */
class NotificationPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('api/v1/notifications*', 'tech/profile/notifications*', 'tech/admin/notification-channels*');
    }
}
