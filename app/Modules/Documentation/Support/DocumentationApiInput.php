<?php

namespace App\Modules\Documentation\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class DocumentationApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Documentation API field.'));
        }
        if ($request->isJson() && ! $request->isMethod('get')) {
            try {
                $value = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($value) && ! ($request->isMethod('delete') && $value === [])) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
    }

    public static function page(Request $request): int
    {
        $data = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($data['per_page'] ?? 25);
    }

    /** Run the same carrier normalizer, semantic URL rules and field validator as web. */
    public static function carrier(Request $request): array
    {
        $stringFields = array_diff(\App\Modules\Documentation\Resources\Api\V1\ShippingCarrierResource::FIELDS,
            ['vendor_id', 'sort_order', 'service_tags', 'allowed_tracking_hosts']);
        $rules = array_fill_keys($stringFields, ['sometimes', 'nullable', 'string']);
        $request->validate($rules + ['service_tags' => 'sometimes|nullable|array',
            'service_tags.*' => 'string', 'allowed_tracking_hosts' => 'sometimes|array',
            'allowed_tracking_hosts.*' => 'string']);
        $form = FormRequest::createFrom($request, new \App\Modules\Documentation\Requests\ShippingCarrierRequest);
        $form->setContainer(app())->setRedirector(app('redirect'));
        $form->setUserResolver($request->getUserResolver());
        $form->setRouteResolver($request->getRouteResolver());
        $form->validateResolved();

        return $form->carrierData();
    }

    /** Opaque concurrency token, not the immutable revision history proposed in #275. */
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

    public static function target(Request $request, bool $lock = false): ?Model
    {
        $parameter = $request->route()->defaults['documentation_target'] ?? null;
        $bound = $parameter ? $request->route($parameter) : null;
        if (! $bound instanceof Model) {
            return null;
        }

        return $bound->newQuery()->whereKey($bound->getKey())->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    public static function etag(Request $request): ?string
    {
        return ($model = self::target($request)) ? self::revision($model->getAttributes()) : null;
    }
}
