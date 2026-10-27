<?php

namespace App\Modules\Booking\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Reject unsupported top-level fields without reflecting their values. */
class BookingApiInput
{
    public static function only(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys)) {
            throw ValidationException::withMessages(['input' => 'Unsupported input fields.']);
        }
    }

    public static function revision(array $data): string
    {
        return hash_hmac('sha256', json_encode($data, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
