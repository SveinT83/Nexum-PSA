<?php

namespace App\Modules\Notification\Notifications;

use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Notification\Channels\QueueInternalWebPushChannel;
use App\Modules\Notification\Contracts\EmailAccountMailNotification;
use App\Modules\Notification\Contracts\QueuesInternalWebPush;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\RoutesEmailThroughAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent when an asset alert is triggered (from RMM integration).
 */
class AssetAlertTriggered extends Notification implements EmailAccountMailNotification, QueuesInternalWebPush
{
    use Queueable, RoutesEmailThroughAccount;

    public function __construct(
        public Asset $asset,
        public string $alertTitle,
        public string $alertMessage,
        public string $integrationType = 'tactical_rmm',
    ) {
        $this->freezeEmailAccountMailSnapshot('alerts');
    }

    public function via(object $notifiable): array
    {
        $channels = [];
        $setting = NotificationSetting::getForUser($notifiable, 'asset_alert');

        if ($setting->database_enabled) {
            $channels[] = 'database';
        }
        if ($setting->mail_enabled) {
            $channels[] = $this->emailAccountMailChannel('alerts');
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
        $assetUrl = route('tech.assets.show', $this->asset->id);

        return (new MailMessage)
            ->subject("[Alert] {$this->alertTitle} — {$this->asset->hostname}")
            ->greeting("Hello {$notifiable->name},")
            ->line("An alert has been triggered on asset **{$this->asset->hostname}** ({$this->asset->type}).")
            ->line("**Alert:** {$this->alertTitle}")
            ->line("**Details:** {$this->alertMessage}")
            ->line('**Source:** '.ucfirst(str_replace('_', ' ', $this->integrationType)))
            ->action('View Asset', $assetUrl);
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'asset_alert',
            'asset_id' => $this->asset->id,
            'asset_hostname' => $this->asset->hostname,
            'alert_title' => $this->alertTitle,
            'alert_message' => $this->alertMessage,
            'integration_type' => $this->integrationType,
            'url' => route('tech.assets.show', $this->asset->id),
        ];
    }

    public function toNextcloudTalk(object $notifiable): array
    {
        return [
            'title' => "⚠️ Alert: {$this->alertTitle}",
            'message' => "Asset **{$this->asset->hostname}** — {$this->alertMessage}",
            'details' => array_filter([
                'Type' => $this->asset->type,
                'Source' => ucfirst(str_replace('_', ' ', $this->integrationType)),
            ]),
            'url' => route('tech.assets.show', $this->asset->id),
            'urlLabel' => 'View Asset',
            'referenceId' => 'asset-alert-'.$this->asset->id.'-'.time(),
        ];
    }

    public function internalWebPushType(): string
    {
        return 'asset_alert';
    }

    public function internalWebPushPayload(): array
    {
        return [
            'title' => 'Asset alert',
            'body' => 'Open Nexum to review the asset alert.',
            'target_id' => $this->asset->id,
            'ttl' => 1800,
            'urgency' => 'high',
        ];
    }
}
