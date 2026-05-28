<?php

namespace App\Modules\UserManagement\Notifications;

use App\Modules\UserManagement\Models\InviteToken;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification
{
    use Queueable;

    public function __construct(
        public InviteToken $inviteToken
    ) {}

    /**
     * Store an in-app audit trail. The email itself is sent by SendUserInviteEmail.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * Store a minimal record in the database for audit purposes.
     */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'user_invited',
            'invite_token_id' => $this->inviteToken->id,
            'expires_at' => $this->inviteToken->expires_at->toIso8601String(),
        ];
    }
}
