<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Knowledge\Support\ArticleRevisionSnapshot;
use App\Modules\Knowledge\Support\DocumentationAgentActor;
use App\Modules\Notification\Actions\SendCustomerPortalNotification;
use App\Modules\Ticket\Models\TicketEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Publishes the exact approved revision and records verified local/provider read-back.
 */
class PublishArticleRevision
{
    public function __construct(
        private readonly ArticleRevisionSnapshot $snapshots,
        private readonly DocumentationAgentActor $systemActors,
        private readonly RecordArticleRevisionEvent $events,
        private readonly SendCustomerPortalNotification $portalNotifications,
    ) {}

    public function handle(ArticleRevision $revision, int $actorId): ArticleRevision
    {
        $revision->refresh();

        $publishedRevisionId = (int) $revision->article()->value('published_revision_id');

        if (! in_array($publishedRevisionId, [(int) $revision->base_revision_id, (int) $revision->id], true)) {
            $this->markConflict($revision, $actorId);
            throw ValidationException::withMessages([
                'revision' => 'The published base changed. This revision cannot be published.',
            ]);
        }

        $dispatchExternal = false;

        try {
            $revision = DB::transaction(function () use ($revision, $actorId, &$dispatchExternal): ArticleRevision {
                $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);
                $article = Article::query()->with(['knowledgeBook', 'knowledgeChapter'])->lockForUpdate()->findOrFail($revision->article_id);

                if (! in_array($revision->state, [ArticleRevision::STATE_APPROVED, ArticleRevision::STATE_PUBLICATION_FAILED], true)) {
                    throw ValidationException::withMessages(['revision' => 'Only an approved or retryable failed revision can be published.']);
                }

                if (! in_array((int) $article->published_revision_id, [(int) $revision->base_revision_id, (int) $revision->id], true)) {
                    throw ValidationException::withMessages(['revision' => 'The published base changed during publication.']);
                }

                $systemActor = $this->systemActors->resolve();
                if (! $systemActor->hasPermissionTo('knowledge.publish_system')) {
                    throw new \Illuminate\Auth\Access\AuthorizationException('The Documentation Agent may not publish Knowledge revisions.');
                }

                $previousRevisionId = $article->published_revision_id;
                $snapshot = $this->snapshots->fromRevision($revision);
                $titleChanged = $article->title !== $snapshot['title'];

                $article->forceFill([
                    'title' => $snapshot['title'],
                    'slug' => $titleChanged ? Str::slug($snapshot['title']).'-'.Str::random(5) : $article->slug,
                    'body_markdown' => $snapshot['body_markdown'],
                    'body_html' => $snapshot['body_html'],
                    'visibility' => $snapshot['visibility'],
                    'status' => $snapshot['article_status'],
                    'client_scope_id' => $snapshot['client_scope_id'],
                    'owner_id' => $snapshot['owner_id'],
                    'category_id' => $snapshot['category_id'],
                    'knowledge_shelf_id' => $snapshot['knowledge_shelf_id'],
                    'knowledge_book_id' => $snapshot['knowledge_book_id'],
                    'knowledge_chapter_id' => $snapshot['knowledge_chapter_id'],
                    'priority' => $snapshot['priority'],
                    'next_review_at' => $snapshot['next_review_at'],
                    'updated_by' => $systemActor->id,
                    'published_revision_id' => $revision->id,
                ])->save();

                $readBack = $this->snapshots->fromArticle($article->refresh());

                if (! hash_equals((string) $revision->snapshot_hash, $this->snapshots->snapshotHash($readBack))) {
                    throw new \RuntimeException('Local publication read-back did not match the approved revision.');
                }

                if ($previousRevisionId && (int) $previousRevisionId !== (int) $revision->id) {
                    ArticleRevision::query()->whereKey($previousRevisionId)->update([
                        'state' => ArticleRevision::STATE_SUPERSEDED,
                        'superseded_at' => now(),
                    ]);
                }

                $dispatchExternal = $this->needsBookStackReadBack($article);
                $from = $revision->state;
                $revision->forceFill([
                    'state' => $dispatchExternal ? ArticleRevision::STATE_PUBLISHING : ArticleRevision::STATE_PUBLISHED,
                    'published_by' => $actorId,
                    'published_at' => now(),
                    'publication_status' => $dispatchExternal ? 'pending_external' : 'verified',
                    'publication_read_back' => ['local' => 'verified', 'snapshot_hash' => $revision->snapshot_hash],
                    'publication_read_back_at' => $dispatchExternal ? null : now(),
                    'publication_error' => null,
                    'publication_attempts' => $revision->publication_attempts + 1,
                ])->save();

                $this->events->handle(
                    $revision,
                    $dispatchExternal ? 'publication_dispatched' : 'publication_verified',
                    $actorId,
                    $from,
                    $revision->state,
                    metadata: ['read_back' => $dispatchExternal ? 'local_pending_provider' : 'local'],
                );

                if ($dispatchExternal) {
                    $article->forceFill(['sync_status' => 'pending_push'])->save();
                    ArticleBookStackSyncState::updateOrCreate(
                        ['article_id' => $article->id],
                        [
                            'pending_revision_id' => $revision->id,
                            'external_type' => 'page',
                            'external_id' => $article->source_id,
                            'external_url' => $article->source_url,
                            'status' => ArticleBookStackSyncState::STATUS_PENDING_OUTBOUND,
                            'origin' => 'nexum',
                        ],
                    );
                    $this->updateDocumentation($revision, DocumentationRequest::STATUS_PUBLISHING);
                } else {
                    $this->completeDocumentation($revision);
                }

                return $revision->refresh();
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->externalFailed($revision, 'local_read_back_failed', $exception->getMessage(), $actorId);
            throw $exception;
        }

        if ($dispatchExternal) {
            PushPendingKnowledgeToBookStack::dispatch();
        } else {
            $this->notifyPortal($revision->article, $revision);
        }

        return $revision;
    }

    /** @param array<string, mixed> $providerReadBack */
    public function externalSucceeded(ArticleRevision $revision, array $providerReadBack = []): ArticleRevision
    {
        return DB::transaction(function () use ($revision, $providerReadBack): ArticleRevision {
            $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($revision->state !== ArticleRevision::STATE_PUBLISHING) {
                return $revision;
            }

            $from = $revision->state;
            $revision->forceFill([
                'state' => ArticleRevision::STATE_PUBLISHED,
                'publication_status' => 'verified',
                'publication_read_back' => array_merge($revision->publication_read_back ?? [], [
                    'provider' => 'book_stack',
                    'provider_id' => $providerReadBack['id'] ?? null,
                    'provider_hash' => $providerReadBack['hash'] ?? null,
                ]),
                'publication_read_back_at' => now(),
                'publication_error' => null,
            ])->save();
            $this->events->handle($revision, 'publication_verified', $revision->published_by, $from, $revision->state, metadata: [
                'read_back' => 'local_and_provider',
                'provider' => 'book_stack',
                'provider_id' => $providerReadBack['id'] ?? null,
            ]);
            $this->completeDocumentation($revision);

            DB::afterCommit(fn () => $this->notifyPortal($revision->article, $revision));

            return $revision->refresh();
        });
    }

    public function externalFailed(ArticleRevision $revision, string $code, string $message, ?int $actorId = null): ArticleRevision
    {
        return DB::transaction(function () use ($revision, $code, $message, $actorId): ArticleRevision {
            $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);

            if ($revision->state === ArticleRevision::STATE_PUBLISHED) {
                return $revision;
            }

            $from = $revision->state;
            $revision->forceFill([
                'state' => ArticleRevision::STATE_PUBLICATION_FAILED,
                'publication_status' => 'failed_retryable',
                'publication_error' => Str::limit($message, 1000),
            ])->save();
            $this->events->handle($revision, 'publication_failed', $actorId, $from, $revision->state, metadata: [
                'reason_code' => $code,
            ]);
            $this->updateDocumentation($revision, DocumentationRequest::STATUS_PUBLICATION_FAILED, $code);

            return $revision->refresh();
        });
    }

    private function markConflict(ArticleRevision $revision, int $actorId): void
    {
        DB::transaction(function () use ($revision, $actorId): void {
            $revision = ArticleRevision::query()->lockForUpdate()->findOrFail($revision->id);
            $from = $revision->state;
            $revision->forceFill([
                'state' => ArticleRevision::STATE_CONFLICT,
                'decision_note' => 'The published base changed before publication.',
            ])->save();
            $this->events->handle($revision, 'stale_base_conflict', $actorId, $from, $revision->state, metadata: [
                'reason_code' => 'published_base_changed',
            ]);
        });
    }

    private function needsBookStackReadBack(Article $article): bool
    {
        return $article->source_system === 'book_stack'
            || $article->knowledgeBook?->source_system === 'book_stack'
            || $article->knowledgeChapter?->source_system === 'book_stack';
    }

    private function updateDocumentation(ArticleRevision $revision, string $status, ?string $failureCode = null): void
    {
        if (! $revision->documentation_request_id) {
            return;
        }

        DocumentationRequest::query()->whereKey($revision->documentation_request_id)->update([
            'status' => $status,
            'failure_code' => $failureCode,
        ]);
    }

    private function completeDocumentation(ArticleRevision $revision): void
    {
        if (! $revision->documentation_request_id) {
            return;
        }

        $request = DocumentationRequest::query()->lockForUpdate()->find($revision->documentation_request_id);

        if (! $request || $request->status === DocumentationRequest::STATUS_COMPLETED) {
            return;
        }

        $request->forceFill([
            'status' => DocumentationRequest::STATUS_COMPLETED,
            'completed_at' => now(),
            'publication_read_back_at' => now(),
            'failure_code' => null,
        ])->save();

        TicketEvent::query()->create([
            'ticket_id' => $request->ticket_id,
            'actor_id' => $revision->system_actor_id,
            'type' => 'documentation_published',
            'message' => 'Approved Knowledge revision was published and read back successfully.',
            'metadata' => [
                'documentation_request_id' => $request->id,
                'article_id' => $revision->article_id,
                'revision_id' => $revision->id,
            ],
        ]);
    }

    private function notifyPortal(Article $article, ArticleRevision $revision): void
    {
        if ($article->status !== 'published' || $article->visibility !== 'client-wide' || ! $article->client_scope_id) {
            return;
        }

        $isFirstPublication = $revision->base_revision_id === null;
        $this->portalNotifications->handle(
            type: $isFirstPublication ? 'portal_knowledge_published' : 'portal_knowledge_updated',
            clientId: (int) $article->client_scope_id,
            siteId: null,
            title: $isFirstPublication ? 'New knowledge article' : 'Knowledge article updated',
            body: $article->title,
            url: route('customer-portal.knowledge.show', $article),
            sourceType: Article::class,
            sourceId: $article->id,
            clientWideVisibleToSiteMembers: true,
            metadata: ['article_id' => $article->id, 'revision_id' => $revision->id],
        );
    }
}
