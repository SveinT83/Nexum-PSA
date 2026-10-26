<?php

namespace App\Modules\Asset\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AssetInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), array_merge($keys, ['_token', '_method']));
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Asset API field.'));
        }
    }

    public static function revision(mixed $value): string
    {
        return '"'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)).'"';
    }

    public static function match(Request $request, mixed $value): void
    {
        if ($request->hasHeader('If-Match')) {
            abort_unless(hash_equals(self::revision($value), $request->header('If-Match')), 409, 'Asset state changed; reload before editing.');
        }
    }
}
