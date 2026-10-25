<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Models\DataExchangeCommandReceipt;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Support\DataExchangeAuthority;
use App\Modules\DataExchange\Support\DataExchangeMutex;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Repeated identical intent replays only after fresh actor and owner authorization. */
class ExecuteDataExchangeCommand
{
    public function handle(Request $request, array $input, callable $execute, ?DataExchangeProfile $profile = null, string $mode = 'manage', bool $required = true): array
    {
        $key = $request->header('Idempotency-Key');
        if (($required && ! $key) || ($key !== null && (strlen($key) < 8 || strlen($key) > 200))) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use an Idempotency-Key of 8 to 200 characters.']);
        }
        $hash = DataExchangeRevision::hash([$request->method(), $request->path(), $input, $request->header('If-Match')]);

        return app(DataExchangeMutex::class)->run(function () use ($request, $execute, $profile, $mode, $key, $hash) {
            DataExchangeAuthority::actor($request, 'data_exchange.'.$mode);
            if ($profile) {
                DataExchangeAuthority::profile($request, $profile->fresh(), $mode);
            }
            $operation = $request->route()->getName();
            $receipt = $key ? DataExchangeCommandReceipt::query()->where('actor_id', $request->user()->id)->where('operation', $operation)->where('key_hash', hash('sha256', $key))->first() : null;
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This key was already used with different input.');
                if ($receipt->profile_id) {
                    DataExchangeAuthority::profile($request, DataExchangeProfile::withTrashed()->find($receipt->profile_id), $mode);
                }

                return $receipt->result + ['operation_id' => $receipt->id, 'replayed' => true];
            }
            $result = $execute();
            if ($key) {
                $receipt = DataExchangeCommandReceipt::query()->create([
                    'actor_id' => $request->user()->id, 'profile_id' => $profile?->id ?? data_get($result, 'profile_id'),
                    'operation' => $operation, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'result' => $result,
                ]);
            }

            return $result + ['operation_id' => $receipt?->id, 'replayed' => false];
        });
    }
}
