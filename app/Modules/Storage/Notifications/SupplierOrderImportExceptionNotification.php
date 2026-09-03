<?php

namespace App\Modules\Storage\Notifications;

use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Channels\QueueInternalWebPushChannel;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Contracts\QueuesInternalWebPush;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SupplierOrderImportExceptionNotification extends Notification implements EmailAccountMailNotification, QueuesInternalWebPush
{
    use Queueable, RoutesEmailThroughAccount;

    /** @param array<string, mixed> $context */
    public function __construct(
        public readonly int $alertId,
        public readonly string $alertType,
        public readonly string $severity,
        public readonly string $title,
        public readonly string $summary,
        public readonly array $context = [],
    ) {
        $this->freezeEmailAccountMailSnapshot('alerts');
    }

    /** @return list<class-string|string> */
    public function via(object $notifiable): array
    {
        $setting = NotificationSetting::getForUser($notifiable, 'storage_purchase_import_exception');
        $channels = [];
        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = $this->emailAccountMailChannel('alerts');
        }
        if ($setting->web_push_enabled) {
            $channels[] = QueueInternalWebPushChannel::class;
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
            ->subject('[Nexum] Supplier-order import exception: '.$this->title)
            ->greeting('Hello '.$notifiable->name.',')
            ->line($this->summary)
            ->line('Severity: '.ucfirst($this->severity))
            ->action('Open Supplier Order Imports', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'storage_purchase_import_exception',
            'alert_id' => $this->alertId,
            'alert_type' => $this->alertType,
            'severity' => $this->severity,
            'title' => $this->title,
            'summary' => $this->summary,
            'context' => $this->context,
            'url' => $this->url(),
        ];
    }

    /** @return array<string, mixed> */
    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => 'Supplier-order import: '.$this->title,
            'message' => $this->summary,
            'details' => ['Severity' => ucfirst($this->severity)],
            'url' => $this->url(),
            'urlLabel' => 'Open imports',
            'referenceId' => 'supplier-order-import-alert-'.$this->alertId,
            'silent' => $this->severity !== 'critical',
        ];
    }

    private function url(): string
    {
        return route('tech.storage.purchase-order-imports.index');
    }

    public function internalWebPushType(): string
    {
        return 'storage_purchase_import_exception';
    }

    public function internalWebPushPayload(): array
    {
        return [
            'title' => 'Supplier-order import needs attention',
            'body' => 'Open Nexum to review the supplier-order import exception.',
            'target_id' => $this->alertId,
            'ttl' => 1800,
            'urgency' => $this->severity === 'critical' ? 'high' : 'normal',
        ];
    }
}
