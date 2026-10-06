<?php

namespace App\Modules\Knowledge\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Knowledge\Actions\CreateArticleRevision;
use App\Modules\Knowledge\Actions\CreateTicketDocumentationRevision;
use App\Modules\Knowledge\Actions\PublishArticleRevision;
use App\Modules\Knowledge\Actions\RollbackArticleRevision;
use App\Modules\Knowledge\Actions\TransitionArticleRevision;
use App\Modules\Knowledge\Actions\UpdateArticle;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class KnowledgeRevisionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'knowledge.view',
            'knowledge.update',
            'knowledge.approve',
            'knowledge.publish',
            'knowledge.rollback',
            'ticket.view',
            'ticket.update',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $this->reviewer = $this->user('reviewer@example.test', [
            'knowledge.view',
            'knowledge.update',
            'knowledge.approve',
            'knowledge.publish',
            'knowledge.rollback',
        ]);
    }

    #[Test]
    public function manual_edit_does_not_change_published_content_until_exact_revision_is_approved_and_published(): void
    {
        $article = $this->publishedArticle();
        $this->actingAs($this->reviewer);

        $revision = app(UpdateArticle::class)->handle($article, [
            'title' => 'Published runbook',
            'body_markdown' => 'Proposed body',
            'visibility' => 'internal',
            'status' => 'published',
        ]);

        $this->assertSame(ArticleRevision::STATE_READY_FOR_REVIEW, $revision->state);
        $this->assertSame('Published body', $article->fresh()->body_markdown);
        $this->assertSame($article->published_revision_id, $revision->base_revision_id);

        app(TransitionArticleRevision::class)->approve($revision, $this->reviewer->id, 'Reviewed.');
        app(PublishArticleRevision::class)->handle($revision, $this->reviewer->id);

        $article->refresh();
        $revision->refresh();

        $this->assertSame('Proposed body', $article->body_markdown);
        $this->assertSame($revision->id, $article->published_revision_id);
        $this->assertSame(ArticleRevision::STATE_PUBLISHED, $revision->state);
        $this->assertSame('verified', $revision->publication_status);
        $this->assertNotNull($revision->publication_read_back_at);
        $this->assertDatabaseHas('knowledge_article_revision_events', [
            'article_revision_id' => $revision->id,
            'event_type' => 'publication_verified',
        ]);
    }

    #[Test]
    public function stale_revision_fails_closed_after_another_revision_is_published(): void
    {
        $article = $this->publishedArticle();
        $this->actingAs($this->reviewer);
        $first = app(UpdateArticle::class)->handle($article, $this->payload('First proposal'));
        $second = app(CreateArticleRevision::class)->handle($article, $this->payload('Second proposal'));

        app(TransitionArticleRevision::class)->approve($first, $this->reviewer->id);
        app(TransitionArticleRevision::class)->approve($second, $this->reviewer->id);
        app(PublishArticleRevision::class)->handle($second, $this->reviewer->id);

        try {
            app(PublishArticleRevision::class)->handle($first, $this->reviewer->id);
            $this->fail('Stale revision publication should have failed.');
        } catch (ValidationException) {
            $this->assertSame(ArticleRevision::STATE_CONFLICT, $first->refresh()->state);
        }

        $this->assertSame('Second proposal', $article->fresh()->body_markdown);
    }

    #[Test]
    public function ticket_technician_can_approve_only_linked_ai_revision_without_publish_permission(): void
    {
        $article = $this->publishedArticle();
        $ticket = Ticket::factory()->create();
        $request = DocumentationRequest::create([
            'ticket_id' => $ticket->id,
            'requested_by' => $this->reviewer->id,
            'status' => DocumentationRequest::STATUS_OPEN,
            'reason' => 'Turn the resolution into a runbook.',
        ]);
        $agent = AiAgent::create([
            'name' => 'Documentation writer',
            'slug' => 'documentation-writer',
            'instructions' => 'Draft documentation only.',
            'is_active' => true,
        ]);
        $revision = app(CreateTicketDocumentationRevision::class)->handle(
            $request,
            $article,
            $agent,
            $this->payload('AI proposal'),
        );
        $ticketTech = $this->user('ticket-tech@example.test', ['ticket.view', 'ticket.update']);
        $systemActor = $revision->systemActor()->firstOrFail();
        $this->assertSame(User::STATUS_DISABLED, $systemActor->status);
        $this->assertTrue((bool) $systemActor->is_system_actor);
        $this->assertSame('knowledge_documentation_agent', $systemActor->system_actor_key);
        $this->assertCount(0, $systemActor->roles);
        $this->assertSame(
            ['knowledge.publish_system', 'knowledge.revision_persist'],
            $systemActor->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        );

        $this->actingAs($ticketTech)
            ->get(route('tech.knowledge.revisions.show', $revision))
            ->assertOk()
            ->assertSee('AI proposal');

        $this->actingAs($ticketTech)
            ->post(route('tech.knowledge.revisions.approve', $revision))
            ->assertRedirect();

        $this->assertSame(ArticleRevision::STATE_APPROVED, $revision->refresh()->state);
        $this->actingAs($ticketTech)
            ->post(route('tech.knowledge.revisions.publish', $revision))
            ->assertForbidden();
        $this->assertFalse($ticketTech->can('knowledge.view'));
        $this->assertFalse($ticketTech->can('knowledge.publish'));
    }

    #[Test]
    public function ticket_revision_is_denied_without_ticket_update_scope(): void
    {
        $article = $this->publishedArticle();
        $ticket = Ticket::factory()->create();
        $request = DocumentationRequest::create([
            'ticket_id' => $ticket->id,
            'status' => DocumentationRequest::STATUS_OPEN,
        ]);
        $agent = AiAgent::create([
            'name' => 'Scoped writer',
            'slug' => 'scoped-writer',
            'instructions' => 'Draft only.',
        ]);
        $revision = app(CreateTicketDocumentationRevision::class)->handle(
            $request,
            $article,
            $agent,
            $this->payload('Scoped AI proposal'),
        );
        $viewer = $this->user('ticket-viewer@example.test', ['ticket.view']);

        $this->actingAs($viewer)
            ->get(route('tech.knowledge.revisions.show', $revision))
            ->assertForbidden();
    }

    #[Test]
    public function failed_book_stack_publication_retries_the_same_revision_without_duplicates(): void
    {
        Queue::fake();
        $article = $this->publishedArticle([
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => '55',
        ]);
        $this->actingAs($this->reviewer);
        $request = DocumentationRequest::create([
            'ticket_id' => Ticket::factory()->create()->id,
            'requested_by' => $this->reviewer->id,
            'status' => DocumentationRequest::STATUS_OPEN,
        ]);
        $revision = app(CreateArticleRevision::class)->handle(
            $article,
            $this->payload('Provider proposal'),
            provenance: ['documentation_request_id' => $request->id, 'ticket_id' => $request->ticket_id],
        );
        app(TransitionArticleRevision::class)->approve($revision, $this->reviewer->id);
        app(PublishArticleRevision::class)->handle($revision, $this->reviewer->id);

        $this->assertSame(ArticleRevision::STATE_PUBLISHING, $revision->refresh()->state);
        app(PublishArticleRevision::class)->externalFailed($revision, 'provider_timeout', 'Provider timed out.');
        $this->assertSame(ArticleRevision::STATE_PUBLICATION_FAILED, $revision->refresh()->state);

        app(PublishArticleRevision::class)->handle($revision, $this->reviewer->id);
        app(PublishArticleRevision::class)->externalSucceeded($revision, ['id' => '55', 'hash' => $revision->content_hash]);

        $this->assertSame(ArticleRevision::STATE_PUBLISHED, $revision->refresh()->state);
        $this->assertSame(2, $revision->publication_attempts);
        $this->assertSame(1, ArticleRevision::where('article_id', $article->id)->where('title', $revision->title)->where('body_markdown', 'Provider proposal')->count());
        $this->assertSame(DocumentationRequest::STATUS_COMPLETED, $request->refresh()->status);
        $this->assertNotNull($request->publication_read_back_at);
        Queue::assertPushed(PushPendingKnowledgeToBookStack::class, 2);
    }

    #[Test]
    public function rollback_creates_a_new_revision_and_preserves_history(): void
    {
        $article = $this->publishedArticle();
        $this->actingAs($this->reviewer);
        $original = $article->publishedRevision;
        $proposal = app(UpdateArticle::class)->handle($article, $this->payload('New published body'));
        app(TransitionArticleRevision::class)->approve($proposal, $this->reviewer->id);
        app(PublishArticleRevision::class)->handle($proposal, $this->reviewer->id);

        $rollback = app(RollbackArticleRevision::class)->handle($original, $this->reviewer->id);

        $this->assertNotSame($original->id, $rollback->id);
        $this->assertNotSame($proposal->id, $rollback->id);
        $this->assertSame('Published body', $rollback->body_markdown);
        $this->assertSame($proposal->id, $rollback->base_revision_id);
        $this->assertSame(3, ArticleRevision::where('article_id', $article->id)->count());
    }

    #[Test]
    public function revision_content_is_immutable_and_repository_authority_blocks_ai_edits(): void
    {
        $article = $this->publishedArticle();
        $revision = app(CreateArticleRevision::class)->handle($article, $this->payload('Immutable proposal'));

        $this->expectException(LogicException::class);
        $revision->forceFill(['title' => 'Mutated'])->save();
    }

    #[Test]
    public function repository_owned_article_rejects_non_repository_proposals(): void
    {
        $article = $this->publishedArticle([
            'source_payload' => ['generated_from' => 'repository-knowledge-docs'],
        ]);

        $this->expectException(ValidationException::class);
        app(CreateArticleRevision::class)->handle($article, $this->payload('Untrusted proposal'), origin: 'ai');
    }

    /** @param array<string, mixed> $overrides */
    private function publishedArticle(array $overrides = []): Article
    {
        $article = Article::create(array_merge([
            'title' => 'Published runbook',
            'slug' => 'published-runbook-'.uniqid(),
            'body_markdown' => 'Published body',
            'body_html' => '<p>Published body</p>',
            'visibility' => 'internal',
            'status' => 'published',
            'owner_id' => $this->reviewer->id,
            'created_by' => $this->reviewer->id,
            'updated_by' => $this->reviewer->id,
        ], $overrides));
        $revision = app(ArticleRevisionIdentity::class)->recordCurrent($article, 'test_baseline');
        $revision->forceFill([
            'state' => ArticleRevision::STATE_PUBLISHED,
            'approved_by' => $this->reviewer->id,
            'approved_at' => now(),
            'published_by' => $this->reviewer->id,
            'published_at' => now(),
            'publication_status' => 'verified',
            'publication_read_back' => ['local' => 'test_verified'],
            'publication_read_back_at' => now(),
        ])->save();
        $article->forceFill(['published_revision_id' => $revision->id])->save();

        return $article->refresh()->load('publishedRevision');
    }

    /** @return array<string, mixed> */
    private function payload(string $body): array
    {
        return [
            'title' => 'Published runbook',
            'body_markdown' => $body,
            'visibility' => 'internal',
            'status' => 'published',
        ];
    }

    /** @param list<string> $permissions */
    private function user(string $email, array $permissions): User
    {
        $user = User::create([
            'name' => $email,
            'email' => $email,
            'password' => Hash::make('password'),
            'status' => User::STATUS_ACTIVE,
        ]);
        $user->givePermissionTo($permissions);

        return $user;
    }
}
