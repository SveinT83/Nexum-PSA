<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Exceptions\AiRuntimeFailure;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiDataEgressPolicy;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Services\AiChatResponder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AiRuntimeFailureBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private AiAgent $agent;

    private string $canary = 'upstreamSecretCanary987654';

    private array $logEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        AiDataEgressPolicy::installation()->update(['ai_enabled' => true, 'allowed_processing_modes' => ['local_only'],
            'maximum_data_profile' => 'full_context']);
        $provider = AiProvider::create(['name' => 'Fixture', 'provider_key' => 'ollama',
            'base_url' => 'https://failure.example.test', 'default_model' => 'fixture-model', 'status' => 'active']);
        $this->agent = AiAgent::create(['name' => 'Fixture', 'slug' => 'failure-fixture',
            'ai_provider_id' => $provider->id, 'model' => 'fixture-model', 'instructions' => 'Help.', 'is_active' => true]);
        Http::preventStrayRequests();
        Log::listen(function ($event): void {
            $this->logEvents[] = [$event->message, $event->context];
        });
    }

    public static function statuses(): array
    {
        return [[401, 'authentication_failed'], [429, 'rate_limited'], [504, 'timeout'], [503, 'provider_failed'], [422, 'invalid_response']];
    }

    #[Test, DataProvider('statuses')]
    public function http_errors_expose_only_safe_category_and_correlation(int $status, string $category): void
    {
        Http::fake(['https://failure.example.test/api/chat' => Http::response([
            'id' => $this->canary, 'model' => $this->canary, 'done_reason' => $this->canary,
            'error' => ['code' => $this->canary, 'message' => 'Authorization: Bearer '.$this->canary],
        ], $status)]);
        $chat = $this->chat();
        app(AiChatResponder::class)->respond($chat);
        $this->assertStringContainsString('Reference:', $chat->messages()->latest('id')->firstOrFail()->body);
        $this->assertStringContainsString('Reference:', $this->agent->provider->fresh()->last_error);
        $this->assertDatabaseHas('ai_model_usage_events', ['status' => 'failed', 'http_status' => $status,
            'error_code' => 'http_'.$status, 'actual_model' => null, 'provider_request_id' => null]);
        $this->assertNoCanary();
        try {
            app(AiChatResponder::class)->complete($this->agent, [['role' => 'user', 'content' => 'ordinary']]);
            $this->fail('Expected a safe HTTP failure.');
        } catch (AiRuntimeFailure $error) {
            $this->assertSame($category, $error->category);
            $this->assertNull($error->getPrevious());
            $this->assertTrue(\Illuminate\Support\Str::isUuid($error->correlationId));
            $this->assertStringNotContainsString($this->canary, (string) $error);
        }
    }

    #[Test]
    public function transport_exception_does_not_reach_chat_status_telemetry_or_exception_chain(): void
    {
        Http::fake(fn () => throw new ConnectionException('Authorization: Bearer '.$this->canary, 28));
        app(AiChatResponder::class)->respond($this->chat());
        $this->assertNoCanary();
        try {
            app(AiChatResponder::class)->complete($this->agent, [['role' => 'user', 'content' => 'ordinary']]);
            $this->fail('Expected transport failure.');
        } catch (AiRuntimeFailure $error) {
            $this->assertSame('timeout', $error->category);
            $this->assertNull($error->getPrevious());
            $this->assertStringNotContainsString($this->canary, (string) $error);
        }
    }

    #[Test]
    public function empty_success_response_details_are_not_diagnostics(): void
    {
        Http::fake(['https://failure.example.test/api/chat' => Http::response([
            'id' => $this->canary, 'model' => $this->canary,
            'incomplete_details' => ['reason' => $this->canary], 'error' => ['message' => $this->canary],
        ])]);
        app(AiChatResponder::class)->respond($this->chat());
        $this->assertNoCanary();
    }

    #[Test]
    public function policy_denial_preserves_existing_typed_callers_and_safe_reference(): void
    {
        AiDataEgressPolicy::installation()->update(['ai_enabled' => false]);
        try {
            app(AiChatResponder::class)->complete($this->agent, [['role' => 'user', 'content' => 'ordinary']]);
            $this->fail('Disabled policy must deny.');
        } catch (\App\Modules\Integration\Exceptions\AiPolicyDeniedException $error) {
            $this->assertSame('ai_disabled', $error->reasonCode);
            $this->assertSame('denied', $error->category);
            $this->assertNull($error->getPrevious());
            $this->assertStringContainsString('Reference:', $error->getMessage());
        }
        Http::assertNothingSent();
    }

    private function chat(): AiChat
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $chat = AiChat::create(['user_id' => $actor->id, 'ai_agent_id' => $this->agent->id,
            'title' => 'Fixture', 'status' => 'open', 'last_message_at' => now()]);
        $chat->messages()->create(['role' => 'user', 'body' => 'Hello.']);

        return $chat;
    }

    private function assertNoCanary(): void
    {
        foreach (['ai_chats', 'ai_chat_messages', 'ai_model_usage_events', 'ai_providers'] as $table) {
            $this->assertStringNotContainsString($this->canary, json_encode(DB::table($table)->get()));
        }
        $this->assertStringNotContainsString($this->canary, json_encode($this->logEvents));
    }
}
