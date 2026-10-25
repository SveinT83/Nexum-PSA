<?php

namespace App\Modules\Economy\Actions;

use App\Modules\Economy\Models\EconomyCommandReceipt;
use App\Modules\Economy\Support\EconomyAuthority;
use App\Modules\Economy\Support\EconomyMutex;
use App\Modules\Economy\Support\EconomyRevision;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Serialized receipts survive deletion and always recheck the current user's rights before replay. */
class ExecuteEconomyCommand
{
    public function handle(Request $request, array $input, callable $execute, bool $required = true): array
    {
        $key = $request->header('Idempotency-Key');
        if (($required && ! $key) || ($key !== null && (strlen($key) < 8 || strlen($key) > 200))) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use an Idempotency-Key of 8 to 200 characters.']);
        }
        $hash = EconomyRevision::hash([$request->method(), $request->path(), $input, $request->header('If-Match')]);

        return app(EconomyMutex::class)->run(function () use ($request, $execute, $key, $hash) {
            EconomyAuthority::check($request);
            $operation = $request->route()->getName();
            $receipt = $key ? EconomyCommandReceipt::query()->where('actor_id', $request->user()->id)->where('operation', $operation)->where('key_hash', hash('sha256', $key))->first() : null;
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This key was already used with different input.');

                return $receipt->result + ['operation_id' => $receipt->id, 'replayed' => true];
            }
            $result = $execute();
            if ($key) {
                $receipt = EconomyCommandReceipt::query()->create(['actor_id' => $request->user()->id, 'operation' => $operation, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'result' => $result]);
            }

            return $result + ['operation_id' => $receipt?->id, 'replayed' => false];
        }, false);
    }
}
