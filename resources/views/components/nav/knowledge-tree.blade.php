@php
    use App\Models\Knowledge\Article;
    use App\Models\Knowledge\Book;
    use App\Models\Knowledge\Chapter;
    use App\Models\Knowledge\Shelf;

    /*
     * BookStack-style content tree for the Knowledge right sidebar.
     *
     * The tree keeps the active branch open based on the current route model:
     * shelf, book, chapter, or page. It intentionally shows the full library so
     * technicians can move between documentation areas without returning to the
     * Knowledge index.
     */
    $routeShelf = request()->route('shelf');
    $routeBook = request()->route('book');
    $routeChapter = request()->route('chapter');
    $routeArticle = request()->route('article');

    $activeShelfId = $routeShelf instanceof Shelf ? $routeShelf->id : null;
    $activeBookId = $routeBook instanceof Book ? $routeBook->id : null;
    $activeChapterId = $routeChapter instanceof Chapter ? $routeChapter->id : null;
    $activePageId = $routeArticle instanceof Article ? $routeArticle->id : null;

    if ($routeBook instanceof Book) {
        $routeBook->loadMissing('shelf');
        $activeShelfId = $routeBook->shelf_id;
    }

    if ($routeChapter instanceof Chapter) {
        $routeChapter->loadMissing('book.shelf');
        $activeBookId = $routeChapter->book_id;
        $activeShelfId = $routeChapter->book?->shelf_id;
    }

    if ($routeArticle instanceof Article) {
        $routeArticle->loadMissing(['knowledgeBook.shelf', 'knowledgeChapter.book.shelf']);
        $activeBookId = $routeArticle->knowledge_book_id;
        $activeChapterId = $routeArticle->knowledge_chapter_id;
        $activeShelfId = $routeArticle->knowledgeBook?->shelf_id
            ?: $routeArticle->knowledgeChapter?->book?->shelf_id;
    }

    $shelves = Shelf::query()
        ->with([
            'books' => fn ($query) => $query
                ->with([
                    'pages:id,title,knowledge_book_id,knowledge_chapter_id,priority,updated_at',
                    'chapters' => fn ($query) => $query
                        ->with('pages:id,title,knowledge_book_id,knowledge_chapter_id,priority,updated_at')
                        ->orderBy('priority')
                        ->orderBy('name'),
                ])
                ->withCount(['chapters', 'pages'])
                ->orderBy('priority')
                ->orderBy('name'),
        ])
        ->withCount('books')
        ->orderBy('name')
        ->get();
@endphp

<!-- ------------------------------------------------- -->
<!-- Knowledge Content Tree -->
<!-- ------------------------------------------------- -->
<nav class="knowledge-tree" aria-label="Knowledge content navigation">
    <div class="d-flex align-items-center justify-content-between gap-2 mb-2">
        <div class="small text-uppercase fw-semibold text-muted">Knowledge</div>
        <a href="{{ route('tech.knowledge.index') }}" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Open Knowledge library">
            <i class="bi bi-house-door" aria-hidden="true"></i>
            <span class="visually-hidden">Open Knowledge library</span>
        </a>
    </div>

    <div class="knowledge-tree-scroll">
        @forelse($shelves as $shelf)
            @php
                $isShelfActive = (int) $activeShelfId === (int) $shelf->id;
                $shelfCollapseId = 'knowledgeTreeShelf' . $shelf->id;
            @endphp

            <section class="knowledge-tree-group">
                <div class="knowledge-tree-row {{ $isShelfActive ? 'is-active' : '' }}">
                    <a href="{{ route('tech.knowledge.shelf', $shelf) }}" class="knowledge-tree-link" @if($isShelfActive) aria-current="page" @endif>
                        <i class="bi bi-bookshelf" aria-hidden="true"></i>
                        <span class="knowledge-tree-label">{{ $shelf->name }}</span>
                    </a>
                    <button
                        class="knowledge-tree-toggle"
                        type="button"
                        data-bs-toggle="collapse"
                        data-bs-target="#{{ $shelfCollapseId }}"
                        aria-expanded="{{ $isShelfActive ? 'true' : 'false' }}"
                        aria-controls="{{ $shelfCollapseId }}"
                        title="Toggle shelf books">
                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                        <span class="visually-hidden">Toggle {{ $shelf->name }}</span>
                    </button>
                </div>

                <div id="{{ $shelfCollapseId }}" class="collapse {{ $isShelfActive ? 'show' : '' }}">
                    <div class="knowledge-tree-children">
                        @forelse($shelf->books as $book)
                            @php
                                $isBookActive = (int) $activeBookId === (int) $book->id;
                                $bookCollapseId = 'knowledgeTreeBook' . $book->id;
                            @endphp

                            <div class="knowledge-tree-item">
                                <div class="knowledge-tree-row knowledge-tree-row-book {{ $isBookActive ? 'is-active' : '' }}">
                                    <a href="{{ route('tech.knowledge.book', $book) }}" class="knowledge-tree-link" @if($isBookActive && ! $activeChapterId && ! $activePageId) aria-current="page" @endif>
                                        <i class="bi bi-journal-text" aria-hidden="true"></i>
                                        <span class="knowledge-tree-label">{{ $book->name }}</span>
                                    </a>
                                    <button
                                        class="knowledge-tree-toggle"
                                        type="button"
                                        data-bs-toggle="collapse"
                                        data-bs-target="#{{ $bookCollapseId }}"
                                        aria-expanded="{{ $isBookActive ? 'true' : 'false' }}"
                                        aria-controls="{{ $bookCollapseId }}"
                                        title="Toggle book contents">
                                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                        <span class="visually-hidden">Toggle {{ $book->name }}</span>
                                    </button>
                                </div>

                                <div id="{{ $bookCollapseId }}" class="collapse {{ $isBookActive ? 'show' : '' }}">
                                    <div class="knowledge-tree-children knowledge-tree-children-book">
                                        @foreach($book->pages as $page)
                                            @php
                                                $isPageActive = (int) $activePageId === (int) $page->id;
                                            @endphp
                                            <a href="{{ route('tech.knowledge.show', $page) }}" class="knowledge-tree-page {{ $isPageActive ? 'is-active' : '' }}" @if($isPageActive) aria-current="page" @endif>
                                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                                <span class="knowledge-tree-label">{{ $page->title }}</span>
                                            </a>
                                        @endforeach

                                        @foreach($book->chapters as $chapter)
                                            @php
                                                $isChapterActive = (int) $activeChapterId === (int) $chapter->id;
                                                $chapterCollapseId = 'knowledgeTreeChapter' . $chapter->id;
                                            @endphp

                                            <div class="knowledge-tree-item">
                                                <div class="knowledge-tree-row knowledge-tree-row-chapter {{ $isChapterActive ? 'is-active' : '' }}">
                                                    <a href="{{ route('tech.knowledge.chapters.edit', $chapter) }}" class="knowledge-tree-link" @if($isChapterActive && ! $activePageId) aria-current="page" @endif>
                                                        <i class="bi bi-collection" aria-hidden="true"></i>
                                                        <span class="knowledge-tree-label">{{ $chapter->name }}</span>
                                                    </a>
                                                    <button
                                                        class="knowledge-tree-toggle"
                                                        type="button"
                                                        data-bs-toggle="collapse"
                                                        data-bs-target="#{{ $chapterCollapseId }}"
                                                        aria-expanded="{{ $isChapterActive ? 'true' : 'false' }}"
                                                        aria-controls="{{ $chapterCollapseId }}"
                                                        title="Toggle chapter pages">
                                                        <i class="bi bi-chevron-down" aria-hidden="true"></i>
                                                        <span class="visually-hidden">Toggle {{ $chapter->name }}</span>
                                                    </button>
                                                </div>

                                                <div id="{{ $chapterCollapseId }}" class="collapse {{ $isChapterActive ? 'show' : '' }}">
                                                    <div class="knowledge-tree-children knowledge-tree-children-chapter">
                                                        @forelse($chapter->pages as $page)
                                                            @php
                                                                $isPageActive = (int) $activePageId === (int) $page->id;
                                                            @endphp
                                                            <a href="{{ route('tech.knowledge.show', $page) }}" class="knowledge-tree-page {{ $isPageActive ? 'is-active' : '' }}" @if($isPageActive) aria-current="page" @endif>
                                                                <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
                                                                <span class="knowledge-tree-label">{{ $page->title }}</span>
                                                            </a>
                                                        @empty
                                                            <div class="knowledge-tree-empty">No pages</div>
                                                        @endforelse
                                                    </div>
                                                </div>
                                            </div>
                                        @endforeach

                                        @if($book->pages->isEmpty() && $book->chapters->isEmpty())
                                            <div class="knowledge-tree-empty">No chapters or pages</div>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @empty
                            <div class="knowledge-tree-empty">No books</div>
                        @endforelse
                    </div>
                </div>
            </section>
        @empty
            <div class="text-muted small">No shelves yet.</div>
        @endforelse
    </div>
</nav>
