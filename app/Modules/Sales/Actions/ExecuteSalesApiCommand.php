<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesApiCommandReceipt;
use App\Modules\Sales\Support\SalesApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Serialize per current actor and exact owner target; persist only authorized result identities. */
class ExecuteSalesApiCommand
{
    public function handle(Request $request, string $operation, array $payload, callable $authorize, callable $execute, bool $required = true): array
    {
        $key = $request->header('Idempotency-Key');
        if (($required && ! $key) || ($key !== null && (! is_string($key) || strlen($key) < 8 || strlen($key) > 200))) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use an Idempotency-Key of 8 to 200 characters.']);
        }
        $fingerprint = hash('sha256', json_encode($this->stable([$request->path(), $payload, $request->header('If-Match')]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $operation, $fingerprint, $authorize, $execute, $key) {
            if ($request->user()) {
                User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            }
            // Authorization also locks the owning opportunity/quote before replay, including capability callers.
            $authorize();
            $query = SalesApiCommandReceipt::query()->where('actor_key', SalesApiAccess::actorKey($request))->where('operation', $operation)
                ->where('key_hash', hash('sha256', (string) $key));
            $receipt = $key ? $query->first() : null;
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $fingerprint), 409, 'This key was used with different input.');

                return $receipt->result + ['operation_id' => $receipt->id, 'replayed' => true];
            }
            $result = $execute();
            if ($key) {
                $receipt = SalesApiCommandReceipt::query()->create([
                    'actor_key' => SalesApiAccess::actorKey($request), 'operation' => $operation,
                    'key_hash' => hash('sha256', $key), 'request_hash' => $fingerprint,
                    'result' => $result, 'created_at' => now(),
                ]);
            }

            return $result + ['operation_id' => $receipt?->id, 'replayed' => false];
        }, 3);
    }

    public function stable(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->stable($item);
            }
        }

        return $value;
    }
}
