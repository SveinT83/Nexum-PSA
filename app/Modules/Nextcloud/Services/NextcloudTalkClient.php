<?php

namespace App\Modules\Nextcloud\Services;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Client for the Nextcloud Talk Bot API and Chat API.
 *
 * Provides methods for:
 * - Sending signed messages via the Bot API (POST /ocs/v2.php/apps/spreed/api/v1/bot/{token}/message)
 * - Listing and managing conversations via the OCS API
 * - Verifying incoming webhook signatures from Talk bots
 *
 * The Bot API requires NC 27.1+ / Talk 17.1+ with the bots-v1 capability.
 * Bots must be installed via `./occ talk:bot:install` on the Nextcloud server.
 *
 * @see https://nextcloud-talk.readthedocs.io/en/latest/bots/
 */
class NextcloudTalkClient
{
    /**
     * Send a message to a Talk conversation via the Bot API.
     *
     * The request is signed using HMAC-SHA256 with the bot's shared secret,
     * as required by the Talk Bot API verification protocol.
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection to use
     * @param  string  $conversationToken  The Talk conversation token (e.g., "n3xtc10ud")
     * @param  string  $message  The message text (supports Markdown if the bot has the feature)
     * @param  array  $options  Optional: referenceId, silent, replyTo
     * @return array Response data from the Talk API
     *
     * @throws RuntimeException If the request fails
     */
    public function sendBotMessage(NextcloudConnection $connection, string $conversationToken, string $message, array $options = []): array
    {
        $secret = $connection->getTalkBotSecret()
            ?? throw new RuntimeException('Talk bot secret is not configured for this connection.');

        // botId is kept for future reference but not sent in the API request;
        // the bot is identified by the signed secret + conversation token.
        $botId = $connection->talk_bot_id;
        if (empty($botId)) {
            throw new RuntimeException('Talk bot ID is not configured for this connection.');
        }

        $baseUrl = rtrim($connection->base_url, '/');
        $endpoint = "/ocs/v2.php/apps/spreed/api/v1/bot/{$conversationToken}/message";

        // Build the request body
        $body = ['message' => $message];

        if (isset($options['referenceId'])) {
            $body['referenceId'] = $options['referenceId'];
        }

        if (! empty($options['silent'])) {
            $body['silent'] = true;
        }

        if (isset($options['replyTo'])) {
            $body['replyTo'] = $options['replyTo'];
        }

        // Generate HMAC-SHA256 signature.
        // Talk Bot API signs over random + plain message text (not the JSON body).
        // @see https://nextcloud-talk.readthedocs.io/en/latest/bots/#sending-a-chat-message
        $random = $this->generateRandomString(64);
        $signature = hash_hmac('sha256', $random.$message, $secret);

        $response = Http::withHeaders([
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'OCS-APIRequest' => 'true',
            'X-Nextcloud-Talk-Bot-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Bot-Random' => $random,
        ])
            ->timeout(15)
            ->post("{$baseUrl}{$endpoint}", $body);

        $this->throwUnlessSuccessful($response, 'send bot message');

        return $response->json('ocs.data') ?? [];
    }

    /**
     * Send a message to a Talk conversation via the OCS Chat API (user context).
     *
     * This uses the standard chat API with user authentication instead of bot signing.
     * Useful for sending messages as a real Nextcloud user rather than a bot.
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection to use
     * @param  string  $conversationToken  The Talk conversation token
     * @param  string  $message  The message text
     * @param  array  $options  Optional: referenceId, replyTo, silent, object
     * @return array Response data from the Talk API
     */
    public function sendChatMessage(NextcloudConnection $connection, string $conversationToken, string $message, array $options = []): array
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $endpoint = "/ocs/v2.php/apps/spreed/api/v1/chat/{$conversationToken}";

        $body = ['message' => $message];

        if (isset($options['referenceId'])) {
            $body['referenceId'] = $options['referenceId'];
        }

        if (isset($options['replyTo'])) {
            $body['replyTo'] = $options['replyTo'];
        }

        if (! empty($options['silent'])) {
            $body['silent'] = true;
        }

        $response = $this->ocs($connection)
            ->acceptJson()
            ->post("{$baseUrl}{$endpoint}", $body);

        $this->throwUnlessSuccessful($response, 'send chat message');

        return $response->json('ocs.data') ?? [];
    }

    /**
     * List available Talk conversations for the service user.
     *
     * Returns conversation tokens and names that can be used as targets
     * for bot messages and notification routing.
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection to use
     * @return array List of conversations with token, name, type, and displayName
     */
    public function listConversations(NextcloudConnection $connection): array
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $endpoint = '/ocs/v2.php/apps/spreed/api/v4/room';

        $response = $this->ocs($connection)
            ->acceptJson()
            ->get("{$baseUrl}{$endpoint}", ['noStatusUpdate' => 1]);

        $this->throwUnlessSuccessful($response, 'list conversations');

        $conversations = $response->json('ocs.data');
        if (! is_array($conversations) || ! array_is_list($conversations) || count($conversations) > 2000) {
            throw new RuntimeException('Talk conversation discovery returned an unsupported response.');
        }
        return collect($conversations)->map(function ($room) {
            if (! is_array($room) || ! is_string($room['token'] ?? null)
                || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $room['token'])) {
                throw new RuntimeException('Talk conversation discovery returned an invalid identifier.');
            }
            $type = (int) ($room['type'] ?? 0);
            return [
                'token' => $room['token'],
                'name' => mb_substr(is_string($room['name'] ?? null) ? $room['name'] : '', 0, 255),
                'displayName' => mb_substr(is_string($room['displayName'] ?? null)
                    ? $room['displayName'] : (is_string($room['name'] ?? null) ? $room['name'] : ''), 0, 512),
                'type' => $type, 'typeLabel' => $this->roomTypeLabel($type),
                'participantType' => (int) ($room['participantType'] ?? 0),
                'readOnly' => (int) ($room['readOnly'] ?? 0),
                'permissions' => isset($room['permissions']) ? (int) $room['permissions'] : null,
                'hasPassword' => (bool) ($room['hasPassword'] ?? false),
                'lastActivity' => max(0, (int) ($room['lastActivity'] ?? 0)),
            ];
        })->values()->all();
    }

    /**
     * Get details for a specific Talk conversation.
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection to use
     * @param  string  $conversationToken  The conversation token
     * @return array Conversation details
     */
    public function getConversation(NextcloudConnection $connection, string $conversationToken): array
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $endpoint = "/ocs/v2.php/apps/spreed/api/v4/room/{$conversationToken}";

        $response = $this->ocs($connection)
            ->acceptJson()
            ->get("{$baseUrl}{$endpoint}", ['noStatusUpdate' => 1]);

        $this->throwUnlessSuccessful($response, 'get conversation');

        return $response->json('ocs.data') ?? [];
    }

    /**
     * Create a new Talk conversation.
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection to use
     * @param  string  $roomName  The name for the new conversation
     * @param  int  $roomType  1=one-to-one, 2=private group, 3=public, 4=changelog
     * @param  array  $options  Optional: password, object, invite users/groups
     * @return array Created conversation data
     */
    public function createConversation(NextcloudConnection $connection, string $roomName, int $roomType = 2, array $options = []): array
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $endpoint = '/ocs/v2.php/apps/spreed/api/v4/room';

        $body = [
            'roomType' => $roomType,
            'roomName' => $roomName,
        ];

        if (isset($options['password'])) {
            $body['password'] = $options['password'];
        }

        if (isset($options['object'])) {
            $body['object'] = $options['object'];
        }

        $response = $this->ocs($connection)
            ->acceptJson()
            ->post("{$baseUrl}{$endpoint}", $body);

        $this->throwUnlessSuccessful($response, 'create conversation');

        return $response->json('ocs.data') ?? [];
    }

    /**
     * Independently read one exact receipt without marking chat, notifications or presence read.
     * Return metadata only; provider message text never enters a delivery receipt.
     */
    public function readMessageReceipt(NextcloudConnection $connection, string $token, int $messageId): ?array
    {
        if (! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $token) || $messageId < 1) {
            throw new RuntimeException('Invalid Talk message receipt identity.');
        }
        $url = rtrim($connection->base_url, '/').'/ocs/v2.php/apps/spreed/api/v1/chat/'.$token;
        $response = $this->ocs($connection)->acceptJson()->get($url, [
            'lookIntoFuture' => 0, 'limit' => 1, 'lastKnownMessageId' => $messageId,
            'includeLastKnown' => 1, 'setReadMarker' => 0, 'noStatusUpdate' => 1,
            'markNotificationsAsRead' => 0,
        ]);
        $this->throwUnlessSuccessful($response, 'read message receipt');
        $rows = $response->json('ocs.data');
        if (! is_array($rows) || ! array_is_list($rows)) {
            throw new RuntimeException('Talk message read-back returned an unsupported response.');
        }
        foreach ($rows as $row) {
            if (is_array($row) && (string) ($row['id'] ?? '') === (string) $messageId
                && ($row['token'] ?? null) === $token) {
                return ['message_id' => $messageId, 'conversation_token' => $token,
                    'reference_id' => is_string($row['referenceId'] ?? null) ? $row['referenceId'] : null];
            }
        }
        return null;
    }

    /**
     * Verify an incoming webhook signature from Nextcloud Talk.
     *
     * IMPORTANT: Inbound webhooks from Nextcloud use headers WITHOUT the
     * "Bot-" prefix (X-Nextcloud-Talk-Random, X-Nextcloud-Talk-Signature),
     * while outbound bot messages TO Nextcloud use the "Bot-" prefix
     * (X-Nextcloud-Talk-Bot-Random, X-Nextcloud-Talk-Bot-Signature).
     *
     * The signature covers random + raw body (the full JSON payload).
     * This differs from outbound where the signature covers random + message text only.
     *
     * @param  string  $secret  The bot's shared secret
     * @param  string  $randomHeader  The X-Nextcloud-Talk-Random header value
     * @param  string  $signatureHeader  The X-Nextcloud-Talk-Signature header value
     * @param  string  $body  The raw request body
     */
    public function verifyIncomingSignature(string $secret, string $randomHeader, string $signatureHeader, string $body): bool
    {
        $expectedSignature = hash_hmac('sha256', $randomHeader.$body, $secret);

        return hash_equals(strtolower($expectedSignature), strtolower($signatureHeader));
    }

    /**
     * Check if the Talk server supports the bots-v1 capability.
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection to check
     * @return bool True if bots-v1 is supported
     */
    public function supportsBots(NextcloudConnection $connection): bool
    {
        $capabilities = $connection->capabilities ?? [];

        $spreedCaps = $capabilities['spreed'] ?? [];

        // bots-v1 capability is nested under spreed capabilities
        if (isset($spreedCaps['bots-v1']) && $spreedCaps['bots-v1']) {
            return true;
        }

        // Also check top-level (some capability formats)
        if (isset($capabilities['bots-v1'])) {
            return true;
        }

        return false;
    }

    /**
     * Parse an incoming Talk bot webhook payload.
     *
     * Incoming messages from Talk bots follow the Activity Streams 2.0 format.
     * This method extracts the key fields into a convenient array.
     *
     * @param  array  $payload  The parsed JSON payload from Talk
     * @return array Normalized message data
     */
    public function parseIncomingMessage(array $payload): array
    {
        $type = $payload['type'] ?? 'unknown';
        $actor = $payload['actor'] ?? [];
        $object = $payload['object'] ?? [];
        $target = $payload['target'] ?? [];

        // Parse the content JSON if it's a string
        $content = $object['content'] ?? null;
        if (is_string($content)) {
            $decoded = json_decode($content, true);
            $content = $decoded ?? ['message' => $content];
        }
        $content = $content ?? [];

        return [
            'type' => $type,
            'actor' => [
                'type' => $actor['type'] ?? '',
                'id' => $actor['id'] ?? '',
                'name' => $actor['name'] ?? '',
            ],
            'message' => [
                'id' => $object['id'] ?? '',
                'name' => $object['name'] ?? '',
                'text' => $content['message'] ?? '',
                'parameters' => $content['parameters'] ?? [],
                'mediaType' => $object['mediaType'] ?? 'text/plain',
            ],
            'conversation' => [
                'token' => $target['id'] ?? '',
                'name' => $target['name'] ?? '',
            ],
            'reaction' => $payload['content'] ?? null,
            'raw' => $payload,
        ];
    }

    /**
     * List bots installed on the Nextcloud server (admin only).
     *
     * @param  NextcloudConnection  $connection  The Nextcloud connection (admin credentials)
     * @return array List of installed bots
     */
    public function listInstalledBots(NextcloudConnection $connection): array
    {
        $baseUrl = rtrim($connection->base_url, '/');
        $endpoint = '/ocs/v2.php/apps/spreed/api/v1/bot/admin';

        $response = $this->ocs($connection)
            ->acceptJson()
            ->get("{$baseUrl}{$endpoint}");

        $this->throwUnlessSuccessful($response, 'list installed bots');

        return $response->json('ocs.data') ?? [];
    }

    // ─── Internal helpers ──────────────────────────────────────────────

    /**
     * Build an OCS-authenticated HTTP client using the connection's service credentials.
     */
    private function ocs(NextcloudConnection $connection)
    {
        if (! $connection->service_username || ! $connection->service_password) {
            throw new RuntimeException('Nextcloud service credentials are not configured.');
        }

        return Http::withBasicAuth($connection->service_username, $connection->service_password)
            ->withHeaders(['OCS-APIRequest' => 'true'])
            ->timeout(20);
    }

    /**
     * Throw if the response is not successful (excluding 207 Multi-Status for DAV).
     */
    private function throwUnlessSuccessful(Response $response, string $operation): void
    {
        $ocs = (int) ($response->json('ocs.meta.statuscode') ?? 0);
        if ($response->successful() && in_array($ocs, [100, 200, 201], true)) {
            return;
        }
        Log::warning('Nextcloud Talk provider request failed', ['operation' => $operation, 'http_status' => $response->status(), 'ocs_status' => $ocs]);
        throw new RuntimeException('Nextcloud Talk request failed with HTTP '.$response->status().' and OCS '.$ocs.'.');
    }

    /**
     * Generate a cryptographically secure random string for Talk bot signatures.
     */
    private function generateRandomString(int $length): string
    {
        $bytes = random_bytes(ceil($length * 3 / 4));

        return substr(base64_encode($bytes), 0, $length);
    }

    /**
     * Map Talk room type integer to a human-readable label.
     */
    private function roomTypeLabel(int $type): string
    {
        return match ($type) {
            1 => 'one-to-one',
            2 => 'group',
            3 => 'public',
            4 => 'changelog',
            5 => 'one-to-one', // former one-to-one
            6 => 'note-to-self',
            default => "unknown({$type})",
        };
    }
}
