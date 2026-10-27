<?php

namespace App\Modules\Nextcloud\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Scoped framework rendering prevents debug/SQL/provider secrets escaping API failures. */
class NextcloudApiPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('api/v1/nextcloud', 'api/v1/nextcloud/*');
    }

    public static function render(\Throwable $error, Request $request)
    {
        if (! self::matches($request)) {
            return null;
        }
        if ($error instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
            return $error->getResponse();
        }
        if ($error instanceof ValidationException) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $error->errors()], 422)->header('Cache-Control', 'no-store');
        }
        $status = match (true) {
            $error instanceof \Illuminate\Auth\AuthenticationException => 401,
            $error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException => 404,
            $error instanceof \Illuminate\Database\UniqueConstraintViolationException => 409,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };
        $message = $error instanceof HttpExceptionInterface ? ($error->getMessage() ?: 'Operation denied.') : match ($status) {
            401 => 'Unauthenticated.',404 => 'Record unavailable.',409 => 'State changed or request already processed.',default => 'Nextcloud API operation failed.'
        };

        return response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store');
    }
}
