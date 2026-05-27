<?php

namespace App\Modules\Notification\Channels;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;
use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Sends notifications to Nextcloud Talk conversations.
 *
 * Supports two delivery modes:
 *
 * 1. **Bot API** (preferred, NC 27.1+ / Talk 17.1+): Sends HMAC-SHA256 signed
 *    messages via the Talk Bot API endpoint. Requires a bot installed on the
 *    Nextcloud server (`./occ talk:bot:install`) and the bot ID + secret stored
 *    in the NextcloudConnection. Supports rich Markdown formatting, reference IDs,
 *    silent messages, and future command processing.
 *
 * 2. **Webhook** (fallback): Sends simple `{ "message": "..." }` POSTs to a
 *    Talk webhook URL. Works with any Talk version but only supports plain text
 *    messages without signing or deduplication.
 *
 * Delivery mode is determined automatically:
 * - If the active NextcloudConnection has `talk_bot_id` and `talk_bot_secret`
 *   configured, Bot API is used.
 * - Otherwise, if a webhook URL is configured (system-wide or per-user),
 *   webhook delivery is used.
 *
 * Setup instructions:
 * - Bot API: See NextcloudConnection admin settings and the Talk bot setup guide.
 * - Webhook: Create a webhook in Talk conversation settings and paste the URL.
 */
class NextcloudTalkChannel
{
    /**
     * Send the given notification via Nextcloud Talk.
     */
    public function send(object $notifiable, Notification $notification): void
    {
        // Check if the channel is enabled system-wide
        $channelConfig = NotificationChannel::getByDriver('nextcloud_talk');

        if (!$channelConfig || !$channelConfig->is_enabled) {
            return;
        }

        if (! $this->hasConfiguredNextcloudConnection($channelConfig)) {
            Log::debug('NextcloudTalk: Channel is enabled, but no active Nextcloud integration exists.');
            return;
        }

        // Get the message data from the notification
        if (!method_exists($notification, 'toNextcloudTalk')) {
            return;
        }

        $data = $notification->toNextcloudTalk($notifiable);

        $connection = $this->activeNextcloudConnection();

        // Prefer Bot API if configured
        if ($connection && $connection->hasTalkBot()) {
            $this->sendViaBotApi($connection, $notifiable, $data, $channelConfig);
            return;
        }

        // Fall back to webhook
        $this->sendViaWebhook($notifiable, $data, $channelConfig);
    }

    /**
     * Send a notification via the Talk Bot API.
     *
     * Uses the TalkClient to send HMAC-signed messages. The conversation
     * token is resolved from: per-user override > system default > connection default.
     */
    protected function sendViaBotApi(
        NextcloudConnection $connection,
        object $notifiable,
        array $data,
        NotificationChannel $channelConfig,
    ): void {
        $talkClient = app(NextcloudTalkClient::class);
        $conversationToken = $this->resolveConversationToken($notifiable, $channelConfig, $connection);

        if (empty($conversationToken)) {
            Log::warning('NextcloudTalk: Bot API configured but no conversation token found.', [
                'notifiable_id' => $notifiable->id ?? null,
            ]);
            return;
        }

        // Build the message with optional title
        $message = $this->formatMessage($data);

        $options = [];
        if (isset($data['referenceId'])) {
            $options['referenceId'] = $data['referenceId'];
        }
        if (!empty($data['silent'])) {
            $options['silent'] = true;
        }
        if (isset($data['replyTo'])) {
            $options['replyTo'] = $data['replyTo'];
        }

        try {
            $talkClient->sendBotMessage($connection, $conversationToken, $message, $options);
            Log::debug('NextcloudTalk: Bot API message sent.', [
                'conversationToken' => $conversationToken,
                'notifiable_id' => $notifiable->id ?? null,
            ]);
        } catch (\Exception $e) {
            Log::error('NextcloudTalk: Bot API delivery failed.', [
                'error' => $e->getMessage(),
                'conversationToken' => $conversationToken,
                'notifiable_id' => $notifiable->id ?? null,
            ]);
        }
    }

    /**
     * Send a notification via the legacy webhook approach.
     */
    protected function sendViaWebhook(object $notifiable, array $data, NotificationChannel $channelConfig): void
    {
        $webhookUrl = $this->getWebhookUrl($notifiable, $channelConfig);

        if (empty($webhookUrl)) {
            Log::debug('NextcloudTalk: No webhook URL configured for notifiable.', [
                'notifiable_id' => $notifiable->id ?? null,
            ]);
            return;
        }

        // Build the payload — webhooks only support plain message
        $message = $data['message'] ?? '';
        if (isset($data['title'])) {
            $message = "**{$data['title']}**\n\n" . $message;
        }

        $payload = ['message' => $message];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($webhookUrl, $payload);

            if (!$response->successful()) {
                Log::warning('NextcloudTalk: Webhook delivery failed.', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'url' => substr($webhookUrl, 0, 50) . '...',
                ]);
            }
        } catch (\Exception $e) {
            Log::error('NextcloudTalk: Webhook delivery exception.', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Format the notification data into a message string.
     *
     * When using the Bot API, this supports rich Markdown formatting.
     * When using webhooks, this returns a simple text message.
     */
    protected function formatMessage(array $data): string
    {
        $parts = [];

        if (isset($data['title'])) {
            $parts[] = "**{$data['title']}**";
            $parts[] = '';
        }

        if (isset($data['message'])) {
            $parts[] = $data['message'];
        }

        // Append optional fields for richer Bot API messages
        if (isset($data['details'])) {
            $parts[] = '';
            foreach ($data['details'] as $label => $value) {
                $parts[] = "- **{$label}:** {$value}";
            }
        }

        if (isset($data['url'])) {
            $parts[] = '';
            $linkText = $data['urlLabel'] ?? 'View';
            $parts[] = "[→ {$linkText}]({$data['url']})";
        }

        return implode("\n", $parts);
    }

    /**
     * Resolve the conversation token for notification delivery.
     *
     * Priority:
     * 1. Per-user override from notification_settings
     * 2. System-wide default from notification_channels config
     * 3. Connection's talk_default_conversation_token
     */
    protected function resolveConversationToken(
        object $notifiable,
        NotificationChannel $channelConfig,
        NextcloudConnection $connection,
    ): ?string {
        // Per-user override
        if ($notifiable instanceof \App\Models\Core\User) {
            $setting = \App\Modules\Notification\Models\NotificationSetting::where('user_id', $notifiable->id)
                ->where('nextcloud_talk_enabled', true)
                ->whereNotNull('nextcloud_talk_webhook_url')
                ->first();

            if ($setting && $setting->nextcloud_talk_webhook_url) {
                // Extract conversation token from a webhook URL if set
                // Webhook URLs often contain the token, e.g., .../apps/spreed/api/v1/room/{token}/webhook
                $token = $this->extractTokenFromWebhookUrl($setting->nextcloud_talk_webhook_url);
                if ($token) {
                    return $token;
                }
            }
        }

        // System-wide default conversation token
        $configToken = $channelConfig->config['default_conversation_token'] ?? null;
        if ($configToken) {
            return $configToken;
        }

        // Connection default
        return $connection->talk_default_conversation_token;
    }

    /**
     * Try to extract a Talk conversation token from a webhook URL.
     *
     * Talk webhook URLs have the format:
     *   https://nextcloud.example.com/apps/spreed/api/v1/room/{token}/webhook
     * or custom formats set by administrators.
     */
    protected function extractTokenFromWebhookUrl(string $url): ?string
    {
        // Try to match the Talk webhook URL pattern
        if (preg_match('#/room/([a-zA-Z0-9]+)/webhook#', $url, $matches)) {
            return $matches[1];
        }

        // If it looks like just a token (no URL structure), return it directly
        if (preg_match('/^[a-zA-Z0-9]{6,20}$/', $url)) {
            return $url;
        }

        return null;
    }

    /**
     * Resolve the webhook URL for the notifiable entity (legacy mode).
     *
     * Priority:
     * 1. User's per-type notification_setting (nextcloud_talk_webhook_url)
     * 2. System-wide default from notification_channels config
     */
    protected function getWebhookUrl(object $notifiable, NotificationChannel $channelConfig): ?string
    {
        if ($notifiable instanceof \App\Models\Core\User) {
            $setting = \App\Modules\Notification\Models\NotificationSetting::where('user_id', $notifiable->id)
                ->where('nextcloud_talk_enabled', true)
                ->whereNotNull('nextcloud_talk_webhook_url')
                ->first();

            if ($setting) {
                return $setting->nextcloud_talk_webhook_url;
            }
        }

        return $channelConfig->config['default_webhook_url'] ?? null;
    }

    /**
     * Check if an active Nextcloud connection exists.
     */
    private function hasConfiguredNextcloudConnection(NotificationChannel $channelConfig): bool
    {
        $connectionId = $channelConfig->config['nextcloud_connection_id'] ?? null;

        if ($connectionId) {
            return NextcloudConnection::query()
                ->where('is_active', true)
                ->whereKey($connectionId)
                ->exists();
        }

        return NextcloudConnection::query()
            ->where('is_active', true)
            ->orderByRaw("case when scope = 'global' and is_default = 1 then 1 else 0 end desc")
            ->orderByDesc('is_default')
            ->orderByRaw("case when scope = 'global' then 1 else 0 end desc")
            ->exists();
    }

    /**
     * Get the active Nextcloud connection for Talk delivery.
     */
    private function activeNextcloudConnection(): ?NextcloudConnection
    {
        return NextcloudConnection::query()
            ->where('is_active', true)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->first();
    }
}