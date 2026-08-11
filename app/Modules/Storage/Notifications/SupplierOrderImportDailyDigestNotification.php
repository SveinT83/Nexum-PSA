<?php

namespace App\Modules\Storage\Notifications;

use App\Modules\Notification\Channels\NextcloudTalkChannel;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\NotificationSetting;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class SupplierOrderImportDailyDigestNotification extends Notification
{
    use Queueable;

    /**
     * @param  array<string, int>  $statusCounts
     * @param  array<string, int>  $reasonCounts
     */
    public function __construct(
        public readonly int $alertId,
        public readonly string $period,
        public readonly int $total,
        public readonly array $statusCounts,
        public readonly array $reasonCounts,
    ) {}

    /** @return list<class-string|string> */
    public function via(object $notifiable): array
    {
        $setting = NotificationSetting::getForUser($notifiable, 'storage_purchase_import_digest');
        $channels = [];
        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = 'mail';
        }
        if ($setting->web_push_enabled) {
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
        $message = (new MailMessage)
            ->subject('[Nexum] Supplier-order import digest '.$this->period)
            ->greeting('Hello '.$notifiable->name.'.')
            ->line($this->total.' supplier-order import(s) were recorded for '.$this->period.'.');
        foreach ($this->statusCounts as $status => $count) {
            $message->line(ucfirst(str_replace('_', ' ', $status)).': '.$count);
        }

        return $message->action('Open Supplier Order Imports', $this->url());
    }

    /** @return array<string, mixed> */
    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'storage_purchase_import_digest',
            'alert_id' => $this->alertId,
            'period' => $this->period,
            'total' => $this->total,
            'status_counts' => $this->statusCounts,
            'reason_counts' => $this->reasonCounts,
            'url' => $this->url(),
        ];
    }

    public function toWebPush(mixed $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Supplier-order import digest')
            ->body($this->total.' import(s) were recorded for '.$this->period.'.')
            ->icon('/logo.png')
            ->badge('/logo.png')
            ->tag('supplier-order-import-digest-'.$this->period)
            ->data(['url' => $this->url(), 'kind' => 'storage_purchase_import_digest'])
            ->options(['TTL' => 21600, 'urgency' => 'low']);
    }

    /** @return array<string, mixed> */
    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => 'Supplier-order import digest '.$this->period,
            'message' => $this->total.' import(s) were recorded.',
            'details' => collect($this->statusCounts)
                ->mapWithKeys(fn (int $count, string $status): array => [
                    ucfirst(str_replace('_', ' ', $status)) => $count,
                ])->all(),
            'url' => $this->url(),
            'urlLabel' => 'Open imports',
            'referenceId' => 'supplier-order-import-digest-'.$this->period,
            'silent' => true,
        ];
    }

    private function url(): string
    {
        return route('tech.storage.purchase-order-imports.index');
    }
}
