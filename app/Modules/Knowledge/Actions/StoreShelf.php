<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Shelf;
use Illuminate\Support\Str;

/**
 * Creates a local Knowledge shelf.
 *
 * Shelves are the top-level BookStack-compatible containers in tdPSA. Local
 * shelves stay source-neutral so they can later participate in two-way sync.
 */
class StoreShelf
{
    /**
     * Persist a shelf from validated form data.
     */
    public function handle(array $data): Shelf
    {
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['source_system'] = $data['source_system'] ?? null;
        $data['sync_status'] = $data['sync_status'] ?? 'local';

        return Shelf::create($data);
    }

    /**
     * Generate a URL-safe slug that does not collide with existing shelves.
     */
    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'shelf';
        $slug = $baseSlug;
        $counter = 2;

        while (Shelf::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
