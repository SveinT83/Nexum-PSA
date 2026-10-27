<?php

namespace App\Modules\Intake\Support;

use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/** Strict transport keys and canonical content fingerprints, including actual uploaded bytes. */
class IntakeApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Intake API field.'));
        }
        if ($request->isJson() && ! $request->isMethod('get')) {
            if ($keys === [] && in_array(trim($request->getContent()), ['', '[]'], true)) {
                return;
            }
            try {
                $body = json_decode($request->getContent(), false, 128, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($body)) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
    }

    public static function canonical(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return ['name' => $value->getClientOriginalName(), 'size' => $value->getSize(),
                'sha256' => hash_file('sha256', $value->getRealPath())];
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value);
            }

            return array_map(self::canonical(...), $value);
        }

        return $value;
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', json_encode(self::canonical($value), JSON_THROW_ON_ERROR));
    }

    public static function revision(IntakeForm|IntakeSubmission $record): string
    {
        $data = $record->fresh()->getAttributes();
        if ($record instanceof IntakeForm) {
            $data['fields'] = $record->fields()->get()->map(fn ($field) => $field->getAttributes())->all();
        } else {
            $data['form_revision'] = $record->form ? self::revision($record->form) : null;
            $data['event_count'] = $record->events()->count();
            $data['attachments'] = $record->attachments()->get()->map(fn ($file) => $file->getAttributes())->all();
        }

        return '"'.self::hash($data).'"';
    }

    public static function page(Request $request): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($data['per_page'] ?? 25);
    }

    public static function key(Request $request, bool $public = false): string
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= ($public ? 32 : 8) && strlen($key) <= 200,
            $key ? 422 : 428, 'A valid Idempotency-Key is required.');

        return hash('sha256', $key);
    }
}
