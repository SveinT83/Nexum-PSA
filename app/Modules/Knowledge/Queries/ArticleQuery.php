<?php

namespace App\Modules\Knowledge\Queries;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Shelf;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Read model for the Knowledge article index.
 *
 * Keeping the list query outside the controller makes future filtering
 * additions straightforward: status, category, owner, visibility, client scope,
 * review due date, and full-text search can be added here without expanding
 * the controller.
 */
class ArticleQuery
{
    private const MAX_SEARCH_TERMS = 24;

    private const MIN_RELEVANCE_SCORE = 14;

    private const TITLE_TERM_SCORE = 6;

    private const BODY_TERM_SCORE = 2;

    /**
     * Return paginated articles for the Tech knowledge base list.
     *
     * The index displays category and owner metadata, so those relations are
     * eager-loaded to avoid N+1 queries in the table.
     */
    public function paginateForTechIndex(int $perPage = 20): LengthAwarePaginator
    {
        return Article::query()
            ->with(['category', 'owner'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Return shelves with nested books and counts for the Knowledge library.
     */
    public function shelvesForLibrary(): Collection
    {
        return Shelf::query()
            ->withCount('books')
            ->with([
                'books' => fn ($query) => $query
                    ->withCount(['chapters', 'pages'])
                    ->orderBy('priority')
                    ->orderBy('name'),
            ])
            ->orderBy('name')
            ->get();
    }

    /**
     * Load a book with chapters and pages in reading order.
     */
    public function bookWithPages(Book $book): Book
    {
        return $book->load([
            'shelf',
            'pages' => fn ($query) => $query->with(['owner', 'category']),
            'chapters.pages' => fn ($query) => $query->with(['owner', 'category']),
        ]);
    }

    /**
     * Find the strongest published Knowledge articles for a ticket.
     *
     * The search intentionally stays database-portable: ticket text and article
     * content are reduced to normalized terms, and ranking happens in PHP so
     * SQLite tests and MySQL production behave predictably without requiring a
     * full-text index. Every eligible article is scored before the result limit
     * is applied, preventing recent weak matches from hiding older strong ones.
     */
    public function relevantForTicket(Ticket $ticket, int $limit = 3): Collection
    {
        $ticket->loadMissing(['category', 'asset', 'tags']);

        $terms = $this->ticketSearchTerms($ticket);

        if ($terms === []) {
            return new Collection;
        }

        $articles = Article::query()
            ->with(['category', 'knowledgeShelf', 'knowledgeBook'])
            ->where('status', 'published')
            ->whereIn('visibility', ['internal', 'client-wide', 'public'])
            ->where(function ($query) use ($ticket) {
                $query->whereNull('client_scope_id');

                if ($ticket->client_id) {
                    $query->orWhere('client_scope_id', $ticket->client_id);
                }
            })
            ->get();

        return $articles
            ->map(function (Article $article) use ($terms) {
                $article->relevance_score = $this->scoreArticle($article, $terms);

                return $article;
            })
            ->filter(fn (Article $article) => $article->relevance_score >= self::MIN_RELEVANCE_SCORE)
            ->sort(function (Article $left, Article $right) {
                $scoreOrder = $right->relevance_score <=> $left->relevance_score;

                return $scoreOrder !== 0
                    ? $scoreOrder
                    : $left->getKey() <=> $right->getKey();
            })
            ->take($limit)
            ->values();
    }

    /**
     * Build compact search terms from the technician's ticket context.
     */
    private function ticketSearchTerms(Ticket $ticket): array
    {
        $context = collect([
            $ticket->subject,
            $ticket->description,
            $ticket->category?->name,
            $ticket->asset?->name,
        ])
            ->merge($ticket->tags->pluck('name'))
            ->filter()
            ->implode(' ');

        $stopWords = [
            'about', 'after', 'again', 'also', 'and', 'are', 'av', 'cannot',
            'could', 'den', 'det', 'eller', 'en', 'er', 'error', 'et', 'for',
            'fra', 'from', 'have', 'inn', 'into', 'issue', 'kan', 'med', 'not',
            'og', 'på', 'saken', 'skal', 'som', 'the', 'this', 'ticket', 'til',
            'vil', 'with',
        ];

        return collect($this->normalizedTokens($context))
            ->filter(fn ($term) => mb_strlen($term) >= 3 && ! in_array($term, $stopWords, true))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(self::MAX_SEARCH_TERMS)
            ->values()
            ->all();
    }

    /**
     * Rank exact title terms above exact body terms so concise runbooks win.
     */
    private function scoreArticle(Article $article, array $terms): int
    {
        $titleTerms = array_fill_keys($this->normalizedTokens($article->title), true);
        $bodyTerms = array_fill_keys(
            $this->normalizedTokens($article->body_markdown ?: $article->body_html),
            true,
        );
        $score = 0;

        foreach ($terms as $term) {
            if (isset($titleTerms[$term])) {
                $score += self::TITLE_TERM_SCORE;

                continue;
            }

            if (isset($bodyTerms[$term])) {
                $score += self::BODY_TERM_SCORE;
            }
        }

        return $score;
    }

    /**
     * Normalize prose into exact terms while keeping hyphenated words intact.
     */
    private function normalizedTokens(?string $text): array
    {
        return Str::of(strip_tags((string) $text))
            ->lower()
            ->replaceMatches('/(?<=\pL)(?:\p{Pd}|-)(?=\pL)/u', '')
            ->replaceMatches('/[^[:alnum:]\pL]+/u', ' ')
            ->explode(' ')
            ->map(fn ($term) => trim($term))
            ->filter()
            ->values()
            ->all();
    }
}
