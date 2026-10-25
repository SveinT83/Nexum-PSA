<?php

namespace App\Modules\DataExchange\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Reject unsupported keys rather than silently ignoring caller intent. */
final class DataExchangeInput
{
    public static function validate(Request|array $input, array $rules): array
    {
        $data = $input instanceof Request ? $input->all() : $input;
        $allowed = array_unique(array_map(fn ($key) => explode('.', $key)[0], array_keys($rules)));
        $unknown = array_diff(array_keys($data), [...$allowed, '_token', '_method']);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'This field is not supported.'));
        }

        return Validator::make($data, $rules)->validate();
    }
}
