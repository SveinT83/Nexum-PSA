<?php

namespace App\Modules\Knowledge\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Article;
use App\Models\Knowledge\Book;
use App\Modules\Knowledge\Actions\StoreBook;
use App\Modules\Knowledge\Resources\Api\V1\KnowledgeBookResource;
use App\Modules\Knowledge\Support\KnowledgeBookStackSync;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KnowledgeBookController extends Controller
{
    public function __construct(private readonly KnowledgeBookStackSync $bookStackSync) {}

    public function index(Request $request)
    {
        $query = Book::query()
            ->with('shelf')
            ->withCount(['chapters', 'pages'])
            ->orderBy('priority')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('slug', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        foreach (['shelf_id', 'source_system', 'sync_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $field === 'shelf_id' ? $request->integer($field) : $request->input($field));
            }
        }

        return KnowledgeBookResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function store(Request $request, StoreBook $storeBook)
    {
        $data = $this->validatedBook($request);
        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        unset($data['sync_to_book_stack']);

        if ($syncToBookStack) {
            $data['sync_status'] = 'pending_push';
        }

        $book = $storeBook->handle($data)->load('shelf');

        if ($syncToBookStack) {
            $this->bookStackSync->markBookForPush($book);
            $this->bookStackSync->dispatchPush();
        }

        return (new KnowledgeBookResource($this->loadBook($book)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Book $book)
    {
        return new KnowledgeBookResource($this->loadBook($book));
    }

    public function update(Request $request, Book $book)
    {
        $book->load('shelf');
        $this->ensureBookStackOwnedRecordCanBeEdited($book);

        $data = $this->validatedBook($request, $book);
        $syncToBookStack = $request->boolean('sync_to_book_stack') || $book->source_system === 'book_stack';
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        unset($data['sync_to_book_stack']);

        if (array_key_exists('priority', $data)) {
            $data['priority'] = (int) ($data['priority'] ?? 0);
        }

        $book->fill($data);

        if ($book->isDirty('name')) {
            $book->slug = Str::slug((string) $data['name']) ?: $book->slug;
        }

        if ($syncToBookStack) {
            $book->sync_status = 'pending_push';
        }

        $book->save();
        $book->load('shelf');

        if ($syncToBookStack) {
            $this->bookStackSync->markBookForPush($book);
            $this->bookStackSync->dispatchPush();
        }

        return new KnowledgeBookResource($this->loadBook($book));
    }

    public function destroy(Book $book)
    {
        if ($book->chapters()->exists() || Article::query()->where('knowledge_book_id', $book->id)->exists()) {
            throw ValidationException::withMessages([
                'book' => 'Only empty books can be deleted.',
            ]);
        }

        if ($book->source_system === 'book_stack') {
            $this->ensureBookStackOwnedRecordCanBeEdited($book);

            $client = $this->bookStackSync->client();

            if (! $client || ! $book->source_id) {
                throw ValidationException::withMessages([
                    'book_stack' => 'BookStack credentials are required before deleting this book.',
                ]);
            }

            $client->deleteBook($book->source_id);
        }

        $book->delete();

        return response()->noContent();
    }

    private function validatedBook(Request $request, ?Book $book = null): array
    {
        return $request->validate([
            'shelf_id' => ['sometimes', 'nullable', Rule::exists('knowledge_shelves', 'id')],
            'name' => [$book ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'priority' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'sync_to_book_stack' => ['sometimes', 'boolean'],
        ]);
    }

    private function ensureTwoWaySyncWhenRequested(bool $syncToBookStack): void
    {
        if ($syncToBookStack && ! $this->bookStackSync->twoWayEnabled()) {
            throw ValidationException::withMessages([
                'sync_to_book_stack' => 'BookStack two-way sync must be active before this record can be pushed.',
            ]);
        }
    }

    private function ensureBookStackOwnedRecordCanBeEdited(Book $book): void
    {
        if ($book->source_system && ! $this->bookStackSync->twoWayEnabled()) {
            throw ValidationException::withMessages([
                'book_stack' => 'Enable two-way sync before editing BookStack-owned books in Nexum PSA.',
            ]);
        }
    }

    private function loadBook(Book $book): Book
    {
        return $book->load('shelf')->loadCount(['chapters', 'pages']);
    }
}
