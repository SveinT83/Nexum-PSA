<?php

namespace App\Modules\Nextcloud\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Receives incoming Talk bot webhook payloads from Nextcloud.
 *
 * This controller is the inbound half of the two-way Nexum ↔ Talk integration.
 * Nextcloud posts Activity Streams 2.0 payloads here whenever a user sends a
 * message to a bot in a Talk conversation.
 *
 * The route is unauthenticated (no Laravel auth middleware) because Nextcloud
 * The route is unauthenticated (no Laravel auth middleware) because Nextcloud
 * calls it as an external webhook. Request legitimacy is verified via
 * HMAC-SHA256 signature in the X-Nextcloud-Talk-Signature and
 * X-Nextcloud-Talk-Random headers (note: no "Bot-" prefix — that prefix
 * is only for bot-to-Nextcloud requests, not for Nextcloud-to-webhook).
 *
 * @see https://nextcloud-talk.readthedocs.io/en/latest/bots/
 */
class TalkWebhookController extends Controller
{
    /**
     * Receive an incoming Talk bot webhook payload.
     *
     * Expected headers:
     *   - X-Nextcloud-Talk-Signature: HMAC-SHA256 signature of (random + body)
     *   - X-Nextcloud-Talk-Random:  Random value used in the signature
     * (Inbound webhooks from Nextcloud use headers WITHOUT the "Bot-" prefix)
     *
     * Expected body: Activity Streams 2.0 JSON from Nextcloud Talk bots.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $signature = $request->header('X-Nextcloud-Talk-Signature');
        $random = $request->header('X-Nextcloud-Talk-Random');
        $body = $request->getContent();

        // ── 1. Validate required headers ─────────────────────────────────

        if (empty($signature) || empty($random)) {
            Log::warning('Nextcloud Talk webhook: missing signature headers.');

            return response()->json(['error' => 'Missing signature headers'], 400);
        }

        // ── 2. Parse payload to identify the connection ────────────────────

        $payload = $request->json()->all();

        if (empty($payload)) {
            Log::warning('Nextcloud Talk webhook: empty or invalid JSON payload.');

            return response()->json(['error' => 'Invalid payload'], 400);
        }

        // ── 3. Find the matching NextcloudConnection ─────────────────────

        $connection = $this->resolveConnection($payload);

        if (! $connection) {
            Log::warning('Nextcloud Talk webhook: no active connection found for incoming payload.', [
                'payload_keys' => array_keys($payload),
            ]);

            return response()->json(['error' => 'No matching connection'], 404);
        }

        // ── 4. Verify HMAC-SHA256 signature ────────────────────────────────

        $talkClient = app(NextcloudTalkClient::class);
        $secret = $connection->getTalkBotSecret();

        if (! $talkClient->verifyIncomingSignature($secret, $random, $signature, $body)) {
            // Production-safe diagnostics: log enough to identify the mismatch
            // without leaking full secrets, signatures, or request bodies.
            $expected = hash_hmac('sha256', $random.$body, $secret);
            Log::warning('Nextcloud Talk webhook: signature verification failed.', [
                'connection_id' => $connection->id,
                'conversationToken' => $payload['target']['id'] ?? 'unknown',
                'random_len' => strlen($random),
                'body_len' => strlen($body),
                'sig_match' => hash_equals($expected, $signature) ? 'yes' : 'no',
                'sig_prefix_recv' => substr($signature, 0, 8).'...',
                'sig_prefix_expect' => substr($expected, 0, 8).'...',
                'secret_len' => strlen($secret),
                'body_first_byte' => ord($body[0] ?? "\0"),
                'body_last_byte' => ord($body[-1] ?? "\0"),
            ]);

            return response()->json(['error' => 'Invalid signature'], 403);
        }

        if (! $this->reserveNonce($connection, $random)) {
            Log::warning('Nextcloud Talk webhook: replayed random header rejected.', [
                'connection_id' => $connection->id,
                'conversationToken' => $payload['target']['id'] ?? 'unknown',
            ]);

            return response()->json(['error' => 'Replay detected'], 409);
        }

        // ── 5. Parse the incoming message ──────────────────────────────────

        $message = $talkClient->parseIncomingMessage($payload);
        $messageType = $message['type'];

        Log::info('Nextcloud Talk webhook: received message.', [
            'connection_id' => $connection->id,
            'type' => $messageType,
            'conversation_token' => $message['conversation']['token'],
            'actor' => $message['actor']['name'] ?? $message['actor']['id'],
            'text' => mb_substr($message['message']['text'] ?? '', 0, 200),
        ]);

        // ── 6. Dispatch based on type ──────────────────────────────────────

        // Activity types: Activity, Create, Message, Reaction, Delete
        // Only 'Create' and message-like types carry user text we want to process.
        if (! in_array($messageType, ['Create', 'Activity', 'Message'], true)) {
            Log::debug('Nextcloud Talk webhook: ignoring non-message type.', [
                'type' => $messageType,
            ]);

            return response()->json(['status' => 'ignored', 'type' => $messageType]);
        }

        // Ignore messages from bots (including our own) to prevent loops.
        if (($message['actor']['type'] ?? '') === 'Application') {
            Log::debug('Nextcloud Talk webhook: ignoring bot-originated message.');

            return response()->json(['status' => 'ignored', 'reason' => 'bot_message']);
        }

        $text = trim($message['message']['text'] ?? '');

        if ($text === '') {
            return response()->json(['status' => 'ignored', 'reason' => 'empty_message']);
        }

        // ── 7. Process commands or log unhandled messages ──────────────────

        $commandResult = $this->processMessage($connection, $message, $text);

        return response()->json($commandResult);
    }

    /**
     * Resolve the NextcloudConnection for an incoming payload.
     *
     * Strategy:
     *   1. Require an explicit conversation token from the payload target
     *   2. Match it to talk_default_conversation_token on an active connection
     */
    private function resolveConnection(array $payload): ?NextcloudConnection
    {
        $conversationToken = $payload['target']['id'] ?? null;

        if (! $conversationToken) {
            return null;
        }

        $connection = NextcloudConnection::query()
            ->where('is_active', true)
            ->where('talk_default_conversation_token', $conversationToken)
            ->whereNotNull('talk_bot_id')
            ->whereNotNull('talk_bot_secret')
            ->first();

        return $connection?->hasTalkBot() ? $connection : null;
    }

    private function reserveNonce(NextcloudConnection $connection, string $random): bool
    {
        $key = 'nextcloud:talk:webhook-random:'.sha1($connection->id.'|'.$random);

        return Cache::add($key, true, now()->addMinutes(10));
    }

    /**
     * Process an incoming user message and dispatch any commands.
     *
     * Commands start with '!' — for example:
     *   !status TK-42    → show ticket status
     *   !help            → list available commands
     *
     * Non-command messages are logged for future reference but produce
     * no automated response yet.
     */
    private function processMessage(NextcloudConnection $connection, array $message, string $text): array
    {
        $talkClient = app(NextcloudTalkClient::class);
        $conversationToken = $message['conversation']['token'];

        // Only process commands (lines starting with !).
        if (! str_starts_with($text, '!')) {
            Log::debug('Nextcloud Talk webhook: non-command message received (no ! prefix).', [
                'conversation_token' => $conversationToken,
                'text_preview' => mb_substr($text, 0, 100),
            ]);

            return ['status' => 'received', 'command' => null];
        }

        // Parse the command: !command arg1 arg2 ...
        $parts = preg_split('/\s+/', $text, 3);
        $command = strtolower(ltrim($parts[0], '!'));
        $arg1 = $parts[1] ?? null;
        $arg2 = $parts[2] ?? null;

        Log::info('Nextcloud Talk webhook: processing command.', [
            'conversation_token' => $conversationToken,
            'command' => $command,
            'arg1' => $arg1,
            'arg2' => $arg2,
        ]);

        $responseText = match ($command) {
            'help' => $this->formatHelpText(),
            'status' => $this->handleStatusCommand($connection, $arg1),
            'ping' => '🏓 Pong! Nexum PSA v'.config('app.version', '0.2.1').' is online.',
            default => "Unknown command: `!{$command}`. Type `!help` for available commands.",
        };

        // Send the response back to the Talk conversation.
        try {
            $talkClient->sendBotMessage($connection, $conversationToken, $responseText, [
                'referenceId' => 'nexum-cmd-'.$command.'-'.time(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Nextcloud Talk webhook: failed to send command response.', [
                'error' => $e->getMessage(),
                'conversation_token' => $conversationToken,
            ]);

            return ['status' => 'error', 'command' => $command, 'error' => 'Failed to send response'];
        }

        return ['status' => 'processed', 'command' => $command];
    }

    /**
     * Handle the !status command — look up a ticket by its number.
     */
    private function handleStatusCommand(NextcloudConnection $connection, ?string $ticketNumber): string
    {
        if (empty($ticketNumber)) {
            return "Usage: `!status TICKET-NUMBER`\nExample: `!status TK-42`";
        }

        // Normalize: accept TK-42, tk-42, TK42, tk42.
        $normalized = strtoupper(preg_replace('/[^a-zA-Z0-9]/', '', $ticketNumber));

        if (! preg_match('/^TK\d+$/i', $normalized)) {
            return "Invalid ticket format: `{$ticketNumber}`. Use format: `TK-42`";
        }

        // Ticket lookup will be wired up in a follow-up once the Ticket
        // module has a query interface we can call from here.
        // For now, acknowledge the command was received.
        Log::info('Nextcloud Talk webhook: status command received for ticket.', [
            'ticket' => $normalized,
            'connection_id' => $connection->id,
        ]);

        return "📋 Ticket **{$normalized}** — status lookup pending. Ticket module integration coming soon.";
    }

    /**
     * Build the !help response text.
     */
    private function formatHelpText(): string
    {
        $version = config('app.version', '0.2.1');

        return <<<MARKDOWN
**Nexum PSA Bot Commands** (v{$version})

`!help` — Show this help message
`!ping` — Check if the bot is online
`!status TK-##` — Look up a ticket status

_More commands coming soon._
MARKDOWN;
    }
}
