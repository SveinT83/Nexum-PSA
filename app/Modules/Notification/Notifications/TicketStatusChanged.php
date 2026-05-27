<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a ticket's status changes (e.g., open → in progress → resolved).
 */
class TicketStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public string $oldStatus,
        public string $newStatus,
        public ?string $changedBy = null,
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

        $talkChannel = \App\Modules\Notification\Models\NotificationChannel::getByDriver('nextcloud_talk');
        if ($talkChannel?->is_enabled && $setting->nextcloud_talk_enabled) {
            $channels[] = \App\Modules\Notification\Channels\NextcloudTalkChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticketUrl = route('tech.tickets.show', $this->ticket->ticket_key);

        return (new MailMessage)
            ->subject("[Ticket {$this->ticket->ticket_key}] Status: {$this->oldStatus} → {$this->newStatus}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Ticket **{$this->ticket->ticket_key}** status has been changed.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Status:** {$this->oldStatus} → {$this->newStatus}")
            ->when($this->changedBy, fn($m) => $m->line("**Changed by:** {$this->changedBy}"))
            ->action('View Ticket', $ticketUrl);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_status_changed',
            'ticket_key' => $this->ticket->ticket_key,
            'ticket_subject' => $this->ticket->subject,
            'old_status' => $this->oldStatus,
            'new_status' => $this->newStatus,
            'changed_by' => $this->changedBy,
            'url' => route('tech.tickets.show', $this->ticket->ticket_key),
        ];
    }

    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => "Ticket {$this->ticket->ticket_key}: {$this->oldStatus} → {$this->newStatus}",
            'message' => "**{$this->ticket->subject}**",
            'details' => array_filter([
                'Status' => "{$this->oldStatus} → {$this->newStatus}",
                'Changed by' => $this->changedBy,
                'Priority' => $this->ticket->priority?->name,
                'Client' => $this->ticket->client?->name,
            ]),
            'url' => route('tech.tickets.show', $this->ticket->ticket_key),
            'urlLabel' => 'View Ticket',
            'referenceId' => 'ticket-status-' . $this->ticket->ticket_key . '-' . $this->newStatus . '-' . time(),
        ];
    }
}