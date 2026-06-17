<?php

namespace App\Modules\Knowledge\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Chapter;
use App\Modules\Knowledge\Actions\StoreChapter;
use App\Modules\Knowledge\Resources\Api\V1\KnowledgeChapterResource;
use App\Modules\Knowledge\Support\KnowledgeBookStackSync;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class KnowledgeChapterController extends Controller
{
    public function __construct(private readonly KnowledgeBookStackSync $bookStackSync) {}

    public function index(Request $request)
    {
        $query = Chapter::query()
            ->with('book')
            ->withCount('pages')
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

        foreach (['book_id', 'source_system', 'sync_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $field === 'book_id' ? $request->integer($field) : $request->input($field));
            }
        }

        return KnowledgeChapterResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function store(Request $request, StoreChapter $storeChapter)
    {
        $data = $this->validatedChapter($request);
        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        unset($data['sync_to_book_stack']);

        if ($syncToBookStack) {
            $data['sync_status'] = 'pending_push';
        }

        $chapter = $storeChapter->handle($data)->load('book.shelf');

        if ($syncToBookStack) {
            $this->bookStackSync->markChapterForPush($chapter);
            $this->bookStackSync->dispatchPush();
        }

        return (new KnowledgeChapterResource($this->loadChapter($chapter)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Chapter $chapter)
    {
        return new KnowledgeChapterResource($this->loadChapter($chapter));
    }

    public function update(Request $request, Chapter $chapter)
    {
        $chapter->load('book.shelf');
        $this->ensureBookStackOwnedRecordCanBeEdited($chapter);

        $data = $this->validatedChapter($request, $chapter);
        $syncToBookStack = $request->boolean('sync_to_book_stack') || $chapter->source_system === 'book_stack';
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        unset($data['sync_to_book_stack']);

        if (array_key_exists('priority', $data)) {
            $data['priority'] = (int) ($data['priority'] ?? 0);
        }

        $chapter->fill($data);

        if ($syncToBookStack) {
            $chapter->sync_status = 'pending_push';
        }

        $chapter->save();
        $chapter->load('book.shelf');

        if ($syncToBookStack) {
            $this->bookStackSync->markChapterForPush($chapter);
            $this->bookStackSync->dispatchPush();
        }

        return new KnowledgeChapterResource($this->loadChapter($chapter));
    }

    public function destroy(Chapter $chapter)
    {
        $chapter->load('book');

        if ($chapter->pages()->exists()) {
            throw ValidationException::withMessages([
                'chapter' => 'Only empty chapters can be deleted.',
            ]);
        }

        if ($chapter->source_system === 'book_stack') {
            $this->ensureBookStackOwnedRecordCanBeEdited($chapter);

            $client = $this->bookStackSync->client();

            if (! $client || ! $chapter->source_id) {
                throw ValidationException::withMessages([
                    'book_stack' => 'BookStack credentials are required before deleting this chapter.',
                ]);
            }

            $client->deleteChapter($chapter->source_id);
        }

        $chapter->delete();

        return response()->noContent();
    }

    private function validatedChapter(Request $request, ?Chapter $chapter = null): array
    {
        return $request->validate([
            'book_id' => [$chapter ? 'sometimes' : 'required', Rule::exists('knowledge_books', 'id')],
            'name' => [$chapter ? 'sometimes' : 'required', 'string', 'max:255'],
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

    private function ensureBookStackOwnedRecordCanBeEdited(Chapter $chapter): void
    {
        if ($chapter->source_system && ! $this->bookStackSync->twoWayEnabled()) {
            throw ValidationException::withMessages([
                'book_stack' => 'Enable two-way sync before editing BookStack-owned chapters in Nexum PSA.',
            ]);
        }
    }

    private function loadChapter(Chapter $chapter): Chapter
    {
        return $chapter->load('book')->loadCount('pages');
    }
}
