<?php

namespace App\Modules\Relationship\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Sensitive API/connection material is never debug output, telemetry or flashed form input. */
class RelationshipApiPrivacy
{
    public static function matches(Request $request): bool
    {
        return $request->is('api/v1/relationships*', 'api/v1/nexum/relationships*',
            'api/v1/tickets/*/relationships/*', 'api/v1/tickets/*/relationship-*',
            'tech/admin/system/relationships*', 'tech/tickets/*/relationships/*');
    }

    public static function render(\Throwable $error, Request $request)
    {
        if (! self::matches($request) || ! $request->is('api/*')) {
            return null;
        }
        if ($error instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
            return $error->getResponse()->header('Cache-Control', 'no-store');
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
        $message = match (true) {
            $error instanceof HttpExceptionInterface => $error->getMessage() ?: 'Operation denied.',
            $status === 401 => 'Unauthenticated.', $status === 404 => 'Record unavailable.',
            $status === 409 => 'State changed or request already processed.',
            default => 'Relationship operation failed.',
        };

        return response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store');
    }

    public function handle(Request $request, Closure $next)
    {
        return $next($request)->header('Cache-Control', 'no-store');
    }
}
