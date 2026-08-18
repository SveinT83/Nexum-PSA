<?php

namespace App\Modules\Notification\Contracts;

use App\Models\Core\User;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;

interface InboundEmailExternalNotificationDispatcher
{
    /**
     * @param  array{mail:bool,web_push:bool,nextcloud_talk:bool,nextcloud_talk_webhook_url?:?string}  $requested
     * @return array{status:'completed'|'suppressed'|'unresolved',reason_code:?string}
     */
    public function deliver(
        #[\SensitiveParameter] User $user,
        #[\SensitiveParameter] InboundEmailRoutedNotification $notification,
        array $requested,
    ): array;
}
