<?php

namespace App\Modules\Signal\Support;

use App\Modules\Signal\Models\Signal;
use App\Modules\Signal\Models\SignalRuleExecution;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SignalApiInput
{
    public static function allowed(Request $request, array $keys): void
    {
        $unknown = array_diff(array_keys($request->all()), $keys);
        if ($unknown) {
            throw ValidationException::withMessages(array_fill_keys($unknown, 'Unsupported Signal API field.'));
        }
        if ($request->isJson() && ! $request->isMethodSafe()) {
            try {
                $value = json_decode($request->getContent(), false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw ValidationException::withMessages(['body' => 'A valid JSON object is required.']);
            }
            if (! is_object($value)) {
                throw ValidationException::withMessages(['body' => 'A JSON object is required.']);
            }
        }
    }

    public static function page(Request $request): int
    {
        $v = $request->validate(['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return (int) ($v['per_page'] ?? 50);
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

    public static function target(Request $request, bool $lock = false): ?\Illuminate\Database\Eloquent\Model
    {
        $parameter = $request->route()->defaults['signal_target'] ?? null;
        $bound = $parameter ? $request->route($parameter) : null;
        if (! $bound instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }
        // All execution entry points serialize on the Signal row, including browser retry.
        if ($bound instanceof SignalRuleExecution && $lock) {
            Signal::whereKey($bound->signal_id)->lockForUpdate()->firstOrFail();
        }

        return $bound->newQuery()->whereKey($bound->getKey())->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    /** Retry revisions include the complete chain, not only second-resolution timestamps. */
    public static function etag(Request $request): ?string
    {
        $target = $request->route()->defaults['signal_target'] ?? null;
        if ($target === 'settings') {
            return self::revision(app(SignalSettings::class)->get());
        }
        $model = $target ? $request->route($target) : null;
        if (! $model instanceof \Illuminate\Database\Eloquent\Model) {
            return null;
        }
        $model = $model->fresh();
        if (! $model) {
            return null;
        }
        if ($model instanceof SignalRuleExecution) {
            $root = $model->retry_of_execution_id ?: $model->id;

            return self::revision(SignalRuleExecution::where('id', $root)->orWhere('retry_of_execution_id', $root)->orderBy('id')->get()->map->getAttributes()->all());
        }
        if ($model instanceof Signal) {
            return self::revision([$model->getAttributes(), $model->executions()->orderBy('id')->get()->map->getAttributes()->all()]);
        }

        return self::revision($model->getAttributes());
    }
}
