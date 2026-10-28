<?php

namespace App\Modules\Task\Support;

use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Str;

/** Shared form normalization; tags and checklist text keep their browser meaning. */
class TaskFormValues
{
    public function parseChecklist(?string $text): array
    {
        if (blank($text)) {
            return [];
        }

        return collect(preg_split('/\r\n|\r|\n/', $text))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->values()
            ->map(fn (string $line, int $index) => [
                'title' => $line,
                'sort_order' => ($index + 1) * 10,
            ])
            ->all();
    }

    public function resolveTagIds(array $tagNames): array
    {
        return collect($tagNames)
            ->map(fn (?string $name) => trim((string) $name))
            ->filter()
            ->unique(fn (string $name) => Str::lower($name))
            ->map(function (string $name) {
                $slug = Str::slug($name);

                $tag = Tag::query()
                    ->where('slug', $slug)
                    ->orWhere('name', $name)
                    ->first();

                if (! $tag) {
                    $tag = Tag::query()->create([
                        'slug' => $slug,
                        'name' => $name,
                        'active' => true,
                    ]);
                }

                return $tag->id;
            })
            ->values()
            ->all();
    }
}
