<?php

namespace App\Modules\Integration\Support;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class RmmAlertExecutionError
{
    /**
     * Persist only bounded, operator-safe summaries. Raw SQL, provider output,
     * endpoint details, and exception messages remain out of the audit tables.
     */
    public static function summarize(Throwable $exception): string
    {
        if ($exception instanceof ValidationException) {
            $message = collect($exception->errors())->flatten()->first();

            return Str::limit((string) ($message ?: 'RMM action validation failed.'), 500, '');
        }
        if ($exception instanceof AuthorizationException) {
            return 'RMM automation was not authorized for the target action.';
        }
        if ($exception instanceof ModelNotFoundException) {
            return 'A configured RMM action target no longer exists.';
        }
        if ($exception instanceof QueryException) {
            return 'The RMM action could not complete its database operation.';
        }

        return 'RMM processing failed ('.class_basename($exception).').';
    }
}
