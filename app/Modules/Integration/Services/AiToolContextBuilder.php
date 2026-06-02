<?php

namespace App\Modules\Integration\Services;

use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Modules\Integration\Models\AiAgent;
use App\Modules\Integration\Models\AiChat;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use Illuminate\Support\Str;

class AiToolContextBuilder
{
    /**
     * Run safe read-only tools allowed by the agent and return model context.
     */
    public function build(AiAgent $agent, string $latestUserMessage, ?AiChat $chat = null): string
    {
        $dataSources = $agent->data_sources ?? [];
        $tools = $agent->allowed_tools ?? [];
        $sections = [];

        if (in_array('knowledge', $dataSources, true) && $this->hasKnowledgeSearch($tools)) {
            $sections[] = $this->knowledgeContext($latestUserMessage);
        }

        if (in_array('active_tickets', $dataSources, true) && $this->hasTicketRead($tools, $agent->allowed_api_scopes ?? [])) {
            $sections[] = $this->ticketContext($latestUserMessage, $chat);
        }

        return collect($sections)->filter()->implode("\n\n");
    }

    private function knowledgeContext(string $latestUserMessage): string
    {
        $knowledge = $this->searchKnowledge($latestUserMessage);

        if ($knowledge['articles']->isEmpty() && $knowledge['books']->isEmpty()) {
            return "Tool: knowledge.search\nResult: No matching Knowledge records were found for the user's latest question.";
        }

        $lines = [
            'Tool: knowledge.search',
            'Instruction: Use these Nexum PSA Knowledge results when answering. If the user asks whether a book or article exists, answer from these results and name the matching records. If the user asks for a link, return the provided URL as a markdown link.',
        ];

        if ($knowledge['books']->isNotEmpty()) {
            $lines[] = 'Books:';

            foreach ($knowledge['books'] as $book) {
                $lines[] = sprintf(
                    '- [%d] %s%s | url: %s%s',
                    $book->id,
                    $book->name,
                    $book->shelf ? ' (shelf: '.$book->shelf->name.')' : '',
                    route('tech.knowledge.book', $book),
                    $book->description ? ' - '.Str::limit(preg_replace('/\s+/', ' ', $book->description), 180) : ''
                );
            }
        }

        if ($knowledge['articles']->isNotEmpty()) {
            $lines[] = 'Articles:';

            foreach ($knowledge['articles'] as $article) {
                $location = collect([
                    $article->knowledgeShelf?->name,
                    $article->knowledgeBook?->name,
                    $article->knowledgeChapter?->name,
                ])->filter()->join(' / ');

                $lines[] = sprintf(
                    '- [%d] %s%s | url: %s - %s',
                    $article->id,
                    $article->title,
                    $location ? ' ('.$location.')' : '',
                    route('tech.knowledge.show', $article),
                    Str::limit(preg_replace('/\s+/', ' ', strip_tags($article->body_markdown ?: $article->body_html ?: '')), 260)
                );
            }
        }

        return implode("\n", $lines);
    }

    private function ticketContext(string $latestUserMessage, ?AiChat $chat): string
    {
        $user = $chat?->user;
        $pageContext = $chat?->metadata['page_context'] ?? [];
        $domain = $pageContext['domain'] ?? null;

        if (! $user || ($domain && $domain !== 'tickets' && ! Str::contains(Str::lower($latestUserMessage), ['ticket', 'tickets', 'sak', 'saker']))) {
            return '';
        }

        $baseQuery = Ticket::query()
            ->with(['priority', 'status', 'client', 'queue'])
            ->where('owner_id', $user->id)
            ->whereHas('status', fn ($query) => $query->where('is_closed', false));

        $assignedOpenCount = (clone $baseQuery)->count();
        $unreadCount = (clone $baseQuery)->where('is_unread', true)->count();
        $overdueResponseCount = (clone $baseQuery)->whereNotNull('first_response_due_at')->where('first_response_due_at', '<', now())->count();
        $overdueResolveCount = (clone $baseQuery)->whereNotNull('resolve_due_at')->where('resolve_due_at', '<', now())->count();

        $tickets = (clone $baseQuery)
            ->orderByDesc('is_unread')
            ->orderByRaw('CASE WHEN first_response_due_at IS NOT NULL AND first_response_due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderByRaw('CASE WHEN resolve_due_at IS NOT NULL AND resolve_due_at < ? THEN 0 ELSE 1 END', [now()])
            ->orderBy(TicketPriority::select('level')->whereColumn('ticket_priorities.id', 'tickets.priority_id'))
            ->latest('updated_at')
            ->limit(8)
            ->get();

        $lines = [
            'Tool: tickets.read',
            'Instruction: Use these live Nexum PSA ticket results to answer ticket count and prioritization questions. Prioritize unread tickets, overdue SLA, lower priority level values, and recent updates.',
            'Summary:',
            '- Open tickets assigned to current user: '.$assignedOpenCount,
            '- Unread assigned tickets: '.$unreadCount,
            '- Overdue first response: '.$overdueResponseCount,
            '- Overdue resolution: '.$overdueResolveCount,
        ];

        if ($tickets->isEmpty()) {
            $lines[] = 'Prioritized tickets: none assigned and open.';

            return implode("\n", $lines);
        }

        $lines[] = 'Prioritized tickets:';

        foreach ($tickets as $ticket) {
            $signals = collect([
                $ticket->is_unread ? 'unread' : null,
                $ticket->first_response_due_at && $ticket->first_response_due_at->isPast() ? 'first response overdue' : null,
                $ticket->resolve_due_at && $ticket->resolve_due_at->isPast() ? 'resolution overdue' : null,
            ])->filter()->join(', ');

            $lines[] = sprintf(
                '- %s: %s | priority: %s | status: %s | client: %s | queue: %s | updated: %s%s',
                $ticket->ticket_key,
                $ticket->subject,
                $ticket->priority?->name ?? 'Unknown',
                $ticket->status?->name ?? 'Unknown',
                $ticket->client?->name ?? 'Unknown',
                $ticket->queue?->name ?? 'Unknown',
                $ticket->updated_at?->format('Y-m-d H:i') ?? 'unknown',
                $signals ? ' | signals: '.$signals : ''
            );
        }

        return implode("\n", $lines);
    }

    /**
     * Search books and articles using portable LIKE queries, then rank locally.
     */
    private function searchKnowledge(string $query): array
    {
        $terms = $this->searchTerms($query);

        if ($terms === []) {
            return [
                'books' => collect(),
                'articles' => collect(),
            ];
        }

        $books = Book::query()
            ->with('shelf')
            ->where(function ($bookQuery) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%' . addcslashes($term, '%_\\') . '%';
                    $bookQuery->orWhere('name', 'like', $like)
                        ->orWhere('description', 'like', $like);
                }
            })
            ->limit(20)
            ->get()
            ->map(fn (Book $book) => tap($book, fn ($item) => $item->ai_score = $this->score($item->name.' '.$item->description, $terms, 7)))
            ->filter(fn (Book $book) => $book->ai_score > 0)
            ->sortByDesc('ai_score')
            ->take(5)
            ->values();

        $articles = Article::query()
            ->with(['knowledgeShelf', 'knowledgeBook', 'knowledgeChapter'])
            ->where('status', 'published')
            ->where(function ($articleQuery) use ($terms) {
                foreach ($terms as $term) {
                    $like = '%' . addcslashes($term, '%_\\') . '%';
                    $articleQuery->orWhere('title', 'like', $like)
                        ->orWhere('body_markdown', 'like', $like)
                        ->orWhere('body_html', 'like', $like);
                }
            })
            ->limit(30)
            ->get()
            ->map(fn (Article $article) => tap($article, fn ($item) => $item->ai_score = $this->score($item->title.' '.$item->body_markdown.' '.$item->body_html, $terms, 5)))
            ->filter(fn (Article $article) => $article->ai_score > 0)
            ->sortByDesc('ai_score')
            ->take(5)
            ->values();

        return [
            'books' => $books,
            'articles' => $articles,
        ];
    }

    private function searchTerms(string $query): array
    {
        $stopWords = [
            'about', 'eller', 'for', 'har', 'have', 'hva', 'ikke', 'med', 'om',
            'oss', 'the', 'til', 'ticket', 'vi',
        ];

        return Str::of($query)
            ->lower()
            ->replaceMatches('/[^[:alnum:]\pL]+/u', ' ')
            ->explode(' ')
            ->map(fn ($term) => trim($term))
            ->filter(fn ($term) => mb_strlen($term) >= 3 && ! in_array($term, $stopWords, true))
            ->unique()
            ->take(12)
            ->values()
            ->all();
    }

    private function score(string $text, array $terms, int $weight): int
    {
        $haystack = Str::lower(strip_tags($text));

        return collect($terms)->sum(fn ($term) => Str::contains($haystack, $term) ? $weight : 0);
    }

    private function hasKnowledgeSearch(array $tools): bool
    {
        return in_array('knowledge.search', $tools, true) || in_array('search', $tools, true);
    }

    private function hasTicketRead(array $tools, array $scopes): bool
    {
        return (in_array('records.read', $tools, true) || in_array('read_records', $tools, true))
            && in_array('tickets.read', $scopes, true);
    }
}
