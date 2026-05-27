<?php

namespace Tests\Unit\Modules\Nextcloud\Services;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudTalkClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NextcloudTalkClientTest extends TestCase
{
    private NextcloudTalkClient $client;

    private NextcloudConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client = app(NextcloudTalkClient::class);

        $this->connection = NextcloudConnection::factory()->create([
            'base_url' => 'https://nextcloud.example.com',
            'service_username' => 'admin',
            'service_password' => 'test-password',
            'is_active' => true,
            'talk_bot_id' => 1,
            'talk_bot_secret' => 'test-bot-secret-key',
            'talk_default_conversation_token' => 'abc123xyz',
            'talk_bot_features' => ['reaction'],
        ]);
    }

    /** @test */
    public function it_sends_signed_bot_message(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/abc123xyz/message' => Http::response([
                'ocs' => [
                    'meta' => ['status' => 'ok', 'statuscode' => 201],
                    'data' => ['id' => 42],
                ],
            ], 201),
        ]);

        $result = $this->client->sendBotMessage(
            $this->connection,
            'abc123xyz',
            'Hello from Nexum!',
            ['referenceId' => 'test-ref-123']
        );

        $this->assertEquals(['id' => 42], $result);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Nextcloud-Talk-Random')
                && $request->hasHeader('X-Nextcloud-Talk-Signature')
                && $request->hasHeader('OCS-APIRequest')
                && $request->url() === 'https://nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/abc123xyz/message'
                && $request->method() === 'POST';
        });
    }

    /** @test */
    public function it_sends_chat_message_with_user_auth(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/chat/abc123xyz' => Http::response([
                'ocs' => [
                    'meta' => ['status' => 'ok', 'statuscode' => 201],
                    'data' => ['id' => 99],
                ],
            ], 201),
        ]);

        $result = $this->client->sendChatMessage(
            $this->connection,
            'abc123xyz',
            'Hello from user context!',
        );

        $this->assertEquals(['id' => 99], $result);

        Http::assertSent(function ($request) {
            return $request->hasHeader('OCS-APIRequest')
                && str_contains($request->url(), '/chat/abc123xyz');
        });
    }

    /** @test */
    public function it_lists_conversations(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/room' => Http::response([
                'ocs' => [
                    'meta' => ['status' => 'ok', 'statuscode' => 200],
                    'data' => [
                        ['token' => 'abc123', 'name' => 'General', 'displayName' => 'General', 'type' => 2, 'hasPassword' => false, 'lastActivity' => 1700000000],
                        ['token' => 'def456', 'name' => 'Support', 'displayName' => 'Support', 'type' => 1, 'hasPassword' => true, 'lastActivity' => 1699999000],
                    ],
                ],
            ], 200),
        ]);

        $conversations = $this->client->listConversations($this->connection);

        $this->assertCount(2, $conversations);
        $this->assertEquals('abc123', $conversations[0]['token']);
        $this->assertEquals('General', $conversations[0]['displayName']);
        $this->assertEquals('public', $conversations[0]['typeLabel']);
        $this->assertEquals('group', $conversations[1]['typeLabel']);
    }

    /** @test */
    public function it_throws_on_missing_bot_secret(): void
    {
        $this->connection->talk_bot_secret = null;
        $this->connection->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Talk bot secret');

        $this->client->sendBotMessage($this->connection, 'abc123xyz', 'test');
    }

    /** @test */
    public function it_throws_on_missing_bot_id(): void
    {
        $this->connection->talk_bot_id = null;
        $this->connection->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Talk bot ID');

        $this->client->sendBotMessage($this->connection, 'abc123xyz', 'test');
    }

    /** @test */
    public function it_verifies_incoming_signatures(): void
    {
        $secret = 'my-bot-secret';
        $random = bin2hex(random_bytes(32));
        $body = json_encode(['type' => 'Activity', 'object' => ['content' => 'hello']]);

        $signature = hash_hmac('sha256', $random . $body, $secret);

        $this->assertTrue(
            $this->client->verifyIncomingSignature($secret, $random, $signature, $body)
        );

        // Wrong signature
        $this->assertFalse(
            $this->client->verifyIncomingSignature($secret, $random, 'badsignature', $body)
        );

        // Wrong body
        $this->assertFalse(
            $this->client->verifyIncomingSignature($secret, $random, $signature, 'wrong body')
        );
    }

    /** @test */
    public function it_parses_incoming_messages(): void
    {
        $payload = [
            'type' => 'Create',
            'actor' => [
                'type' => 'Person',
                'id' => 'user42',
                'name' => 'Jo',
            ],
            'object' => [
                'id' => 'msg-1',
                'name' => 'message',
                'content' => json_encode(['message' => '!status TK-42', 'parameters' => []]),
                'mediaType' => 'text/plain',
            ],
            'target' => [
                'id' => 'abc123',
                'name' => 'Support',
            ],
        ];

        $parsed = $this->client->parseIncomingMessage($payload);

        $this->assertEquals('Create', $parsed['type']);
        $this->assertEquals('user42', $parsed['actor']['id']);
        $this->assertEquals('Jo', $parsed['actor']['name']);
        $this->assertEquals('!status TK-42', $parsed['message']['text']);
        $this->assertEquals('abc123', $parsed['conversation']['token']);
    }

    /** @test */
    public function it_checks_bot_capability_support(): void
    {
        $conn = $this->connection;
        $this->assertFalse($this->client->supportsBots($conn));

        $conn->capabilities = ['spreed' => ['bots-v1' => true]];
        $conn->save();
        $this->assertTrue($this->client->supportsBots($conn));
    }

    /** @test */
    public function it_handles_api_errors_gracefully(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/abc123xyz/message' => Http::response([
                'ocs' => ['meta' => ['status' => 'failure', 'statuscode' => 404, 'message' => 'Conversation not found']],
            ], 404),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Conversation not found');

        $this->client->sendBotMessage($this->connection, 'abc123xyz', 'test');
    }

    /** @test */
    public function it_includes_optional_bot_message_fields(): void
    {
        Http::fake([
            'nextcloud.example.com/ocs/v2.php/apps/spreed/api/v1/bot/abc123xyz/message' => Http::response([
                'ocs' => [
                    'meta' => ['status' => 'ok', 'statuscode' => 201],
                    'data' => ['id' => 1],
                ],
            ], 201),
        ]);

        $this->client->sendBotMessage(
            $this->connection,
            'abc123xyz',
            'Silent message',
            ['referenceId' => 'ref-1', 'silent' => true, 'replyTo' => 42]
        );

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return $body['message'] === 'Silent message'
                && $body['referenceId'] === 'ref-1'
                && $body['silent'] === true
                && $body['replyTo'] === 42;
        });
    }
}