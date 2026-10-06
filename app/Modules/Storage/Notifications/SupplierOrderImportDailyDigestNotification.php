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

class SupplierOrderImportDailyDigestNotification extends Notification implements EmailAccountMailNotification, QueuesInternalWebPush
{
    use Queueable, RoutesEmailThroughAccount;

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
    ) {
        $this->freezeEmailAccountMailSnapshot('alerts');
    }

    /** @return list<class-string|string> */
    public function via(object $notifiable): array
    {
        $setting = NotificationSetting::getForUser($notifiable, 'storage_purchase_import_digest');
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

    public function internalWebPushType(): string
    {
        return 'storage_purchase_import_digest';
    }

    public function internalWebPushPayload(): array
    {
        return [
            'title' => 'Supplier-order import digest',
            'body' => 'Open Nexum to review the supplier-order import summary.',
            'target_id' => $this->alertId,
            'ttl' => 21600,
            'urgency' => 'low',
        ];
    }
}
