<?php

namespace App\Modules\Nextcloud\Support;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Strict inputs, keyed fingerprints and connection-wide optimistic revisions. */
class NextcloudApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Nextcloud API field.'));
        }
        if ($request->isJson() && ! $request->isMethod('get')) {
            try {
                $value = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($value) && $value !== []) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
    }

    public static function page(Request $request): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($data['per_page'] ?? 25);
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

        return '"'.hash_hmac('sha256', json_encode($value, JSON_THROW_ON_ERROR), config('app.key')).'"';
    }

    public static function etag(Request $request): ?string
    {
        $connection = $request->route('connection');
        if (! $connection instanceof NextcloudConnection || ! $connection->exists) {
            return null;
        }
        $connection = $connection->fresh();
        if (! $connection) {
            return null;
        }

        return self::revision([$connection->getAttributes(),
            $connection->userMappings()->orderBy('id')->get()->map->getAttributes()->all(),
            $connection->groupMappings()->orderBy('id')->get()->map->getAttributes()->all(),
            $connection->calendarMappings()->orderBy('id')->get()->map->getAttributes()->all(),
            $connection->folderMappings()->orderBy('id')->get()->map->getAttributes()->all()]);
    }

    public static function path(string $path): string
    {
        $decoded = rawurldecode($path);
        abort_if(strlen($decoded) > 500 || preg_match('~(?:^|/)\.\.?(?:/|$)|[\\\\\x00-\x1f?#]~', $decoded), 422, 'Use a Nextcloud path without traversal or URL parameters.');

        return '/'.trim($decoded, '/');
    }
}
