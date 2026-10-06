<?php

namespace App\Modules\Integration\Actions;

use App\Models\Core\User;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\ArticleBookStackSyncState;
use App\Models\Knowledge\ArticleRevision;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Services\BookStack\BookStackClient;
use App\Modules\Integration\Support\BookStackSyncErrorSanitizer;
use App\Modules\Knowledge\Support\ArticleRevisionIdentity;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class SyncBookStackToKnowledge
{
    public function __construct(
        private readonly Integration $integration,
        private readonly BookStackClient $client,
        private readonly User $actor,
    ) {}

    /**
     * Pull BookStack pages into Knowledge while keeping Nexum PSA as the local
     * ownership and review system for synchronized content.
     *
     * @return array{created: int, updated: int, skipped: int, failed: int, total: int, errors: array<int, string>}
     */
    public function execute(): array
    {
        $summary = [
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'candidates' => 0,
            'conflicts' => 0,
            'remote_deleted' => 0,
            'failed' => 0,
            'total' => 0,
            'errors' => [],
        ];

        $hierarchy = $this->syncHierarchy();
        $seenPageIds = [];

        foreach ($this->client->allPages() as $listedPage) {
            $summary['total']++;
            $pageId = (string) Arr::get($listedPage, 'id');
            $seenPageIds[] = $pageId;

            try {
                $page = $this->client->readPage($pageId);
                $result = $this->upsertPage($page, $hierarchy);
                $summary[$result]++;
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $message = $this->errorSanitizer()->message($exception);
                if (str_contains($message, 'rate-limited')) {
                    $summary['rate_limited'] = ($summary['rate_limited'] ?? 0) + 1;
                }
                $summary['errors'][] = 'Page '.$pageId.': '.$message;
            }
        }

        $this->detectRemoteDeletions($seenPageIds, $summary);

        $config = $this->integration->config ?? [];
        $config['last_sync_summary'] = $summary;
        $config['last_pull_at'] = now()->toIso8601String();
        $config['last_error_at'] = $summary['failed'] === 0 ? null : now()->toIso8601String();
        $this->integration->config = $config;
        $this->integration->last_sync_at = now();
        $this->integration->is_healthy = $summary['failed'] === 0;
        $this->integration->last_error = $summary['failed'] === 0
            ? null
            : collect($summary['errors'])
                ->take(5)
                ->join("\n");
        $this->integration->save();

        return $summary;
    }

    /**
     * Synchronize BookStack shelves and books before pages are imported.
     *
     * @return array{default_shelf: Shelf, books: array<string, Book>, chapters: array<string, Chapter>, shelf_books: array<string, Shelf>}
     */
    private function syncHierarchy(): array
    {
        $shelfBookMap = [];
        $defaultShelf = $this->upsertDefaultShelf();
        $usedDefaultShelf = false;

        foreach ($this->client->allShelves() as $listedShelfPayload) {
            $shelfPayload = $this->client->readShelf((string) Arr::get($listedShelfPayload, 'id'));
            $shelf = $this->upsertShelf($shelfPayload);

            foreach (Arr::get($shelfPayload, 'books', []) as $bookPayload) {
                $bookId = (string) Arr::get($bookPayload, 'id');

                if ($bookId !== '') {
                    $shelfBookMap[$bookId] = $shelf;
                }
            }
        }

        $books = [];

        foreach ($this->client->allBooks() as $bookPayload) {
            $bookId = (string) Arr::get($bookPayload, 'id');
            $shelf = $shelfBookMap[$bookId] ?? $defaultShelf;
            $usedDefaultShelf = $usedDefaultShelf || $shelf->is($defaultShelf);
            $books[$bookId] = $this->upsertBook($bookPayload, $shelf);
        }

        $chapters = $this->syncChapters($books);

        if (! $usedDefaultShelf && ! $defaultShelf->books()->exists()) {
            $defaultShelf->delete();
        }

        return [
            'default_shelf' => $defaultShelf,
            'books' => $books,
            'chapters' => $chapters,
            'shelf_books' => $shelfBookMap,
        ];
    }

    private function upsertPage(array $page, array $hierarchy): string
    {
        $sourceId = (string) Arr::get($page, 'id');
        $book = $this->bookForPage($page, $hierarchy);
        $chapter = $this->chapterForPage($page, $book, $hierarchy);
        $remoteHash = $this->revisionIdentity()->remoteHash($page);

        $article = Article::withTrashed()
            ->where('source_system', 'book_stack')
            ->where('source_type', 'page')
            ->where('source_id', $sourceId)
            ->first();

        if (! $article) {
            $article = new Article([
                'slug' => $this->articleSlug($page),
                'visibility' => 'internal',
                'owner_id' => $this->actor->id,
                'created_by' => $this->actor->id,
                'view_count' => 0,
                'next_review_at' => now()->addYear(),
            ]);
            $this->applyRemotePage($article, $page, $book, $chapter, $remoteHash);
            $revision = $this->revisionIdentity()->recordCurrent($article, 'book_stack');
            $this->markStateSynced($article, $revision, $page, $remoteHash, 'inbound');

            return 'created';
        }

        if ($article->trashed()) {
            $article->restore();
        }

        $article->loadMissing(['knowledgeBook', 'knowledgeChapter']);
        $state = $this->syncState($article, $sourceId);
        $currentRevision = $this->revisionIdentity()->recordCurrent($article, 'nexum_baseline');
        $localHash = $this->revisionIdentity()->localHash($article);

        if (! $state->last_synced_local_hash || ! $state->last_synced_remote_hash) {
            if (hash_equals($localHash, $remoteHash)) {
                $this->refreshSourceMetadata($article, $page, $remoteHash);
                $this->markStateSynced($article, $currentRevision, $page, $remoteHash, 'baseline');

                return 'skipped';
            }

            $this->storeCandidate(
                article: $article,
                page: $page,
                book: $book,
                chapter: $chapter,
                state: $state,
                revisionState: 'conflict_candidate',
                syncStatus: ArticleBookStackSyncState::STATUS_CONFLICT,
                reason: 'unknown_baseline_diverged',
            );

            return 'conflicts';
        }

        $localChanged = ! hash_equals($state->last_synced_local_hash, $localHash);
        $remoteChanged = ! hash_equals($state->last_synced_remote_hash, $remoteHash);

        if (! $localChanged && ! $remoteChanged) {
            $this->refreshSourceMetadata($article, $page, $remoteHash);
            $state->forceFill([
                'external_url' => $this->sourceUrl($page),
                'remote_updated_at' => $this->sourceUpdatedAt($page),
                'remote_snapshot' => $this->sourcePayload($page),
            ])->save();

            return 'skipped';
        }

        if ($localChanged && ! $remoteChanged) {
            $status = ($this->integration->config['two_way_sync_enabled'] ?? false)
                ? ArticleBookStackSyncState::STATUS_PENDING_OUTBOUND
                : ArticleBookStackSyncState::STATUS_CONFLICT;
            $article->forceFill([
                'sync_status' => $status === ArticleBookStackSyncState::STATUS_PENDING_OUTBOUND
                    ? 'pending_push'
                    : 'conflict',
            ])->save();
            $state->forceFill([
                'pending_revision_id' => $currentRevision->id,
                'status' => $status,
                'conflict_reason' => $status === ArticleBookStackSyncState::STATUS_CONFLICT
                    ? 'local_changed_while_push_disabled'
                    : null,
                'last_direction' => 'outbound',
            ])->save();

            return $status === ArticleBookStackSyncState::STATUS_CONFLICT ? 'conflicts' : 'skipped';
        }

        if (! $localChanged && $remoteChanged && $this->automaticInboundEnabled()) {
            $this->applyRemotePage($article, $page, $book, $chapter, $remoteHash);
            $revision = $this->revisionIdentity()->recordCurrent($article, 'book_stack', $currentRevision);
            $this->markStateSynced($article, $revision, $page, $remoteHash, 'inbound');

            return 'updated';
        }

        $isConflict = $localChanged && $remoteChanged;
        $this->storeCandidate(
            article: $article,
            page: $page,
            book: $book,
            chapter: $chapter,
            state: $state,
            revisionState: $isConflict ? 'conflict_candidate' : 'imported_candidate',
            syncStatus: $isConflict
                ? ArticleBookStackSyncState::STATUS_CONFLICT
                : ArticleBookStackSyncState::STATUS_PENDING_INBOUND,
            reason: $isConflict ? 'simultaneous_changes' : 'automatic_inbound_disabled',
        );

        return $isConflict ? 'conflicts' : 'candidates';
    }

    /** @param array<int, string> $seenPageIds */
    private function detectRemoteDeletions(array $seenPageIds, array &$summary): void
    {
        ArticleBookStackSyncState::query()
            ->with('article')
            ->whereNotNull('external_id')
            ->when($seenPageIds !== [], fn ($query) => $query->whereNotIn('external_id', $seenPageIds))
            ->get()
            ->each(function (ArticleBookStackSyncState $state) use (&$summary): void {
                if (! $state->article || $state->status === ArticleBookStackSyncState::STATUS_REMOTE_DELETED) {
                    return;
                }

                $state->forceFill([
                    'status' => ArticleBookStackSyncState::STATUS_REMOTE_DELETED,
                    'conflict_reason' => 'remote_record_deleted',
                    'last_direction' => 'inbound',
                ])->save();
                $state->article->forceFill(['sync_status' => 'remote_deleted'])->save();
                $summary['remote_deleted']++;
            });
    }

    private function syncState(Article $article, string $externalId): ArticleBookStackSyncState
    {
        return ArticleBookStackSyncState::firstOrCreate(
            ['article_id' => $article->id],
            [
                'external_type' => 'page',
                'external_id' => $externalId !== '' ? $externalId : null,
                'external_url' => $article->source_url,
                'status' => $externalId !== ''
                    ? ArticleBookStackSyncState::STATUS_BASELINE_UNKNOWN
                    : ArticleBookStackSyncState::STATUS_REMOTE_MISSING_IDENTIFIER,
                'origin' => 'discovered',
            ],
        );
    }

    private function applyRemotePage(Article $article, array $page, ?Book $book, ?Chapter $chapter, string $remoteHash): void
    {
        $html = (string) Arr::get($page, 'html', '');
        $markdown = $this->revisionIdentity()->normalizeMarkdown((string) Arr::get($page, 'markdown', ''));

        $article->forceFill([
            'title' => (string) Arr::get($page, 'name', 'BookStack page '.Arr::get($page, 'id')),
            'body_markdown' => $markdown !== '' ? $markdown : $this->plainTextFromHtml($html),
            'body_html' => $html,
            'status' => Arr::get($page, 'draft') ? 'draft' : 'published',
            'knowledge_shelf_id' => $book?->shelf_id,
            'knowledge_book_id' => $book?->id,
            'knowledge_chapter_id' => $chapter?->id,
            'priority' => (int) Arr::get($page, 'priority', 0),
            'updated_by' => $this->actor->id,
            'source_system' => 'book_stack',
            'source_type' => 'page',
            'source_id' => (string) Arr::get($page, 'id'),
            'source_url' => $this->sourceUrl($page),
            'source_checksum' => $remoteHash,
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($page),
            'sync_status' => 'synced',
            'source_payload' => $this->sourcePayload($page),
        ])->save();
    }

    private function refreshSourceMetadata(Article $article, array $page, string $remoteHash): void
    {
        $article->forceFill([
            'source_url' => $this->sourceUrl($page),
            'source_checksum' => $remoteHash,
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($page),
            'sync_status' => 'synced',
            'source_payload' => $this->sourcePayload($page),
        ])->save();
    }

    private function markStateSynced(Article $article, ArticleRevision $revision, array $page, string $remoteHash, string $direction): void
    {
        $this->syncState($article, (string) Arr::get($page, 'id'))->forceFill([
            'last_synced_revision_id' => $revision->id,
            'pending_revision_id' => null,
            'candidate_revision_id' => null,
            'external_id' => (string) Arr::get($page, 'id'),
            'external_url' => $this->sourceUrl($page),
            'status' => ArticleBookStackSyncState::STATUS_SYNCED,
            'last_synced_local_hash' => $revision->content_hash,
            'last_synced_remote_hash' => $remoteHash,
            'last_direction' => $direction,
            'origin' => 'book_stack',
            'last_synced_at' => now(),
            'remote_updated_at' => $this->sourceUpdatedAt($page),
            'conflict_reason' => null,
            'remote_snapshot' => $this->sourcePayload($page),
        ])->save();

        $previousRevisionId = $article->published_revision_id;

        if ($previousRevisionId && (int) $previousRevisionId !== (int) $revision->id) {
            ArticleRevision::query()->whereKey($previousRevisionId)->update([
                'state' => ArticleRevision::STATE_SUPERSEDED,
                'superseded_at' => now(),
            ]);
        }

        $targetState = $article->status === 'published'
            ? ArticleRevision::STATE_PUBLISHED
            : ArticleRevision::STATE_DRAFT;
        $revision->forceFill([
            'state' => $targetState,
            'approved_by' => $this->actor->id,
            'approved_at' => $article->status === 'published' ? now() : null,
            'published_by' => $this->actor->id,
            'published_at' => $article->status === 'published' ? now() : null,
            'publication_status' => 'verified',
            'publication_read_back' => [
                'local' => 'verified',
                'provider' => 'book_stack',
                'provider_hash' => $remoteHash,
            ],
            'publication_read_back_at' => now(),
        ])->save();
        $article->forceFill(['published_revision_id' => $revision->id])->save();

        if (! $revision->events()->where('event_type', 'book_stack_inbound_verified')->exists()) {
            app(\App\Modules\Knowledge\Actions\RecordArticleRevisionEvent::class)->handle(
                $revision,
                'book_stack_inbound_verified',
                $this->actor->id,
                null,
                $targetState,
                metadata: ['read_back' => 'local_and_provider', 'provider' => 'book_stack'],
            );
        }
    }

    private function storeCandidate(
        Article $article,
        array $page,
        ?Book $book,
        ?Chapter $chapter,
        ArticleBookStackSyncState $state,
        string $revisionState,
        string $syncStatus,
        string $reason,
    ): void {
        $candidate = $this->revisionIdentity()->recordRemoteCandidate(
            $article,
            $page,
            $book,
            $chapter,
            $revisionState,
            $state->lastSyncedRevision,
        );

        $article->forceFill(['sync_status' => $syncStatus])->save();
        $state->forceFill([
            'candidate_revision_id' => $candidate->id,
            'status' => $syncStatus,
            'external_url' => $this->sourceUrl($page),
            'last_direction' => 'inbound',
            'origin' => 'book_stack',
            'remote_updated_at' => $this->sourceUpdatedAt($page),
            'conflict_reason' => $reason,
            'remote_snapshot' => $this->sourcePayload($page),
        ])->save();
    }

    private function automaticInboundEnabled(): bool
    {
        $config = $this->integration->config ?? [];

        return (bool) ($config['automatic_inbound_sync_enabled'] ?? false);
    }

    private function revisionIdentity(): ArticleRevisionIdentity
    {
        return app(ArticleRevisionIdentity::class);
    }

    private function errorSanitizer(): BookStackSyncErrorSanitizer
    {
        return app(BookStackSyncErrorSanitizer::class);
    }

    private function upsertDefaultShelf(): Shelf
    {
        $shelf = $this->findShelfBySourceOrSlug('virtual_shelf', 'default', 'bookstack') ?? new Shelf;

        $shelf->forceFill([
            'name' => 'BookStack',
            'slug' => 'bookstack',
            'description' => 'Imported BookStack books that are not assigned to a shelf.',
            'source_system' => 'book_stack',
            'source_type' => 'virtual_shelf',
            'source_id' => 'default',
            'source_url' => rtrim((string) $this->integration->server, '/'),
            'source_checksum' => hash('sha256', 'bookstack-default-shelf'),
            'source_synced_at' => now(),
            'sync_status' => 'synced',
            'source_payload' => ['virtual' => true],
        ])->save();

        return $shelf;
    }

    private function upsertShelf(array $payload): Shelf
    {
        $sourceId = (string) Arr::get($payload, 'id');
        $slug = $this->sourceSlug('bookstack-shelf', $payload);
        $shelf = $this->findShelfBySourceOrSlug('shelf', $sourceId, $slug) ?? new Shelf;

        $shelf->forceFill([
            'name' => (string) Arr::get($payload, 'name', 'BookStack shelf '.$sourceId),
            'slug' => $slug,
            'description' => $this->descriptionFromPayload($payload),
            'source_system' => 'book_stack',
            'source_type' => 'shelf',
            'source_id' => $sourceId,
            'source_url' => $this->shelfUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => Arr::only($payload, ['id', 'name', 'slug', 'description', 'description_html', 'books']),
        ])->save();

        return $shelf;
    }

    private function upsertBook(array $payload, Shelf $shelf): Book
    {
        $sourceId = (string) Arr::get($payload, 'id');
        $slug = $this->sourceSlug('bookstack-book', $payload);
        $book = $this->findBookBySourceOrSlug('book', $sourceId, $slug) ?? new Book;

        $book->forceFill([
            'shelf_id' => $shelf->id,
            'name' => (string) Arr::get($payload, 'name', 'BookStack book '.$sourceId),
            'slug' => $slug,
            'description' => $this->descriptionFromPayload($payload),
            'priority' => (int) Arr::get($payload, 'priority', 0),
            'source_system' => 'book_stack',
            'source_type' => 'book',
            'source_id' => $sourceId,
            'source_url' => $this->bookUrl($payload),
            'source_checksum' => $this->payloadChecksum($payload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($payload),
            'sync_status' => 'synced',
            'source_payload' => Arr::only($payload, ['id', 'name', 'slug', 'description', 'description_html', 'created_at', 'updated_at']),
        ])->save();

        return $book;
    }

    private function bookForPage(array $page, array $hierarchy): ?Book
    {
        $bookId = (string) Arr::get($page, 'book_id', Arr::get($page, 'book.id'));

        if ($bookId !== '' && isset($hierarchy['books'][$bookId])) {
            return $hierarchy['books'][$bookId];
        }

        $bookPayload = Arr::get($page, 'book');

        if (is_array($bookPayload) && Arr::get($bookPayload, 'id')) {
            return $this->upsertBook($bookPayload, $hierarchy['default_shelf']);
        }

        return null;
    }

    /**
     * @param  array<string, Book>  $books
     * @return array<string, Chapter>
     */
    private function syncChapters(array $books): array
    {
        $chapters = [];

        foreach ($this->client->allChapters() as $chapterPayload) {
            $chapterId = (string) Arr::get($chapterPayload, 'id');
            $bookId = (string) Arr::get($chapterPayload, 'book_id', Arr::get($chapterPayload, 'book.id'));
            $book = $books[$bookId] ?? null;

            if ($chapterId !== '' && $book) {
                $chapters[$chapterId] = $this->upsertChapter($chapterPayload, $book);
            }
        }

        return $chapters;
    }

    private function chapterForPage(array $page, ?Book $book, array $hierarchy): ?Chapter
    {
        $chapterId = Arr::get($page, 'chapter_id', Arr::get($page, 'chapter.id'));

        if (! $book || ! $chapterId) {
            return null;
        }

        $chapterId = (string) $chapterId;

        if (isset($hierarchy['chapters'][$chapterId])) {
            return $hierarchy['chapters'][$chapterId];
        }

        $chapterPayload = Arr::get($page, 'chapter');

        if (! is_array($chapterPayload)) {
            $chapterPayload = $this->client->readChapter($chapterId);
        }

        return $this->upsertChapter($chapterPayload, $book);
    }

    private function upsertChapter(array $chapterPayload, Book $book): Chapter
    {
        $chapterId = (string) Arr::get($chapterPayload, 'id');
        $slug = $this->sourceSlug('bookstack-chapter', $chapterPayload);
        $chapter = $this->findChapterBySourceOrSlug('chapter', $chapterId, $slug) ?? new Chapter;

        $chapter->forceFill([
            'book_id' => $book->id,
            'name' => (string) Arr::get($chapterPayload, 'name', 'Chapter '.$chapterId),
            'slug' => $slug,
            'description' => $this->descriptionFromPayload($chapterPayload),
            'priority' => (int) Arr::get($chapterPayload, 'priority', 0),
            'source_system' => 'book_stack',
            'source_type' => 'chapter',
            'source_id' => $chapterId,
            'source_url' => $this->chapterUrl($chapterPayload, $book),
            'source_checksum' => $this->payloadChecksum($chapterPayload),
            'source_synced_at' => now(),
            'source_updated_at' => $this->sourceUpdatedAt($chapterPayload),
            'sync_status' => 'synced',
            'source_payload' => Arr::only($chapterPayload, ['id', 'name', 'slug', 'description', 'description_html', 'priority']),
        ])->save();

        return $chapter;
    }

    private function findShelfBySourceOrSlug(string $sourceType, string $sourceId, string $slug): ?Shelf
    {
        // Source metadata is authoritative, but slug fallback repairs older partial imports.
        return Shelf::query()
            ->where('source_system', 'book_stack')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first()
            ?? Shelf::query()->where('slug', $slug)->first();
    }

    private function findBookBySourceOrSlug(string $sourceType, string $sourceId, string $slug): ?Book
    {
        return Book::query()
            ->where('source_system', 'book_stack')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first()
            ?? Book::query()->where('slug', $slug)->first();
    }

    private function findChapterBySourceOrSlug(string $sourceType, string $sourceId, string $slug): ?Chapter
    {
        return Chapter::query()
            ->where('source_system', 'book_stack')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first()
            ?? Chapter::query()->where('slug', $slug)->first();
    }

    private function articleSlug(array $page): string
    {
        $bookSlug = (string) Arr::get($page, 'book.slug', Arr::get($page, 'book_slug', 'bookstack'));
        $pageSlug = (string) Arr::get($page, 'slug', Arr::get($page, 'id'));

        return Str::slug('bookstack-'.$bookSlug.'-'.$pageSlug.'-'.Arr::get($page, 'id'));
    }

    private function sourceSlug(string $prefix, array $payload): string
    {
        return Str::slug($prefix.'-'.Arr::get($payload, 'slug', Arr::get($payload, 'name', Arr::get($payload, 'id'))).'-'.Arr::get($payload, 'id'));
    }

    private function descriptionFromPayload(array $payload): ?string
    {
        $description = trim((string) Arr::get($payload, 'description', ''));

        if ($description !== '') {
            return $description;
        }

        $descriptionHtml = trim((string) Arr::get($payload, 'description_html', ''));

        return $descriptionHtml !== '' ? $this->plainTextFromHtml($descriptionHtml) : null;
    }

    private function plainTextFromHtml(string $html): string
    {
        $text = trim(html_entity_decode(strip_tags($html)));

        return $text !== '' ? $text : 'Imported from BookStack without editable markdown content.';
    }

    private function sourceUrl(array $page): ?string
    {
        $bookSlug = Arr::get($page, 'book.slug', Arr::get($page, 'book_slug'));
        $pageSlug = Arr::get($page, 'slug');

        if (! $bookSlug || ! $pageSlug) {
            return null;
        }

        return rtrim((string) $this->integration->server, '/').'/books/'.$bookSlug.'/page/'.$pageSlug;
    }

    private function shelfUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/shelves/'.$slug : null;
    }

    private function bookUrl(array $payload): ?string
    {
        $slug = Arr::get($payload, 'slug');

        return $slug ? rtrim((string) $this->integration->server, '/').'/books/'.$slug : null;
    }

    private function chapterUrl(array $payload, Book $book): ?string
    {
        $slug = Arr::get($payload, 'slug');

        if (! $slug || ! $book->source_payload || ! isset($book->source_payload['slug'])) {
            return null;
        }

        return rtrim((string) $this->integration->server, '/').'/books/'.$book->source_payload['slug'].'/chapter/'.$slug;
    }

    private function sourceUpdatedAt(array $page): ?Carbon
    {
        $updatedAt = Arr::get($page, 'updated_at');

        return $updatedAt ? Carbon::parse($updatedAt) : null;
    }

    /**
     * Keep only source metadata needed for debugging and future hierarchy mapping.
     *
     * @return array<string, mixed>
     */
    private function sourcePayload(array $page): array
    {
        return Arr::only($page, [
            'id',
            'book_id',
            'chapter_id',
            'slug',
            'priority',
            'revision_count',
            'template',
            'editor',
            'book',
            'chapter',
            'tags',
        ]);
    }

    private function payloadChecksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
