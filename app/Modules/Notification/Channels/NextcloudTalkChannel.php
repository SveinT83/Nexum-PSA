<?php

namespace App\Modules\Notification\Channels;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;
use App\Modules\Notification\Models\NotificationChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

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
    public function send(
        #[\SensitiveParameter] object $notifiable,
        #[\SensitiveParameter] Notification $notification,
    ): array {
        return $this->sendUsingAuthority($notifiable, $notification, false, null);
    }

    /**
     * Send from a recipient decision that already selected one exact
     * notification type/setting. This path must never inspect another type's
     * per-user Talk URL.
     */
    public function sendExact(
        #[\SensitiveParameter] object $notifiable,
        #[\SensitiveParameter] Notification $notification,
        #[\SensitiveParameter] ?string $exactWebhookUrl,
    ): array {
        return $this->sendUsingAuthority($notifiable, $notification, true, $exactWebhookUrl);
    }

    private function sendUsingAuthority(
        #[\SensitiveParameter] object $notifiable,
        #[\SensitiveParameter] Notification $notification,
        bool $exactAuthority,
        #[\SensitiveParameter] ?string $exactWebhookUrl,
    ): array {
        // Check if the channel is enabled system-wide
        $channelConfig = NotificationChannel::getByDriver('nextcloud_talk');

        if (! $channelConfig || ! $channelConfig->is_enabled) {
            return $this->suppressed('nextcloud_talk_channel_disabled');
        }

        $connection = $this->configuredNextcloudConnection($channelConfig);
        if (! $connection) {
            Log::debug('NextcloudTalk: Channel is enabled, but no active Nextcloud integration exists.');

            return $this->suppressed('nextcloud_talk_connection_missing');
        }

        // Get the message data from the notification
        if (! method_exists($notification, 'toNextcloudTalk')) {
            return $this->suppressed('nextcloud_talk_notification_unsupported');
        }

        $data = $notification->toNextcloudTalk($notifiable);

        // Prefer Bot API if configured
        if ($connection->hasTalkBot()) {
            return $this->sendViaBotApi(
                $connection,
                $notifiable,
                $data,
                $channelConfig,
                $exactAuthority,
                $exactWebhookUrl,
            );
        }

        // Fall back to webhook
        return $this->sendViaWebhook(
            $notifiable,
            $data,
            $channelConfig,
            $exactAuthority,
            $exactWebhookUrl,
        );
    }

    /**
     * Send a notification via the Talk Bot API.
     *
     * Uses the TalkClient to send HMAC-signed messages. The conversation
     * token is resolved from: per-user override > system default > connection default.
     */
    protected function sendViaBotApi(
        NextcloudConnection $connection,
        #[\SensitiveParameter] object $notifiable,
        #[\SensitiveParameter] array $data,
        NotificationChannel $channelConfig,
        bool $exactAuthority = false,
        #[\SensitiveParameter] ?string $exactWebhookUrl = null,
    ): array {
        $talkClient = app(NextcloudTalkClient::class);
        $conversationToken = $exactAuthority
            ? $this->resolveExactConversationToken($exactWebhookUrl, $channelConfig, $connection)
            : $this->resolveConversationToken($notifiable, $channelConfig, $connection);

        if (empty($conversationToken)) {
            Log::warning('NextcloudTalk: Bot API configured but no conversation token found.', [
                'notifiable_id' => $notifiable->id ?? null,
            ]);

            return $this->suppressed('nextcloud_talk_conversation_missing');
        }

        // Build the message with optional title
        $message = $this->formatMessage($data);

        $options = [];
        if (isset($data['referenceId'])) {
            $options['referenceId'] = $data['referenceId'];
        }
        if (! empty($data['silent'])) {
            $options['silent'] = true;
        }
        if (isset($data['replyTo'])) {
            $options['replyTo'] = $data['replyTo'];
        }

        try {
            $talkClient->sendBotMessage($connection, $conversationToken, $message, $options);
            Log::debug('NextcloudTalk: Bot API message sent.', [
                'connection_id' => $connection->id,
                'notifiable_id' => $notifiable->id ?? null,
            ]);
        } catch (Throwable $exception) {
            Log::error('NextcloudTalk: Bot API delivery failed.', [
                'exception' => $exception::class,
                'connection_id' => $connection->id,
                'notifiable_id' => $notifiable->id ?? null,
            ]);

            return $this->unresolved('nextcloud_talk_bot_delivery_unresolved');
        }

        return $this->delivered();
    }

    /**
     * Send a notification via the legacy webhook approach.
     */
    protected function sendViaWebhook(
        #[\SensitiveParameter] object $notifiable,
        #[\SensitiveParameter] array $data,
        NotificationChannel $channelConfig,
        bool $exactAuthority = false,
        #[\SensitiveParameter] ?string $exactWebhookUrl = null,
    ): array {
        $webhookUrl = $exactAuthority
            ? $this->getExactWebhookUrl($exactWebhookUrl, $channelConfig)
            : $this->getWebhookUrl($notifiable, $channelConfig);

        if (empty($webhookUrl)) {
            Log::debug('NextcloudTalk: No webhook URL configured for notifiable.', [
                'notifiable_id' => $notifiable->id ?? null,
            ]);

            return $this->suppressed('nextcloud_talk_webhook_missing');
        }

        // Build the payload — webhooks only support plain message
        $message = $data['message'] ?? '';
        if (isset($data['title'])) {
            $message = "**{$data['title']}**\n\n".$message;
        }

        $payload = ['message' => $message];

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($webhookUrl, $payload);

            if (! $response->successful()) {
                Log::warning('NextcloudTalk: Webhook delivery failed.', [
                    'status' => $response->status(),
                ]);

                return $this->unresolved('nextcloud_talk_webhook_delivery_unresolved');
            }
        } catch (Throwable $exception) {
            Log::error('NextcloudTalk: Webhook delivery exception.', [
                'exception' => $exception::class,
            ]);

            return $this->unresolved('nextcloud_talk_webhook_delivery_unresolved');
        }

        return $this->delivered();
    }

    /**
     * Format the notification data into a message string.
     *
     * When using the Bot API, this supports rich Markdown formatting.
     * When using webhooks, this returns a simple text message.
     */
    protected function formatMessage(#[\SensitiveParameter] array $data): string
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

    private function resolveExactConversationToken(
        #[\SensitiveParameter] ?string $exactWebhookUrl,
        NotificationChannel $channelConfig,
        NextcloudConnection $connection,
    ): ?string {
        if ($exactWebhookUrl !== null) {
            // An explicit per-type target is authoritative. Invalid input is
            // suppression, never permission to fall through to another room.
            $scheme = parse_url($exactWebhookUrl, PHP_URL_SCHEME);
            if (filter_var($exactWebhookUrl, FILTER_VALIDATE_URL) === false
                || ! is_string($scheme)
                || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
                return null;
            }

            return $this->extractTokenFromWebhookUrl($exactWebhookUrl);
        }

        return $channelConfig->config['default_conversation_token']
            ?? $connection->talk_default_conversation_token;
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
        if (preg_match('#/room/([a-zA-Z0-9_-]+)/webhook#', $url, $matches)) {
            return $matches[1];
        }

        // If it looks like just a token (no URL structure), return it directly
        if (preg_match('/^[a-zA-Z0-9_-]{6,64}$/', $url)) {
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

    private function getExactWebhookUrl(
        #[\SensitiveParameter] ?string $exactWebhookUrl,
        NotificationChannel $channelConfig,
    ): ?string {
        if ($exactWebhookUrl !== null) {
            return filter_var($exactWebhookUrl, FILTER_VALIDATE_URL) !== false
                ? $exactWebhookUrl
                : null;
        }

        return $channelConfig->config['default_webhook_url'] ?? null;
    }

    /**
     * Check if an active Nextcloud connection exists.
     */
    private function configuredNextcloudConnection(
        NotificationChannel $channelConfig,
    ): ?NextcloudConnection {
        $connectionId = $channelConfig->config['nextcloud_connection_id'] ?? null;

        if ($connectionId) {
            return NextcloudConnection::query()
                ->where('is_active', true)
                ->whereKey($connectionId)
                ->first();
        }

        return NextcloudConnection::query()
            ->where('is_active', true)
            ->orderByRaw("case when scope = 'global' and is_default = 1 then 1 else 0 end desc")
            ->orderByDesc('is_default')
            ->orderByRaw("case when scope = 'global' then 1 else 0 end desc")
            ->orderBy('name')
            ->first();
    }

    /** @return array{status:'delivered',reason_code:string} */
    private function delivered(): array
    {
        return [
            'status' => 'delivered',
            'reason_code' => 'nextcloud_talk_delivery_confirmed',
        ];
    }

    /** @return array{status:'suppressed',reason_code:string} */
    private function suppressed(string $reasonCode): array
    {
        return ['status' => 'suppressed', 'reason_code' => $reasonCode];
    }

    /** @return array{status:'unresolved',reason_code:string} */
    private function unresolved(string $reasonCode): array
    {
        return ['status' => 'unresolved', 'reason_code' => $reasonCode];
    }
}
