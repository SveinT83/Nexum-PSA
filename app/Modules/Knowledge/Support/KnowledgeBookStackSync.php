<?php

namespace App\Modules\Knowledge\Support;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Integration\Services\BookStack\BookStackClient;

/**
 * Shared BookStack sync policy for Knowledge UI and API entry points.
 *
 * Keeping this outside controllers prevents AI/API workflows from drifting
 * away from the technician UI rules for pending pushes and integration health.
 */
class KnowledgeBookStackSync
{
    public function integration(): ?Integration
    {
        return Integration::where('type', 'book_stack')->first();
    }

    public function canPull(): bool
    {
        $integration = $this->integration();

        return (bool) (
            $integration?->status === 'active'
            && $integration->server
            && $integration->getSecret('token_id')
            && $integration->getSecret('token_secret')
        );
    }

    public function twoWayEnabled(): bool
    {
        $integration = $this->integration();
        $config = $integration?->config ?? [];

        return (bool) (
            $integration?->status === 'active'
            && $integration->server
            && ($config['two_way_sync_enabled'] ?? false)
        );
    }

    public function client(): ?BookStackClient
    {
        $integration = $this->integration();
        $tokenId = $integration?->getSecret('token_id');
        $tokenSecret = $integration?->getSecret('token_secret');

        if (! $integration?->server || ! $tokenId || ! $tokenSecret) {
            return null;
        }

        return new BookStackClient($integration->server, $tokenId, $tokenSecret);
    }

    public function dispatchPush(): void
    {
        PushPendingKnowledgeToBookStack::dispatch();
    }

    public function markShelfForPush(Shelf $shelf): void
    {
        $shelf->forceFill(['sync_status' => 'pending_push'])->save();
    }

    public function markBookForPush(Book $book, bool $includeParents = true): void
    {
        $book->forceFill(['sync_status' => 'pending_push'])->save();

        if ($includeParents && $book->shelf && blank($book->shelf->source_system)) {
            $this->markShelfForPush($book->shelf);
        }
    }

    public function markChapterForPush(Chapter $chapter, bool $includeParents = true): void
    {
        $chapter->forceFill(['sync_status' => 'pending_push'])->save();

        if (! $includeParents) {
            return;
        }

        $chapter->loadMissing('book.shelf');

        if ($chapter->book && blank($chapter->book->source_system)) {
            $this->markBookForPush($chapter->book);
        } elseif ($chapter->book?->shelf && blank($chapter->book->shelf->source_system)) {
            $this->markShelfForPush($chapter->book->shelf);
        }
    }

    public function markArticleForPush(Article $article, bool $includeParents = true): void
    {
        $article->forceFill(['sync_status' => 'pending_push'])->save();

        if (! $includeParents) {
            return;
        }

        $article->loadMissing(['knowledgeBook.shelf', 'knowledgeChapter.book.shelf']);

        if ($article->knowledgeChapter && blank($article->knowledgeChapter->source_system)) {
            $this->markChapterForPush($article->knowledgeChapter);

            return;
        }

        if ($article->knowledgeBook && blank($article->knowledgeBook->source_system)) {
            $this->markBookForPush($article->knowledgeBook);
        }
    }

    public function markArticleForPushWhenNeeded(Article $article): bool
    {
        if (! $this->twoWayEnabled()) {
            return false;
        }

        $article->loadMissing(['knowledgeBook', 'knowledgeChapter']);

        $shouldPush = $article->source_system === 'book_stack'
            || $article->knowledgeChapter?->source_system === 'book_stack'
            || $article->knowledgeBook?->source_system === 'book_stack';

        if (! $shouldPush) {
            return false;
        }

        $this->markArticleForPush($article, includeParents: false);
        $this->dispatchPush();

        return true;
    }
}
