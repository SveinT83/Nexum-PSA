<?php

namespace App\Modules\Integration\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Explicit route field lists reject ignored writes, including actions validated in multiple shared form stages. */
final class RejectUnsupportedDomainApiInput
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = array_merge($request->route()->defaults['parity_input_fields'] ?? [], ['_token', '_method']);
        $unsupported = array_diff(array_keys($request->all()), $allowed);
        if ($unsupported !== []) {
            $errors = [];
            foreach ($unsupported as $field) {
                $errors[$field] = 'This field is not supported by this API operation.';
            }
            throw ValidationException::withMessages($errors);
        }

        return $next($request);
    }
}
