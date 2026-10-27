<?php

namespace App\Modules\System\Support;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Strict field contracts, canonical byte-aware replay identity and opaque state versions. */
final class SystemApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        if (array_diff(array_keys($request->all()), $keys)) {
            throw ValidationException::withMessages(['body' => 'Unsupported System API field.']);
        }
        if ($request->isJson() && ! $request->isMethodSafe()) {
            try {
                $body = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($body)) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
    }

    public static function hash(mixed $value): string
    {
        $normalize = function ($data) use (&$normalize) {
            if ($data instanceof UploadedFile) {
                return ['sha256' => hash_file('sha256', $data->getRealPath()),
                    'size' => $data->getSize(), 'mime' => $data->getMimeType()];
            }
            if (! is_array($data)) {
                return $data;
            }
            if (! array_is_list($data)) {
                ksort($data);
            }

            return array_map($normalize, $data);
        };

        return hash_hmac('sha256', json_encode($normalize($value), JSON_THROW_ON_ERROR), config('app.key'));
    }

    public static function commandKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 200, $key === null ? 428 : 422, 'Idempotency-Key must contain 8 to 200 characters.');

        return hash('sha256', $key);
    }
}
