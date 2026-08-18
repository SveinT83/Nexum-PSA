<?php

namespace App\Modules\Notification\Notifications;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class InboundEmailRoutedNotification extends Notification implements EmailAccountMailNotification, ShouldQueue
{
    use Queueable, RoutesEmailThroughAccount;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        #[\SensitiveParameter] public readonly array $payload,
        #[\SensitiveParameter] public readonly string $databaseNotificationId,
        #[\SensitiveParameter] ?array $frozenMailSnapshot = null,
        private readonly bool $frozenWebPushPreview = false,
    ) {
        if ($frozenMailSnapshot === null) {
            $this->freezeEmailAccountMailSnapshot(
                filled($this->payload['ticket_id'] ?? null) ? 'tickets' : 'system',
            );
        } else {
            $this->hydrateFrozenMailSnapshot($frozenMailSnapshot);
        }
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
            $channels[] = $this->emailAccountMailChannel(
                filled($this->payload['ticket_id'] ?? null) ? 'tickets' : 'system',
            );
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
        return (new WebPushMessage)
            ->title((string) ($this->payload['push_title'] ?? 'Nexum notification'))
            ->body($this->pushBody())
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

    private function pushBody(): string
    {
        if (! $this->frozenWebPushPreview) {
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

    /** @param array<string, mixed> $snapshot */
    private function hydrateFrozenMailSnapshot(#[\SensitiveParameter] array $snapshot): void
    {
        $scope = is_string($snapshot['scope'] ?? null) ? $snapshot['scope'] : null;
        $accountId = filter_var(
            $snapshot['account_id'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $bindingVersion = filter_var(
            $snapshot['provider_binding_version'] ?? null,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        $failureCode = is_string($snapshot['failure_code'] ?? null)
            ? $snapshot['failure_code']
            : null;

        $this->emailAccountMailSnapshotCaptured = true;
        if (! array_key_exists((string) $scope, EmailAccount::DEFAULT_SCOPES)
            || (($accountId === false || $bindingVersion === false) && $failureCode === null)) {
            $this->emailAccountMailSnapshotFailureCode = 'provider_binding_snapshot_missing';

            return;
        }

        $this->emailAccountMailScope = $scope;
        $this->emailAccountMailAccountId = $accountId === false ? null : (int) $accountId;
        $this->emailAccountMailProviderBindingVersion = $bindingVersion === false
            ? null
            : (int) $bindingVersion;
        $this->emailAccountMailSnapshotFailureCode = in_array($failureCode, [
            null,
            'provider_binding_snapshot_missing',
            'provider_binding_snapshot_unavailable',
        ], true) ? $failureCode : 'provider_binding_snapshot_missing';
    }
}
