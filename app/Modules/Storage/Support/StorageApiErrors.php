<?php

namespace App\Modules\Storage\Support;

use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Domain refusals are safe API errors; never return database SQL or provider output. */
class StorageApiErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            $error = $response->exception ?? null;
            if ($error instanceof QueryException) {
                $code = $error->errorInfo[1] ?? null;
                $conflict = str_starts_with((string) $error->getCode(), '23') || in_array($code, [1205, 1213], true);

                return response()->json(['message' => $conflict ? 'Storage data changed concurrently; reload before retrying.'
                    : 'Storage API storage is unavailable; verify the command migration and database.'], $conflict ? 409 : 503);
            }
            if ($error instanceof \InvalidArgumentException || $error instanceof \DomainException) {
                return response()->json(['message' => $error->getMessage(), 'errors' => ['storage' => [$error->getMessage()]]], 422);
            }
            if ($response->getStatusCode() >= 500) {
                return response()->json(['message' => 'Storage could not verify this operation; inspect its operation state before retrying.'], 503);
            }

            return $response;
        } catch (QueryException $error) {
            $code = $error->errorInfo[1] ?? null;
            if (str_starts_with((string) $error->getCode(), '23') || in_array($code, [1205, 1213], true)) {
                abort(409, 'Storage data changed concurrently; reload before retrying.');
            }
            abort(503, 'Storage API storage is unavailable; verify the command migration and database.');
        } catch (\InvalidArgumentException|\DomainException $error) {
            throw ValidationException::withMessages(['storage' => $error->getMessage()]);
        }
    }
}
