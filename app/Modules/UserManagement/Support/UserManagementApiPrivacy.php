<?php

namespace App\Modules\UserManagement\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Sensitive operations are no-store and never flash or log credential-bearing requests. */
class UserManagementApiPrivacy
{
    public static function matches(Request $request): bool
    {
        $action = $request->route()?->getActionName();

        return $request->is('api/v1/*') && is_string($action)
            && str_starts_with($action, 'App\\Modules\\UserManagement\\Controllers\\');
    }

    /** Laravel's routing pipeline renders exceptions before outer middleware catches them. */
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
            $error instanceof \App\Modules\UserManagement\Security\Exceptions\UserSecurityMutationDenied => 403,
            $error instanceof \App\Modules\UserManagement\Sso\SsoFailure => 422,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };
        $message = match (true) {
            $error instanceof HttpExceptionInterface => $error->getMessage() ?: 'Operation denied.',
            $status === 401 => 'Unauthenticated.', $status === 404 => 'Record unavailable.',
            $status === 409 => 'State changed or request already processed.',
            $status === 403 => 'protected_mutation_denied', $status === 422 => 'Work account operation could not be completed.',
            default => 'UserManagement operation failed.',
        };

        return response()->json(['message' => $message], $status)->header('Cache-Control', 'no-store');
    }

    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
        } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
            $response = $error->getResponse();
        } catch (ValidationException $error) {
            $response = response()->json(['message' => 'The given data was invalid.', 'errors' => $error->errors()], 422);
        } catch (\App\Modules\UserManagement\Sso\SsoFailure $error) {
            $response = response()->json(['message' => 'Work account operation could not be completed.'], 422);
        } catch (\Throwable $error) {
            if ($error instanceof HttpExceptionInterface) {
                $response = response()->json(['message' => $error->getMessage()], $error->getStatusCode(), $error->getHeaders());
            } elseif ($error instanceof \App\Modules\UserManagement\Security\Exceptions\UserSecurityMutationDenied) {
                $response = response()->json(['message' => 'protected_mutation_denied'], 403);
            } elseif ($error instanceof \Illuminate\Database\Eloquent\ModelNotFoundException) {
                $response = response()->json(['message' => 'Record unavailable.'], 404);
            } else {
                \Illuminate\Support\Facades\Log::error('UserManagement API operation failed.', ['exception_class' => get_class($error)]);
                $response = response()->json(['message' => 'UserManagement operation failed.'], 500);
            }
        } finally {
            foreach (['current_password', 'password', 'password_confirmation', 'code', 'recovery_code', 'client_secret', 'logout_token'] as $key) {
                $request->request->remove($key);
            }
        }

        return $response->header('Cache-Control', 'no-store');
    }
}
