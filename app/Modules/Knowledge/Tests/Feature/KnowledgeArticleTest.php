<?php

namespace App\Modules\Knowledge\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\Settings\CommonSetting;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Feature tests for the Knowledge module's route-level article workflow.
 *
 * These tests cover the non-Livewire fallback routes. The Livewire component
 * delegates to the same StoreArticle and UpdateArticle actions, so this gives
 * coverage for the shared persistence path once the test database driver is
 * available.
 */
class KnowledgeArticleTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();

        Role::create(['name' => 'Tech']);
        $this->tech = User::create([
            'name' => 'Knowledge Tech',
            'email' => 'knowledge-tech@example.test',
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $this->tech->assignRole('Tech');
    }

    #[Test]
    public function tech_user_can_create_article(): void
    {
        $this->actingAs($this->tech);

        $response = $this->post(route('tech.knowledge.store'), [
            'title' => 'VPN Setup',
            'body_markdown' => '# VPN Setup',
            'visibility' => 'internal',
            'status' => 'published',
            'next_review_at' => now()->addYear()->format('Y-m-d'),
        ]);

        $article = Article::firstOrFail();

        $response->assertRedirect(route('tech.knowledge.show', $article));
        $this->assertSame($this->tech->id, $article->owner_id);
        $this->assertSame($this->tech->id, $article->created_by);
        $this->assertNotEmpty($article->slug);
        $this->assertNotEmpty($article->body_html);
    }

    #[Test]
    public function authenticated_api_user_can_create_list_show_and_update_articles(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.read', 'knowledge.create', 'knowledge.update']);

        $this->postJson(route('api.v1.knowledge.articles.store'), [
            'title' => 'API Knowledge Article',
            'body_markdown' => '# API Knowledge Article',
            'visibility' => 'internal',
            'status' => 'published',
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'API Knowledge Article')
            ->assertJsonPath('data.owner_id', $this->tech->id)
            ->assertJsonPath('data.status', 'published');

        $article = Article::query()->where('title', 'API Knowledge Article')->firstOrFail();

        $this->assertNotEmpty($article->body_html);

        $this->getJson(route('api.v1.knowledge.articles.index', ['q' => 'API Knowledge']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $article->id);

        $this->getJson(route('api.v1.knowledge.articles.show', $article))
            ->assertOk()
            ->assertJsonPath('data.id', $article->id);

        $this->patchJson(route('api.v1.knowledge.articles.update', $article), [
            'title' => 'API Knowledge Article Updated',
            'body_markdown' => 'Updated body.',
            'status' => 'needs_review',
        ])
            ->assertOk()
            ->assertJsonPath('data.title', 'API Knowledge Article Updated')
            ->assertJsonPath('data.status', 'needs_review')
            ->assertJsonPath('data.body_html', "<p>Updated body.</p>\n");
    }

    #[Test]
    public function knowledge_visibility_scope_remains_separate_from_work_context(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.create']);

        $client = Client::factory()->create(['name' => 'Knowledge Scope Client']);

        $this->postJson(route('api.v1.knowledge.articles.store'), [
            'title' => 'Client Visible Runbook',
            'body_markdown' => 'Client scoped content.',
            'visibility' => 'client-wide',
            'client_scope_id' => $client->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'client-wide')
            ->assertJsonPath('data.client_scope_id', $client->id);

        $this->postJson(route('api.v1.knowledge.articles.store'), [
            'title' => 'Public Runbook',
            'body_markdown' => 'Public content.',
            'visibility' => 'public',
            'client_scope_id' => $client->id,
        ])
            ->assertCreated()
            ->assertJsonPath('data.visibility', 'public')
            ->assertJsonPath('data.client_scope_id', null);
    }

    #[Test]
    public function knowledge_read_api_token_cannot_create_articles(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.read']);

        $this->postJson(route('api.v1.knowledge.articles.store'), [
            'title' => 'Blocked Knowledge Article',
            'body_markdown' => 'Blocked.',
        ])->assertForbidden();
    }

    #[Test]
    public function authenticated_api_user_can_manage_knowledge_hierarchy(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->tech, ['knowledge.read', 'knowledge.create', 'knowledge.update']);

        Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);

        $this->postJson(route('api.v1.knowledge.shelves.store'), [
            'name' => 'API Shelf',
            'description' => 'Created by API.',
            'sync_to_book_stack' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API Shelf')
            ->assertJsonPath('data.sync_status', 'pending_push');

        $shelf = Shelf::where('name', 'API Shelf')->firstOrFail();

        $this->postJson(route('api.v1.knowledge.books.store'), [
            'shelf_id' => $shelf->id,
            'name' => 'API Book',
            'priority' => 10,
            'sync_to_book_stack' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API Book')
            ->assertJsonPath('data.shelf_id', $shelf->id)
            ->assertJsonPath('data.sync_status', 'pending_push');

        $book = Book::where('name', 'API Book')->firstOrFail();

        $this->postJson(route('api.v1.knowledge.chapters.store'), [
            'book_id' => $book->id,
            'name' => 'API Chapter',
            'priority' => 20,
            'sync_to_book_stack' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'API Chapter')
            ->assertJsonPath('data.book_id', $book->id)
            ->assertJsonPath('data.sync_status', 'pending_push');

        $chapter = Chapter::where('name', 'API Chapter')->firstOrFail();

        $this->getJson(route('api.v1.knowledge.shelves.index', ['q' => 'API Shelf']))
            ->assertOk()
            ->assertJsonPath('data.0.id', $shelf->id);

        $this->patchJson(route('api.v1.knowledge.chapters.update', $chapter), [
            'description' => 'Updated by API.',
        ])
            ->assertOk()
            ->assertJsonPath('data.description', 'Updated by API.');

        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function knowledge_api_rejects_book_stack_sync_request_when_two_way_sync_is_disabled(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.create']);

        $this->postJson(route('api.v1.knowledge.shelves.store'), [
            'name' => 'Unsynced Shelf',
            'sync_to_book_stack' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sync_to_book_stack');
    }

    #[Test]
    public function article_api_can_mark_local_hierarchy_for_book_stack_push(): void
    {
        Queue::fake();
        Sanctum::actingAs($this->tech, ['knowledge.read', 'knowledge.create', 'knowledge.update']);

        Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
            ],
        ]);

        $shelf = Shelf::create([
            'name' => 'Local Shelf',
            'slug' => 'local-shelf',
            'sync_status' => 'local',
        ]);
        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Local Book',
            'slug' => 'local-book',
            'sync_status' => 'local',
        ]);
        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Local Chapter',
            'slug' => 'local-chapter',
            'sync_status' => 'local',
        ]);

        $this->postJson(route('api.v1.knowledge.articles.store'), [
            'title' => 'Pushable API Article',
            'body_markdown' => 'Ready for BookStack.',
            'knowledge_chapter_id' => $chapter->id,
            'visibility' => 'internal',
            'status' => 'published',
            'sync_to_book_stack' => true,
        ])
            ->assertCreated()
            ->assertJsonPath('data.sync_status', 'pending_push');

        $article = Article::where('title', 'Pushable API Article')->firstOrFail();

        $this->assertSame('pending_push', $article->sync_status);
        $this->assertSame('pending_push', $chapter->fresh()->sync_status);
        $this->assertSame('pending_push', $book->fresh()->sync_status);
        $this->assertSame('pending_push', $shelf->fresh()->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function article_api_rejects_book_stack_owned_update_when_two_way_sync_is_disabled(): void
    {
        Sanctum::actingAs($this->tech, ['knowledge.update']);

        $article = Article::create([
            'title' => 'Synced Page',
            'slug' => 'synced-page',
            'body_markdown' => 'Synced.',
            'body_html' => '<p>Synced.</p>',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => '123',
        ]);

        $this->patchJson(route('api.v1.knowledge.articles.update', $article), [
            'title' => 'Blocked Update',
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('book_stack');
    }

    #[Test]
    public function admin_can_update_knowledge_article_defaults(): void
    {
        $this->actingAs($this->tech)
            ->get(route('tech.admin.settings.knowledge'))
            ->assertOk()
            ->assertViewIs('knowledge::Admin.Settings.edit')
            ->assertSee('Knowledge Settings')
            ->assertSee('Article Defaults');

        $this->actingAs($this->tech)
            ->put(route('tech.admin.settings.knowledge.update'), [
                'default_visibility' => 'client-wide',
                'default_status' => 'draft',
                'default_review_days' => 90,
                'default_priority' => 25,
            ])
            ->assertRedirect(route('tech.admin.settings.knowledge'));

        $settings = json_decode(CommonSetting::query()->where('type', 'knowledge')->where('name', 'defaults')->value('json'), true);

        $this->assertSame('client-wide', $settings['default_visibility']);
        $this->assertSame('draft', $settings['default_status']);
        $this->assertSame(90, $settings['default_review_days']);
        $this->assertSame(25, $settings['default_priority']);
    }

    #[Test]
    public function article_creation_uses_configured_defaults(): void
    {
        $this->actingAs($this->tech);

        $this->put(route('tech.admin.settings.knowledge.update'), [
            'default_visibility' => 'public',
            'default_status' => 'needs_review',
            'default_review_days' => 30,
            'default_priority' => 15,
        ])->assertRedirect(route('tech.admin.settings.knowledge'));

        $this->post(route('tech.knowledge.store'), [
            'title' => 'Defaulted Knowledge Page',
            'body_markdown' => 'Uses configured defaults.',
        ])->assertRedirect();

        $article = Article::query()->where('title', 'Defaulted Knowledge Page')->firstOrFail();

        $this->assertSame('public', $article->visibility);
        $this->assertSame('needs_review', $article->status);
        $this->assertSame(15, $article->priority);
        $this->assertTrue($article->next_review_at->isSameDay(now()->addDays(30)));
    }

    #[Test]
    public function tech_user_can_browse_knowledge_library_books(): void
    {
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'Operations',
            'slug' => 'operations',
        ]);

        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
        ]);

        Article::create([
            'title' => 'VPN Setup',
            'slug' => 'vpn-setup',
            'body_markdown' => 'VPN setup steps',
            'body_html' => 'VPN setup steps',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'knowledge_shelf_id' => $shelf->id,
            'knowledge_book_id' => $book->id,
        ]);

        $this->get(route('tech.knowledge.index'))
            ->assertOk()
            ->assertSee('<h1>Knowledge</h1>', false)
            ->assertSee('Dashboard')
            ->assertSee('Operations')
            ->assertSee('New Shelf')
            ->assertSee('Runbooks')
            ->assertSee('Library Status')
            ->assertSee('Shelves')
            ->assertSee('Books')
            ->assertDontSee('Shelves, books, chapters, and pages')
            ->assertDontSee('Sync Now');

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => false,
                'sync_mode' => 'pull_only',
                'read_only' => true,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->get(route('tech.knowledge.index'))
            ->assertOk()
            ->assertSee('Sync Now');

        $this->get(route('tech.knowledge.shelf', $shelf))
            ->assertOk()
            ->assertSee('New Book');

        $this->get(route('tech.knowledge.book', $book))
            ->assertOk()
            ->assertSee('New Chapter')
            ->assertSee('New Page')
            ->assertSee('VPN Setup');
    }

    #[Test]
    public function tech_user_can_create_shelf_and_book(): void
    {
        $this->actingAs($this->tech);

        $shelfResponse = $this->post(route('tech.knowledge.shelves.store'), [
            'name' => 'Customer Documentation',
            'description' => 'Shared customer runbooks and standards.',
        ]);

        $shelf = Shelf::firstOrFail();

        $shelfResponse->assertRedirect(route('tech.knowledge.shelf', $shelf));
        $this->assertSame('Customer Documentation', $shelf->name);
        $this->assertSame('customer-documentation', $shelf->slug);
        $this->assertSame('local', $shelf->sync_status);

        $bookResponse = $this->post(route('tech.knowledge.books.store', $shelf), [
            'name' => 'Onboarding',
            'description' => 'Customer onboarding pages.',
            'priority' => 10,
        ]);

        $book = Book::firstOrFail();

        $bookResponse->assertRedirect(route('tech.knowledge.book', $book));
        $this->assertSame($shelf->id, $book->shelf_id);
        $this->assertSame('onboarding', $book->slug);
        $this->assertSame(10, $book->priority);
        $this->assertSame('local', $book->sync_status);
    }

    #[Test]
    public function tech_user_can_edit_book_and_move_it_to_another_shelf(): void
    {
        $this->actingAs($this->tech);

        $sourceShelf = Shelf::create([
            'name' => 'Old Shelf',
            'slug' => 'old-shelf',
        ]);
        $targetShelf = Shelf::create([
            'name' => 'New Shelf',
            'slug' => 'new-shelf',
        ]);
        $book = Book::create([
            'shelf_id' => $sourceShelf->id,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'description' => 'Old text',
            'priority' => 1,
            'sync_status' => 'local',
        ]);

        $this->get(route('tech.knowledge.book', $book))
            ->assertOk()
            ->assertSee('Edit Book');

        $this->get(route('tech.knowledge.books.edit', $book))
            ->assertOk()
            ->assertSee('Save Book')
            ->assertSee('New Shelf');

        $this->put(route('tech.knowledge.books.update', $book), [
            'shelf_id' => $targetShelf->id,
            'name' => 'Runbooks Updated',
            'description' => 'Updated text',
            'priority' => 9,
        ])->assertRedirect(route('tech.knowledge.book', $book));

        $book->refresh();

        $this->assertSame($targetShelf->id, $book->shelf_id);
        $this->assertSame('Runbooks Updated', $book->name);
        $this->assertSame('Updated text', $book->description);
        $this->assertSame(9, $book->priority);
        $this->assertSame('local', $book->sync_status);
    }

    #[Test]
    public function book_stack_sync_switch_is_only_visible_when_two_way_sync_is_active(): void
    {
        $this->actingAs($this->tech);

        $this->get(route('tech.knowledge.shelves.create'))
            ->assertOk()
            ->assertDontSee('Sync to BookStack');

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);

        $shelf = Shelf::create([
            'name' => 'Operations',
            'slug' => 'operations',
        ]);

        $this->get(route('tech.knowledge.shelves.create'))
            ->assertOk()
            ->assertSee('Sync to BookStack')
            ->assertSee('name="sync_to_book_stack" value="1" checked', false);

        $this->get(route('tech.knowledge.books.create', $shelf))
            ->assertOk()
            ->assertSee('Sync to BookStack')
            ->assertSee('name="sync_to_book_stack" value="1" checked', false);
    }

    #[Test]
    public function checked_book_stack_sync_switch_marks_records_for_worker_push(): void
    {
        Queue::fake();
        $this->actingAs($this->tech);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);

        $this->post(route('tech.knowledge.shelves.store'), [
            'name' => 'Queued Shelf',
            'description' => 'Should be pushed.',
            'sync_to_book_stack' => '1',
        ])->assertRedirect();

        $shelf = Shelf::where('name', 'Queued Shelf')->firstOrFail();

        $this->assertSame('pending_push', $shelf->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);

        $this->post(route('tech.knowledge.books.store', $shelf), [
            'name' => 'Queued Book',
            'description' => 'Should be pushed.',
            'priority' => 5,
            'sync_to_book_stack' => '1',
        ])->assertRedirect();

        $book = Book::where('name', 'Queued Book')->firstOrFail();

        $this->assertSame('pending_push', $book->sync_status);
        $this->assertSame('pending_push', $shelf->fresh()->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class, 2);

        $this->post(route('tech.knowledge.chapters.store', $book), [
            'name' => 'Queued Chapter',
            'description' => 'Should be pushed.',
            'priority' => 10,
            'sync_to_book_stack' => '1',
        ])->assertRedirect();

        $chapter = Chapter::where('name', 'Queued Chapter')->firstOrFail();

        $this->assertSame('pending_push', $chapter->sync_status);
        $this->assertSame('pending_push', $book->fresh()->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class, 3);
    }

    #[Test]
    public function repository_documentation_sync_updates_knowledge_and_can_queue_book_stack_push(): void
    {
        Queue::fake();

        $this->artisan('knowledge:sync-docs', ['--module' => ['System'], '--push' => true])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 5')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'system/company-profile-and-branding')
            ->firstOrFail();

        $this->assertSame('Company Profile And Branding', $article->title);
        $this->assertSame('pending_push', $article->sync_status);
        $this->assertSame('System', $article->source_payload['module']);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function repository_documentation_sync_preserves_book_stack_backed_pages(): void
    {
        $book = Book::create([
            'name' => 'Nexum PSA',
            'slug' => 'bookstack-book-nexum-psa-339',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '339',
            'sync_status' => 'synced',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'System',
            'slug' => 'system',
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '55',
            'sync_status' => 'synced',
        ]);

        $article = Article::create([
            'title' => 'Old Branding Page',
            'slug' => 'company-profile-and-branding',
            'body_markdown' => 'Old body',
            'body_html' => '<p>Old body</p>',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'knowledge_book_id' => $book->id,
            'knowledge_chapter_id' => $chapter->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => '99',
            'sync_status' => 'synced',
        ]);

        $this->artisan('knowledge:sync-docs', ['--module' => ['System']])
            ->assertSuccessful();

        $article->refresh();

        $this->assertSame('Company Profile And Branding', $article->title);
        $this->assertSame('book_stack', $article->source_system);
        $this->assertSame('page', $article->source_type);
        $this->assertSame('99', $article->source_id);
        $this->assertSame('pending_push', $article->sync_status);
        $this->assertSame('system/company-profile-and-branding', $article->source_payload['repository_source_id']);
    }

    #[Test]
    public function repository_documentation_sync_includes_lead_intelligence_docs(): void
    {
        $this->artisan('knowledge:sync-docs', ['--module' => ['LeadIntelligence']])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 1')
            ->expectsOutput('modules: LeadIntelligence')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'lead-intelligence/lead-intelligence-overview')
            ->firstOrFail();

        $this->assertSame('Lead Intelligence Overview', $article->title);
        $this->assertSame('LeadIntelligence', $article->source_payload['module']);
    }

    #[Test]
    public function repository_documentation_sync_includes_work_context_docs(): void
    {
        $this->artisan('knowledge:sync-docs', ['--module' => ['WorkContext']])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 1')
            ->expectsOutput('modules: WorkContext')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'work-context/work-context-foundation')
            ->firstOrFail();

        $this->assertSame('Work Context Foundation', $article->title);
        $this->assertSame('WorkContext', $article->source_payload['module']);
    }

    #[Test]
    public function repository_documentation_sync_includes_signal_docs(): void
    {
        $this->artisan('knowledge:sync-docs', ['--module' => ['Signal']])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 1')
            ->expectsOutput('modules: Signal')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'signals/signal-domain-overview')
            ->firstOrFail();

        $this->assertSame('Signal Domain Overview', $article->title);
        $this->assertSame('Signal', $article->source_payload['module']);
    }

    #[Test]
    public function repository_documentation_sync_includes_documentation_docs(): void
    {
        $this->artisan('knowledge:sync-docs', ['--module' => ['Documentation']])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 1')
            ->expectsOutput('modules: Documentation')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'documentation/documentation-overview')
            ->firstOrFail();

        $this->assertSame('Documentation Overview', $article->title);
        $this->assertSame('Documentation', $article->source_payload['module']);
    }

    #[Test]
    public function repository_documentation_sync_includes_relationship_docs_and_can_queue_bookstack_push(): void
    {
        Queue::fake();

        $this->artisan('knowledge:sync-docs', ['--module' => ['Relationship'], '--push' => true])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 2')
            ->expectsOutput('modules: Relationship')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'relationships/nexum-relationships')
            ->firstOrFail();

        $this->assertSame('Nexum Relationships', $article->title);
        $this->assertSame('Relationship', $article->source_payload['module']);
        $this->assertSame('pending_push', $article->sync_status);

        $testPlan = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'relationships/two-instance-test-plan')
            ->firstOrFail();

        $this->assertSame('Nexum Relationship Two-Instance Test Plan', $testPlan->title);
        $this->assertSame('Relationship', $testPlan->source_payload['module']);
        $this->assertSame('pending_push', $testPlan->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function repository_documentation_sync_includes_sales_docs(): void
    {
        $this->artisan('knowledge:sync-docs', ['--module' => ['Sales']])
            ->expectsOutput('chapters: 1')
            ->expectsOutput('articles: 2')
            ->expectsOutput('modules: Sales')
            ->assertSuccessful();

        $article = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'sales/sales-api')
            ->firstOrFail();

        $this->assertSame('Sales API', $article->title);
        $this->assertSame('Sales', $article->source_payload['module']);

        $overview = Article::where('source_system', 'nexum')
            ->where('source_type', 'repository-docs')
            ->where('source_id', 'sales/sales-overview')
            ->firstOrFail();

        $this->assertSame('Sales Overview', $overview->title);
        $this->assertSame('Sales', $overview->source_payload['module']);
    }

    #[Test]
    public function tech_user_can_create_chapter_inside_book(): void
    {
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'Operations',
            'slug' => 'operations',
        ]);

        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
        ]);

        $this->get(route('tech.knowledge.chapters.create', $book))
            ->assertOk()
            ->assertSee('Create Chapter')
            ->assertSee('Runbooks');

        $response = $this->post(route('tech.knowledge.chapters.store', $book), [
            'name' => 'Network',
            'description' => 'Network runbook pages.',
            'priority' => 20,
        ]);

        $chapter = Chapter::firstOrFail();

        $response->assertRedirect(route('tech.knowledge.book', $book));
        $this->assertSame($book->id, $chapter->book_id);
        $this->assertSame('Network', $chapter->name);
        $this->assertSame('network', $chapter->slug);
        $this->assertSame(20, $chapter->priority);
        $this->assertSame('local', $chapter->sync_status);
    }

    #[Test]
    public function tech_user_can_edit_and_delete_empty_local_chapter_from_form(): void
    {
        $this->actingAs($this->tech);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Network',
            'slug' => 'network',
            'description' => 'Old text',
            'priority' => 5,
            'sync_status' => 'local',
        ]);

        $this->get(route('tech.knowledge.book', $book))
            ->assertOk()
            ->assertSee('Edit');

        $this->get(route('tech.knowledge.chapters.edit', $chapter))
            ->assertOk()
            ->assertSee('Save Chapter')
            ->assertSee('Confirm Deletion', false);

        $this->put(route('tech.knowledge.chapters.update', $chapter), [
            'name' => 'Network Updated',
            'description' => 'Updated text',
            'priority' => 9,
        ])->assertRedirect(route('tech.knowledge.book', $book));

        $this->assertSame('Network Updated', $chapter->fresh()->name);
        $this->assertSame(9, $chapter->fresh()->priority);

        $this->delete(route('tech.knowledge.chapters.destroy', $chapter))
            ->assertRedirect(route('tech.knowledge.book', $book));

        $this->assertDatabaseMissing('knowledge_chapters', ['id' => $chapter->id]);
    }

    #[Test]
    public function chapter_with_pages_cannot_be_deleted(): void
    {
        $this->actingAs($this->tech);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Network',
            'slug' => 'network',
            'sync_status' => 'local',
        ]);

        Article::create([
            'title' => 'VPN Setup',
            'slug' => 'vpn-setup',
            'body_markdown' => 'VPN setup steps',
            'body_html' => 'VPN setup steps',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'knowledge_book_id' => $book->id,
            'knowledge_chapter_id' => $chapter->id,
        ]);

        $this->get(route('tech.knowledge.chapters.edit', $chapter))
            ->assertOk()
            ->assertDontSee('Confirm Deletion', false)
            ->assertSee('Delete is available when the chapter has no pages.');

        $this->delete(route('tech.knowledge.chapters.destroy', $chapter))
            ->assertRedirect(route('tech.knowledge.chapters.edit', $chapter))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('knowledge_chapters', ['id' => $chapter->id]);
    }

    #[Test]
    public function book_stack_owned_empty_chapter_can_be_deleted_when_two_way_sync_is_enabled(): void
    {
        Http::fake([
            'https://docs.example.test/api/chapters/10' => Http::response(null, 204),
        ]);
        $this->actingAs($this->tech);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Empty Synced Chapter',
            'slug' => 'empty-synced-chapter',
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '10',
            'sync_status' => 'synced',
        ]);

        $this->get(route('tech.knowledge.chapters.edit', $chapter))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('warning');

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->get(route('tech.knowledge.chapters.edit', $chapter))
            ->assertOk()
            ->assertSee('Confirm Deletion', false);

        $this->delete(route('tech.knowledge.chapters.destroy', $chapter))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('knowledge_chapters', ['id' => $chapter->id]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://docs.example.test/api/chapters/10'
        );
    }

    #[Test]
    public function missing_book_stack_chapter_is_deleted_locally_when_empty(): void
    {
        Http::fake([
            'https://docs.example.test/api/chapters/340' => Http::response([
                'message' => 'No query results for model [BookStack\Entities\Models\Chapter] 340',
            ], 404),
        ]);
        $this->actingAs($this->tech);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Missing Synced Chapter',
            'slug' => 'missing-synced-chapter',
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '340',
            'sync_status' => 'synced',
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->delete(route('tech.knowledge.chapters.destroy', $chapter))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('knowledge_chapters', ['id' => $chapter->id]);
    }

    #[Test]
    public function tech_user_can_delete_empty_local_shelf_and_book(): void
    {
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'Temporary Shelf',
            'slug' => 'temporary-shelf',
            'sync_status' => 'local',
        ]);

        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Temporary Book',
            'slug' => 'temporary-book',
            'sync_status' => 'local',
        ]);

        $this->get(route('tech.knowledge.shelf', $shelf))
            ->assertOk()
            ->assertSee('Edit Shelf');

        $this->put(route('tech.knowledge.shelves.update', $shelf), [
            'name' => 'Temporary Shelf Updated',
            'description' => 'Updated shelf text',
        ])->assertRedirect(route('tech.knowledge.shelf', $shelf));

        $shelf->refresh();

        $this->assertSame('Temporary Shelf Updated', $shelf->name);
        $this->assertSame('Updated shelf text', $shelf->description);
        $this->assertSame('local', $shelf->sync_status);

        $this->get(route('tech.knowledge.book', $book))
            ->assertOk()
            ->assertDontSee('Confirm Deletion', false);

        $this->get(route('tech.knowledge.books.edit', $book))
            ->assertOk()
            ->assertSee('Delete Book')
            ->assertSee('Confirm Deletion', false);

        $this->delete(route('tech.knowledge.books.destroy', $book))
            ->assertRedirect(route('tech.knowledge.shelf', $shelf));

        $this->assertDatabaseMissing('knowledge_books', ['id' => $book->id]);

        $this->get(route('tech.knowledge.shelf', $shelf))
            ->assertOk()
            ->assertDontSee('Delete is available from Edit Shelf.')
            ->assertDontSee('Confirm Deletion', false);

        $this->get(route('tech.knowledge.shelves.edit', $shelf))
            ->assertOk()
            ->assertSee('Delete Shelf')
            ->assertSee('Confirm Deletion', false);

        $this->delete(route('tech.knowledge.shelves.destroy', $shelf))
            ->assertRedirect(route('tech.knowledge.index'));

        $this->assertDatabaseMissing('knowledge_shelves', ['id' => $shelf->id]);
    }

    #[Test]
    public function book_stack_owned_empty_shelf_edit_and_delete_requires_two_way_sync(): void
    {
        Queue::fake();
        Http::fake([
            'https://docs.example.test/api/shelves/10' => Http::response(null, 204),
        ]);
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'BookStack Shelf',
            'slug' => 'bookstack-shelf',
            'description' => 'Old text',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '10',
            'sync_status' => 'synced',
        ]);

        $this->get(route('tech.knowledge.shelves.edit', $shelf))
            ->assertRedirect(route('tech.knowledge.shelf', $shelf))
            ->assertSessionHas('warning');

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->put(route('tech.knowledge.shelves.update', $shelf), [
            'name' => 'BookStack Shelf Updated',
            'description' => 'Updated text',
        ])->assertRedirect(route('tech.knowledge.shelf', $shelf));

        $shelf->refresh();

        $this->assertSame('BookStack Shelf Updated', $shelf->name);
        $this->assertSame('pending_push', $shelf->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);

        $this->get(route('tech.knowledge.shelves.edit', $shelf))
            ->assertOk()
            ->assertSee('Delete Shelf')
            ->assertSee('Confirm Deletion', false);

        $this->delete(route('tech.knowledge.shelves.destroy', $shelf))
            ->assertRedirect(route('tech.knowledge.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('knowledge_shelves', ['id' => $shelf->id]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://docs.example.test/api/shelves/10'
        );
    }

    #[Test]
    public function book_stack_owned_empty_book_can_be_deleted_when_two_way_sync_is_enabled(): void
    {
        Http::fake([
            'https://docs.example.test/api/books/20' => Http::response(null, 204),
        ]);
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'BookStack Shelf',
            'slug' => 'bookstack-shelf',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '10',
            'sync_status' => 'synced',
        ]);
        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Empty Synced Book',
            'slug' => 'empty-synced-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '20',
            'sync_status' => 'synced',
        ]);

        $this->get(route('tech.knowledge.books.edit', $book))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('warning');

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->get(route('tech.knowledge.book', $book))
            ->assertOk()
            ->assertDontSee('Confirm Deletion', false);

        $this->get(route('tech.knowledge.books.edit', $book))
            ->assertOk()
            ->assertSee('Delete Book')
            ->assertSee('Confirm Deletion', false);

        $this->delete(route('tech.knowledge.books.destroy', $book))
            ->assertRedirect(route('tech.knowledge.shelf', $shelf))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('knowledge_books', ['id' => $book->id]);
        Http::assertSent(fn ($request) => $request->method() === 'DELETE'
            && $request->url() === 'https://docs.example.test/api/books/20'
        );
    }

    #[Test]
    public function missing_book_stack_book_is_deleted_locally_when_empty(): void
    {
        Http::fake([
            'https://docs.example.test/api/books/20' => Http::response([
                'message' => 'No query results for model [BookStack\Entities\Models\Book] 20',
            ], 404),
        ]);
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'BookStack Shelf',
            'slug' => 'bookstack-shelf',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '10',
            'sync_status' => 'synced',
        ]);
        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Missing Synced Book',
            'slug' => 'missing-synced-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '20',
            'sync_status' => 'synced',
        ]);

        $integration = Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);
        $integration->setSecret('token_id', 'token-id');
        $integration->setSecret('token_secret', 'token-secret');
        $integration->save();

        $this->delete(route('tech.knowledge.books.destroy', $book))
            ->assertRedirect(route('tech.knowledge.shelf', $shelf))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('knowledge_books', ['id' => $book->id]);
    }

    #[Test]
    public function synced_knowledge_items_link_to_book_stack_instead_of_local_delete(): void
    {
        $this->actingAs($this->tech);

        $shelf = Shelf::create([
            'name' => 'Operations',
            'slug' => 'operations',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '1',
            'source_url' => 'https://docs.example.test/shelves/operations',
        ]);

        $book = Book::create([
            'shelf_id' => $shelf->id,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
            'source_url' => 'https://docs.example.test/books/runbooks',
        ]);

        $article = Article::create([
            'title' => 'VPN Setup',
            'slug' => 'vpn-setup',
            'body_markdown' => 'VPN setup steps',
            'body_html' => 'VPN setup steps',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'knowledge_shelf_id' => $shelf->id,
            'knowledge_book_id' => $book->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => '3',
            'source_url' => 'https://docs.example.test/books/runbooks/page/vpn-setup',
        ]);

        $this->get(route('tech.knowledge.shelf', $shelf))
            ->assertOk()
            ->assertSee('Open in BookStack')
            ->assertSee('Delete is available when the synced shelf has no books.');

        $this->get(route('tech.knowledge.book', $book))
            ->assertOk()
            ->assertSee('Open in BookStack')
            ->assertSee('Delete is available when the synced book has no chapters or pages.');

        $this->get(route('tech.knowledge.show', $article))
            ->assertOk()
            ->assertSee('Open in BookStack')
            ->assertDontSee('Edit</a>', false);

        $this->delete(route('tech.knowledge.destroy', $article))
            ->assertRedirect(route('tech.knowledge.show', $article))
            ->assertSessionHas('warning');

        $this->delete(route('tech.knowledge.books.destroy', $book))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('warning');

        $this->delete(route('tech.knowledge.shelves.destroy', $shelf))
            ->assertRedirect(route('tech.knowledge.shelf', $shelf))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('articles', ['id' => $article->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('knowledge_books', ['id' => $book->id]);
        $this->assertDatabaseHas('knowledge_shelves', ['id' => $shelf->id]);
    }

    #[Test]
    public function book_stack_owned_page_edit_requires_two_way_sync_and_queues_push(): void
    {
        Queue::fake();
        $this->actingAs($this->tech);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
        ]);

        $article = Article::create([
            'title' => 'VPN Setup',
            'slug' => 'vpn-setup',
            'body_markdown' => 'VPN setup steps',
            'body_html' => 'VPN setup steps',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
            'knowledge_book_id' => $book->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => '3',
        ]);

        $this->get(route('tech.knowledge.edit', $article))
            ->assertRedirect(route('tech.knowledge.show', $article))
            ->assertSessionHas('warning');

        Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);

        $this->get(route('tech.knowledge.show', $article))
            ->assertOk()
            ->assertSee(route('tech.knowledge.edit', $article), false)
            ->assertSee('Edit');

        $this->put(route('tech.knowledge.update', $article), [
            'title' => 'VPN Setup Updated',
            'body_markdown' => 'Updated VPN setup steps',
            'visibility' => 'internal',
            'status' => 'published',
            'knowledge_book_id' => $book->id,
        ])->assertRedirect(route('tech.knowledge.show', $article));

        $this->assertSame('pending_push', $article->fresh()->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function book_stack_owned_book_edit_requires_two_way_sync_and_queues_push(): void
    {
        Queue::fake();
        $this->actingAs($this->tech);

        $sourceShelf = Shelf::create([
            'name' => 'Old Shelf',
            'slug' => 'old-shelf',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '10',
        ]);
        $targetShelf = Shelf::create([
            'name' => 'New Shelf',
            'slug' => 'new-shelf',
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => '11',
        ]);
        $book = Book::create([
            'shelf_id' => $sourceShelf->id,
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
            'sync_status' => 'synced',
        ]);

        $this->get(route('tech.knowledge.books.edit', $book))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('warning');

        Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);

        $this->put(route('tech.knowledge.books.update', $book), [
            'shelf_id' => $targetShelf->id,
            'name' => 'Runbooks Updated',
            'description' => 'Updated text',
            'priority' => 9,
        ])->assertRedirect(route('tech.knowledge.book', $book));

        $book->refresh();

        $this->assertSame($targetShelf->id, $book->shelf_id);
        $this->assertSame('Runbooks Updated', $book->name);
        $this->assertSame('pending_push', $book->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function local_page_inside_book_stack_hierarchy_queues_push_on_create_and_update(): void
    {
        Queue::fake();
        $this->actingAs($this->tech);

        Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Network',
            'slug' => 'network',
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '3',
            'sync_status' => 'synced',
        ]);

        $this->post(route('tech.knowledge.store'), [
            'title' => 'New Synced Page',
            'body_markdown' => 'Initial text',
            'visibility' => 'internal',
            'status' => 'published',
            'knowledge_chapter_id' => $chapter->id,
        ])->assertRedirect();

        $article = Article::where('title', 'New Synced Page')->firstOrFail();

        $this->assertSame('pending_push', $article->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);

        $article->forceFill(['sync_status' => 'local'])->save();

        $this->put(route('tech.knowledge.update', $article), [
            'title' => 'New Synced Page Updated',
            'body_markdown' => 'Updated text',
            'visibility' => 'internal',
            'status' => 'published',
            'knowledge_chapter_id' => $chapter->id,
        ])->assertRedirect(route('tech.knowledge.show', $article));

        $this->assertSame('pending_push', $article->fresh()->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class, 2);
    }

    #[Test]
    public function book_stack_owned_chapter_edit_requires_two_way_sync_and_queues_push(): void
    {
        Queue::fake();
        $this->actingAs($this->tech);

        $book = Book::create([
            'name' => 'Runbooks',
            'slug' => 'runbooks',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '2',
        ]);

        $chapter = Chapter::create([
            'book_id' => $book->id,
            'name' => 'Network',
            'slug' => 'network',
            'description' => 'Old text',
            'priority' => 5,
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => '3',
            'sync_status' => 'synced',
        ]);

        $this->get(route('tech.knowledge.chapters.edit', $chapter))
            ->assertRedirect(route('tech.knowledge.book', $book))
            ->assertSessionHas('warning');

        Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'config' => [
                'two_way_sync_enabled' => true,
                'sync_mode' => 'two_way',
                'read_only' => false,
            ],
        ]);

        $this->put(route('tech.knowledge.chapters.update', $chapter), [
            'name' => 'Network Updated',
            'description' => 'Updated text',
            'priority' => 9,
        ])->assertRedirect(route('tech.knowledge.book', $book));

        $chapter->refresh();

        $this->assertSame('Network Updated', $chapter->name);
        $this->assertSame('Updated text', $chapter->description);
        $this->assertSame(9, $chapter->priority);
        $this->assertSame('pending_push', $chapter->sync_status);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class);
    }

    #[Test]
    public function showing_article_increments_view_count(): void
    {
        $this->actingAs($this->tech);

        $article = Article::create([
            'title' => 'Password Reset',
            'slug' => 'password-reset',
            'body_markdown' => 'Reset steps',
            'body_html' => 'Reset steps',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->tech->id,
            'created_by' => $this->tech->id,
        ]);

        $this->get(route('tech.knowledge.show', $article))->assertOk();

        $this->assertSame(1, $article->fresh()->view_count);
    }
}
