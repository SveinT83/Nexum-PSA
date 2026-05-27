<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Models\NotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a ticket is approaching or has breached its SLA deadline.
 */
class TicketSlaWarning extends Notification
{
    use Queueable;

    public function __construct(
        public string $ticketKey,
        public string $ticketSubject,
        public string $slaType, // 'response' or 'resolution'
        public string $severity, // 'warning' or 'breached'
        public ?string $dueAt = null,
    ) {}

    public function via(object $notifiable): array
    {
        $channels = [];
        $setting = NotificationSetting::getForUser($notifiable, 'ticket_sla_warning');

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
        $ticketUrl = route('tech.tickets.show', $this->ticketKey);
        $icon = $this->severity === 'breached' ? '🚨' : '⚠️';
        $label = $this->severity === 'breached' ? 'BREACHED' : 'WARNING';
        $slaLabel = $this->slaType === 'response' ? 'First Response' : 'Resolution';

        return (new MailMessage)
            ->subject("{$icon} [SLA {$label}] Ticket {$this->ticketKey} — {$slaLabel} SLA")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$icon} The **{$slaLabel} SLA** for ticket **{$this->ticketKey}** has been **{$this->severity}**.")
            ->line("**Subject:** {$this->ticketSubject}")
            ->when($this->dueAt, fn($m) => $m->line("**Due:** {$this->dueAt}"))
            ->action('View Ticket', $ticketUrl);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'ticket_sla_warning',
            'ticket_key' => $this->ticketKey,
            'ticket_subject' => $this->ticketSubject,
            'sla_type' => $this->slaType,
            'severity' => $this->severity,
            'due_at' => $this->dueAt,
            'url' => route('tech.tickets.show', $this->ticketKey),
        ];
    }

    public function toNextcloudTalk(object $notifiable): array
    {
        $icon = $this->severity === 'breached' ? '🚨' : '⚠️';

        return [
            'title' => "{$icon} SLA {$this->severity}: {$this->ticketKey}",
            'message' => "**{$this->ticketSubject}** — {$this->slaType} SLA {$this->severity}",
        ];
    }
}