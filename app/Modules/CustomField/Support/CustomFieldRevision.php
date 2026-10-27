<?php

namespace App\Modules\CustomField\Support;

use App\Modules\CustomField\Models\CustomFieldDefinition;
use Illuminate\Http\Request;

/** Content revisions detect same-second definition edits without changing stored values. */
class CustomFieldRevision
{
    public static function stable(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::stable(...), $value);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::stable($value), JSON_THROW_ON_ERROR));
    }

    public static function of(CustomFieldDefinition $definition): string
    {
        return self::hash($definition->getRawOriginal());
    }

    public static function check(Request $request, CustomFieldDefinition $definition): void
    {
        $revision = $request->header('If-Match');
        abort_unless($revision, 428, 'If-Match is required; read the definition first.');
        abort_unless(hash_equals(self::of($definition), trim($revision, '"')), 412, 'The definition changed; reload and review it.');
    }
}
