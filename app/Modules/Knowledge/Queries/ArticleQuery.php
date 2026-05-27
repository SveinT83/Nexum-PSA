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
     * The search intentionally stays database-portable: ticket text is reduced
     * to a small keyword set, candidates are fetched with LIKE filters, and the
     * final ranking happens in PHP so SQLite tests and MySQL production behave
     * predictably without requiring a full-text index.
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
            ->where(function ($query) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%' . addcslashes($term, '%_\\') . '%';

                    $query->orWhere('title', 'like', $like)
                        ->orWhere('body_markdown', 'like', $like)
                        ->orWhere('body_html', 'like', $like);
                }
            })
            ->latest('updated_at')
            ->limit(30)
            ->get();

        return $articles
            ->map(function (Article $article) use ($terms) {
                $article->relevance_score = $this->scoreArticle($article, $terms);

                return $article;
            })
            ->filter(fn (Article $article) => $article->relevance_score > 0)
            ->sortByDesc('relevance_score')
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
            'about', 'after', 'again', 'also', 'cannot', 'could', 'error', 'from',
            'have', 'into', 'issue', 'med', 'not', 'og', 'på', 'saken', 'the',
            'this', 'ticket', 'til', 'with',
        ];

        return Str::of($context)
            ->lower()
            ->replaceMatches('/[^[:alnum:]\pL]+/u', ' ')
            ->explode(' ')
            ->map(fn ($term) => trim($term))
            ->filter(fn ($term) => mb_strlen($term) >= 3 && ! in_array($term, $stopWords, true))
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * Rank title hits above body hits so a concise runbook title wins.
     */
    private function scoreArticle(Article $article, array $terms): int
    {
        $title = Str::lower($article->title);
        $body = Str::lower(strip_tags((string) ($article->body_markdown ?: $article->body_html)));
        $score = 0;

        foreach ($terms as $term) {
            if (Str::contains($title, $term)) {
                $score += 8;
            }

            if (Str::contains($body, $term)) {
                $score += 2;
            }
        }

        return $score;
    }
}
