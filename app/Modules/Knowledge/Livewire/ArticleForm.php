<?php

namespace App\Modules\Knowledge\Livewire;

use App\Models\Clients\Client;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Jobs\PushPendingKnowledgeToBookStack;
use App\Modules\Knowledge\Actions\StoreArticle;
use App\Modules\Knowledge\Actions\UpdateArticle;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use App\Modules\Taxonomy\Models\Category;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Livewire form for creating and editing knowledge articles.
 *
 * The component owns UI state only: field values, select options, validation,
 * and the save event. Persistence is delegated to StoreArticle/UpdateArticle so
 * the same business behavior is shared with the standard controller routes.
 */
class ArticleForm extends Component
{
    /** The article being created or edited. */
    public Article $article;

    /** Category options shown in the form. */
    public $categories;

    /** Client options used when visibility is client-wide. */
    public $clients;

    /** Knowledge structure options used to place pages in a BookStack-style library. */
    public $shelves;

    public $books;

    public $chapters;

    public ?string $title = null;

    public ?string $body_markdown = null;

    public $category_id = null;

    public $knowledge_shelf_id = null;

    public $knowledge_book_id = null;

    public $knowledge_chapter_id = null;

    public int $priority = 0;

    public string $visibility = 'internal';

    public string $status = 'published';

    public $client_scope_id = null;

    public ?string $next_review_at = null;

    /**
     * Hydrate the form from an existing article or apply sensible defaults.
     */
    public function mount(Article $article): void
    {
        $this->article = $article;
        $this->categories = Category::orderBy('name')->get();
        $this->clients = Client::where('active', true)->orderBy('name')->get();
        $this->shelves = Shelf::query()->orderBy('name')->get();
        $this->books = collect();
        $this->chapters = collect();

        if ($article->exists) {
            $this->title = $article->title;
            $this->body_markdown = $article->body_markdown;
            $this->category_id = $article->category_id;
            $this->knowledge_shelf_id = $article->knowledge_shelf_id;
            $this->knowledge_book_id = $article->knowledge_book_id;
            $this->knowledge_chapter_id = $article->knowledge_chapter_id;
            $this->priority = $article->priority;
            $this->visibility = $article->visibility;
            $this->status = $article->status;
            $this->client_scope_id = $article->client_scope_id;
            $this->next_review_at = $article->next_review_at?->format('Y-m-d');
            $this->refreshBookOptions();
            $this->refreshChapterOptions();

            return;
        }

        $defaults = app(KnowledgeSettings::class)->articleDefaults();

        $this->status = $defaults['status'];
        $this->visibility = $defaults['visibility'];
        $this->priority = $defaults['priority'];
        $this->knowledge_shelf_id = $article->knowledge_shelf_id;
        $this->knowledge_book_id = $article->knowledge_book_id;
        $this->knowledge_chapter_id = $article->knowledge_chapter_id;
        $this->next_review_at = $defaults['next_review_at'];
        $this->refreshBookOptions();
        $this->refreshChapterOptions();
    }

    /**
     * Refresh book options when the selected shelf changes.
     */
    public function updatedKnowledgeShelfId(): void
    {
        $this->knowledge_book_id = null;
        $this->knowledge_chapter_id = null;
        $this->refreshBookOptions();
        $this->refreshChapterOptions();
    }

    /**
     * Refresh chapter options when the selected book changes.
     */
    public function updatedKnowledgeBookId(): void
    {
        $this->knowledge_chapter_id = null;
        $this->refreshChapterOptions();
    }

    /**
     * Only show books that belong to the selected shelf.
     */
    private function refreshBookOptions(): void
    {
        $this->books = empty($this->knowledge_shelf_id)
            ? collect()
            : Book::query()
                ->where('shelf_id', $this->knowledge_shelf_id)
                ->orderBy('priority')
                ->orderBy('name')
                ->get();
    }

    /**
     * Only show chapters that belong to the selected book.
     */
    private function refreshChapterOptions(): void
    {
        $this->chapters = empty($this->knowledge_book_id)
            ? collect()
            : Chapter::query()
                ->where('book_id', $this->knowledge_book_id)
                ->orderBy('priority')
                ->orderBy('name')
                ->get();
    }

    /**
     * Validate and persist the article.
     *
     * Store/update actions are resolved from Laravel's container here so their
     * dependencies stay injectable while the Livewire method remains simple.
     */
    public function save()
    {
        $validated = $this->validate($this->rules());

        if ($this->article->exists) {
            app(UpdateArticle::class)->handle($this->article, $validated);
            $this->markArticleForBookStackPushWhenNeeded();
            $message = 'Article updated successfully.';
        } else {
            $this->article = app(StoreArticle::class)->handle($validated);
            $this->markArticleForBookStackPushWhenNeeded();
            $message = 'Article created successfully.';
        }

        session()->flash('success', $message);

        return redirect()->route('tech.knowledge.show', $this->article);
    }

    /**
     * Validation rules shared by create and edit modes.
     */
    protected function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'knowledge_shelf_id' => 'nullable|exists:knowledge_shelves,id',
            'knowledge_book_id' => [
                'nullable',
                Rule::exists('knowledge_books', 'id')
                    ->where(fn ($query) => $query->where('shelf_id', $this->knowledge_shelf_id)),
            ],
            'knowledge_chapter_id' => [
                'nullable',
                Rule::exists('knowledge_chapters', 'id')
                    ->where(fn ($query) => $query->where('book_id', $this->knowledge_book_id)),
            ],
            'priority' => 'nullable|integer|min:0',
            'client_scope_id' => 'nullable|exists:clients,id',
            'next_review_at' => 'nullable|date',
        ];
    }

    /**
     * Render the module-local Livewire view.
     */
    public function render()
    {
        return view('knowledge::Livewire.article-form');
    }

    /**
     * Pages placed under BookStack-owned hierarchy must be pushed back.
     */
    private function markArticleForBookStackPushWhenNeeded(): void
    {
        if (! $this->bookStackTwoWaySyncEnabled()) {
            return;
        }

        $this->article->loadMissing(['knowledgeBook', 'knowledgeChapter']);

        $shouldPush = $this->article->source_system === 'book_stack'
            || $this->article->knowledgeChapter?->source_system === 'book_stack'
            || $this->article->knowledgeBook?->source_system === 'book_stack';

        if (! $shouldPush) {
            return;
        }

        $this->article->forceFill(['sync_status' => 'pending_push'])->save();
        PushPendingKnowledgeToBookStack::dispatch();
    }

    private function bookStackTwoWaySyncEnabled(): bool
    {
        $integration = Integration::where('type', 'book_stack')->first();
        $config = $integration?->config ?? [];

        return (bool) (
            $integration?->status === 'active'
            && $integration->server
            && ($config['two_way_sync_enabled'] ?? false)
        );
    }
}
