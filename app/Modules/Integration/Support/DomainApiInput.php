<?php

namespace App\Modules\Integration\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Reject unsupported mutation fields instead of returning success for silently discarded input. */
final class DomainApiInput
{
    public static function validate(Request $request, array $rules, array $contextFields = []): array
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $allowed = array_unique(array_merge(array_map(fn ($key) => explode('.', $key)[0], array_keys($rules)), $contextFields, $request->route()?->defaults['parity_input_fields'] ?? [], ['_token', '_method']));
            $unsupported = array_diff(array_keys($request->all()), $allowed);
            if ($unsupported !== []) {
                $errors = [];
                foreach ($unsupported as $field) {
                    $errors[$field] = 'This field is not supported by this API operation.';
                }
                throw ValidationException::withMessages($errors);
            }
        }

        return $request->validate($rules);
    }
}
