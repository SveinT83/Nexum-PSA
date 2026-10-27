<?php

namespace App\Modules\Knowledge\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Strict transport input and opaque state tokens; immutable revisions own content history. */
class KnowledgeApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Knowledge API field.'));
        }
        if ($request->isJson() && ! $request->isMethod('get') && $request->getContent() !== '') {
            try {
                $value = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($value) && $value !== []) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
        $request->validate(['q' => 'sometimes|string|max:255', 'page' => 'sometimes|integer|min:1',
            'per_page' => 'sometimes|integer|min:1|max:100', 'source_system' => 'sometimes|string|max:50',
            'sync_status' => 'sometimes|string|max:50', 'book_id' => 'sometimes|integer|min:1',
            'shelf_id' => 'sometimes|nullable|integer|min:1', 'chapter_id' => 'sometimes|integer|min:1',
            'category_id' => 'sometimes|nullable|integer|min:1', 'client_scope_id' => 'sometimes|nullable|integer|min:1',
            'status' => 'sometimes|in:draft,published,archived,needs_review',
            'visibility' => 'sometimes|in:internal,client-wide,public']);
    }

    public static function revision(mixed $value): string
    {
        $sort = function (&$data) use (&$sort): void {
            if (! is_array($data)) {
                return;
            }
            if (! array_is_list($data)) {
                ksort($data);
            }
            foreach ($data as &$child) {
                $sort($child);
            }
        };
        $sort($value);

        return '"'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)).'"';
    }

    public static function target(Request $request, bool $lock = false): ?Model
    {
        $parameter = $request->route()->defaults['knowledge_target'] ?? null;
        $model = $parameter ? $request->route($parameter) : null;
        if (! $model instanceof Model) {
            return null;
        }

        return $model->newQuery()->whereKey($model->getKey())->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public static function etag(Request $request): ?string
    {
        $model = self::target($request);
        if ($model) {
            $attributes = $model->getAttributes();
            if ($model instanceof \App\Models\Knowledge\Article) {
                // Reading a page increments views without invalidating an editor's content token.
                unset($attributes['view_count'], $attributes['updated_at']);
                $attributes['tag_ids'] = $model->tags()->orderBy('tags.id')->pluck('tags.id')->all();
            }

            return self::revision($attributes);
        }
        if (($request->route()->defaults['knowledge_settings'] ?? false)) {
            return self::revision(app(KnowledgeSettings::class)->get());
        }

        return null;
    }

    public static function assertCurrent(Request $request): void
    {
        self::target($request, true);
        foreach ($request->route()->parameters() as $model) {
            if ($model instanceof Model) {
                $model->refresh();
            }
        }
        if ($request->hasHeader('If-Match')) {
            $current = self::etag($request);
            abort_unless($current && hash_equals($current, $request->header('If-Match')), 412,
                'Knowledge state changed; reload before retrying.');
        }
    }
}
