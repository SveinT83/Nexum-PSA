<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a ticket is assigned to a technician.
 */
class TicketAssigned extends Notification
{
    use Queueable;

    public function __construct(
        public Ticket $ticket,
        public string $assignedBy,
    ) {}

    /**
     * Determine which channels to use based on user preferences.
     */
    public function via(object $notifiable): array
    {
        $channels = [];
        $setting = NotificationSetting::getForUser($notifiable, 'ticket_assigned');

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = 'mail';
        }

        // Check if Nextcloud Talk is enabled system-wide and for this user
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
            ->subject("[Ticket {$this->ticket->ticket_key}] Assigned to you")
            ->greeting("Hello {$notifiable->name},")
            ->line("A ticket has been assigned to you by **{$this->assignedBy}**.")
            ->line("**Subject:** {$this->ticket->subject}")
            ->line("**Priority:** " . ($this->ticket->priority?->name ?? 'Unset'))
            ->line("**Client:** " . ($this->ticket->client?->name ?? 'N/A'))
            ->action('View Ticket', $ticketUrl)
            ->line('Please review and respond accordingly.');
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_assigned',
            'ticket_key' => $this->ticket->ticket_key,
            'ticket_subject' => $this->ticket->subject,
            'assigned_by' => $this->assignedBy,
            'client_name' => $this->ticket->client?->name,
            'priority' => $this->ticket->priority?->name,
            'url' => route('tech.tickets.show', $this->ticket->ticket_key),
        ];
    }

    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => "Ticket {$this->ticket->ticket_key} assigned to you",
            'message' => "**{$this->ticket->subject}**",
            'details' => array_filter([
                'Assigned by' => $this->assignedBy,
                'Priority' => $this->ticket->priority?->name,
                'Client' => $this->ticket->client?->name,
            ]),
            'url' => route('tech.tickets.show', $this->ticket->ticket_key),
            'urlLabel' => 'View Ticket',
            'referenceId' => 'ticket-assigned-' . $this->ticket->ticket_key . '-' . time(),
        ];
    }
}