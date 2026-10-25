<?php

namespace App\Modules\DataExchange\Http\Middleware;

use App\Modules\DataExchange\Support\DataExchangeAuthority;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Public errors are typed and sanitized; raw database, transport and server details stay internal. */
class DataExchangeApiGuard
{
    public function handle(Request $request, Closure $next)
    {
        $request->headers->set('Accept', 'application/json');
        try {
            foreach ($request->route()->parameters() as $key => $value) {
                if (in_array($key, ['profile', 'run', 'file', 'preview', 'schedule', 'deliveryTarget', 'attempt', 'operation'], true) && is_string($value)) {
                    abort_unless(preg_match('/^[1-9][0-9]*$/D', $value) && strlen($value) <= 19, 404);
                }
            }
            DataExchangeAuthority::actor($request, 'data_exchange.'.$request->route()->defaults['dx_permission']);

            return $next($request);
        } catch (\Illuminate\Validation\ValidationException $error) {
            return response()->json(['message' => 'The supplied input is invalid.', 'errors' => $error->errors()], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json(['message' => 'The requested record is unavailable.'], 404);
        } catch (\Illuminate\Auth\Access\AuthorizationException) {
            return response()->json(['message' => 'Current token and actor authority are required.'], 403);
        } catch (\Illuminate\Database\QueryException $error) {
            $conflict = str_starts_with((string) $error->getCode(), '23') || in_array($error->errorInfo[1] ?? null, [1205, 1213], true);

            return response()->json(['message' => $conflict ? 'The records changed concurrently; read them again.' : 'Data Exchange storage is unavailable.'], $conflict ? 409 : 503);
        } catch (HttpExceptionInterface $error) {
            return response()->json(['message' => $error->getMessage() ?: 'Operation unavailable.'], $error->getStatusCode());
        } catch (\Throwable $error) {
            if (app()->environment('testing')) {
                throw $error;
            }
            report($error);

            return response()->json(['message' => 'The Data Exchange operation failed.'], 500);
        }
    }
}
