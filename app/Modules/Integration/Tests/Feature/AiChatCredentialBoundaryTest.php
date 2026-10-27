<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Integration\Jobs\GenerateAiChatResponse;
use App\Modules\Integration\Livewire\Tech\Ai\ContextChat;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AiChatCredentialBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private AiAgent $agent;

    private string $canary = 'syntheticCredentialCanary987654';

    private array $logEvents = [];

    protected function setUp(): void
    {
        parent::setUp();
        Role::findOrCreate('Tech');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo('integration.ai_manage');
        $provider = AiProvider::create(['name' => 'Fixture', 'provider_key' => 'ollama',
            'base_url' => 'https://example.test', 'default_model' => 'fixture', 'status' => 'active']);
        $this->agent = AiAgent::create(['name' => 'Fixture', 'slug' => 'fixture', 'ai_provider_id' => $provider->id,
            'model' => 'fixture', 'instructions' => 'Help.', 'is_active' => true, 'is_default' => true]);
        Bus::fake();
        Http::preventStrayRequests();
        Http::fake();
        Log::listen(function ($event): void {
            $this->logEvents[] = [$event->message, $event->context];
        });
        $this->actingAs($this->actor);
    }

    #[Test]
    public function new_chat_rejection_precedes_validation_titles_jobs_and_old_input(): void
    {
        // Invalid agent/length must not invoke ordinary validation and flash the secret.
        $result = $this->withSession(['_old_input' => ['message' => $this->canary]])
            ->post(route('tech.ai.chats.store'), ['ai_agent_id' => 'invalid',
                'title' => 'password='.$this->canary, 'message' => str_repeat('x', 20001)]);
        $result->assertRedirect(route('tech.ai.chats.index'))->assertSessionHasErrors('message');
        $this->assertStringNotContainsString($this->canary, json_encode(session()->all()));
        $this->assertDatabaseCount('ai_chats', 0);
        $this->assertNoEffects();
    }

    #[Test]
    public function json_and_existing_chat_rejections_do_not_change_history_or_timestamps(): void
    {
        $chat = AiChat::create(['user_id' => $this->actor->id, 'ai_agent_id' => $this->agent->id,
            'title' => 'Existing', 'status' => 'open', 'last_message_at' => now()->subHour()]);
        $before = $chat->fresh()->getRawOriginal();
        $result = $this->postJson(route('tech.ai.chats.messages.store', $chat), [
            'message' => '{"refresh_token":"'.$this->canary.'"}',
        ])->assertUnprocessable()->assertJsonValidationErrors('message');
        $this->assertStringNotContainsString($this->canary, $result->getContent());
        $this->assertSame($before, $chat->fresh()->getRawOriginal());
        $this->assertNoEffects();
    }

    #[Test]
    public function rightbar_clears_message_and_nested_public_context_before_dehydration(): void
    {
        $component = Livewire::test(ContextChat::class)
            ->set('message', 'Authorization: Bearer '.$this->canary)
            ->set('recordContext', ['title' => 'password='.$this->canary])
            ->call('send')->assertHasErrors('message')->assertSet('message', '')
            ->assertSet('recordContext', null);
        $this->assertStringNotContainsString($this->canary, json_encode($component->snapshot));
        $this->assertStringNotContainsString($this->canary, $component->html());
        $this->assertDatabaseCount('ai_chats', 0);
        $this->assertNoEffects();
    }

    #[Test]
    public function ordinary_chat_input_and_rightbar_pending_reply_are_preserved(): void
    {
        $this->post(route('tech.ai.chats.store'), ['ai_agent_id' => $this->agent->id,
            'message' => 'How do I reset a password?'])->assertRedirect();
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'body' => 'How do I reset a password?']);
        Bus::assertDispatchedAfterResponse(GenerateAiChatResponse::class);
        Livewire::test(ContextChat::class)->set('message', 'Explain the recovery process.')
            ->call('send')->assertHasNoErrors()->assertSet('message', '');
        $this->assertDatabaseHas('ai_chat_messages', ['role' => 'user', 'body' => 'Explain the recovery process.']);
        Http::assertNothingSent();
    }

    #[Test]
    public function nested_credential_keys_cannot_reach_validation_old_input_or_bypass_it_as_extra_fields(): void
    {
        foreach ([['message' => ['password' => ['value' => $this->canary]]],
            ['message' => 'Ordinary question.', 'context' => ['password' => ['value' => $this->canary]]]] as $payload) {
            $this->post(route('tech.ai.chats.store'), ['ai_agent_id' => $this->agent->id] + $payload)
                ->assertRedirect(route('tech.ai.chats.index'))->assertSessionHasErrors('message');
            $this->assertStringNotContainsString($this->canary, json_encode(session()->all()));
        }
        $this->assertDatabaseCount('ai_chats', 0);
        $this->assertNoEffects();
    }

    #[Test]
    public function deferred_updates_and_poll_commits_clear_input_before_send_and_snapshot(): void
    {
        $component = Livewire::test(ContextChat::class)->set('message', 'password='.$this->canary)
            ->assertHasErrors('message')->assertSet('message', '');
        $this->assertStringNotContainsString($this->canary, json_encode($component->snapshot));
        $component->call('processPendingResponse');
        $this->assertStringNotContainsString($this->canary, json_encode($component->snapshot));
        $this->assertDatabaseCount('ai_chats', 0);
        $this->assertNoEffects();
    }

    private function assertNoEffects(): void
    {
        $this->assertDatabaseCount('ai_chat_messages', 0);
        $this->assertDatabaseCount('ai_model_usage_events', 0);
        Bus::assertNothingDispatched();
        Bus::assertNotDispatchedAfterResponse(GenerateAiChatResponse::class);
        Http::assertNothingSent();
        $this->assertStringNotContainsString($this->canary, json_encode($this->logEvents));
        // Inspect database-owned text copies as well as the rendered/session state.
        foreach (['ai_chats', 'ai_chat_messages', 'ai_model_usage_events'] as $table) {
            $this->assertStringNotContainsString($this->canary, json_encode(DB::table($table)->get()));
        }
    }
}
