<?php

namespace App\Modules\Economy\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Economy has scalar inputs only; unsupported nested/server fields are rejected, including queries. */
class EconomyInput
{
    public static function validate(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_merge(array_keys($rules), ['_token', '_method']));
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This field is not supported by this operation.'));
        }

        return $request->validate($rules);
    }
}
