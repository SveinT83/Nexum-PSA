<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Core\User;
use App\Modules\Commercial\Models\CommercialCommandReceipt;
use App\Modules\Commercial\Support\CommercialRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Current authority and owner locks precede replay, so a stale token cannot recover old data. */
class ExecuteCommercialCommand
{
    public static function actorKey(Request $request): string
    {
        $portal = $request->attributes->get('customerPortalContext');
        $identity = $portal ? 'portal:'.$portal->account->id.':'.$portal->membership->id.':'.$portal->contact->id
            : ($request->attributes->has('commercial_public_hash') ? 'public:'.$request->attributes->get('commercial_public_hash') : 'staff:'.$request->user()?->id);

        return hash('sha256', $identity);
    }

    public function handle(Request $request, callable $authorize, callable $execute, bool $required = true): array
    {
        $key = $request->header('Idempotency-Key');
        if (($required && ! $key) || ($key !== null && (strlen($key) < 8 || strlen($key) > 200))) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use an Idempotency-Key of 8 to 200 characters.']);
        }
        if ($required || $key) {
            abort_unless(\Illuminate\Support\Facades\Schema::hasTable('commercial_command_receipts'), 503, 'The Commercial command migration must be applied before this operation is enabled.');
        }
        $hash = hash('sha256', json_encode(CommercialRevision::stable([$request->path(), $request->all(), $request->header('If-Match')]), JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($request, $authorize, $execute, $key, $hash) {
            if ($request->user()) {
                User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            }
            $authorize();
            $operation = $request->route()->getName();
            $receipt = $key ? CommercialCommandReceipt::query()->where('actor_key', self::actorKey($request))->where('operation', $operation)->where('key_hash', hash('sha256', $key))->first() : null;
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This key was already used with different input.');

                return $receipt->result + ['operation_id' => $receipt->id, 'replayed' => true];
            }
            $result = $execute();
            if ($key) {
                $receipt = CommercialCommandReceipt::query()->create(['actor_key' => self::actorKey($request), 'operation' => $operation, 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'result' => $result, 'created_at' => now()]);
            }

            return $result + ['operation_id' => $receipt?->id, 'replayed' => false];
        }, 3);
    }
}
