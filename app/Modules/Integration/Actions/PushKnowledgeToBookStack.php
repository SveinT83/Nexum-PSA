<?php

namespace App\Modules\Integration\Actions;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use App\Modules\Integration\Support\BookStackSyncErrorSanitizer;
use App\Modules\Knowledge\Actions\PublishArticleRevision;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use Illuminate\Support\Arr;

/**
 * Pushes locally-owned Knowledge content into BookStack.
 *
 * This is the first two-way sync path: local shelves, books, and pages are
 * created in BookStack, then marked as BookStack-backed records in Nexum PSA.
 */
class PushKnowledgeToBookStack
{
    public function __construct(
        private readonly Integration $integration,
        private readonly BookStackClient $client,
    ) {}

    /**
     * @return array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}
     */
    public function execute(): array
    {
        $summary = [
            'shelves' => 0,
            'books' => 0,
            'chapters' => 0,
            'pages' => 0,
            'skipped' => 0,
            'failed' => 0,
            'total' => 0,
            'errors' => [],
        ];

        $this->pushShelves($summary);
        $this->pushBooks($summary);
        $this->syncShelfBookMemberships($summary);
        $this->pushChapters($summary);
        $this->pushPages($summary);
        $this->recordSummary($summary);

        return $summary;
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushShelves(array &$summary): void
    {
        Shelf::query()
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'shelf')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('name')
            ->get()
            ->each(function (Shelf $shelf) use (&$summary): void {
                $summary['total']++;

                try {
                    $payload = [
                        'name' => $shelf->name,
                        'description' => $shelf->description,
                    ];

                    if ($shelf->source_system === 'book_stack' && filled($shelf->source_id)) {
                        $payload['books'] = $this->bookStackBookIdsForShelf($shelf);
                    }

                    $response = $shelf->source_system === 'book_stack' && filled($shelf->source_id)
                        ? $this->client->updateShelf($shelf->source_id, $payload)
                        : ($this->recoverExistingShelf($shelf)
                            ?? $this->client->createShelf($payload));
                    $remoteId = (string) Arr::get($response, 'id', $shelf->source_id);
                    $readBack = filled($remoteId) ? $this->client->readShelf($remoteId) : [];

                    $this->markShelfSynced($shelf, $readBack + $response);
                    $summary['shelves']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Shelf '.$shelf->id.': '.$this->sanitizedError($exception);
                }
            });
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushBooks(array &$summary): void
    {
        Book::query()
            ->with('shelf')
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'book')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->each(function (Book $book) use (&$summary): void {
                $summary['total']++;

                try {
                    $payload = [
                        'name' => $book->name,
                        'description' => $book->description,
                    ];

                    $response = $book->source_system === 'book_stack' && filled($book->source_id)
                        ? $this->client->updateBook($book->source_id, $payload)
                        : ($this->recoverExistingBook($book)
                            ?? $this->client->createBook($payload));
                    $remoteId = (string) Arr::get($response, 'id', $book->source_id);
                    $readBack = filled($remoteId) ? $this->client->readBook($remoteId) : [];

                    $this->markBookSynced($book, $readBack + $response);
                    $summary['books']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Book '.$book->id.': '.$this->sanitizedError($exception);
                }
            });
    }

    /**
     * BookStack assigns books to shelves by updating the shelf's book ID list.
     *
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function syncShelfBookMemberships(array &$summary): void
    {
        Shelf::query()
            ->with('books')
            ->where('source_system', 'book_stack')
            ->where('source_type', 'shelf')
            ->get()
            ->each(function (Shelf $shelf) use (&$summary): void {
                $bookIds = $this->bookStackBookIdsForShelf($shelf);

                try {
                    $payload = $this->client->updateShelf($shelf->source_id, [
                        'name' => $shelf->name,
                        'description' => $shelf->description,
                        'books' => $bookIds,
                    ]);
                    $readBack = $this->client->readShelf((string) Arr::get($payload, 'id', $shelf->source_id));

                    $this->markShelfSynced($shelf, $readBack + $payload);
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Shelf membership '.$shelf->id.': '.$this->sanitizedError($exception);
                }
            });
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushChapters(array &$summary): void
    {
        Chapter::query()
            ->with('book')
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'chapter')
                            ->whereNotNull('source_id');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'nexum')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('name')
            ->get()
            ->each(function (Chapter $chapter) use (&$summary): void {
                $summary['total']++;

                try {
                    $isUpdate = $chapter->source_system === 'book_stack' && filled($chapter->source_id);
                    $payload = $this->chapterPayload($chapter, $isUpdate);

                    if ($payload === null) {
                        $summary['skipped']++;
                        $summary['errors'][] = 'Chapter '.$chapter->id.': skipped because its book is not synced to BookStack.';

                        return;
                    }

                    $response = $isUpdate
                        ? $this->client->updateChapter($chapter->source_id, $payload)
                        : ($this->recoverExistingChapter($chapter, $payload)
                            ?? $this->client->createChapter($payload));
                    $remoteId = (string) Arr::get($response, 'id', $chapter->source_id);
                    $readBack = filled($remoteId) ? $this->client->readChapter($remoteId) : [];

                    $this->markChapterSynced($chapter, $readBack + $response);
                    $summary['chapters']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    $summary['errors'][] = 'Chapter '.$chapter->id.': '.$this->sanitizedError($exception);
                }
            });
    }

    /**
     * @return array<string, mixed>|null
     */
    private function chapterPayload(Chapter $chapter, bool $isUpdate = false): ?array
    {
        $bookId = $chapter->book?->source_system === 'book_stack'
            ? $chapter->book->source_id
            : null;

        if (! $bookId && ! $isUpdate) {
            return null;
        }

        return array_filter([
            'book_id' => $bookId,
            'name' => $chapter->name,
            'description' => $chapter->description,
            'priority' => $chapter->priority,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array{shelves: int, books: int, chapters: int, pages: int, skipped: int, failed: int, total: int, errors: array<int, string>}  $summary
     */
    private function pushPages(array &$summary): void
    {
        Article::query()
            ->with(['knowledgeBook', 'knowledgeChapter'])
            ->where('sync_status', 'pending_push')
            ->where(function ($query): void {
                $query->whereNull('source_system')
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'book_stack')
                            ->where('source_type', 'page');
                    })
                    ->orWhere(function ($query): void {
                        $query->where('source_system', 'nexum')
                            ->whereNotNull('source_id');
                    });
            })
            ->orderBy('priority')
            ->orderBy('title')
            ->get()
            ->each(function (Article $article) use (&$summary): void {
                $summary['total']++;

                try {
                    $revision = null;
                    $revision = app(ArticleRevisionIdentity::class)->recordCurrent($article, 'nexum');

                    if ($article->status !== 'published') {
                        $summary['skipped']++;
                        $summary['errors'][] = 'Page '.$article->id.': only a published Nexum revision can be pushed.';

                        return;
                    }

                    $state = $this->outboundState($article, $revision);
                    $explicitResolution = $state->status === ArticleBookStackSyncState::STATUS_RESOLVING_OUTBOUND
                        && (int) $state->pending_revision_id === (int) $revision->id;
                    $recreateRemote = $explicitResolution
                        && in_array($state->conflict_reason, ['remote_record_deleted', 'missing_external_identifier'], true);

                    if (
                        blank($article->source_id)
                        && ! $recreateRemote
                        && $article->source_system === 'book_stack'
                    ) {
                        $summary['skipped']++;
                        $summary['errors'][] = 'Page '.$article->id.': the missing BookStack identifier requires explicit review.';

                        return;
                    }

                    $isUpdate = $article->source_system === 'book_stack' && filled($article->source_id) && ! $recreateRemote;
                    $payload = $this->pagePayload($article, $isUpdate);

                    if ($payload === null) {
                        $summary['skipped']++;
                        $summary['errors'][] = 'Page '.$article->id.': skipped because it has no synced BookStack book or chapter.';

                        return;
                    }

                    if ($isUpdate) {
                        $remoteBefore = $this->client->readPage($article->source_id);
                        $remoteBeforeHash = app(ArticleRevisionIdentity::class)->remoteHash($remoteBefore);
                        $knownRemoteChanged = filled($state->last_synced_remote_hash)
                            && ! hash_equals($state->last_synced_remote_hash, $remoteBeforeHash);
                        $unknownBaseDiverged = blank($state->last_synced_remote_hash)
                            && ! hash_equals($revision->content_hash, $remoteBeforeHash);

                        if (($knownRemoteChanged || $unknownBaseDiverged) && ! $explicitResolution) {
                            $this->recordOutboundConflict($article, $state, $remoteBefore);
                            $summary['skipped']++;
                            $summary['errors'][] = 'Page '.$article->id.': remote content changed and requires conflict review.';

                            return;
                        }

                        $response = $this->client->updatePage($article->source_id, $payload);
                    } else {
                        $response = $this->recoverExistingPage($article, $revision)
                            ?? $this->client->createPage($payload);
                    }

                    $remoteId = (string) Arr::get($response, 'id', $article->source_id);

                    if ($remoteId === '') {
                        throw new \RuntimeException('BookStack did not return an external page identifier.');
                    }

                    $readBack = $this->client->readPage($remoteId);
                    $readBackHash = app(ArticleRevisionIdentity::class)->remoteHash($readBack);

                    if (! hash_equals($revision->content_hash, $readBackHash)) {
                        throw new \RuntimeException('BookStack read-back did not match the exact outbound revision.');
                    }

                    $this->markPageSynced($article, $readBack + $response, $revision, $state);
                    if ($revision->state === ArticleRevision::STATE_PUBLISHING) {
                        app(PublishArticleRevision::class)->externalSucceeded($revision, [
                            'id' => $remoteId,
                            'hash' => $readBackHash,
                        ]);
                    }

                    $summary['pages']++;
                } catch (\Throwable $exception) {
                    $summary['failed']++;
                    if ($revision?->state === ArticleRevision::STATE_PUBLISHING) {
                        app(PublishArticleRevision::class)->externalFailed(
                            $revision,
                            'book_stack_publication_failed',
                            $this->sanitizedError($exception),
                        );
                    }

                    $summary['errors'][] = 'Page '.$article->id.': '.$this->sanitizedError($exception);
                }
            });
    }

    private function pagePayload(Article $article, bool $isUpdate = false): ?array
    {
        if ($article->knowledgeChapter?->source_system === 'book_stack' && $article->knowledgeChapter->source_id) {
            $parent = ['chapter_id' => (int) $article->knowledgeChapter->source_id];
        } elseif ($article->knowledgeBook?->source_system === 'book_stack' && $article->knowledgeBook->source_id) {
            $parent = ['book_id' => (int) $article->knowledgeBook->source_id];
        } elseif ($isUpdate) {
            $parent = [];
        } else {
            return null;
        }

        return $parent + [
            'name' => $article->title,
            'markdown' => $article->body_markdown,
            'priority' => $article->priority,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function bookStackBookIdsForShelf(Shelf $shelf): array
    {
        return $shelf->books
            ->where('source_system', 'book_stack')
            ->pluck('source_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markShelfSynced(Shelf $shelf, array $payload): void
    {
        $shelf->forceFill([
            'name' => (string) Arr::get($payload, 'name', $shelf->name),
            'slug' => (string) Arr::get($payload, 'slug', $shelf->slug),
            'description' => Arr::get($payload, 'description', $shelf->description),
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => (string) Arr::get($payload, 'id', $shelf->source_id),
            'source_url' => $this->shelfUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markBookSynced(Book $book, array $payload): void
    {
        $book->forceFill([
            'name' => (string) Arr::get($payload, 'name', $book->name),
            'slug' => (string) Arr::get($payload, 'slug', $book->slug),
            'description' => Arr::get($payload, 'description', $book->description),
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => (string) Arr::get($payload, 'id', $book->source_id),
            'source_url' => $this->bookUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markChapterSynced(Chapter $chapter, array $payload): void
    {
        $chapter->loadMissing('book');

        $chapter->forceFill([
            'name' => (string) Arr::get($payload, 'name', $chapter->name),
            'slug' => (string) Arr::get($payload, 'slug', $chapter->slug),
            'description' => Arr::get($payload, 'description', $chapter->description),
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => (string) Arr::get($payload, 'id', $chapter->source_id),
            'source_url' => $this->chapterUrl($chapter, $payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => $payload,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function markPageSynced(
        Article $article,
        array $payload,
        ArticleRevision $revision,
        ArticleBookStackSyncState $state,
    ): void {
        $article = Article::query()
            ->with(['knowledgeBook', 'knowledgeChapter'])
            ->findOrFail($article->id);
        $identity = app(ArticleRevisionIdentity::class);
        $remoteHash = $identity->remoteHash($payload);
        $currentHash = $identity->localHash($article);
        $isCurrentRevision = hash_equals($revision->content_hash, $currentHash);
        $syncStatus = $isCurrentRevision ? 'synced' : 'pending_push';

        $article->forceFill([
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => (string) Arr::get($payload, 'id', $article->source_id),
            'source_url' => $this->pageUrl($article, $payload),
            'source_checksum' => $remoteHash,
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => $syncStatus,
            'source_payload' => Arr::only($payload, [
                'id',
                'book_id',
                'chapter_id',
                'slug',
                'priority',
                'revision_count',
                'book',
                'chapter',
                'tags',
            ]),
        ])->save();

        $pendingRevision = $isCurrentRevision
            ? null
            : $identity->recordCurrent($article, 'nexum');

        $state->forceFill([
            'last_synced_revision_id' => $revision->id,
            'pending_revision_id' => $pendingRevision?->id,
            'candidate_revision_id' => null,
            'external_type' => 'page',
            'external_id' => $article->source_id,
            'external_url' => $article->source_url,
            'status' => $isCurrentRevision
                ? ArticleBookStackSyncState::STATUS_SYNCED
                : ArticleBookStackSyncState::STATUS_PENDING_OUTBOUND,
            'last_synced_local_hash' => $revision->content_hash,
            'last_synced_remote_hash' => $remoteHash,
            'outbound_operation_key' => null,
            'last_direction' => 'outbound',
            'origin' => 'nexum',
            'last_synced_at' => now(),
            'remote_updated_at' => $this->sourceUpdatedAt($payload),
            'conflict_reason' => null,
            'remote_snapshot' => Arr::only($payload, [
                'id',
                'book_id',
                'chapter_id',
                'slug',
                'priority',
                'revision_count',
                'book',
                'chapter',
                'tags',
            ]),
        ])->save();
    }

    private function outboundState(Article $article, ArticleRevision $revision): ArticleBookStackSyncState
    {
        $state = ArticleBookStackSyncState::firstOrCreate(
            ['article_id' => $article->id],
            [
                'external_type' => 'page',
                'external_id' => $article->source_system === 'book_stack' ? $article->source_id : null,
                'external_url' => $article->source_url,
                'status' => ArticleBookStackSyncState::STATUS_PENDING_OUTBOUND,
                'origin' => 'nexum',
            ],
        );

        $state->forceFill([
            'pending_revision_id' => $revision->id,
            'outbound_operation_key' => hash('sha256', $article->id.'|'.$revision->id.'|'.$revision->content_hash),
            'last_direction' => 'outbound',
        ])->save();

        return $state;
    }

    private function recordOutboundConflict(
        Article $article,
        ArticleBookStackSyncState $state,
        array $remotePage,
    ): void {
        $bookId = Arr::get($remotePage, 'book_id', Arr::get($remotePage, 'book.id'));
        $chapterId = Arr::get($remotePage, 'chapter_id', Arr::get($remotePage, 'chapter.id'));
        $book = filled($bookId)
            ? Book::query()->where('source_system', 'book_stack')->where('source_id', (string) $bookId)->first()
            : null;
        $chapter = filled($chapterId)
            ? Chapter::query()->where('source_system', 'book_stack')->where('source_id', (string) $chapterId)->first()
            : null;
        $candidate = app(ArticleRevisionIdentity::class)->recordRemoteCandidate(
            $article,
            $remotePage,
            $book,
            $chapter,
            'conflict_candidate',
            $state->lastSyncedRevision,
        );

        $article->forceFill(['sync_status' => 'conflict'])->save();
        $state->forceFill([
            'candidate_revision_id' => $candidate->id,
            'status' => ArticleBookStackSyncState::STATUS_CONFLICT,
            'conflict_reason' => 'remote_changed_before_outbound',
            'remote_updated_at' => $this->sourceUpdatedAt($remotePage),
            'remote_snapshot' => Arr::only($remotePage, [
                'id',
                'book_id',
                'chapter_id',
                'slug',
                'priority',
                'revision_count',
                'book',
                'chapter',
                'tags',
            ]),
        ])->save();
    }

    private function recoverExistingPage(Article $article, ArticleRevision $revision): ?array
    {
        $matches = [];

        foreach ($this->client->allPages() as $listedPage) {
            if ((string) Arr::get($listedPage, 'name') !== $article->title) {
                continue;
            }

            $remotePage = $this->client->readPage((string) Arr::get($listedPage, 'id'));

            if (hash_equals($revision->content_hash, app(ArticleRevisionIdentity::class)->remoteHash($remotePage))) {
                $matches[] = $remotePage;
            }
        }

        if (count($matches) > 1) {
            throw new \RuntimeException('Several BookStack pages match the pending Nexum revision.');
        }

        return $matches[0] ?? null;
    }

    private function recoverExistingShelf(Shelf $shelf): ?array
    {
        $matches = [];
        foreach ($this->client->allShelves() as $listedShelf) {
            if ((string) Arr::get($listedShelf, 'name') !== $shelf->name) {
                continue;
            }
            $remote = $this->client->readShelf((string) Arr::get($listedShelf, 'id'));
            if ($this->sameOptionalText(Arr::get($remote, 'description'), $shelf->description)) {
                $matches[] = $remote;
            }
        }

        return $this->singleRecoveredRecord($matches, 'shelves');
    }

    private function recoverExistingBook(Book $book): ?array
    {
        $matches = [];
        foreach ($this->client->allBooks() as $listedBook) {
            if ((string) Arr::get($listedBook, 'name') !== $book->name) {
                continue;
            }
            $remote = $this->client->readBook((string) Arr::get($listedBook, 'id'));
            if ($this->sameOptionalText(Arr::get($remote, 'description'), $book->description)) {
                $matches[] = $remote;
            }
        }

        return $this->singleRecoveredRecord($matches, 'books');
    }

    /** @param array<string, mixed> $payload */
    private function recoverExistingChapter(Chapter $chapter, array $payload): ?array
    {
        $matches = [];
        foreach ($this->client->allChapters() as $listedChapter) {
            if ((string) Arr::get($listedChapter, 'name') !== $chapter->name) {
                continue;
            }
            $remote = $this->client->readChapter((string) Arr::get($listedChapter, 'id'));
            $remoteBookId = (string) Arr::get($remote, 'book_id', Arr::get($remote, 'book.id'));
            if (
                $remoteBookId === (string) ($payload['book_id'] ?? '')
                && $this->sameOptionalText(Arr::get($remote, 'description'), $chapter->description)
                && (int) Arr::get($remote, 'priority', 0) === (int) $chapter->priority
            ) {
                $matches[] = $remote;
            }
        }

        return $this->singleRecoveredRecord($matches, 'chapters');
    }

    /**
     * @param  array<int, array<string, mixed>>  $matches
     * @return array<string, mixed>|null
     */
    private function singleRecoveredRecord(array $matches, string $type): ?array
    {
        if (count($matches) > 1) {
            throw new \RuntimeException('Several BookStack '.$type.' match the pending Nexum record.');
        }

        return $matches[0] ?? null;
    }

    private function sameOptionalText(mixed $left, mixed $right): bool
    {
        return trim((string) $left) === trim((string) $right);
    }

    private function recordSummary(array $summary): void
    {
        $config = $this->integration->config ?? [];
        $config['last_push_summary'] = $summary;
        $config['last_push_at'] = now()->toIso8601String();
        $config['last_error_at'] = $summary['failed'] === 0 && $summary['skipped'] === 0 ? null : now()->toIso8601String();
        $this->integration->config = $config;
        $this->integration->last_sync_at = now();
        $this->integration->is_healthy = $summary['failed'] === 0 && $summary['skipped'] === 0;
        $this->integration->last_error = $summary['failed'] === 0 && $summary['skipped'] === 0
            ? null
            : $this->lastErrorSummary($summary['errors']);
        $this->integration->save();
    }

    /**
     * @param  array<int, string>  $errors
     */
    private function lastErrorSummary(array $errors): string
    {
        return mb_substr(implode("\n", array_slice($errors, 0, 5)), 0, 4000);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function shelfUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/shelves/'.$slug : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bookUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/books/'.$slug : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function chapterUrl(Chapter $chapter, array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');
        $bookSlug = Arr::get($payload, 'book.slug')
            ?: Arr::get($payload, 'book_slug')
            ?: Arr::get($chapter->book?->source_payload, 'slug');

        return $bookSlug && $slug
            ? rtrim((string) $this->integration->server, '/').'/books/'.$bookSlug.'/chapter/'.$slug
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pageUrl(Article $article, array $payload): ?string
    {
        if (Arr::get($payload, 'url')) {
            return (string) Arr::get($payload, 'url');
        }

        $bookSlug = Arr::get($payload, 'book.slug')
            ?: Arr::get($payload, 'book_slug')
            ?: Arr::get($article->knowledgeBook?->source_payload, 'slug');
        $pageSlug = Arr::get($payload, 'slug');

        return $bookSlug && $pageSlug
            ? rtrim((string) $this->integration->server, '/').'/books/'.$bookSlug.'/page/'.$pageSlug
            : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sourceUpdatedAt(array $payload): ?\Illuminate\Support\Carbon
    {
        $updatedAt = Arr::get($payload, 'updated_at');

        return $updatedAt ? \Illuminate\Support\Carbon::parse($updatedAt) : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function payloadChecksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function sanitizedError(\Throwable $exception): string
    {
        logger()->warning('BookStack push failed safely.', [
            'exception_class' => $exception::class,
            'source_file' => basename($exception->getFile()),
            'source_line' => $exception->getLine(),
        ]);

        return app(BookStackSyncErrorSanitizer::class)->message($exception);
    }
}
