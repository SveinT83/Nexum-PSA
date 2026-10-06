<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Generic content only. Workday's durable delivery job owns transport and rechecks. */
class WorkdayReminderNotification extends Notification implements EmailAccountMailNotification, ShouldQueue
{
    use RoutesEmailThroughAccount;

    public function __construct(public string $path, ?array $snapshot = null, private ?string $reminderUuid = null, private ?string $expiresAt = null)
    {
        if ($snapshot === null) {
            $this->freezeEmailAccountMailSnapshot('system');
        } else {
            $snapshot += ['captured' => false, 'scope' => null, 'account_id' => null,
                'provider_binding_version' => null, 'failure_code' => null];
            $this->emailAccountMailSnapshotCaptured = (bool) $snapshot['captured'];
            $this->emailAccountMailScope = $snapshot['scope'];
            $this->emailAccountMailAccountId = $snapshot['account_id'];
            $this->emailAccountMailProviderBindingVersion = $snapshot['provider_binding_version'];
            $this->emailAccountMailSnapshotFailureCode = $snapshot['failure_code'];
        }
    }

    public function via(object $notifiable): array
    {
        // Never enter Laravel's generic queued notification retry path.
        return [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('Review your workday')
            ->line('Open Nexum to record and save your actual working time.')
            ->action('Open workday', url($this->path));
    }

    public function toDatabase(object $notifiable): array
    {
        return ['type' => 'workday_reminder', 'title' => 'Review your workday',
            'message' => 'Open Nexum to record and save your actual working time.', 'url' => $this->path,
            'workday_reminder' => $this->reminderUuid, 'workday_expires_at' => $this->expiresAt];
    }
}
