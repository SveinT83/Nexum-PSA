<?php

namespace App\Modules\Knowledge\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\DocumentationRequest;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Contact\Models\Contact;
use App\Modules\CustomerPortal\Models\CustomerPortalAccount;
use App\Modules\CustomerPortal\Models\CustomerPortalMembership;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Knowledge\Actions\CreateArticleRevision;
use App\Modules\Knowledge\Actions\CreateTicketDocumentationRevision;
use App\Modules\Knowledge\Actions\PublishArticleRevision;
use App\Modules\Knowledge\Models\KnowledgeApiCommand;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use App\Modules\Taxonomy\Livewire\TagManager;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Real bearer and current actor contracts, persistence and exact typed HTTP responses. */
class KnowledgeApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(realpath(dirname(__DIR__, 5)), base_path(), 'Runtime must use this exact checkout.');
        foreach (['knowledge.view', 'knowledge.create', 'knowledge.update', 'knowledge.delete', 'knowledge.manage_drafts',
            'knowledge.approve', 'knowledge.publish', 'knowledge.rollback', 'knowledge.manage_settings', 'knowledge.sync_bookstack',
            'integration.view', 'ticket.view', 'ticket.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        $this->tech->givePermissionTo(['knowledge.view', 'knowledge.create', 'knowledge.update', 'knowledge.delete', 'knowledge.manage_drafts',
            'knowledge.approve', 'knowledge.publish', 'knowledge.rollback', 'knowledge.manage_settings', 'knowledge.sync_bookstack', 'integration.view']);
        $this->token = $this->tech->createToken('Knowledge parity', ['*'])->plainTextToken;
        $this->bearer($this->token);
    }

    private function bearer(string $token): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($token);
    }

    private function key(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    private function published(array $overrides = []): Article
    {
        $article = Article::create(array_replace(['title' => 'Published runbook', 'slug' => 'runbook-'.Str::uuid(),
            'body_markdown' => 'Published body', 'body_html' => '<p>Published body</p>', 'status' => 'published',
            'visibility' => 'internal', 'owner_id' => $this->tech->id, 'created_by' => $this->tech->id], $overrides));
        $revision = app(ArticleRevisionIdentity::class)->recordCurrent($article, 'test_baseline');
        $revision->forceFill(['state' => ArticleRevision::STATE_PUBLISHED, 'publication_status' => 'verified',
            'publication_read_back' => ['local' => 'verified'], 'publication_read_back_at' => now()])->save();
        $article->forceFill(['published_revision_id' => $revision->id])->save();

        return $article->refresh();
    }

    #[Test]
    public function hierarchy_article_metadata_defaults_and_every_legacy_method_have_real_typed_readback(): void
    {
        $this->getJson('/api/v1/knowledge/context')->assertOk()->assertJsonPath('data.article_writes_create_proposals', true);
        $shelf = $this->postJson('/api/v1/knowledge/shelves', ['name' => 'Shelf'], $this->key())->assertCreated()->json('data.id');
        $book = $this->postJson('/api/v1/knowledge/books', ['name' => 'Book', 'shelf_id' => $shelf], $this->key())->assertCreated()->json('data.id');
        $chapter = $this->postJson('/api/v1/knowledge/chapters', ['name' => 'Chapter', 'book_id' => $book], $this->key())->assertCreated()->json('data.id');
        $r = $this->postJson('/api/v1/knowledge/articles', ['title' => 'Proposal', 'body_markdown' => 'Proposed body',
            'knowledge_shelf_id' => $shelf, 'knowledge_book_id' => $book, 'knowledge_chapter_id' => $chapter], $this->key())->assertCreated();
        $article = $r->json('data.id');
        $r->assertJsonPath('data.status', 'draft')->assertJsonPath('meta.published_unchanged', true);
        foreach (['shelves', 'books', 'chapters', 'articles'] as $kind) {
            $this->getJson('/api/v1/knowledge/'.$kind)->assertOk();
        }
        $this->getJson('/api/v1/knowledge/shelves/'.$shelf)->assertOk()->assertJsonCount(1, 'data.books');
        $this->getJson('/api/v1/knowledge/books/'.$book)->assertOk()->assertJsonCount(1, 'data.chapters');
        $this->getJson('/api/v1/knowledge/chapters/'.$chapter)->assertOk()->assertJsonCount(1, 'data.pages');
        $this->getJson('/api/v1/knowledge/articles/'.$article)->assertOk()->assertJsonPath('data.view_count', 1);
        $this->deleteJson('/api/v1/knowledge/books/'.$book, [], $this->key())->assertUnprocessable();
        foreach (['shelves' => $shelf, 'books' => $book, 'chapters' => $chapter] as $kind => $id) {
            $this->putJson('/api/v1/knowledge/'.$kind.'/'.$id, ['name' => 'Updated '.$kind], $this->key())->assertOk();
            $this->patchJson('/api/v1/knowledge/'.$kind.'/'.$id, ['description' => 'Partial description'], $this->key())->assertOk();
        }
        $this->putJson('/api/v1/knowledge/articles/'.$article, ['title' => 'New proposal'], $this->key())->assertOk()->assertJsonPath('data.title', 'Proposal');
        $this->patchJson('/api/v1/knowledge/articles/'.$article, ['body_markdown' => 'Another proposal'], $this->key())->assertOk();
        foreach (['shelves', 'books', 'chapters', 'categories', 'clients', 'tags'] as $type) {
            $this->getJson('/api/v1/knowledge/lookups?type='.$type)->assertOk();
        }
        $this->getJson('/api/v1/knowledge/settings')->assertOk();
        $this->putJson('/api/v1/knowledge/settings', ['default_visibility' => 'internal', 'default_status' => 'needs_review', 'default_review_days' => 30, 'default_priority' => 42], $this->key())->assertOk()->assertJsonPath('data.default_priority', 42);
        $this->postJson('/api/v1/knowledge/articles/'.$article.'/tags', ['name' => 'Operational'], $this->key())->assertOk()->assertJsonPath('data.tags.0.name', 'Operational');
        $tag = Tag::where('name', 'Operational')->firstOrFail();
        $this->postJson('/api/v1/knowledge/articles/'.$article.'/tags', ['tag_id' => $tag->id], $this->key())->assertOk()->assertJsonCount(1, 'data.tags');
        $this->deleteJson('/api/v1/knowledge/articles/'.$article.'/tags/'.$tag->id, [], $this->key())->assertOk()->assertJsonCount(0, 'data.tags');
        $op = $r->json('operation_id');
        $this->getJson('/api/v1/knowledge/operations/'.$op)->assertOk()->assertJsonPath('data.state', 'completed');
        $this->deleteJson('/api/v1/knowledge/articles/'.$article, [], $this->key())->assertNoContent();
        $this->assertSoftDeleted('articles', ['id' => $article]);
        foreach (['chapters' => $chapter, 'books' => $book, 'shelves' => $shelf] as $kind => $id) {
            $this->deleteJson('/api/v1/knowledge/'.$kind.'/'.$id, [], $this->key())->assertNoContent();
        }
    }

    #[Test]
    public function exact_revision_review_publication_retry_and_rollback_preserve_immutable_history(): void
    {
        $article = $this->published();
        $baseline = $article->published_revision_id;
        $response = $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['body_markdown' => 'Proposed revised body'], $this->key())->assertOk();
        $revision = $response->json('meta.revision_id');
        $this->assertSame('Published body', $article->fresh()->body_markdown);
        $this->getJson('/api/v1/knowledge/articles/'.$article->id.'/revisions')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/knowledge/revisions/'.$revision)->assertOk()->assertJsonPath('data.diff.added.0', 'Proposed revised body');
        $key = $this->key();
        $this->postJson('/api/v1/knowledge/revisions/'.$revision.'/approve', ['note' => 'Reviewed exact body'], $key)->assertOk()->assertJsonPath('data.state', 'approved');
        $this->postJson('/api/v1/knowledge/revisions/'.$revision.'/approve', ['note' => 'Reviewed exact body'], $key)->assertOk()->assertJsonPath('replayed', true);
        $this->postJson('/api/v1/knowledge/revisions/'.$revision.'/publish', [], $this->key())->assertOk()->assertJsonPath('data.state', 'published');
        $this->assertSame('Proposed revised body', $article->fresh()->body_markdown);
        $other = $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['body_markdown' => 'Rejected body'], $this->key())->assertOk()->json('meta.revision_id');
        $this->postJson('/api/v1/knowledge/revisions/'.$other.'/reject', ['note' => 'Wrong instructions'], $this->key())->assertOk()->assertJsonPath('data.state', 'rejected');
        ArticleRevision::findOrFail($revision)->forceFill(['state' => 'publication_failed'])->save();
        $this->postJson('/api/v1/knowledge/revisions/'.$revision.'/retry', [], $this->key())->assertOk()->assertJsonPath('data.state', 'published');
        $rollback = $this->postJson('/api/v1/knowledge/revisions/'.$baseline.'/rollback', [], $this->key())->assertCreated()->json('data.id');
        $this->assertNotEquals($baseline, $rollback);
        $this->assertSame('Published body', ArticleRevision::findOrFail($rollback)->body_markdown);
        $this->assertSame('Proposed revised body', $article->fresh()->body_markdown);
    }

    #[Test]
    public function access_requires_current_actor_and_scope_and_separates_narrow_edit_forms(): void
    {
        $article = $this->published();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');
        $this->bearer($user->createToken('No rights', ['*'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/articles')->assertForbidden();
        $this->postJson('/api/v1/knowledge/articles', ['title' => 'Denied', 'body_markdown' => 'Denied'], $this->key())->assertForbidden();
        $this->bearer($this->tech->createToken('Read only', ['knowledge.read'])->plainTextToken);
        $this->postJson('/api/v1/knowledge/shelves', ['name' => 'Denied'], $this->key())->assertForbidden();
        $user->givePermissionTo('knowledge.manage_drafts');
        $this->bearer($user->createToken('Narrow editor', ['knowledge.read', 'knowledge.update'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/context')->assertOk();
        $this->getJson('/api/v1/knowledge/articles/'.$article->id)->assertOk();
        $this->getJson('/api/v1/knowledge/articles')->assertForbidden();
        $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['title' => 'Proposal'], $this->key())->assertOk();
        $user->forceFill(['status' => User::STATUS_DISABLED])->save();
        $this->getJson('/api/v1/knowledge/articles/'.$article->id)->assertForbidden();
    }

    #[Test]
    public function replay_is_encrypted_conflict_safe_token_bound_and_rechecks_revoked_authority(): void
    {
        $key = $this->key();
        $payload = ['title' => 'Private marker 7a23', 'body_markdown' => 'Secret content retained encrypted'];
        $r = $this->postJson('/api/v1/knowledge/articles', $payload, $key)->assertCreated();
        $op = $r->json('operation_id');
        $id = $r->json('data.id');
        $this->postJson('/api/v1/knowledge/articles', $payload, $key)->assertCreated()->assertJsonPath('replayed', true)->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('articles', 1);
        $this->assertStringNotContainsString('Private marker', DB::table('knowledge_api_commands')->where('id', $op)->value('result'));
        $this->postJson('/api/v1/knowledge/articles', $payload + ['priority' => 1], $key)->assertConflict();
        $this->bearer($this->tech->createToken('Other token', ['*'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/operations/'.$op)->assertNotFound();
        $this->bearer($this->token);
        $this->tech->revokePermissionTo('knowledge.manage_drafts');
        $this->postJson('/api/v1/knowledge/articles', $payload, $key)->assertForbidden();
        $this->getJson('/api/v1/knowledge/operations/'.$op)->assertForbidden();
    }

    #[Test]
    public function strict_input_validation_preconditions_and_repository_authority_do_not_claim_failed_keys(): void
    {
        $article = $this->published();
        foreach (['per_page=101', 'page=0', 'q[]=bad', 'status=wrong', 'visibility=wrong', 'unexpected=x'] as $q) {
            $this->getJson('/api/v1/knowledge/articles?'.$q)->assertUnprocessable();
        }
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/tags', ['name' => 'New'])->assertStatus(428);
        $before = KnowledgeApiCommand::count();
        $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['published_revision_id' => 234], $this->key())->assertUnprocessable();
        $etag = $this->getJson('/api/v1/knowledge/articles/'.$article->id)->assertOk()->headers->get('ETag');
        $article->forceFill(['title' => 'Concurrent browser edit'])->save();
        $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['title' => 'Must not win'], $this->key() + ['If-Match' => $etag])->assertStatus(412);
        $article->forceFill(['source_payload' => ['generated_from' => 'repository-knowledge-docs']])->save();
        $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['body_markdown' => 'Denied'], $this->key())->assertUnprocessable();
        $this->assertSame($before, KnowledgeApiCommand::count());
        $shelf = Shelf::create(['name' => 'Wrong shelf', 'slug' => 'wrong']);
        $other = Shelf::create(['name' => 'Other', 'slug' => 'other']);
        $book = Book::create(['name' => 'Book', 'slug' => 'book', 'shelf_id' => $other->id]);
        $this->postJson('/api/v1/knowledge/articles', ['title' => 'Mismatch', 'body_markdown' => 'body', 'knowledge_shelf_id' => $shelf->id, 'knowledge_book_id' => $book->id], $this->key())->assertUnprocessable();
    }

    #[Test]
    public function stale_approval_never_overwrites_newer_published_content(): void
    {
        $article = $this->published();
        $a = $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['body_markdown' => 'First'], $this->key())->assertOk()->json('meta.revision_id');
        $b = $this->patchJson('/api/v1/knowledge/articles/'.$article->id, ['body_markdown' => 'Second'], $this->key())->assertOk()->json('meta.revision_id');
        $this->postJson('/api/v1/knowledge/revisions/'.$a.'/approve', [], $this->key())->assertOk();
        $this->postJson('/api/v1/knowledge/revisions/'.$a.'/publish', [], $this->key())->assertOk();
        $failed = $this->postJson('/api/v1/knowledge/revisions/'.$b.'/approve', [], $this->key())->assertUnprocessable();
        $this->getJson('/api/v1/knowledge/operations/'.$failed->json('operation_id'))->assertOk()->assertJsonPath('data.state', 'failed');
        $this->assertSame('First', $article->fresh()->body_markdown);
        $this->assertSame('conflict', ArticleRevision::findOrFail($b)->state);
    }

    #[Test]
    public function ticket_only_revision_review_does_not_grant_library_or_other_revision_or_publish_access(): void
    {
        $article = $this->published();
        $ticket = Ticket::factory()->create();
        $request = DocumentationRequest::create(['ticket_id' => $ticket->id, 'requested_by' => $this->tech->id, 'status' => DocumentationRequest::STATUS_OPEN]);
        $agent = AiAgent::create(['name' => 'Documentation writer', 'slug' => 'writer', 'instructions' => 'Draft only', 'is_active' => true]);
        $revision = app(CreateTicketDocumentationRevision::class)->handle($request, $article, $agent, ['title' => 'Exact proposal', 'body_markdown' => 'Exact instructions', 'visibility' => 'internal', 'status' => 'published']);
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $user->assignRole('Tech');
        $user->givePermissionTo(['ticket.view', 'ticket.update']);
        $this->bearer($user->createToken('Exact review', ['knowledge.revisions.read', 'knowledge.revisions.approve', 'knowledge.revisions.publish'])->plainTextToken);
        $this->getJson('/api/v1/knowledge/revisions/'.$revision->id)->assertOk();
        $this->getJson('/api/v1/knowledge/revisions/'.$article->published_revision_id)->assertForbidden();
        $this->getJson('/api/v1/knowledge/articles')->assertForbidden();
        $this->getJson('/api/v1/knowledge/articles/'.$article->id.'/revisions')->assertForbidden();
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/approve', [], $this->key())->assertOk();
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/reject', ['note' => 'Denied'], $this->key())->assertForbidden();
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/publish', [], $this->key())->assertForbidden();
    }

    #[Test]
    public function conflict_selection_is_a_proposal_and_outbound_resolution_remains_provider_gated(): void
    {
        Queue::fake();
        $article = $this->published(['source_system' => 'book_stack', 'source_type' => 'page', 'source_id' => '42']);
        $candidate = app(CreateArticleRevision::class)->handle($article, ['title' => 'Remote candidate', 'body_markdown' => 'Remote body'], origin: 'book_stack', actorId: $this->tech->id, state: 'remote_candidate');
        ArticleBookStackSyncState::create(['article_id' => $article->id, 'candidate_revision_id' => $candidate->id, 'status' => 'conflict']);
        $this->getJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack')->assertOk()->assertJsonPath('data.state.status', 'conflict');
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack/accept-remote', [], $this->key())->assertOk()->assertJsonPath('data.state', 'ready_for_review');
        $this->assertSame('Published body', $article->fresh()->body_markdown);
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack/keep-nexum', [], $this->key())->assertConflict();
        Queue::assertNothingPushed();
        config(['knowledge-api.provider_execution_enabled' => true]);
        $key = $this->key();
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack/keep-nexum', [], $key)->assertOk()->assertJsonPath('data.state.status', 'resolving_outbound');
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack/keep-nexum', [], $key)->assertOk()->assertJsonPath('replayed', true);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class, 1);
    }

    #[Test]
    public function provider_pull_and_uncertain_deletion_claims_are_not_automatically_reexecuted(): void
    {
        $integration = Integration::create(['name' => 'Test BookStack', 'type' => 'book_stack', 'status' => 'active', 'server' => 'https://docs.example.test', 'config' => ['two_way_sync_enabled' => true]]);
        $integration->setSecret('token_id', 'test-id');
        $integration->setSecret('token_secret', 'test-secret');
        $integration->save();
        config(['knowledge-api.provider_execution_enabled' => true]);
        Http::fake(['*' => Http::response(['data' => [], 'total' => 0])]);
        $key = $this->key();
        $this->postJson('/api/v1/knowledge/book-stack/pull', [], $key)->assertOk()->assertJsonPath('data.failed', 0);
        $count = count(Http::recorded());
        $this->postJson('/api/v1/knowledge/book-stack/pull', [], $key)->assertOk()->assertJsonPath('replayed', true);
        $this->assertCount($count, Http::recorded());
        $shelf = Shelf::create(['name' => 'Remote shelf', 'slug' => 'remote', 'source_system' => 'book_stack', 'source_id' => '55']);
        Http::fake(function () {
            throw new \Illuminate\Http\Client\ConnectionException('Private provider failure');
        });
        $deleteKey = $this->key();
        $r = $this->deleteJson('/api/v1/knowledge/shelves/'.$shelf->id, [], $deleteKey)->assertStatus(202)->assertJsonPath('state', 'unknown');
        $this->deleteJson('/api/v1/knowledge/shelves/'.$shelf->id, [], $deleteKey)->assertStatus(202)->assertJsonPath('replayed', true);
        $this->getJson('/api/v1/knowledge/operations/'.$r->json('operation_id'))->assertOk()->assertJsonPath('data.reconciliation_required', true);
        $this->assertDatabaseHas('knowledge_shelves', ['id' => $shelf->id]);
        $this->deleteJson('/api/v1/knowledge/shelves/'.$shelf->id, [], $this->key())->assertConflict();
        $this->deleteJson('/api/v1/knowledge/shelves/0'.$shelf->id, [], $this->key())->assertConflict();
        $this->assertStringNotContainsString('Private provider failure', $r->getContent());
    }

    #[Test]
    public function portal_reads_use_current_membership_and_only_published_own_client_or_public_projection(): void
    {
        [$client,$site,$user,$account,$membership,$token] = $this->portal();
        $own = $this->published(['visibility' => 'client-wide', 'client_scope_id' => $client->id]);
        $public = $this->published(['visibility' => 'public']);
        $internal = $this->published();
        $draft = $this->published(['status' => 'draft', 'visibility' => 'public']);
        $other = $this->published(['visibility' => 'client-wide', 'client_scope_id' => Client::factory()->create()->id]);
        $this->bearer($token);
        $this->getJson('/api/v1/knowledge/portal/articles')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/knowledge/portal/articles/'.$own->id)->assertOk()->assertJsonMissingPath('data.body_markdown')->assertJsonMissingPath('data.source_system');
        foreach ([$internal, $draft, $other] as $a) {
            $this->getJson('/api/v1/knowledge/portal/articles/'.$a->id)->assertNotFound();
        }
        $this->getJson('/api/v1/knowledge/portal/articles?membership_id=999999')->assertForbidden();
        $membership->forceFill(['status' => 'revoked'])->save();
        $this->getJson('/api/v1/knowledge/portal/articles')->assertForbidden();
    }

    #[Test]
    public function shared_livewire_tag_operations_enforce_the_same_knowledge_mutation_authority(): void
    {
        $article = $this->published();
        $tag = Tag::create(['name' => 'Shared tag', 'active' => true]);
        $this->actingAs($this->tech);
        Livewire::test(TagManager::class, ['model' => $article, 'module' => 'knowledge'])->call('addTag', $tag->id)->assertHasNoErrors();
        $this->assertTrue($article->tags()->whereKey($tag->id)->exists());
        $this->tech->revokePermissionTo('knowledge.manage_drafts');
        Livewire::test(TagManager::class, ['model' => $article, 'module' => 'knowledge'])->call('removeTag', $tag->id)->assertForbidden();
        $this->assertTrue($article->tags()->whereKey($tag->id)->exists());
    }

    #[Test]
    public function completed_deletes_replay_after_binding_disappears_but_recheck_native_authority(): void
    {
        foreach (['shelves', 'articles'] as $kind) {
            $id = $kind === 'shelves' ? Shelf::create(['name' => 'Disposable', 'slug' => 'disposable'])->id : $this->published()->id;
            $key = $this->key();
            $first = $this->deleteJson('/api/v1/knowledge/'.$kind.'/'.$id, [], $key)->assertNoContent();
            $again = $this->deleteJson('/api/v1/knowledge/'.$kind.'/'.$id, [], $key)->assertNoContent();
            $this->assertSame($first->headers->get('X-Operation-Id'), $again->headers->get('X-Operation-Id'));
            $this->tech->revokePermissionTo('knowledge.delete');
            $this->deleteJson('/api/v1/knowledge/'.$kind.'/'.$id, [], $key)->assertForbidden();
            $this->tech->givePermissionTo('knowledge.delete');
        }
    }

    #[Test]
    public function provider_execution_requires_both_extra_scope_and_current_native_sync_authority(): void
    {
        $article = $this->published(['source_system' => 'book_stack', 'source_type' => 'page', 'source_id' => '47']);
        config(['knowledge-api.provider_execution_enabled' => true]);
        $this->bearer($this->tech->createToken('No provider execution', ['knowledge.sync'])->plainTextToken);
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack/keep-nexum', [], $this->key())->assertForbidden();
        $this->bearer($this->token);
        $this->tech->revokePermissionTo('knowledge.sync_bookstack');
        $this->postJson('/api/v1/knowledge/articles/'.$article->id.'/book-stack/keep-nexum', [], $this->key())->assertForbidden();
        $this->assertDatabaseCount('knowledge_api_commands', 0);
    }

    #[Test]
    public function externally_backed_publication_retries_exact_revision_and_completes_only_after_provider_readback(): void
    {
        Queue::fake();
        config(['knowledge-api.provider_execution_enabled' => true]);
        $article = $this->published(['source_system' => 'book_stack', 'source_type' => 'page', 'source_id' => '48']);
        // Proposal creation does not need API provider execution or enqueue an unapproved body.
        $revision = app(CreateArticleRevision::class)->handle($article, ['body_markdown' => 'External exact body']);
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/approve', [], $this->key())->assertOk();
        $key = $this->key();
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/publish', [], $key)->assertOk()->assertJsonPath('data.state', 'publishing');
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/publish', [], $key)->assertOk()->assertJsonPath('replayed', true);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class, 1);
        $publish = app(PublishArticleRevision::class);
        $publish->externalFailed($revision, 'controlled', 'Controlled fake failure');
        $this->postJson('/api/v1/knowledge/revisions/'.$revision->id.'/retry', [], $this->key())->assertOk()->assertJsonPath('data.state', 'publishing');
        $publish->externalSucceeded($revision, ['id' => 48, 'hash' => 'exact-provider-hash']);
        $this->getJson('/api/v1/knowledge/revisions/'.$revision->id)->assertOk()->assertJsonPath('data.state', 'published')->assertJsonPath('data.publication_read_back.provider', 'book_stack');
    }

    #[Test]
    public function portal_session_and_contact_revocation_keep_the_current_membership_boundary(): void
    {
        [$client,$site,$user,$account,$membership,$token] = $this->portal();
        $article = $this->published(['visibility' => 'client-wide', 'client_scope_id' => $client->id]);
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $this->actingAs($user, 'web')->withSession(['customer_portal_membership_id' => $membership->id]);
        $this->getJson('/api/v1/knowledge/portal/articles/'.$article->id)->assertOk();
        $user->contact->forceFill(['status' => 'inactive'])->save();
        $this->getJson('/api/v1/knowledge/portal/articles')->assertForbidden();
    }

    private function portal(): array
    {
        $client = Client::factory()->create(['active' => true]);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = Contact::create(['type' => 'person', 'status' => 'active', 'display_name' => 'Portal API Contact']);
        $user = User::factory()->create(['contact_id' => $contact->id, 'status' => User::STATUS_ACTIVE]);
        $account = CustomerPortalAccount::create(['user_id' => $user->id, 'contact_id' => $contact->id, 'status' => 'active']);
        $membership = CustomerPortalMembership::create(['customer_portal_account_id' => $account->id,
            'client_id' => $client->id, 'site_id' => $site->id, 'role' => 'viewer', 'status' => 'active']);
        $token = $user->createToken('Portal documents', ['knowledge.portal.read'])->plainTextToken;

        return [$client, $site, $user, $account, $membership, $token];
    }

    public function json($method, $uri, array $data = [], array $headers = [], $options = 0)
    {
        $response = parent::json($method, $uri, $data, $headers, $options);
        if ($response->status() < 200 || $response->status() >= 300 || ! str_starts_with($uri, '/api/v1/')) {
            return $response;
        }
        $route = \Illuminate\Support\Facades\Route::getRoutes()->match(\Illuminate\Http\Request::create($uri, $method));
        if (! str_starts_with($route->getActionName(), 'App\\Modules\\Knowledge\\')) {
            return $response;
        }
        $document = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, 512, JSON_THROW_ON_ERROR);
        $operation = $document['paths']['/'.$route->uri()][strtolower($method)] ?? null;
        $this->assertNotNull($operation, $method.' '.$route->uri().' has a published operation');
        $this->assertArrayHasKey($response->status(), $operation['responses']);
        if ($response->status() !== 204) {
            $schema = $operation['responses'][$response->status()]['content']['application/json']['schema'] ?? null;
            $this->assertIsArray($schema, 'A successful response must have a typed contract');
            $errors = [];
            $this->checkSchema(json_decode($response->getContent(), false, 512, JSON_THROW_ON_ERROR),
                $schema, $document['components']['schemas'], '$', $errors);
            $this->assertSame([], $errors, $method.' '.$route->uri().' response contract');
        }
        if ($response->headers->has('ETag') && is_string($response->json('data.revision'))) {
            $this->assertSame($response->headers->get('ETag'), $response->json('data.revision'), 'Body revision and ETag must agree');
        }
        self::$observed[strtolower($method).' /'.$route->uri()] = $response->status();

        return $response;
    }

    private static array $observed = [];

    public static function tearDownAfterClass(): void
    {
        if ($path = getenv('KNOWLEDGE_API_EVIDENCE_PATH')) {
            file_put_contents($path, json_encode(self::$observed, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
        parent::tearDownAfterClass();
    }

    private function checkSchema(mixed $value, array $schema, array $schemas, string $path, array &$errors): void
    {
        if ($value === null && ($schema['nullable'] ?? false)) {
            return;
        }
        if (isset($schema['$ref'])) {
            $this->checkSchema($value, $schemas[basename($schema['$ref'])], $schemas, $path, $errors);

            return;
        }
        foreach (['oneOf', 'anyOf'] as $mode) {
            if (! isset($schema[$mode])) {
                continue;
            }
            $matches = 0;
            foreach ($schema[$mode] as $part) {
                $candidateErrors = [];
                $this->checkSchema($value, $part, $schemas, $path, $candidateErrors);
                if ($candidateErrors === []) {
                    $matches++;
                }
            }
            if (($mode === 'oneOf' && $matches !== 1) || ($mode === 'anyOf' && $matches < 1)) {
                $errors[] = $path.' does not match '.$mode.' (matches '.$matches.')';
            }
        }
        $type = $schema['type'] ?? null;
        $valid = match ($type) {
            'object' => $value instanceof \stdClass,
            'array' => is_array($value),
            'integer' => is_int($value),
            'number' => is_int($value) || is_float($value),
            'string' => is_string($value),
            'boolean' => is_bool($value),
            default => true,
        };
        if (! $valid) {
            $errors[] = $path.' expected '.$type.', got '.get_debug_type($value);

            return;
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            $errors[] = $path.' outside declared enum';
        }
        if ($type === 'object') {
            $fields = get_object_vars($value);
            foreach ($schema['required'] ?? [] as $field) {
                if (! array_key_exists($field, $fields)) {
                    $errors[] = $path.'.'.$field.' required';
                }
            }
            foreach ($fields as $field => $child) {
                if (isset($schema['properties'][$field])) {
                    $this->checkSchema($child, $schema['properties'][$field], $schemas, $path.'.'.$field, $errors);
                } elseif (($schema['additionalProperties'] ?? true) === false) {
                    $errors[] = $path.'.'.$field.' undeclared';
                } elseif (is_array($schema['additionalProperties'] ?? null)) {
                    $this->checkSchema($child, $schema['additionalProperties'], $schemas, $path.'.'.$field, $errors);
                }
            }
        } elseif ($type === 'array') {
            foreach ($value as $index => $child) {
                $this->checkSchema($child, $schema['items'] ?? [], $schemas, $path.'.'.$index, $errors);
            }
            if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                $errors[] = $path.' has too many items';
            }
        }
        if ($type === 'string' && isset($schema['format']) && in_array($schema['format'], ['date-time', 'date'], true)
            && strtotime($value) === false) {
            $errors[] = $path.' invalid '.$schema['format'];
        }
    }
}
