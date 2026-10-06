<?php

namespace App\Modules\Integration\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Models\Knowledge\Book;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Actions\PushKnowledgeToBookStack;
use App\Modules\Integration\Actions\SyncBookStackToKnowledge;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookStackRevisionSafetyTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $role = Role::create(['name' => 'Admin']);
        foreach (['knowledge.view', 'knowledge.update', 'knowledge.manage_drafts'] as $name) {
            $role->givePermissionTo(Permission::findOrCreate($name));
        }

        $this->admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->admin->assignRole($role);
    }

    #[Test]
    public function clean_inbound_change_move_and_loop_fast_forward_from_exact_base(): void
    {
        $integration = $this->integration(['automatic_inbound_sync_enabled' => true]);
        $first = $this->page('Initial title', 'Initial body');
        $moved = $this->page('Renamed remotely', 'Remote body', chapterId: 8);
        $chapter = [
            'id' => 8,
            'book_id' => 7,
            'name' => 'Moved chapter',
            'slug' => 'moved-chapter',
            'priority' => 1,
        ];
        $client = Mockery::mock(BookStackClient::class);
        $client->shouldReceive('allShelves')->times(3)->andReturn([]);
        $client->shouldReceive('allBooks')->times(3)->andReturn([$this->bookPayload()]);
        $client->shouldReceive('allChapters')->times(3)->andReturn([], [$chapter], [$chapter]);
        $client->shouldReceive('allPages')->times(3)->andReturn([['id' => 42]], [['id' => 42]], [['id' => 42]]);
        $client->shouldReceive('readPage')->times(3)->andReturn($first, $moved, $moved);

        $created = (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $updated = (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $loop = (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();

        $article = Article::where('source_id', '42')->firstOrFail();
        $state = $article->bookStackSyncState()->firstOrFail();

        $this->assertSame(1, $created['created']);
        $this->assertSame(1, $updated['updated']);
        $this->assertSame(1, $loop['skipped']);
        $this->assertSame('Renamed remotely', $article->title);
        $this->assertSame('Remote body', $article->body_markdown);
        $this->assertSame('8', $article->knowledgeChapter?->source_id);
        $this->assertSame(ArticleBookStackSyncState::STATUS_SYNCED, $state->status);
        $this->assertSame(2, $article->revisions()->count());
    }

    #[Test]
    public function simultaneous_edits_create_candidate_and_explicit_acceptance_creates_revision(): void
    {
        $integration = $this->integration(['automatic_inbound_sync_enabled' => true]);
        $initial = $this->page('Shared title', 'Shared body');
        $remote = $this->page('Remote title', 'Remote change');
        $client = $this->pullClient([$initial, $remote]);

        (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $article = Article::where('source_id', '42')->firstOrFail();
        $article->forceFill([
            'title' => 'Local title',
            'body_markdown' => 'Local change',
            'body_html' => '<p>Local change</p>',
            'sync_status' => 'pending_push',
            'updated_by' => $this->admin->id,
        ])->save();

        $summary = (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $article->refresh();
        $state = $article->bookStackSyncState()->with('candidateRevision')->firstOrFail();

        $this->assertSame(1, $summary['conflicts']);
        $this->assertSame('Local change', $article->body_markdown);
        $this->actingAs($this->admin)
            ->get(route('tech.knowledge.show', $article))
            ->assertOk()
            ->assertSeeText('BookStack synchronization review')
            ->assertSeeText('Local change')
            ->assertSeeText('Remote change')
            ->assertSeeText('Accept BookStack candidate');

        $this->assertSame(ArticleBookStackSyncState::STATUS_CONFLICT, $state->status);
        $this->assertSame('Remote change', $state->candidateRevision?->body_markdown);

        $candidate = $state->candidateRevision;
        $this->actingAs($this->admin)
            ->post(route('tech.knowledge.book-stack.accept-remote', $article))
            ->assertRedirect(route('tech.knowledge.revisions.show', $candidate))
            ->assertSessionHas('success');

        $article->refresh();
        $this->assertSame('Local change', $article->body_markdown);
        $this->assertSame('conflict', $article->sync_status);
        $this->assertSame(ArticleBookStackSyncState::STATUS_CONFLICT, $article->bookStackSyncState->status);
        $this->assertSame('ready_for_review', $candidate->refresh()->state);
        $this->assertSame(3, $article->revisions()->count());
    }

    #[Test]
    public function disabled_automatic_inbound_keeps_clean_remote_change_as_candidate(): void
    {
        $integration = $this->integration(['automatic_inbound_sync_enabled' => false]);
        $initial = $this->page('Shared title', 'Shared body');
        $remote = $this->page('Remote title', 'Remote change');
        $client = $this->pullClient([$initial, $remote]);

        (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $summary = (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();

        $article = Article::where('source_id', '42')->firstOrFail();
        $state = $article->bookStackSyncState()->with('candidateRevision')->firstOrFail();

        $this->assertSame(1, $summary['candidates']);
        $this->assertSame('Shared body', $article->body_markdown);
        $this->assertSame(ArticleBookStackSyncState::STATUS_PENDING_INBOUND, $state->status);
        $this->assertSame('Remote change', $state->candidateRevision?->body_markdown);
    }

    #[Test]
    public function stale_outbound_job_never_overwrites_newer_nexum_revision(): void
    {
        $integration = $this->integration();
        [$article, $state] = $this->knownSyncedArticle($this->page('Page', 'Base'));
        $article->forceFill([
            'body_markdown' => 'Outbound revision',
            'body_html' => '<p>Outbound revision</p>',
            'sync_status' => 'pending_push',
            'updated_by' => $this->admin->id,
        ])->save();
        $remoteBase = $this->page('Page', 'Base');
        $remoteOutbound = $this->page('Page', 'Outbound revision');
        $client = Mockery::mock(BookStackClient::class);
        $client->shouldReceive('readPage')->twice()->andReturn($remoteBase, $remoteOutbound);
        $client->shouldReceive('updatePage')->once()->andReturnUsing(function () use ($article, $remoteOutbound): array {
            $article->fresh()->forceFill([
                'body_markdown' => 'Newer local revision',
                'body_html' => '<p>Newer local revision</p>',
                'sync_status' => 'pending_push',
                'updated_by' => $this->admin->id,
            ])->save();

            return $remoteOutbound;
        });

        $summary = (new PushKnowledgeToBookStack($integration, $client))->execute();
        $article->refresh();
        $state->refresh();

        $this->assertSame(1, $summary['pages']);
        $this->assertSame('Newer local revision', $article->body_markdown);
        $this->assertSame('pending_push', $article->sync_status);
        $this->assertSame(ArticleBookStackSyncState::STATUS_PENDING_OUTBOUND, $state->status);
        $this->assertNotNull($state->pending_revision_id);
        $this->assertSame('Newer local revision', $state->pendingRevision?->body_markdown);
    }

    #[Test]
    public function ambiguous_create_retry_recovers_exact_remote_page_without_duplicate_post(): void
    {
        $integration = $this->integration();
        $book = Book::create([
            'name' => 'Remote book',
            'slug' => 'remote-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '7',
            'sync_status' => 'synced',
            'source_payload' => ['slug' => 'remote-book'],
        ]);
        $article = Article::create([
            'title' => 'New page',
            'slug' => 'new-page',
            'body_markdown' => 'New content',
            'body_html' => '<p>New content</p>',
            'visibility' => 'internal',
            'status' => 'published',
            'priority' => 0,
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'knowledge_book_id' => $book->id,
            'sync_status' => 'pending_push',
        ]);
        $remote = $this->page('New page', 'New content', id: 99);
        $client = Mockery::mock(BookStackClient::class);
        $client->shouldReceive('allPages')->twice()->andReturn([], [['id' => 99, 'name' => 'New page']]);
        $client->shouldReceive('createPage')->once()->andThrow(new \RuntimeException('Request timed out after remote acceptance.'));
        $client->shouldReceive('readPage')->twice()->with('99')->andReturn($remote);

        $first = (new PushKnowledgeToBookStack($integration, $client))->execute();
        $second = (new PushKnowledgeToBookStack($integration, $client))->execute();

        $article->refresh();
        $this->assertSame(1, $first['failed']);
        $this->assertSame(1, $second['pages']);
        $this->assertSame('99', $article->source_id);
        $this->assertSame('synced', $article->sync_status);
    }

    #[Test]
    public function remote_deletion_and_disabled_integration_preserve_nexum_work(): void
    {
        $integration = $this->integration();
        $initial = $this->page('Protected page', 'Protected body');
        $client = Mockery::mock(BookStackClient::class);
        $client->shouldReceive('allShelves')->twice()->andReturn([]);
        $client->shouldReceive('allBooks')->twice()->andReturn([$this->bookPayload()]);
        $client->shouldReceive('allChapters')->twice()->andReturn([]);
        $client->shouldReceive('allPages')->twice()->andReturn([['id' => 42]], []);
        $client->shouldReceive('readPage')->once()->andReturn($initial);

        (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $summary = (new SyncBookStackToKnowledge($integration, $client, $this->admin))->execute();
        $article = Article::where('source_id', '42')->firstOrFail();

        $this->assertSame(1, $summary['remote_deleted']);
        $this->assertSame('Protected body', $article->body_markdown);
        $this->assertSame(ArticleBookStackSyncState::STATUS_REMOTE_DELETED, $article->bookStackSyncState->status);

        $article->forceFill(['sync_status' => 'pending_push'])->save();
        $integration->forceFill(['status' => 'disabled'])->save();
        (new PushPendingKnowledgeToBookStack)->handle();

        $this->assertSame('pending_push', $article->fresh()->sync_status);
        $this->assertSame(ArticleBookStackSyncState::STATUS_REMOTE_DELETED, $article->bookStackSyncState->fresh()->status);
    }

    /** @param array<string, mixed> $config */
    private function integration(array $config = []): Integration
    {
        return Integration::create([
            'name' => 'BookStack',
            'type' => 'book_stack',
            'server' => 'https://docs.example.test',
            'status' => 'active',
            'is_healthy' => true,
            'config' => $config + [
                'two_way_sync_enabled' => true,
                'automatic_inbound_sync_enabled' => true,
            ],
        ]);
    }

    /** @param array<int, array<string, mixed>> $pages */
    private function pullClient(array $pages): BookStackClient
    {
        $client = Mockery::mock(BookStackClient::class);
        $count = count($pages);
        $client->shouldReceive('allShelves')->times($count)->andReturn([]);
        $client->shouldReceive('allBooks')->times($count)->andReturn([$this->bookPayload()]);
        $client->shouldReceive('allChapters')->times($count)->andReturn([]);
        $client->shouldReceive('allPages')->times($count)->andReturn([['id' => 42]]);
        $client->shouldReceive('readPage')->times($count)->andReturn(...$pages);

        return $client;
    }

    /** @return array{Article, ArticleBookStackSyncState} */
    private function knownSyncedArticle(array $page): array
    {
        $book = Book::create([
            'name' => 'Remote book',
            'slug' => 'remote-book',
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => '7',
            'sync_status' => 'synced',
            'source_payload' => ['slug' => 'remote-book'],
        ]);
        $article = Article::create([
            'title' => $page['name'],
            'slug' => 'page',
            'body_markdown' => $page['markdown'],
            'body_html' => $page['html'],
            'visibility' => 'internal',
            'status' => 'published',
            'priority' => $page['priority'],
            'owner_id' => $this->admin->id,
            'created_by' => $this->admin->id,
            'knowledge_book_id' => $book->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => (string) $page['id'],
            'sync_status' => 'synced',
        ]);
        $revision = app(ArticleRevisionIdentity::class)->recordCurrent($article, 'baseline');
        $state = ArticleBookStackSyncState::create([
            'article_id' => $article->id,
            'last_synced_revision_id' => $revision->id,
            'external_type' => 'page',
            'external_id' => (string) $page['id'],
            'status' => ArticleBookStackSyncState::STATUS_SYNCED,
            'last_synced_local_hash' => $revision->content_hash,
            'last_synced_remote_hash' => app(ArticleRevisionIdentity::class)->remoteHash($page),
            'last_synced_at' => now(),
        ]);

        return [$article, $state];
    }

    /** @return array<string, mixed> */
    private function page(
        string $name,
        string $markdown,
        ?int $chapterId = null,
        int $id = 42,
    ): array {
        return [
            'id' => $id,
            'book_id' => 7,
            'chapter_id' => $chapterId,
            'name' => $name,
            'slug' => 'page-'.$id,
            'html' => '<p>'.$markdown.'</p>',
            'markdown' => $markdown,
            'draft' => false,
            'priority' => 0,
            'updated_at' => now()->toIso8601String(),
            'book' => ['id' => 7, 'slug' => 'remote-book'],
        ];
    }

    /** @return array<string, mixed> */
    private function bookPayload(): array
    {
        return [
            'id' => 7,
            'name' => 'Remote book',
            'slug' => 'remote-book',
            'description' => 'Remote book',
        ];
    }
}
