<?php

namespace App\Modules\Knowledge\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Knowledge\Shelf;
use App\Modules\Knowledge\Actions\StoreShelf;
use App\Modules\Knowledge\Resources\Api\V1\KnowledgeShelfResource;
use App\Modules\Knowledge\Support\KnowledgeBookStackSync;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class KnowledgeShelfController extends Controller
{
    public function __construct(private readonly KnowledgeBookStackSync $bookStackSync) {}

    public function index(Request $request)
    {
        $query = Shelf::query()
            ->withCount('books')
            ->orderBy('name');

        if ($request->filled('q')) {
            $needle = '%'.trim((string) $request->input('q')).'%';
            $query->where(function ($inner) use ($needle): void {
                $inner->where('name', 'like', $needle)
                    ->orWhere('slug', 'like', $needle)
                    ->orWhere('description', 'like', $needle);
            });
        }

        foreach (['source_system', 'sync_status'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        return KnowledgeShelfResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    public function store(Request $request, StoreShelf $storeShelf)
    {
        $data = $this->validatedShelf($request);
        $syncToBookStack = $request->boolean('sync_to_book_stack');
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        unset($data['sync_to_book_stack']);

        if ($syncToBookStack) {
            $data['sync_status'] = 'pending_push';
        }

        $shelf = $storeShelf->handle($data);

        if ($syncToBookStack) {
            $this->bookStackSync->dispatchPush();
        }

        return (new KnowledgeShelfResource($this->loadShelf($shelf)))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Shelf $shelf)
    {
        return new KnowledgeShelfResource($this->loadShelf($shelf));
    }

    public function update(Request $request, Shelf $shelf)
    {
        $this->ensureBookStackOwnedRecordCanBeEdited($shelf);

        $data = $this->validatedShelf($request, $shelf);
        $syncToBookStack = $request->boolean('sync_to_book_stack') || $shelf->source_system === 'book_stack';
        $this->ensureTwoWaySyncWhenRequested($syncToBookStack);

        unset($data['sync_to_book_stack']);

        $shelf->fill($data);

        if ($shelf->isDirty('name')) {
            $shelf->slug = Str::slug((string) $data['name']) ?: $shelf->slug;
        }

        if ($syncToBookStack) {
            $shelf->sync_status = 'pending_push';
        }

        $shelf->save();

        if ($syncToBookStack) {
            $this->bookStackSync->dispatchPush();
        }

        return new KnowledgeShelfResource($this->loadShelf($shelf));
    }

    public function destroy(Shelf $shelf)
    {
        if ($shelf->books()->exists()) {
            throw ValidationException::withMessages([
                'shelf' => 'Only empty shelves can be deleted.',
            ]);
        }

        if ($shelf->source_system === 'book_stack') {
            $this->ensureBookStackOwnedRecordCanBeEdited($shelf);

            $client = $this->bookStackSync->client();

            if (! $client || ! $shelf->source_id) {
                throw ValidationException::withMessages([
                    'book_stack' => 'BookStack credentials are required before deleting this shelf.',
                ]);
            }

            $client->deleteShelf($shelf->source_id);
        }

        $shelf->delete();

        return response()->noContent();
    }

    private function validatedShelf(Request $request, ?Shelf $shelf = null): array
    {
        return $request->validate([
            'name' => [$shelf ? 'sometimes' : 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
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

    private function ensureBookStackOwnedRecordCanBeEdited(Shelf $shelf): void
    {
        if ($shelf->source_system && ! $this->bookStackSync->twoWayEnabled()) {
            throw ValidationException::withMessages([
                'book_stack' => 'Enable two-way sync before editing BookStack-owned shelves in Nexum PSA.',
            ]);
        }
    }

    private function loadShelf(Shelf $shelf): Shelf
    {
        return $shelf->loadCount('books');
    }
}
