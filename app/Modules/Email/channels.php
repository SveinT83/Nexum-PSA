<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('email.user.{userId}', function ($user, $userId) {
    $channelUserId = (string) $userId;

    return preg_match('/^[1-9][0-9]*$/D', $channelUserId) === 1
        && (string) $user->getAuthIdentifier() === $channelUserId
        && $user->isActive()
        && ! $user->isSystemActor();
}, ['guards' => ['web']]);
