<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Book;
use Illuminate\Support\Str;

/**
 * Creates a local Knowledge book inside an optional shelf.
 *
 * Books mirror BookStack's second hierarchy level and provide the normal place
 * where technicians add pages.
 */
class StoreBook
{
    /**
     * Persist a book from validated form data.
     */
    public function handle(array $data): Book
    {
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['priority'] = (int) ($data['priority'] ?? 0);
        $data['source_system'] = $data['source_system'] ?? null;
        $data['sync_status'] = $data['sync_status'] ?? 'local';

        return Book::create($data);
    }

    /**
     * Generate a URL-safe slug that does not collide with existing books.
     */
    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'book';
        $slug = $baseSlug;
        $counter = 2;

        while (Book::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
