<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('email.user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});
