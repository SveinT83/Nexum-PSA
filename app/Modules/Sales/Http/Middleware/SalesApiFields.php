<?php

namespace App\Modules\Sales\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Static per-operation field lists reject ignored writes, including operations without a form validator. */
class SalesApiFields
{
    public function handle(Request $request, Closure $next)
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            $extra = array_diff(array_keys($request->all()), array_merge($request->route()->defaults['sales_fields'] ?? [], ['_token', '_method']));
            if ($extra !== []) {
                throw ValidationException::withMessages(array_fill_keys($extra, 'Unsupported field.'));
            }
        }

        foreach (['tag_names', 'seller_checklist', 'approval_policy_hints', 'selected_line_ids', 'acknowledgement_ids'] as $field) {
            if ($request->has($field) && $request->input($field) !== null && (! is_array($request->input($field)) || ! array_is_list($request->input($field)))) {
                throw ValidationException::withMessages([$field => 'Use a list, not an object with unsupported keys.']);
            }
        }

        return $next($request);
    }
}
