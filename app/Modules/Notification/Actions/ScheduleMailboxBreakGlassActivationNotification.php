<?php

namespace App\Modules\Notification\Actions;

use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Notification\Jobs\DispatchMailboxBreakGlassActivationNotification;

class ScheduleMailboxBreakGlassActivationNotification
{
    /**
     * Queue Notification-owned delivery only after the activation transaction commits. The
     * delivery action is idempotent per access and recipient, so a queue retry cannot duplicate it.
     */
    public function schedule(EmailBreakGlassAccess $access): void
    {
        DispatchMailboxBreakGlassActivationNotification::dispatch((int) $access->id)
            ->onQueue('notifications')
            ->afterCommit();
    }
}
