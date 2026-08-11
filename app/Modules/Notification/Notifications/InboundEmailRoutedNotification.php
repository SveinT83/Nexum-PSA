<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class InboundEmailRoutedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly array $payload,
        public readonly string $databaseNotificationId,
    ) {
        $this->afterCommit();
    }

    /**
     * @return list<class-string|string>
     */
    public function via(object $notifiable): array
    {
        $setting = NotificationSetting::getForUser($notifiable, (string) $this->payload['type']);
        $channels = [];

        if ($setting->mail_enabled) {
            $channels[] = 'mail';
        }

        if ($setting->web_push_enabled && app(WebPushReadiness::class)->isReady()) {
            $channels[] = WebPushChannel::class;
        }

        $talk = NotificationChannel::getByDriver('nextcloud_talk');
        if ($talk?->is_enabled && $setting->nextcloud_talk_enabled) {
            $channels[] = NextcloudTalkChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('[Nexum] '.$this->mailSubject())
            ->greeting('Hello '.$notifiable->name.'.')
            ->line($this->payload['mail_summary'] ?? 'A new inbound email notification is ready in Nexum.')
            ->action((string) ($this->payload['action_label'] ?? 'Open Nexum'), $this->openUrl());
    }

    public function toWebPush(mixed $notifiable, Notification $notification): WebPushMessage
    {
        $setting = NotificationSetting::getForUser($notifiable, (string) $this->payload['type']);

        return (new WebPushMessage)
            ->title((string) ($this->payload['push_title'] ?? 'Nexum notification'))
            ->body($this->pushBody($setting))
            ->icon('/logo.png')
            ->badge('/logo.png')
            ->tag((string) $this->payload['web_push_tag'])
            ->data([
                'url' => $this->openPath(),
                'kind' => $this->payload['type'],
                'notification_id' => $this->databaseNotificationId,
            ])
            ->options([
                'TTL' => 1800,
                'urgency' => 'normal',
            ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => $this->mailSubject(),
            'message' => $this->payload['mail_summary'] ?? 'A new inbound email notification is ready in Nexum.',
            'details' => array_filter([
                'Ticket' => $this->payload['ticket_key'] ?? null,
                'Source' => $this->payload['source_label'] ?? null,
            ]),
            'url' => $this->openUrl(),
            'urlLabel' => $this->payload['action_label'] ?? 'Open Nexum',
            'referenceId' => $this->payload['delivery_identity'],
        ];
    }

    private function mailSubject(): string
    {
        return (string) ($this->payload['title'] ?? 'Inbound Email');
    }

    private function openUrl(): string
    {
        return route('tech.profile.notifications.open', ['notification' => $this->databaseNotificationId]);
    }

    private function openPath(): string
    {
        return route('tech.profile.notifications.open', ['notification' => $this->databaseNotificationId], false);
    }

    private function pushBody(NotificationSetting $setting): string
    {
        if (! $setting->web_push_preview_enabled) {
            return (string) ($this->payload['push_body'] ?? 'Open Nexum to view the notification.');
        }

        $sender = trim((string) ($this->payload['preview_sender_name'] ?? ''));
        $subject = trim((string) ($this->payload['preview_subject'] ?? ''));

        if ($sender !== '' && $subject !== '') {
            return str($sender.': '.$subject)->limit(140)->toString();
        }

        return $subject !== ''
            ? str($subject)->limit(140)->toString()
            : (string) ($this->payload['push_body'] ?? 'Open Nexum to view the notification.');
    }
}
