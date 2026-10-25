<?php

namespace App\Modules\Commercial\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/** Reject unknown nested fields as well as server-owned top-level fields. */
class CommercialInput
{
    public static function validate(Request $request, array $rules, ?array $merged = null, array $messages = []): array
    {
        if ($request->is('api/*')) {
            $unknown = [];
            foreach (Arr::dot($request->all()) as $key => $value) {
                if (in_array($key, ['_token', '_method'], true)) {
                    continue;
                }
                $allowed = false;
                foreach (array_keys($rules) as $pattern) {
                    if (preg_match('/^'.str_replace('\\*', '[^.]+', preg_quote($pattern, '/')).'$/D', $key)) {
                        $allowed = true;
                        break;
                    }
                }
                if (! $allowed) {
                    $unknown[$key] = 'This field is not supported by this operation.';
                }
            }
            if ($unknown) {
                throw ValidationException::withMessages($unknown);
            }
        }

        return validator($merged ?? $request->all(), $rules, $messages)->validate();
    }
}
