<?php

namespace App\Modules\Notification\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Canonical type marker for mandatory in-app emergency-access notices. Delivery is recorded through
 * RecordCanonicalNotification rather than Laravel's non-idempotent database channel.
 */
class MailboxBreakGlassActivatedNotification extends Notification
{
    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return [];
    }
}
