<?php

namespace App\Modules\Marketing\Support;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Never expose database/provider exception details or debug traces through Marketing. */
class MarketingApiErrors
{
    public function handle(Request $request, Closure $next)
    {
        try {
            $response = $next($request);
            if ($response->getStatusCode() >= 400) {
                // Laravel's routing pipeline may render exceptions before outer middleware.
                $status = $response->getStatusCode() >= 500 ? 503 : $response->getStatusCode();
                $body = json_decode($response->getContent(), true) ?: [];
                $safe = ['message' => $status === 503 ? 'Marketing operation could not be completed. Read command state before retrying.' : ($body['message'] ?? 'Marketing operation is unavailable.')];
                if (isset($body['errors'])) {
                    $safe['errors'] = $body['errors'];
                }
                if (isset($body['operation_id'])) {
                    $safe['operation_id'] = $body['operation_id'];
                    $safe['replayed'] = $body['replayed'] ?? false;
                }

                return response()->json($safe, $status);
            }

            return $response;
        } catch (ValidationException $error) {
            return response()->json(['message' => 'Invalid Marketing input.', 'errors' => $error->errors()], 422);
        } catch (ModelNotFoundException) {
            return response()->json(['message' => 'Marketing record not found.'], 404);
        } catch (HttpExceptionInterface $error) {
            return response()->json(['message' => $error->getMessage() ?: 'Marketing operation is unavailable.'], $error->getStatusCode());
        } catch (\Throwable $error) {
            Log::error('Marketing API operation failed.', ['exception_class' => $error::class]);

            return response()->json(['message' => 'Marketing operation could not be completed. Read command state before retrying.'], 503);
        }
    }
}
