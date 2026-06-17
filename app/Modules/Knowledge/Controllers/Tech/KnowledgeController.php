<?php

namespace App\Modules\Knowledge\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Models\Knowledge\Chapter;
use App\Models\Knowledge\Shelf;
use App\Modules\Knowledge\Actions\DeleteArticle;
use App\Modules\Knowledge\Actions\RecordArticleView;
use App\Modules\Knowledge\Actions\StoreArticle;
use App\Modules\Knowledge\Actions\StoreBook;
use App\Modules\Knowledge\Actions\StoreChapter;
use App\Modules\Knowledge\Actions\StoreShelf;
use App\Modules\Knowledge\Actions\UpdateArticle;
use App\Modules\Knowledge\Queries\ArticleQuery;
use App\Modules\Knowledge\Support\KnowledgeBookStackSync;
use App\Modules\Knowledge\Support\KnowledgeSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Tech-facing HTTP controller for the Knowledge module.
 *
 * The controller keeps HTTP concerns close to the routes: request validation,
 * route-model binding, view selection, and redirects. Article creation/update
 * behavior lives in Actions so the Livewire form and future API endpoints can
 * reuse the same domain operations.
 */
class KnowledgeController extends Controller
{
    /**
     * List knowledge articles for the Tech knowledge base.
     */
    public function index(ArticleQuery $query): View
    {
        return view('knowledge::Tech.index', [
            'shelves' => $query->shelvesForLibrary(),
            'articles' => $query->paginateForTechIndex(),
            'bookStackCanSync' => $this->bookStackSync()->canPull(),
        ]);
    }

    /**
     * Show the books that belong to a Knowledge shelf.
     */
    public function shelf(Shelf $shelf): View
    {
        $shelf->load([
            'books' => fn ($query) => $query
                ->withCount(['chapters', 'pages'])
                ->orderBy('priority')
                ->orderBy('name'),
        ]);

        return view('knowledge::Tech.shelf', compact('shelf'));
    }

    /**
     * Show a BookStack-style book with direct pages and chapter pages.
     */
    public function book(Book $book, ArticleQuery $query): View
    {
        return view('knowledge::Tech.book', [
            'book' => $query->bookWithPages($book),
        ]);
    }

    /**
     * Show the shelf creation screen.
     */
    public function createShelf(): View
    {
        return view('knowledge::Tech.shelf-form', [
            'shelf' => new Shelf,
            'canSyncToBookStack' => $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Create a local shelf in the Knowledge library.
     */
    public function storeShelf(Request $request, StoreShelf $action): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sync_to_book_stack' => 'nullable|boolean',
        ]);
        $syncToBookStack = $this->bookStackTwoWaySyncEnabled() && $request->boolean('sync_to_book_stack');

        unset($validated['sync_to_book_stack']);

        if ($syncToBookStack) {
            $validated['sync_status'] = 'pending_push';
        }

        $shelf = $action->handle($validated);

        if ($syncToBookStack) {
            $this->bookStackSync()->dispatchPush();
        }

        return redirect()->route('tech.knowledge.shelf', $shelf)
            ->with('success', 'Shelf created successfully.');
    }

    /**
     * Show the shelf edit screen.
     */
    public function editShelf(Shelf $shelf): View|RedirectResponse
    {
        if ($shelf->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.shelf', $shelf)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned shelves in Nexum PSA.');
        }

        return view('knowledge::Tech.shelf-form', [
            'shelf' => $shelf,
            'canSyncToBookStack' => $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Update a shelf and queue BookStack-owned changes for push.
     */
    public function updateShelf(Request $request, Shelf $shelf): RedirectResponse
    {
        if ($shelf->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.shelf', $shelf)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned shelves in Nexum PSA.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'sync_to_book_stack' => 'nullable|boolean',
        ]);
        $syncToBookStack = $this->bookStackTwoWaySyncEnabled()
            && ($request->boolean('sync_to_book_stack') || $shelf->source_system === 'book_stack');

        unset($validated['sync_to_book_stack']);

        $shelf->fill($validated);

        if ($shelf->isDirty('name')) {
            $shelf->slug = Str::slug($validated['name']) ?: $shelf->slug;
        }

        if ($syncToBookStack) {
            $shelf->sync_status = 'pending_push';
        }

        $shelf->save();

        if ($syncToBookStack) {
            $this->bookStackSync()->dispatchPush();
        }

        return redirect()->route('tech.knowledge.shelf', $shelf)
            ->with('success', 'Shelf updated successfully.');
    }

    /**
     * Delete an empty shelf. BookStack-owned shelves are deleted in BookStack
     * first so Nexum PSA does not orphan an external record locally.
     */
    public function destroyShelf(Shelf $shelf): RedirectResponse
    {
        if ($shelf->books()->exists()) {
            return redirect()->route('tech.knowledge.shelf', $shelf)
                ->with('warning', 'Only empty shelves can be deleted.');
        }

        if ($shelf->source_system === 'book_stack') {
            if (! $this->bookStackTwoWaySyncEnabled()) {
                return redirect()->route('tech.knowledge.shelf', $shelf)
                    ->with('warning', 'Enable two-way sync before deleting BookStack-owned shelves in Nexum PSA.');
            }

            $client = $this->bookStackClient();

            if (! $client || ! $shelf->source_id) {
                return redirect()->route('tech.knowledge.shelf', $shelf)
                    ->with('warning', 'BookStack credentials are required before deleting this shelf.');
            }

            try {
                $client->deleteShelf($shelf->source_id);
            } catch (\Throwable $exception) {
                return redirect()->route('tech.knowledge.shelf', $shelf)
                    ->with('warning', 'BookStack shelf delete failed: '.$exception->getMessage());
            }
        }

        $shelf->delete();

        return redirect()->route('tech.knowledge.index')
            ->with('success', 'Shelf deleted successfully.');
    }

    /**
     * Show the book creation screen for a shelf.
     */
    public function createBook(Shelf $shelf): View
    {
        return view('knowledge::Tech.book-form', [
            'book' => new Book(['shelf_id' => $shelf->id]),
            'shelf' => $shelf,
            'shelves' => Shelf::query()->orderBy('name')->get(),
            'canSyncToBookStack' => $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Create a local book under the selected shelf.
     */
    public function storeBook(Request $request, Shelf $shelf, StoreBook $action): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
            'sync_to_book_stack' => 'nullable|boolean',
        ]);
        $syncToBookStack = $this->bookStackTwoWaySyncEnabled() && $request->boolean('sync_to_book_stack');

        unset($validated['sync_to_book_stack']);

        if ($syncToBookStack) {
            $validated['sync_status'] = 'pending_push';
        }

        $book = $action->handle($validated + ['shelf_id' => $shelf->id]);

        if ($syncToBookStack) {
            if (blank($shelf->source_system) && $shelf->sync_status !== 'pending_push') {
                $shelf->forceFill(['sync_status' => 'pending_push'])->save();
            }

            $this->bookStackSync()->dispatchPush();
        }

        return redirect()->route('tech.knowledge.book', $book)
            ->with('success', 'Book created successfully.');
    }

    /**
     * Show the book edit screen, including shelf reassignment.
     */
    public function editBook(Book $book): View|RedirectResponse
    {
        $book->load('shelf');

        if ($book->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.book', $book)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned books in Nexum PSA.');
        }

        return view('knowledge::Tech.book-form', [
            'book' => $book,
            'shelf' => $book->shelf,
            'shelves' => Shelf::query()->orderBy('name')->get(),
            'canSyncToBookStack' => $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Update a book and queue BookStack-owned hierarchy changes for push.
     */
    public function updateBook(Request $request, Book $book): RedirectResponse
    {
        $book->load('shelf');

        if ($book->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.book', $book)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned books in Nexum PSA.');
        }

        $validated = $request->validate([
            'shelf_id' => 'required|exists:knowledge_shelves,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
            'sync_to_book_stack' => 'nullable|boolean',
        ]);

        $syncToBookStack = $this->bookStackTwoWaySyncEnabled()
            && ($request->boolean('sync_to_book_stack') || $book->source_system === 'book_stack');

        unset($validated['sync_to_book_stack']);

        $book->fill($validated + ['priority' => (int) ($validated['priority'] ?? 0)]);

        if ($book->isDirty('name')) {
            $book->slug = Str::slug($validated['name']) ?: $book->slug;
        }

        if ($syncToBookStack) {
            $book->sync_status = 'pending_push';
        }

        $book->save();

        if ($syncToBookStack) {
            $this->bookStackSync()->dispatchPush();
        }

        return redirect()->route('tech.knowledge.book', $book)
            ->with('success', 'Book updated successfully.');
    }

    /**
     * Delete an empty book. BookStack-owned books are deleted in BookStack
     * first so Nexum PSA does not orphan external content locally.
     */
    public function destroyBook(Book $book): RedirectResponse
    {
        $shelf = $book->shelf;

        if ($book->chapters()->exists() || Article::query()->where('knowledge_book_id', $book->id)->exists()) {
            return redirect()->route('tech.knowledge.book', $book)
                ->with('warning', 'Only empty books can be deleted.');
        }

        if ($book->source_system === 'book_stack') {
            if (! $this->bookStackTwoWaySyncEnabled()) {
                return redirect()->route('tech.knowledge.book', $book)
                    ->with('warning', 'Enable two-way sync before deleting BookStack-owned books in Nexum PSA.');
            }

            $client = $this->bookStackClient();

            if (! $client || ! $book->source_id) {
                return redirect()->route('tech.knowledge.book', $book)
                    ->with('warning', 'BookStack credentials are required before deleting this book.');
            }

            try {
                $client->deleteBook($book->source_id);
            } catch (\Throwable $exception) {
                return redirect()->route('tech.knowledge.book', $book)
                    ->with('warning', 'BookStack book delete failed: '.$exception->getMessage());
            }
        }

        $book->delete();

        return redirect()->route($shelf ? 'tech.knowledge.shelf' : 'tech.knowledge.index', $shelf ?: [])
            ->with('success', 'Book deleted successfully.');
    }

    /**
     * Show the chapter creation screen for a book.
     */
    public function createChapter(Book $book): View
    {
        $book->load('shelf');

        return view('knowledge::Tech.chapter-form', [
            'chapter' => new Chapter(['book_id' => $book->id]),
            'book' => $book,
            'canSyncToBookStack' => $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Create a local chapter under the selected book.
     */
    public function storeChapter(Request $request, Book $book, StoreChapter $action): RedirectResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
            'sync_to_book_stack' => 'nullable|boolean',
        ]);
        $syncToBookStack = $this->bookStackTwoWaySyncEnabled() && $request->boolean('sync_to_book_stack');

        unset($validated['sync_to_book_stack']);

        if ($syncToBookStack) {
            $validated['sync_status'] = 'pending_push';
        }

        $chapter = $action->handle($validated + ['book_id' => $book->id]);

        if ($syncToBookStack) {
            if (blank($book->source_system) && $book->sync_status !== 'pending_push') {
                $book->forceFill(['sync_status' => 'pending_push'])->save();
            }

            if ($book->shelf && blank($book->shelf->source_system) && $book->shelf->sync_status !== 'pending_push') {
                $book->shelf->forceFill(['sync_status' => 'pending_push'])->save();
            }

            $this->bookStackSync()->dispatchPush();
        }

        return redirect()->route('tech.knowledge.book', $book)
            ->with('success', 'Chapter created successfully.');
    }

    /**
     * Show the chapter edit screen.
     */
    public function editChapter(Chapter $chapter): View|RedirectResponse
    {
        $chapter->load(['book.shelf', 'pages']);

        if ($chapter->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.book', $chapter->book)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned chapters in Nexum PSA.');
        }

        return view('knowledge::Tech.chapter-form', [
            'chapter' => $chapter,
            'book' => $chapter->book,
            'canSyncToBookStack' => $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Update a chapter and queue BookStack-owned changes for push.
     */
    public function updateChapter(Request $request, Chapter $chapter): RedirectResponse
    {
        $chapter->load('book.shelf');

        if ($chapter->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.book', $chapter->book)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned chapters in Nexum PSA.');
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'priority' => 'nullable|integer|min:0',
            'sync_to_book_stack' => 'nullable|boolean',
        ]);
        $syncToBookStack = $this->bookStackTwoWaySyncEnabled() && ($request->boolean('sync_to_book_stack') || $chapter->source_system === 'book_stack');

        unset($validated['sync_to_book_stack']);

        $chapter->fill($validated + ['priority' => (int) ($validated['priority'] ?? 0)]);

        if ($syncToBookStack) {
            $chapter->sync_status = 'pending_push';
        }

        $chapter->save();

        if ($syncToBookStack) {
            $this->bookStackSync()->dispatchPush();
        }

        return redirect()->route('tech.knowledge.book', $chapter->book)
            ->with('success', 'Chapter updated successfully.');
    }

    /**
     * Delete an empty chapter. BookStack-owned chapters are deleted in
     * BookStack first so Nexum PSA does not orphan external content locally.
     */
    public function destroyChapter(Chapter $chapter): RedirectResponse
    {
        $chapter->load('book');
        $book = $chapter->book;

        if ($chapter->pages()->exists()) {
            return redirect()->route('tech.knowledge.chapters.edit', $chapter)
                ->with('warning', 'Only empty chapters can be deleted.');
        }

        if ($chapter->source_system === 'book_stack') {
            if (! $this->bookStackTwoWaySyncEnabled()) {
                return redirect()->route('tech.knowledge.book', $book)
                    ->with('warning', 'Enable two-way sync before deleting BookStack-owned chapters in Nexum PSA.');
            }

            $client = $this->bookStackClient();

            if (! $client || ! $chapter->source_id) {
                return redirect()->route('tech.knowledge.book', $book)
                    ->with('warning', 'BookStack credentials are required before deleting this chapter.');
            }

            try {
                $client->deleteChapter($chapter->source_id);
            } catch (\Throwable $exception) {
                return redirect()->route('tech.knowledge.book', $book)
                    ->with('warning', 'BookStack chapter delete failed: '.$exception->getMessage());
            }
        }

        $chapter->delete();

        return redirect()->route('tech.knowledge.book', $book)
            ->with('success', 'Chapter deleted successfully.');
    }

    /**
     * Show the page creation screen with the current book preselected.
     */
    public function createPageInBook(Book $book): View
    {
        $book->load('shelf');

        return view('knowledge::Tech.form', [
            'article' => new Article([
                'knowledge_shelf_id' => $book->shelf_id,
                'knowledge_book_id' => $book->id,
            ]),
            'book' => $book,
        ]);
    }

    /**
     * Show the article creation screen.
     *
     * The module uses the same Blade page and Livewire component for create and
     * edit. An unsaved Article instance tells the form it is creating a record.
     */
    public function create(KnowledgeSettings $settings): View
    {
        return view('knowledge::Tech.form', [
            'article' => new Article($settings->articleDefaults()),
        ]);
    }

    /**
     * Create an article through the non-Livewire fallback route.
     *
     * The current UI primarily uses the Livewire form, but this route remains
     * useful for progressive enhancement, tests, and future API-like form posts.
     */
    public function store(Request $request, StoreArticle $action, KnowledgeSettings $settings): RedirectResponse
    {
        $request->merge($settings->articleDefaults($request->all()));

        $article = $action->handle($this->validatedArticle($request));
        $this->markArticleForBookStackPushWhenNeeded($article);

        return redirect()->route('tech.knowledge.show', $article)
            ->with('success', 'Article created successfully.');
    }

    /**
     * Display an article and record a view.
     */
    public function show(Article $article, RecordArticleView $recordArticleView): View
    {
        $article->load(['category', 'owner', 'clientScope', 'creator', 'updater', 'tags', 'knowledgeShelf', 'knowledgeBook', 'knowledgeChapter']);
        $recordArticleView->handle($article);

        return view('knowledge::Tech.show', [
            'article' => $article,
            'canEditArticle' => blank($article->source_system) || $this->bookStackTwoWaySyncEnabled(),
        ]);
    }

    /**
     * Show the article edit screen.
     */
    public function edit(Article $article): View|RedirectResponse
    {
        if ($article->source_system && ! $this->bookStackTwoWaySyncEnabled()) {
            return redirect()->route('tech.knowledge.show', $article)
                ->with('warning', 'Enable two-way sync before editing BookStack-owned pages in Nexum PSA.');
        }

        return view('knowledge::Tech.form', compact('article'));
    }

    /**
     * Update an article through the non-Livewire fallback route.
     */
    public function update(Request $request, Article $article, UpdateArticle $action): RedirectResponse
    {
        $action->handle($article, $this->validatedArticle($request));
        $this->markArticleForBookStackPushWhenNeeded($article);

        return redirect()->route('tech.knowledge.show', $article)
            ->with('success', 'Article updated successfully.');
    }

    /**
     * Soft-delete an article and return to the list.
     */
    public function destroy(Article $article, DeleteArticle $action): RedirectResponse
    {
        if ($article->source_system) {
            return redirect()->route('tech.knowledge.show', $article)
                ->with('warning', 'Synced pages must be removed in BookStack.');
        }

        $action->handle($article);

        return redirect()->route('tech.knowledge.index')
            ->with('success', 'Article deleted successfully.');
    }

    /**
     * Validate article fields shared by create/update requests.
     *
     * client_scope_id is only meaningful when visibility is "client-wide".
     * Current validation accepts it as nullable for all visibility values so the
     * UI can hide/show that field without requiring custom request classes.
     */
    private function validatedArticle(Request $request): array
    {
        return $request->validate([
            'title' => 'required|string|max:255',
            'body_markdown' => 'required|string',
            'visibility' => 'required|string|in:internal,client-wide,public',
            'status' => 'required|string|in:draft,published,archived,needs_review',
            'category_id' => 'nullable|exists:categories,id',
            'client_scope_id' => 'nullable|exists:clients,id',
            'knowledge_shelf_id' => 'nullable|exists:knowledge_shelves,id',
            'knowledge_book_id' => 'nullable|exists:knowledge_books,id',
            'knowledge_chapter_id' => 'nullable|exists:knowledge_chapters,id',
            'priority' => 'nullable|integer|min:0',
            'next_review_at' => 'nullable|date',
        ]);
    }

    /**
     * Determine whether local Knowledge records can opt in to BookStack push.
     */
    private function bookStackTwoWaySyncEnabled(): bool
    {
        return $this->bookStackSync()->twoWayEnabled();
    }

    /**
     * Build a BookStack API client when the integration has stored credentials.
     */
    private function bookStackClient()
    {
        return $this->bookStackSync()->client();
    }

    /**
     * BookStack remains the owner, so local edits must be pushed back.
     */
    private function markArticleForBookStackPushWhenNeeded(Article $article): void
    {
        $this->bookStackSync()->markArticleForPushWhenNeeded($article);
    }

    /**
     * Resolve the shared BookStack sync policy helper.
     */
    private function bookStackSync(): KnowledgeBookStackSync
    {
        return app(KnowledgeBookStackSync::class);
    }
}
