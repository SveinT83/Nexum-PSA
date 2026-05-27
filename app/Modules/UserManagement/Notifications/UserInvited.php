<?php

namespace App\Modules\UserManagement\Notifications;

use App\Modules\UserManagement\Models\InviteToken;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserInvited extends Notification
{
    use Queueable;

    public function __construct(
        public InviteToken $inviteToken
    ) {}

    /**
     * Send via mail (and database for audit trail).
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $appName = config('app.name');
        $acceptUrl = route('invite.accept', ['token' => $this->inviteToken->token]);
        $expiresHours = config('auth.invite_expire_hours', 72);

        return (new MailMessage)
            ->subject("You've been invited to {$appName}")
            ->greeting("Hello {$notifiable->name}!")
            ->line("You have been invited to join **{$appName}**.")
            ->line('Click the button below to set up your account and choose a password.')
            ->action('Accept Invitation', $acceptUrl)
            ->line("This invitation link expires in **{$expiresHours} hours**. If you did not expect this invitation, you can safely ignore this email.")
            ->salutation("Regards,\n{$appName}");
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