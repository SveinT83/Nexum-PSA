<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Channels\QueueInternalWebPushChannel;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Contracts\QueuesInternalWebPush;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when a ticket is approaching or has breached its SLA deadline.
 */
class TicketSlaWarning extends Notification implements EmailAccountMailNotification, QueuesInternalWebPush
{
    use Queueable, RoutesEmailThroughAccount;

    public function __construct(
        public string $ticketKey,
        public string $ticketSubject,
        public string $slaType, // 'response' or 'resolution'
        public string $severity, // 'warning' or 'breached'
        public ?string $dueAt = null,
    ) {
        $this->freezeEmailAccountMailSnapshot('tickets');
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $setting = NotificationSetting::getForUser($notifiable, 'ticket_sla_warning');

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = $this->emailAccountMailChannel('tickets');
        }
        if ($setting->web_push_enabled) {
            $channels[] = QueueInternalWebPushChannel::class;
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
            ->when($this->dueAt, fn ($m) => $m->line("**Due:** {$this->dueAt}"))
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
        $slaLabel = $this->slaType === 'response' ? 'First Response' : 'Resolution';

        return [
            'title' => "{$icon} SLA {$this->severity}: {$this->ticketKey}",
            'message' => "**{$this->ticketSubject}** — {$slaLabel} SLA {$this->severity}",
            'details' => array_filter([
                'SLA type' => $slaLabel,
                'Due' => $this->dueAt,
            ]),
            'url' => route('tech.tickets.show', $this->ticketKey),
            'urlLabel' => 'View Ticket',
            'referenceId' => 'sla-'.$this->ticketKey.'-'.$this->slaType.'-'.$this->severity,
            'silent' => $this->severity === 'warning', // warning = silent, breached = loud
        ];
    }

    public function internalWebPushType(): string
    {
        return 'ticket_sla_warning';
    }

    public function internalWebPushPayload(): array
    {
        return [
            'title' => $this->severity === 'breached' ? 'Ticket SLA breached' : 'Ticket SLA warning',
            'body' => 'Open Nexum to review the Ticket SLA status.',
            'target_id' => Ticket::query()->where('ticket_key', $this->ticketKey)->value('id'),
            'ttl' => 900,
            'urgency' => $this->severity === 'breached' ? 'high' : 'normal',
        ];
    }
}
