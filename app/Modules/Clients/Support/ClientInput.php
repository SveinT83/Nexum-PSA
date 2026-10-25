<?php

namespace App\Modules\Clients\Support;

use App\Modules\CustomField\Actions\SyncCustomFieldValues;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Owner policy must reject attempted hidden fields instead of confirming an ignored write. */
class ClientInput
{
    public static function fields(Request $request, Model $record): void
    {
        $values = (array) $request->input('custom_fields', []);
        $definitions = app(SyncCustomFieldValues::class)->definitionsFor($record, $request->user(), 'api')->keyBy('key');
        foreach ($values as $key => $value) {
            if (! $definitions->has($key)) {
                throw ValidationException::withMessages(['custom_fields.'.$key => 'This field is not editable through this API for the current actor.']);
            }
        }
    }

    public static function allowed(Request $request, array $keys): void
    {
        $unsupported = array_diff(array_keys($request->all()), array_merge($keys, ['_token', '_method']));
        if ($unsupported) {
            throw ValidationException::withMessages(array_fill_keys($unsupported, 'This field is not supported by this API operation.'));
        }
    }
}
