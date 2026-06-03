<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Models\Clients\Client;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TalkWebhookTest extends TestCase
{
    use RefreshDatabase;

    private NextcloudConnection $connection;
    private string $botSecret = '38774b602b3d204d53821954a5d181128a36509ca30aed299410cccc025934c5';
    private string $conversationToken = 'abewy9qb';

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = NextcloudConnection::query()->create([
            'name' => 'TronderCloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_SYNC,
            'base_url' => 'https://cloud.ramforth.net',
            'sync_interval_minutes' => 15,
            'service_username' => 'svc-nexum',
            'service_password' => 'test-password',
            'is_active' => true,
            'is_default' => true,
            'talk_bot_id' => 10,
            'talk_bot_secret' => $this->botSecret,
            'talk_default_conversation_token' => $this->conversationToken,
            'talk_bot_features' => ['response'],
        ]);
    }

    #[Test]
    public function webhook_rejects_requests_without_signature_headers(): void
    {
        $response = $this->postJson('/api/nextcloud/talk/webhook', [
            'type' => 'Create',
            'actor' => ['type' => 'Person', 'id' => 'user1', 'name' => 'Test User'],
            'object' => [
                'id' => 'msg-1',
                'name' => 'message',
                'content' => json_encode(['message' => '!ping']),
                'mediaType' => 'text/plain',
            ],
            'target' => [
                'id' => $this->conversationToken,
                'name' => 'Nexum Test',
            ],
        ]);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Missing signature headers']);
    }

    #[Test]
    public function webhook_rejects_requests_with_invalid_signature(): void
    {
        $body = json_encode([
            'type' => 'Create',
            'actor' => ['type' => 'Person', 'id' => 'user1', 'name' => 'Test User'],
            'object' => [
                'id' => 'msg-1',
                'name' => 'message',
                'content' => json_encode(['message' => '!ping']),
                'mediaType' => 'text/plain',
            ],
            'target' => [
                'id' => $this->conversationToken,
                'name' => 'Nexum Test',
            ],
        ]);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => 'invalid-signature',
            'X-Nextcloud-Talk-Random' => 'invalid-random',
        ])->postJson('/api/nextcloud/talk/webhook', json_decode($body, true));

        $response->assertStatus(403);
        $response->assertJson(['error' => 'Invalid signature']);
    }

    #[Test]
    public function webhook_rejects_empty_payload(): void
    {
        $random = str()->random(64);
        $body = '';
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => $signature,
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', []);

        $response->assertStatus(400);
        $response->assertJson(['error' => 'Invalid payload']);
    }

    #[Test]
    public function webhook_resolves_connection_by_conversationToken(): void
    {
        $talkClient = $this->app->make(NextcloudTalkClient::class);
        $payload = $this->makePayload('!ping');
        $body = json_encode($payload);
        $random = str()->random(64);
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        // Mock the talk client's sendBotMessage to prevent actual HTTP calls.
        $mockClient = $this->getMockBuilder(NextcloudTalkClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendBotMessage'])
            ->getMock();
        $mockClient->expects($this->once())
            ->method('sendBotMessage')
            ->willReturnCallback(function ($conn, $token, $message, $options) {
                $this->assertSame('🏓 Pong! Nexum PSA Talk bot is online.', $message);
                $this->assertSame($this->conversationToken, $token);

                return ['id' => 999];
            });
        $this->app->instance(NextcloudTalkClient::class, $mockClient);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed', 'command' => 'ping']);
    }

    #[Test]
    public function webhook_ignores_bot_messages_to_prevent_loops(): void
    {
        $payload = $this->makePayload('!ping', actorType: 'Application');
        $body = json_encode($payload);
        $random = str()->random(64);
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'ignored', 'reason' => 'bot_message']);
    }

    #[Test]
    public function webhook_ignores_non_command_messages(): void
    {
        $payload = $this->makePayload('Hello there, how are you?');
        $body = json_encode($payload);
        $random = str()->random(64);
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'received', 'command' => null]);
    }

    #[Test]
    public function webhook_handles_help_command(): void
    {
        $mockClient = $this->getMockBuilder(NextcloudTalkClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendBotMessage'])
            ->getMock();
        $mockClient->expects($this->once())
            ->method('sendBotMessage')
            ->willReturnCallback(function ($conn, $token, $message, $options) {
                $this->assertStringContainsString('!help', $message);
                $this->assertStringContainsString('!ping', $message);
                $this->assertStringContainsString('!status', $message);

                return ['id' => 998];
            });
        $this->app->instance(NextcloudTalkClient::class, $mockClient);

        $payload = $this->makePayload('!help');
        $body = json_encode($payload);
        $random = str()->random(64);
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed', 'command' => 'help']);
    }

    #[Test]
    public function webhook_falls_back_to_default_connection_when_no_conversation_match(): void
    {
        // Connection without matching talk_default_conversation_token.
        $conn = NextcloudConnection::query()->create([
            'name' => 'Other Cloud',
            'scope' => NextcloudConnection::SCOPE_GLOBAL,
            'mode' => NextcloudConnection::MODE_READ_ONLY,
            'base_url' => 'https://other.example.test',
            'sync_interval_minutes' => 30,
            'is_active' => true,
            'is_default' => false,
            'talk_bot_id' => 20,
            'talk_bot_secret' => 'other-secret-value-here-for-testing-32b',
            'talk_bot_features' => ['response'],
        ]);

        $payload = $this->makePayload('!ping');
        // Change the target to something that doesn't match any default conversation token.
        $payload['target']['id'] = 'unknown-token';

        // Re-sign with the connection that has the matching token.
        $body = json_encode($payload);
        $random = str()->random(64);
        // The default connection (is_default=true) should be matched.
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        $mockClient = $this->getMockBuilder(NextcloudTalkClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['sendBotMessage'])
            ->getMock();
        $mockClient->expects($this->once())
            ->method('sendBotMessage')
            ->willReturn(['id' => 1]);
        $this->app->instance(NextcloudTalkClient::class, $mockClient);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', $payload);

        $response->assertStatus(200);
        $response->assertJson(['status' => 'processed', 'command' => 'ping']);
    }

    #[Test]
    public function webhook_returns_404_when_no_active_connection_has_talk_bot(): void
    {
        // Deactivate the connection.
        $this->connection->update(['is_active' => false]);

        $payload = $this->makePayload('!ping');
        $body = json_encode($payload);
        $random = str()->random(64);
        $signature = hash_hmac('sha256', $random . $body, $this->botSecret);

        $response = $this->withHeaders([
            'X-Nextcloud-Talk-Signature' => strtolower($signature),
            'X-Nextcloud-Talk-Random' => $random,
        ])->postJson('/api/nextcloud/talk/webhook', $payload);

        $response->assertStatus(404);
        $response->assertJson(['error' => 'No matching connection']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    private function makePayload(string $message, string $actorType = 'Person'): array
    {
        return [
            'type' => 'Create',
            'actor' => [
                'type' => $actorType,
                'id' => $actorType === 'Application' ? 'bot-10' : 'user1',
                'name' => $actorType === 'Application' ? 'Nexum PSA' : 'Test User',
            ],
            'object' => [
                'id' => 'msg-' . str()->random(8),
                'name' => 'message',
                'content' => json_encode(['message' => $message]),
                'mediaType' => 'text/plain',
            ],
            'target' => [
                'id' => $this->conversationToken,
                'name' => 'Nexum Test',
            ],
        ];
    }
}