<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a closed/resolved ticket is reopened.
 */
class TicketReopened extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public ?string $reopenedBy = null,
        public ?string $reason = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        $setting = NotificationSetting::getForUser($notifiable, 'ticket_status_changed');

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = 'mail';
        }

        $talkChannel = NotificationChannel::getByDriver('nextcloud_talk');
        if ($talkChannel?->is_enabled && $setting->nextcloud_talk_enabled) {
            $channels[] = \App\Modules\Notification\Channels\NextcloudTalkChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticketUrl = route('tech.tickets.show', $this->ticket->ticket_key);

        return (new MailMessage)
            ->subject("[Ticket {$this->ticket->ticket_key}] Reopened")
            ->greeting("Hello {$notifiable->name},")
            ->line("Ticket **{$this->ticket->ticket_key}** has been reopened.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->when($this->reopenedBy, fn($m) => $m->line("**Reopened by:** {$this->reopenedBy}"))
            ->when($this->reason, fn($m) => $m->line("**Reason:** {$this->reason}"))
            ->action('View Ticket', $ticketUrl);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_reopened',
            'ticket_key' => $this->ticket->ticket_key,
            'ticket_subject' => $this->ticket->subject,
            'reopened_by' => $this->reopenedBy,
            'reason' => $this->reason,
            'url' => route('tech.tickets.show', $this->ticket->ticket_key),
        ];
    }

    public function toNextcloudTalk(object $notifiable): array
    {
        $message = "**{$this->ticket->subject}** — reopened";
        if ($this->reopenedBy) {
            $message .= " by {$this->reopenedBy}";
        }
        if ($this->reason) {
            $message .= ": {$this->reason}";
        }

        return [
            'title' => "Ticket {$this->ticket->ticket_key} reopened",
            'message' => $message,
        ];
    }
}