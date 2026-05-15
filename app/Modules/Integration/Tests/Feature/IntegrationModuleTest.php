<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Controllers\Admin\ApiController;
use App\Modules\Integration\Controllers\Admin\AiIntegrationController;
use App\Modules\Integration\Controllers\Admin\IntegrationsController;
use App\Modules\Integration\Controllers\Tech\AiChatController;
use App\Modules\Integration\Jobs\PullBookStackToKnowledge;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Integration\Models\AiChatMessage;
use App\Modules\Integration\Models\AiProvider;
use App\Modules\Integration\Models\AiSystemSetting;
use App\Modules\Integration\Services\AiChatCleanup;
use App\Modules\Integration\Services\AiChatResponder;
use App\Modules\Integration\Support\AiMessageFormatter;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class IntegrationModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole('Admin');
    }

    #[Test]
    public function admin_can_open_integration_index_from_integration_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.index');

        $this->assertSame(IntegrationsController::class.'@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.index')
            ->assertViewHas('integrations');
    }

    #[Test]
    public function admin_can_open_api_management_from_integration_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.api.index');

        $this->assertSame(ApiController::class.'@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.api.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.api.index')
            ->assertViewHas('apiKeys');
    }

    #[Test]
    public function admin_can_configure_ai_provider_and_default_agent(): void
    {
        $techRole = Role::create(['name' => 'Tech']);
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.ai.index');

        $this->assertSame(AiIntegrationController::class.'@index', $route->getActionName());

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.ai.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.ai.index')
            ->assertSee('Providers')
            ->assertSee('Agents')
            ->assertSee('Tools');

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.ai.providers.store'), [
                'name' => 'Managed Ollama',
                'provider_key' => 'ollama',
                'base_url' => 'https://ollama.example.test/',
                'default_model' => 'llama3.1',
                'embedding_model' => 'nomic-embed-text',
                'api_key' => 'secret-token',
                'status' => 'active',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $provider = AiProvider::firstOrFail();

        $this->assertSame('Managed Ollama', $provider->name);
        $this->assertSame('https://ollama.example.test', $provider->base_url);
        $this->assertSame('secret-token', $provider->getSecret('api_key'));

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.ai.agents.store'), [
                'ai_provider_id' => $provider->id,
                'name' => 'Knowledge Desk',
                'slug' => 'knowledge-desk',
                'model' => 'llama3.1:70b',
                'instructions' => 'Use Knowledge first and cite the source records.',
                'data_sources' => ['knowledge', 'active_tickets'],
                'allowed_tools' => ['search', 'read_records', 'tickets.update'],
                'allowed_api_scopes' => ['tickets.read', 'tickets.write', 'knowledge.read'],
                'role_ids' => [$techRole->id],
                'default_domains' => ['tickets'],
                'is_active' => '1',
                'is_default' => '1',
                'can_execute_actions' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $agent = AiAgent::with(['provider', 'roles'])->firstOrFail();

        $this->assertTrue($agent->is_default);
        $this->assertFalse($agent->can_execute_actions);
        $this->assertSame(['knowledge', 'active_tickets'], $agent->data_sources);
        $this->assertSame(['knowledge.search', 'records.read'], $agent->allowed_tools);
        $this->assertSame(['tickets.read', 'knowledge.read'], $agent->allowed_api_scopes);
        $this->assertSame(['tickets'], $agent->default_domains);
        $this->assertTrue($agent->provider->is($provider));
        $this->assertTrue($agent->roles->contains($techRole));

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.ai.index'))
            ->assertOk()
            ->assertSee('Managed Ollama')
            ->assertSee('Knowledge Desk')
            ->assertSee('Default');
    }

    #[Test]
    public function rightbar_ai_chat_uses_domain_default_agent_and_page_context(): void
    {
        Http::fake([
            'https://ollama.example.test/api/chat' => Http::response([
                'message' => [
                    'content' => 'You are on the ticket queue.',
                ],
            ], 200),
        ]);

        $techRole = Role::create(['name' => 'Tech']);
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole($techRole);
        $provider = AiProvider::create([
            'name' => 'Managed Ollama',
            'provider_key' => 'ollama',
            'base_url' => 'https://ollama.example.test',
            'default_model' => 'llama3.1',
            'status' => 'active',
        ]);
        $queue = TicketQueue::create([
            'name' => 'Support',
            'slug' => 'support',
            'is_default' => true,
            'is_active' => true,
        ]);
        $openStatus = TicketStatus::create([
            'name' => 'Open',
            'slug' => 'open',
            'state' => 'open',
            'is_default' => true,
            'is_closed' => false,
            'is_active' => true,
        ]);
        $highPriority = TicketPriority::create([
            'name' => 'High',
            'slug' => 'high',
            'level' => 1,
            'is_active' => true,
        ]);
        $normalPriority = TicketPriority::create([
            'name' => 'Normal',
            'slug' => 'normal',
            'level' => 3,
            'is_active' => true,
        ]);
        Ticket::create([
            'ticket_key' => 'TD-2026-000101',
            'queue_id' => $queue->id,
            'status_id' => $openStatus->id,
            'priority_id' => $highPriority->id,
            'owner_id' => $tech->id,
            'subject' => 'Backup failed on server',
            'is_unread' => true,
            'first_response_due_at' => now()->subHour(),
        ]);
        Ticket::create([
            'ticket_key' => 'TD-2026-000102',
            'queue_id' => $queue->id,
            'status_id' => $openStatus->id,
            'priority_id' => $normalPriority->id,
            'owner_id' => $tech->id,
            'subject' => 'New laptop request',
        ]);
        $fallbackAgent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Global Desk',
            'slug' => 'global-desk',
            'model' => 'llama3.1',
            'instructions' => 'Use global context.',
            'data_sources' => ['knowledge'],
            'allowed_tools' => ['knowledge.search'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $ticketAgent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Ticket Desk',
            'slug' => 'ticket-desk',
            'model' => 'llama3.1',
            'instructions' => 'Use ticket context.',
            'data_sources' => ['active_tickets', 'knowledge'],
            'allowed_tools' => ['knowledge.search', 'records.read'],
            'allowed_api_scopes' => ['tickets.read'],
            'default_domains' => ['tickets'],
            'is_active' => true,
        ]);
        $fallbackAgent->roles()->sync([$techRole->id]);
        $ticketAgent->roles()->sync([$techRole->id]);

        Livewire::actingAs($tech)
            ->test('tech.ai.context-chat', ['pageTitle' => 'Ticket Queue'])
            ->set('domain', 'tickets')
            ->set('routeName', 'tech.tickets.index')
            ->set('pageUrl', 'http://localhost/tickets')
            ->assertSet('selectedAgentId', $ticketAgent->id)
            ->set('message', 'Hva ser du?')
            ->call('send')
            ->assertSee('AI is thinking...')
            ->call('processPendingResponse')
            ->assertHasNoErrors();

        $chat = AiChat::with(['agent', 'messages'])->firstOrFail();

        $this->assertTrue($chat->agent->is($ticketAgent));
        $this->assertSame('tickets', $chat->metadata['page_context']['domain']);
        $this->assertSame('tech.tickets.index', $chat->metadata['page_context']['route_name']);
        $this->assertSame('Ticket Queue', $chat->metadata['page_context']['title']);
        $this->assertSame('You are on the ticket queue.', $chat->messages->last()->body);

        Http::assertSent(fn ($request) => str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'Current tdPSA page context:'
        ) && str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'tech.tickets.index'
        ) && str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'Open tickets assigned to current user: 2'
        ) && str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'TD-2026-000101'
        ));
    }

    #[Test]
    public function ai_chat_messages_render_safe_links_that_open_in_new_tabs(): void
    {
        $html = (string) AiMessageFormatter::render(
            'Open [TD-2026-000008](https://nexum-psa.local/tech/tickets/TD-2026-000008) <script>alert(1)</script>'
        );

        $this->assertStringContainsString(
            '<a href="https://nexum-psa.local/tech/tickets/TD-2026-000008" target="_blank" rel="noopener noreferrer">TD-2026-000008</a>',
            $html
        );
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
    }

    #[Test]
    public function rightbar_ai_chat_is_hidden_without_active_provider_agent_pair(): void
    {
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $provider = AiProvider::create([
            'name' => 'Disabled OpenAI',
            'provider_key' => 'openai',
            'default_model' => 'gpt-4.1-mini',
            'status' => 'disabled',
        ]);

        AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Disabled Provider Agent',
            'slug' => 'disabled-provider-agent',
            'model' => 'gpt-4.1-mini',
            'instructions' => 'No active provider.',
            'data_sources' => ['knowledge'],
            'allowed_tools' => ['knowledge.search'],
            'is_default' => true,
            'is_active' => true,
        ]);

        Livewire::actingAs($tech)
            ->test('tech.ai.context-chat', ['pageTitle' => 'Ticket Queue'])
            ->assertDontSee('AI')
            ->assertDontSee('Agent')
            ->assertDontSee('Ask about this page');
    }

    #[Test]
    public function admin_can_update_ai_retention_and_memory_settings(): void
    {
        Livewire::actingAs($this->admin)
            ->test('tech.admin.system.integrations.ai-settings')
            ->assertSee('Retention', false)
            ->assertSee('Memory', false)
            ->set('retentionForm.context_message_limit', 12)
            ->set('retentionForm.chat_retention_days', 45)
            ->set('retentionForm.delete_empty_chats_after_days', 3)
            ->set('retentionForm.delete_failed_pending_after_hours', 6)
            ->set('retentionForm.cleanup_enabled', true)
            ->call('saveRetentionSettings')
            ->assertHasNoErrors();

        $settings = AiSystemSetting::current();

        $this->assertSame(12, $settings->context_message_limit);
        $this->assertSame(45, $settings->chat_retention_days);
        $this->assertSame(3, $settings->delete_empty_chats_after_days);
        $this->assertSame(6, $settings->delete_failed_pending_after_hours);
        $this->assertTrue($settings->cleanup_enabled);
    }

    #[Test]
    public function ai_responder_uses_configured_context_message_limit(): void
    {
        Http::fake([
            'https://ollama.example.test/api/chat' => Http::response([
                'message' => [
                    'content' => 'Context limited.',
                ],
            ], 200),
        ]);

        AiSystemSetting::current()->forceFill(['context_message_limit' => 2])->save();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $provider = AiProvider::create([
            'name' => 'Managed Ollama',
            'provider_key' => 'ollama',
            'base_url' => 'https://ollama.example.test',
            'default_model' => 'llama3.1',
            'status' => 'active',
        ]);
        $agent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Short Memory',
            'slug' => 'short-memory',
            'model' => 'llama3.1',
            'instructions' => 'Use short context.',
            'data_sources' => [],
            'allowed_tools' => [],
            'is_active' => true,
        ]);
        $chat = AiChat::create([
            'user_id' => $user->id,
            'ai_agent_id' => $agent->id,
            'title' => 'Memory test',
            'status' => 'open',
        ]);
        $chat->messages()->create(['user_id' => $user->id, 'role' => 'user', 'body' => 'old message']);
        $chat->messages()->create(['role' => 'assistant', 'body' => 'middle message', 'metadata' => ['status' => 'complete']]);
        $chat->messages()->create(['user_id' => $user->id, 'role' => 'user', 'body' => 'latest message']);
        $pending = $chat->messages()->create(['role' => 'assistant', 'body' => 'AI is thinking...', 'metadata' => ['status' => 'pending']]);

        app(AiChatResponder::class)->respond($chat, $pending->id);

        Http::assertSent(fn ($request) => ! str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'old message'
        ) && str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'middle message'
        ) && str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'latest message'
        ));
    }

    #[Test]
    public function ai_chat_cleanup_deletes_old_chats_and_expires_stale_pending_messages(): void
    {
        $settings = AiSystemSetting::current();
        $settings->forceFill([
            'chat_retention_days' => 30,
            'delete_empty_chats_after_days' => 2,
            'delete_failed_pending_after_hours' => 4,
            'cleanup_enabled' => true,
        ])->save();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        $oldChat = AiChat::create([
            'user_id' => $user->id,
            'title' => 'Old chat',
            'status' => 'open',
        ]);
        $oldChat->forceFill(['created_at' => now()->subDays(45), 'updated_at' => now()->subDays(45)])->save();
        $oldChat->messages()->create(['user_id' => $user->id, 'role' => 'user', 'body' => 'remove me']);

        $emptyChat = AiChat::create([
            'user_id' => $user->id,
            'title' => 'Empty chat',
            'status' => 'open',
        ]);
        $emptyChat->forceFill(['created_at' => now()->subDays(5), 'updated_at' => now()->subDays(5)])->save();

        $activeChat = AiChat::create([
            'user_id' => $user->id,
            'title' => 'Active chat',
            'status' => 'open',
        ]);
        $pending = $activeChat->messages()->create([
            'role' => 'assistant',
            'body' => 'AI is thinking...',
            'metadata' => ['status' => 'pending'],
        ]);
        $pending->forceFill(['created_at' => now()->subHours(8), 'updated_at' => now()->subHours(8)])->save();

        $summary = app(AiChatCleanup::class)->run();

        $this->assertSame(1, $summary['deleted_old_chats']);
        $this->assertSame(1, $summary['deleted_empty_chats']);
        $this->assertSame(1, $summary['expired_pending_messages']);
        $this->assertDatabaseMissing('ai_chats', ['id' => $oldChat->id]);
        $this->assertDatabaseMissing('ai_chats', ['id' => $emptyChat->id]);
        $this->assertSame('failed', $pending->fresh()->metadata['status']);
        $this->assertNotNull(AiSystemSetting::current()->last_cleanup_at);
    }

    #[Test]
    public function admin_can_fetch_provider_models_with_livewire_before_saving_provider(): void
    {
        Http::fake([
            'https://api.openai.com/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-4.1-mini'],
                    ['id' => 'gpt-4.1'],
                ],
            ], 200),
        ]);

        Livewire::actingAs($this->admin)
            ->test('tech.admin.system.integrations.ai-settings')
            ->set('providerForm.name', 'OpenAI support key')
            ->set('providerForm.provider_key', 'openai')
            ->set('providerForm.api_key', 'openai-secret')
            ->call('fetchModels')
            ->assertSet('modelOptions', ['gpt-4.1', 'gpt-4.1-mini'])
            ->set('providerForm.default_model', 'gpt-4.1-mini')
            ->call('saveProvider')
            ->assertHasNoErrors();

        $provider = AiProvider::firstOrFail();

        $this->assertSame('OpenAI support key', $provider->name);
        $this->assertSame('openai', $provider->provider_key);
        $this->assertNull($provider->base_url);
        $this->assertSame('gpt-4.1-mini', $provider->default_model);
        $this->assertSame(['gpt-4.1', 'gpt-4.1-mini'], $provider->config['available_models']);
        $this->assertSame('openai-secret', $provider->getSecret('api_key'));

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/models'
            && $request->hasHeader('Authorization', 'Bearer openai-secret')
        );
    }

    #[Test]
    public function ai_provider_delete_requires_edit_and_confirmation_modal(): void
    {
        $provider = AiProvider::create([
            'name' => 'OpenAI production',
            'provider_key' => 'openai',
            'default_model' => 'gpt-4.1-mini',
            'status' => 'active',
        ]);

        Livewire::actingAs($this->admin)
            ->test('tech.admin.system.integrations.ai-settings')
            ->assertSee('OpenAI production')
            ->assertDontSee('Delete provider')
            ->call('editProvider', $provider->id)
            ->assertSee('Delete provider')
            ->assertDontSee('Confirm delete')
            ->call('confirmDeleteProvider')
            ->assertSee('Confirm delete')
            ->call('cancelDelete')
            ->assertDontSee('Confirm delete')
            ->call('confirmDeleteProvider')
            ->call('deleteConfirmed')
            ->assertHasNoErrors();

        $this->assertDatabaseMissing('ai_providers', [
            'id' => $provider->id,
        ]);
    }

    #[Test]
    public function technician_can_open_ai_chat_workspace_and_start_chat_with_available_agent(): void
    {
        Http::fake([
            'https://ollama.example.test/api/chat' => Http::response([
                'message' => [
                    'content' => 'I am awake.',
                ],
            ], 200),
        ]);

        $techRole = Role::create(['name' => 'Tech']);
        $salesRole = Role::create(['name' => 'Sales']);
        $tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $tech->assignRole($techRole);
        $route = Route::getRoutes()->getByName('tech.ai.chats.index');

        $this->assertSame(AiChatController::class.'@index', $route->getActionName());

        $provider = AiProvider::create([
            'name' => 'Managed Ollama',
            'provider_key' => 'ollama',
            'base_url' => 'https://ollama.example.test',
            'default_model' => 'llama3.1',
            'status' => 'active',
        ]);
        $shelf = Shelf::create([
            'name' => 'Operations',
            'slug' => 'operations',
        ]);
        Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Instruksjoner for ticket foring',
            'slug' => 'instruksjoner-for-ticket-foring',
            'description' => 'Rutiner for hvordan teknikere skal fore ticket.',
        ]);
        $techAgent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Knowledge Desk',
            'slug' => 'knowledge-desk-tech',
            'model' => 'llama3.1',
            'instructions' => 'Use Knowledge first.',
            'data_sources' => ['knowledge'],
            'allowed_tools' => ['search'],
            'is_default' => true,
            'is_active' => true,
        ]);
        $techAgent->roles()->sync([$techRole->id]);
        $salesAgent = AiAgent::create([
            'ai_provider_id' => $provider->id,
            'name' => 'Sales Desk',
            'slug' => 'sales-desk',
            'model' => 'llama3.1',
            'instructions' => 'Use sales data.',
            'data_sources' => ['clients'],
            'allowed_tools' => ['search'],
            'is_active' => true,
        ]);
        $salesAgent->roles()->sync([$salesRole->id]);

        $this->actingAs($tech)
            ->get(route('tech.ai.chats.index'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Ai.Chats.index')
            ->assertSee('AI Chats')
            ->assertSee('Knowledge Desk')
            ->assertDontSee('Sales Desk');

        $this->actingAs($tech)
            ->post(route('tech.ai.chats.store'), [
                'ai_agent_id' => $techAgent->id,
                'message' => 'Har vi en bok om instruksjoner for ticket foring?',
            ])
            ->assertRedirect();

        $chat = AiChat::with('messages')->firstOrFail();

        $this->assertSame($tech->id, $chat->user_id);
        $this->assertSame($techAgent->id, $chat->ai_agent_id);
        $this->assertSame('Har vi en bok om instruksjoner for ticket foring?', $chat->title);
        $this->assertSame('Har vi en bok om instruksjoner for ticket foring?', $chat->messages->first()->body);
        $this->assertSame('I am awake.', $chat->messages->last()->body);

        $this->actingAs($tech)
            ->post(route('tech.ai.chats.messages.store', $chat), [
                'message' => 'Narrow it to Windows clients.',
            ])
            ->assertRedirect(route('tech.ai.chats.index', ['chat' => $chat->id]));

        $this->assertSame(4, AiChatMessage::where('ai_chat_id', $chat->id)->count());
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            'Instruksjoner for ticket foring'
        ) && str_contains(
            collect($request['messages'])->pluck('content')->implode("\n"),
            '/tech/knowledge/books/'
        ));
    }

    #[Test]
    public function admin_can_open_book_stack_settings_from_integration_module(): void
    {
        $route = Route::getRoutes()->getByName('tech.admin.system.integrations.book_stack.settings');

        $this->assertSame(IntegrationsController::class.'@bookStackSettings', $route->getActionName());
        $this->assertSame(
            IntegrationsController::class.'@bookStackPush',
            Route::getRoutes()->getByName('tech.admin.system.integrations.book_stack.push')->getActionName()
        );

        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.integrations.book_stack.settings'))
            ->assertOk()
            ->assertViewIs('integration::Tech.Admin.System.Integrations.book_stack.settings')
            ->assertViewHas('integration');
    }

    #[Test]
    public function admin_can_save_book_stack_configuration_without_api_credentials(): void
    {
        Http::fake();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.update'), [
                'server' => 'https://docs.example.test/',
                'sync_interval_minutes' => 10,
                'two_way_sync_enabled' => '1',
            ])
            ->assertRedirect();

        $integration = Integration::where('type', 'book_stack')->firstOrFail();

        $this->assertSame('BookStack', $integration->name);
        $this->assertSame('https://docs.example.test', $integration->server);
        $this->assertFalse($integration->is_healthy);
        $this->assertNull($integration->last_error);
        $this->assertSame(10, $integration->config['sync_interval_minutes']);
        $this->assertFalse($integration->config['read_only']);
        $this->assertTrue($integration->config['two_way_sync_enabled']);
        $this->assertSame('two_way', $integration->config['sync_mode']);

        Http::assertNothingSent();
    }

    #[Test]
    public function admin_can_test_book_stack_configuration_when_credentials_exist(): void
    {
        Http::fake([
            'https://docs.example.test/api/books*' => Http::response(['data' => []], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => false,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => true,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.test'))
            ->assertRedirect();

        $integration->refresh();

        $this->assertTrue($integration->is_healthy);
        $this->assertNull($integration->last_error);

        Http::assertSent(fn ($request) => $request->url() === 'https://docs.example.test/api/books?count=1'
            && $request->hasHeader('Authorization', 'Token token-id:token-secret')
        );
    }

    #[Test]
    public function admin_can_sync_book_stack_pages_into_knowledge_articles(): void
    {
        Http::fake([
            'https://docs.example.test/api/shelves/2' => Http::response([
                'id' => 2,
                'name' => 'Operations Shelf',
                'slug' => 'operations-shelf',
                'description' => 'Operational documentation',
                'books' => [
                    ['id' => 7],
                ],
            ], 200),
            'https://docs.example.test/api/shelves*' => Http::response([
                'data' => [
                    [
                        'id' => 2,
                        'name' => 'Operations Shelf',
                        'slug' => 'operations-shelf',
                        'description' => 'Operational documentation',
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/books*' => Http::response([
                'data' => [
                    [
                        'id' => 7,
                        'name' => 'Operations',
                        'slug' => 'operations',
                        'description' => 'Operations book',
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/chapters*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
            'https://docs.example.test/api/pages/42' => Http::response([
                'id' => 42,
                'book_id' => 7,
                'chapter_id' => null,
                'name' => 'VPN Setup',
                'slug' => 'vpn-setup',
                'html' => '<h1>VPN Setup</h1><p>Use the managed profile.</p>',
                'markdown' => '# VPN Setup',
                'draft' => false,
                'updated_at' => '2026-05-14T10:00:00.000000Z',
                'book' => [
                    'id' => 7,
                    'slug' => 'operations',
                    'name' => 'Operations',
                ],
                'tags' => [
                    ['name' => 'System', 'value' => 'VPN', 'order' => 0],
                ],
            ], 200),
            'https://docs.example.test/api/pages?count=100&offset=0&sort=%2Bid' => Http::response([
                'data' => [
                    [
                        'id' => 42,
                        'name' => 'VPN Setup',
                        'slug' => 'vpn-setup',
                        'book_id' => 7,
                        'chapter_id' => null,
                        'book_slug' => 'operations',
                        'updated_at' => '2026-05-14T10:00:00.000000Z',
                    ],
                ],
                'total' => 1,
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => true,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $article = Article::where('source_system', 'book_stack')->firstOrFail();
        $shelf = Shelf::where('source_system', 'book_stack')->where('source_type', 'shelf')->firstOrFail();
        $book = Book::where('source_system', 'book_stack')->where('source_type', 'book')->firstOrFail();

        $this->assertSame('VPN Setup', $article->title);
        $this->assertSame('published', $article->status);
        $this->assertSame('page', $article->source_type);
        $this->assertSame('42', $article->source_id);
        $this->assertSame('https://docs.example.test/books/operations/page/vpn-setup', $article->source_url);
        $this->assertSame($shelf->id, $book->shelf_id);
        $this->assertSame($shelf->id, $article->knowledge_shelf_id);
        $this->assertSame($book->id, $article->knowledge_book_id);
        $this->assertSame($this->admin->id, $article->owner_id);
        $this->assertSame($this->admin->id, $article->created_by);
        $this->assertSame('synced', $article->sync_status);
        $this->assertNotEmpty($article->source_checksum);

        $integration->refresh();

        $this->assertTrue($integration->is_healthy);
        $this->assertNotNull($integration->last_sync_at);
        $this->assertSame(1, $integration->config['last_sync_summary']['created']);
        $this->assertSame(0, $integration->config['last_sync_summary']['failed']);
    }

    #[Test]
    public function scheduled_book_stack_pull_runs_only_when_interval_is_due(): void
    {
        Carbon::setTestNow('2026-05-15 10:00:00');
        Http::fake([
            'https://docs.example.test/api/shelves*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
            'https://docs.example.test/api/books*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
            'https://docs.example.test/api/chapters*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
            'https://docs.example.test/api/pages*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 60,
                'read_only' => true,
                'last_pull_at' => now()->subMinutes(15)->toIso8601String(),
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        (new PullBookStackToKnowledge())->handle();

        Http::assertNothingSent();
        $this->assertSame(
            now()->subMinutes(15)->toIso8601String(),
            $integration->fresh()->config['last_pull_at']
        );

        $config = $integration->fresh()->config;
        $config['last_pull_at'] = now()->subMinutes(61)->toIso8601String();
        $integration->forceFill(['config' => $config])->save();

        (new PullBookStackToKnowledge())->handle();

        Http::assertSent(fn ($request) => $request->url() === 'https://docs.example.test/api/shelves?count=100&offset=0&sort=%2Bid');
        $this->assertSame(0, $integration->fresh()->config['last_sync_summary']['failed']);
        $this->assertSame(now()->toIso8601String(), $integration->fresh()->config['last_pull_at']);

        Carbon::setTestNow();
    }

    #[Test]
    public function book_stack_sync_uses_shelf_read_response_for_book_membership(): void
    {
        Http::fake([
            'https://docs.example.test/api/shelves/9' => Http::response([
                'id' => 9,
                'name' => 'Kunder',
                'slug' => 'kunder',
                'description' => 'Customer documentation',
                'books' => [
                    ['id' => 223],
                ],
            ], 200),
            'https://docs.example.test/api/shelves*' => Http::response([
                'data' => [
                    [
                        'id' => 9,
                        'name' => 'Kunder',
                        'slug' => 'kunder',
                        'description' => 'Customer documentation',
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/books*' => Http::response([
                'data' => [
                    [
                        'id' => 223,
                        'name' => '00223 - Tronder Service',
                        'slug' => '00223-tronder-service',
                        'description' => 'Customer book',
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/chapters*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
            'https://docs.example.test/api/pages*' => Http::response([
                'data' => [],
                'total' => 0,
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => true,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $customerShelf = Shelf::where('source_system', 'book_stack')->where('source_type', 'shelf')->firstOrFail();
        $book = Book::where('source_system', 'book_stack')->where('source_type', 'book')->firstOrFail();

        $this->assertSame('Kunder', $customerShelf->name);
        $this->assertSame($customerShelf->id, $book->shelf_id);
        $this->assertDatabaseMissing('knowledge_shelves', [
            'source_system' => 'book_stack',
            'source_type' => 'virtual_shelf',
            'source_id' => 'default',
        ]);
    }

    #[Test]
    public function book_stack_sync_uses_chapter_list_names_instead_of_chapter_ids(): void
    {
        Http::fake([
            'https://docs.example.test/api/shelves/9' => Http::response([
                'id' => 9,
                'name' => 'Kunder',
                'slug' => 'kunder',
                'books' => [
                    ['id' => 223],
                ],
            ], 200),
            'https://docs.example.test/api/shelves*' => Http::response([
                'data' => [
                    [
                        'id' => 9,
                        'name' => 'Kunder',
                        'slug' => 'kunder',
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/books*' => Http::response([
                'data' => [
                    [
                        'id' => 223,
                        'name' => '00223 - Tronder Service',
                        'slug' => '00223-tronder-service',
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/chapters*' => Http::response([
                'data' => [
                    [
                        'id' => 185,
                        'book_id' => 223,
                        'name' => 'NextCloud',
                        'slug' => 'nextcloud',
                        'description' => 'NextCloud documentation',
                        'priority' => 4,
                    ],
                ],
                'total' => 1,
            ], 200),
            'https://docs.example.test/api/pages/501' => Http::response([
                'id' => 501,
                'book_id' => 223,
                'chapter_id' => 185,
                'name' => 'NextCloud Login',
                'slug' => 'nextcloud-login',
                'html' => '<p>Login steps.</p>',
                'markdown' => 'Login steps.',
                'draft' => false,
                'updated_at' => '2026-05-14T10:00:00.000000Z',
                'book' => [
                    'id' => 223,
                    'slug' => '00223-tronder-service',
                    'name' => '00223 - Tronder Service',
                ],
            ], 200),
            'https://docs.example.test/api/pages?count=100&offset=0&sort=%2Bid' => Http::response([
                'data' => [
                    [
                        'id' => 501,
                        'name' => 'NextCloud Login',
                        'slug' => 'nextcloud-login',
                        'book_id' => 223,
                        'chapter_id' => 185,
                        'book_slug' => '00223-tronder-service',
                    ],
                ],
                'total' => 1,
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => true,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.sync'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter = Chapter::where('source_system', 'book_stack')->where('source_id', '185')->firstOrFail();
        $article = Article::where('source_system', 'book_stack')->where('source_id', '501')->firstOrFail();

        $this->assertSame('NextCloud', $chapter->name);
        $this->assertSame('bookstack-chapter-nextcloud-185', $chapter->slug);
        $this->assertSame(4, $chapter->priority);
        $this->assertSame($chapter->id, $article->knowledge_chapter_id);
    }

    #[Test]
    public function admin_can_push_local_knowledge_content_to_book_stack_when_two_way_sync_is_enabled(): void
    {
        Http::fake([
            'https://docs.example.test/api/shelves' => Http::response([
                'id' => 101,
                'name' => 'Local Shelf',
                'slug' => 'local-shelf',
                'description' => 'Local shelf description',
                'updated_at' => '2026-05-14T12:00:00.000000Z',
            ], 200),
            'https://docs.example.test/api/books' => Http::response([
                'id' => 202,
                'name' => 'Local Book',
                'slug' => 'local-book',
                'description' => 'Local book description',
                'updated_at' => '2026-05-14T12:01:00.000000Z',
            ], 200),
            'https://docs.example.test/api/shelves/101' => Http::response([
                'id' => 101,
                'name' => 'Local Shelf',
                'slug' => 'local-shelf',
                'description' => 'Local shelf description',
                'updated_at' => '2026-05-14T12:02:00.000000Z',
                'books' => [
                    ['id' => 202],
                ],
            ], 200),
            'https://docs.example.test/api/chapters' => Http::response([
                'id' => 404,
                'book_id' => 202,
                'name' => 'Local Chapter',
                'slug' => 'local-chapter',
                'description' => 'Local chapter description',
                'priority' => 3,
                'updated_at' => '2026-05-14T12:02:30.000000Z',
                'book' => [
                    'id' => 202,
                    'slug' => 'local-book',
                ],
            ], 200),
            'https://docs.example.test/api/pages' => Http::response([
                'id' => 303,
                'book_id' => 202,
                'chapter_id' => 404,
                'name' => 'Local Page',
                'slug' => 'local-page',
                'html' => '<h1>Local Page</h1>',
                'markdown' => '# Local Page',
                'priority' => 5,
                'updated_at' => '2026-05-14T12:03:00.000000Z',
                'book' => [
                    'id' => 202,
                    'slug' => 'local-book',
                ],
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => false,
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $shelf = Shelf::create([
            'name' => 'Local Shelf',
            'slug' => 'local-shelf',
            'description' => 'Local shelf description',
            'sync_status' => 'pending_push',
        ]);

        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Local Book',
            'slug' => 'local-book',
            'description' => 'Local book description',
            'sync_status' => 'pending_push',
        ]);

        $article = Article::create([
            'title' => 'Local Page',
            'slug' => 'local-page',
            'body_markdown' => '# Local Page',
            'body_html' => '<h1>Local Page</h1>',
            'visibility' => 'internal',
            'status' => 'published',
            'priority' => 5,
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'knowledge_shelf_id' => $shelf->id,
            'knowledge_book_id' => $book->id,
            'sync_status' => 'pending_push',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Local Chapter',
            'slug' => 'local-chapter',
            'description' => 'Local chapter description',
            'priority' => 3,
            'sync_status' => 'pending_push',
        ]);
        $article->forceFill(['knowledge_chapter_id' => $chapter->id])->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.push'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $shelf->refresh();
        $book->refresh();
        $chapter->refresh();
        $article->refresh();
        $integration->refresh();

        $this->assertSame('book_stack', $shelf->source_system);
        $this->assertSame('101', $shelf->source_id);
        $this->assertSame('https://docs.example.test/shelves/local-shelf', $shelf->source_url);
        $this->assertSame('book_stack', $book->source_system);
        $this->assertSame('202', $book->source_id);
        $this->assertSame('https://docs.example.test/books/local-book', $book->source_url);
        $this->assertSame('book_stack', $chapter->source_system);
        $this->assertSame('404', $chapter->source_id);
        $this->assertSame('https://docs.example.test/books/local-book/chapter/local-chapter', $chapter->source_url);
        $this->assertSame('book_stack', $article->source_system);
        $this->assertSame('303', $article->source_id);
        $this->assertSame('https://docs.example.test/books/local-book/page/local-page', $article->source_url);
        $this->assertSame('synced', $article->sync_status);
        $this->assertSame(1, $integration->config['last_push_summary']['shelves']);
        $this->assertSame(1, $integration->config['last_push_summary']['books']);
        $this->assertSame(1, $integration->config['last_push_summary']['chapters']);
        $this->assertSame(1, $integration->config['last_push_summary']['pages']);
        $this->assertSame(0, $integration->config['last_push_summary']['failed']);

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://docs.example.test/api/pages'
            && $request['chapter_id'] === 404
            && $request['name'] === 'Local Page'
            && $request['markdown'] === '# Local Page'
        );

        Http::assertSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://docs.example.test/api/chapters'
            && $request['book_id'] === '202'
            && $request['name'] === 'Local Chapter'
        );

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/shelves/101'
            && $request['books'] === [202]
        );
    }

    #[Test]
    public function admin_push_updates_book_stack_owned_pending_pages(): void
    {
        Http::fake([
            'https://docs.example.test/api/pages/303' => Http::response([
                'id' => 303,
                'book_id' => 202,
                'chapter_id' => 404,
                'name' => 'Updated Page',
                'slug' => 'updated-page',
                'html' => '<h1>Updated Page</h1>',
                'markdown' => '# Updated Page',
                'priority' => 7,
                'updated_at' => '2026-05-14T12:10:00.000000Z',
                'book' => [
                    'id' => 202,
                    'slug' => 'local-book',
                ],
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => false,
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $book = Book::create([
            'name' => 'Local Book',
            'slug' => 'local-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '202',
            'sync_status' => 'synced',
            'source_payload' => ['slug' => 'local-book'],
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Updated Chapter',
            'slug' => 'updated-chapter',
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '404',
            'sync_status' => 'synced',
        ]);

        $article = Article::create([
            'title' => 'Updated Page',
            'slug' => 'updated-page',
            'body_markdown' => '# Updated Page',
            'body_html' => '<h1>Old Page</h1>',
            'visibility' => 'internal',
            'status' => 'published',
            'priority' => 7,
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'knowledge_book_id' => $book->id,
            'knowledge_chapter_id' => $chapter->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => '303',
            'sync_status' => 'pending_push',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.push'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $article->refresh();

        $this->assertSame('synced', $article->sync_status);
        $this->assertSame('https://docs.example.test/books/local-book/page/updated-page', $article->source_url);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/pages/303'
            && $request['name'] === 'Updated Page'
            && $request['markdown'] === '# Updated Page'
            && $request['chapter_id'] === 404
            && $request['priority'] === 7
        );

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://docs.example.test/api/pages'
        );
    }

    #[Test]
    public function admin_push_updates_book_stack_owned_pending_books_and_shelf_membership(): void
    {
        Http::fake([
            'https://docs.example.test/api/books/202' => Http::response([
                'id' => 202,
                'name' => 'Moved Book',
                'slug' => 'moved-book',
                'description' => 'Moved book description',
                'updated_at' => '2026-05-14T12:15:00.000000Z',
            ], 200),
            'https://docs.example.test/api/shelves/101' => Http::response([
                'id' => 101,
                'name' => 'Old Shelf',
                'slug' => 'old-shelf',
                'description' => 'Old shelf description',
                'updated_at' => '2026-05-14T12:16:00.000000Z',
                'books' => [],
            ], 200),
            'https://docs.example.test/api/shelves/102' => Http::response([
                'id' => 102,
                'name' => 'New Shelf',
                'slug' => 'new-shelf',
                'description' => 'New shelf description',
                'updated_at' => '2026-05-14T12:17:00.000000Z',
                'books' => [
                    ['id' => 202],
                ],
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => false,
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $oldShelf = Shelf::create([
            'name' => 'Old Shelf',
            'slug' => 'old-shelf',
            'description' => 'Old shelf description',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '101',
            'sync_status' => 'synced',
        ]);
        $newShelf = Shelf::create([
            'name' => 'New Shelf',
            'slug' => 'new-shelf',
            'description' => 'New shelf description',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '102',
            'sync_status' => 'synced',
        ]);
        $book = Book::create([
            'shelf_id' => $newShelf->id,
            'name' => 'Moved Book',
            'slug' => 'moved-book',
            'description' => 'Moved book description',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '202',
            'sync_status' => 'pending_push',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.push'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $book->refresh();
        $integration->refresh();

        $this->assertSame('synced', $book->sync_status);
        $this->assertSame(1, $integration->config['last_push_summary']['books']);
        $this->assertSame(0, $integration->config['last_push_summary']['failed']);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/books/202'
            && $request['name'] === 'Moved Book'
        );

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/shelves/101'
            && $request['books'] === []
        );

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/shelves/102'
            && $request['books'] === [202]
        );
    }

    #[Test]
    public function admin_push_updates_book_stack_owned_pending_shelves(): void
    {
        Http::fake([
            'https://docs.example.test/api/shelves/101' => Http::response([
                'id' => 101,
                'name' => 'Updated Shelf',
                'slug' => 'updated-shelf',
                'description' => 'Updated shelf description',
                'updated_at' => '2026-05-14T12:18:00.000000Z',
                'books' => [
                    ['id' => 202],
                ],
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => false,
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $shelf = Shelf::create([
            'name' => 'Updated Shelf',
            'slug' => 'updated-shelf',
            'description' => 'Updated shelf description',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '101',
            'sync_status' => 'pending_push',
        ]);
        Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Existing Book',
            'slug' => 'existing-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '202',
            'sync_status' => 'synced',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.push'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $shelf->refresh();
        $integration->refresh();

        $this->assertSame('synced', $shelf->sync_status);
        $this->assertSame(1, $integration->config['last_push_summary']['shelves']);
        $this->assertSame(0, $integration->config['last_push_summary']['failed']);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/shelves/101'
            && $request['name'] === 'Updated Shelf'
            && $request['description'] === 'Updated shelf description'
            && $request['books'] === [202]
        );
    }

    #[Test]
    public function admin_push_updates_book_stack_owned_pending_chapters(): void
    {
        Http::fake([
            'https://docs.example.test/api/chapters/404' => Http::response([
                'id' => 404,
                'book_id' => 202,
                'name' => 'Updated Chapter',
                'slug' => 'updated-chapter',
                'description' => 'Updated chapter description',
                'priority' => 8,
                'updated_at' => '2026-05-14T12:20:00.000000Z',
                'book' => [
                    'id' => 202,
                    'slug' => 'local-book',
                ],
            ], 200),
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => false,
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $book = Book::create([
            'name' => 'Local Book',
            'slug' => 'local-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '202',
            'sync_status' => 'synced',
            'source_payload' => ['slug' => 'local-book'],
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Updated Chapter',
            'slug' => 'old-chapter',
            'description' => 'Updated chapter description',
            'priority' => 8,
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '404',
            'sync_status' => 'pending_push',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.push'))
            ->assertRedirect()
            ->assertSessionHas('success');

        $chapter->refresh();
        $integration->refresh();

        $this->assertSame('synced', $chapter->sync_status);
        $this->assertSame('updated-chapter', $chapter->slug);
        $this->assertSame('https://docs.example.test/books/local-book/chapter/updated-chapter', $chapter->source_url);
        $this->assertSame(1, $integration->config['last_push_summary']['chapters']);
        $this->assertSame(0, $integration->config['last_push_summary']['failed']);

        Http::assertSent(fn ($request) => $request->method() === 'PUT'
            && $request->url() === 'https://docs.example.test/api/chapters/404'
            && $request['book_id'] === '202'
            && $request['name'] === 'Updated Chapter'
            && $request['description'] === 'Updated chapter description'
            && $request['priority'] === 8
        );

        Http::assertNotSent(fn ($request) => $request->method() === 'POST'
            && $request->url() === 'https://docs.example.test/api/chapters'
        );
    }

    #[Test]
    public function admin_cannot_push_local_knowledge_content_when_two_way_sync_is_disabled(): void
    {
        Http::fake();

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'sync_interval_minutes' => 10,
                'read_only' => true,
                'two_way_sync_enabled' => false,
                'sync_mode' => 'pull_only',
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.integrations.book_stack.push'))
            ->assertRedirect()
            ->assertSessionHas('warning');

        Http::assertNothingSent();
    }
}
