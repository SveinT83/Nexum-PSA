<?php

namespace App\Modules\Knowledge\Actions;

use App\Models\Knowledge\Chapter;
use Illuminate\Support\Str;

/**
 * Creates a local Knowledge chapter inside a book.
 *
 * Chapters mirror BookStack's optional third hierarchy level and give teams a
 * structured place for grouped pages without requiring external sync.
 */
class StoreChapter
{
    /**
     * Persist a chapter from validated form data.
     */
    public function handle(array $data): Chapter
    {
        $data['slug'] = $this->uniqueSlug($data['name']);
        $data['priority'] = (int) ($data['priority'] ?? 0);
        $data['source_system'] = $data['source_system'] ?? null;
        $data['sync_status'] = $data['sync_status'] ?? 'local';

        return Chapter::create($data);
    }

    /**
     * Generate a URL-safe slug that does not collide with existing chapters.
     */
    private function uniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name) ?: 'chapter';
        $slug = $baseSlug;
        $counter = 2;

        while (Chapter::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$counter;
            $counter++;
        }

        return $slug;
    }
}
