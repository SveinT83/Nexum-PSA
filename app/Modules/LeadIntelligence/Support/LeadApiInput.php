<?php

namespace App\Modules\LeadIntelligence\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Strict transport inputs and opaque concurrency tokens; no hidden persistence. */
class LeadApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Lead Intelligence API field.'));
        }
        if ($request->isJson() && ! $request->isMethod('get')) {
            try {
                $value = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($value) && ! ($value === [] && $keys === [])) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
    }

    public static function page(Request $request, int $default = 25): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($data['per_page'] ?? $default);
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

        return '"'.hash('sha256', json_encode($value, JSON_THROW_ON_ERROR)).'"';
    }

    public static function etag(Request $request): ?string
    {
        foreach ($request->route()->parameters() as $value) {
            if ($value instanceof Model) {
                return self::revision($value->fresh()->getAttributes());
            }
        }
        if (str_contains($request->path(), 'lead-intelligence/settings')) {
            return self::revision(app(LeadIntelligenceSettings::class)->get());
        }

        return null;
    }
}
