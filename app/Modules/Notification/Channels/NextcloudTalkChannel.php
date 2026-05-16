<?php

namespace App\Modules\Notification\Channels;

use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications to Nextcloud Talk via webhook.
 *
 * Nextcloud Talk supports incoming webhooks that post messages to
 * a specified conversation. Each user can also have a personal
 * webhook URL for direct notifications.
 *
 * Setup:
 * 1. In Nextcloud Talk, create a webhook for the target conversation
 * 2. Store the webhook URL in notification_channels config (system-wide)
 *    or in the user's notification_settings (per-user override)
 * 3. Enable the nextcloud_talk channel in admin settings
 */
class NextcloudTalkChannel
{
    /**
     * Send the given notification via Nextcloud Talk webhook.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // Check if the channel is enabled system-wide
        $channelConfig = NotificationChannel::getByDriver('nextcloud_talk');

        if (!$channelConfig || !$channelConfig->is_enabled) {
            return;
        }

        // Get the message data from the notification
        if (!method_exists($notification, 'toNextcloudTalk')) {
            return;
        }

        $data = $notification->toNextcloudTalk($notifiable);

        // Determine webhook URL: per-user override > system default
        $webhookUrl = $this->getWebhookUrl($notifiable, $channelConfig);

        if (empty($webhookUrl)) {
            Log::debug('NextcloudTalk: No webhook URL configured for notifiable', [
                'notifiable_id' => $notifiable->id ?? null,
            ]);
            return;
        }

        // Build the payload
        $payload = [
            'message' => $data['message'] ?? '',
        ];

        if (isset($data['title'])) {
            // Prepend title as bold heading
            $payload['message'] = "**{$data['title']}**\n\n" . $payload['message'];
        }

        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                ])
                ->post($webhookUrl, $payload);

            if (!$response->successful()) {
                Log::warning('NextcloudTalk: Webhook delivery failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => substr($webhookUrl, 0, 50) . '...',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NextcloudTalk: Webhook delivery exception', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Resolve the webhook URL for the notifiable entity.
     *
     * Priority:
     * 1. User's per-type notification_setting (nextcloud_talk_webhook_url)
     * 2. System-wide default from notification_channels config
     */
    protected function getWebhookUrl(object $notifiable, NotificationChannel $channelConfig): ?string
    {
        // Check if the notifiable has a per-user webhook URL
        if ($notifiable instanceof \App\Models\Core\User) {
            $setting = \App\Modules\Notification\Models\NotificationSetting::where('user_id', $notifiable->id)
                ->where('nextcloud_talk_enabled', true)
                ->whereNotNull('nextcloud_talk_webhook_url')
                ->first();

            if ($setting) {
                return $setting->nextcloud_talk_webhook_url;
            }
        }

        // Fall back to system-wide default
        return $channelConfig->config['default_webhook_url'] ?? null;
    }
}