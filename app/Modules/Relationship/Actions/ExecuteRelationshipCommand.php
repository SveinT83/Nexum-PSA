<?php

namespace App\Modules\Relationship\Actions;

use App\Modules\Relationship\Models\NexumRelationship;
use App\Modules\Relationship\Models\RelationshipApiCommand;
use App\Modules\Relationship\Support\RelationshipApiAccess;
use App\Modules\Relationship\Support\RelationshipProjection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Commit external intent before I/O; local mutation and encrypted result are atomic. */
class ExecuteRelationshipCommand
{
    public function handle(Request $request, string $operation, ?NexumRelationship $relationship, callable $action, bool $provider = false): array
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 200, 428, 'Idempotency-Key (8-200 characters) is required.');
        $payload = $request->all();
        $sort = function (&$value) use (&$sort) {
            if (! is_array($value)) {
                return;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$child) {
                $sort($child);
            }
        };
        $sort($payload);
        $hash = hash_hmac('sha256', json_encode([$operation, $relationship?->id, $payload, $request->header('If-Match')], JSON_THROW_ON_ERROR), config('app.key'));
        $identity = ['actor_id' => $request->user()->id, 'token_hash' => RelationshipApiAccess::tokenHash($request), 'key_hash' => hash('sha256', $key)];
        $authority = $request->attributes->get('relationship_authority', [
            'permission' => $request->route()->defaults['relationship_permission'],
            'abilities' => [$request->route()->defaults['relationship_ability']],
        ]);
        $claim = DB::transaction(function () use ($identity, $hash, $operation, $relationship, $action, $provider, $request, $authority) {
            DB::table('relationship_api_mutex')->where('id', 1)->lockForUpdate()->first();
            $existing = RelationshipApiCommand::query()->where($identity)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key belongs to a different Relationship request.');

                return ['command' => $existing, 'replayed' => true];
            }
            if ($relationship) {
                $fresh = NexumRelationship::query()->whereKey($relationship->id)->lockForUpdate()->firstOrFail();
                abort_unless($request->header('If-Match'), 428, 'If-Match is required.');
                abort_unless(hash_equals(RelationshipProjection::version($fresh), $request->header('If-Match')), 412, 'Relationship state changed; refresh context.');
                $relationship->setRawAttributes($fresh->getAttributes(), true);
            }
            $command = RelationshipApiCommand::create(array_merge($identity, ['id' => (string) Str::uuid(),
                'operation' => $operation, 'relationship_id' => $relationship?->id, 'request_hash' => $hash,
                'state' => $provider ? 'reserved' : 'completed', 'authority' => $authority]));
            if (! $provider) {
                $command->result = $action();
                $command->relationship_id = $command->result['relationship']['id'] ?? $relationship?->id;
                $command->save();
            }

            return ['command' => $command, 'replayed' => false];
        });
        $command = $claim['command'];
        if ($provider && ! $claim['replayed']) {
            try {
                $command->result = $action();
                $command->state = $command->result['state'];
            } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $command->state = 'rejected';
                $command->result = ['state' => 'rejected', 'status' => method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 422];
                $command->save();
                throw $error;
            } catch (\Throwable $error) {
                $command->state = 'unresolved';
                $command->result = ['state' => 'unresolved', 'reconciliation_required' => true];
            }
            $command->save();
        }

        return self::project($command, $claim['replayed']);
    }

    public static function project(RelationshipApiCommand $command, bool $replayed = false): array
    {
        return ['operation_id' => $command->id, 'operation' => $command->operation,
            'relationship_id' => $command->relationship_id, 'state' => $command->state,
            'replayed' => $replayed, 'result' => $command->result,
            'reconciliation_required' => in_array($command->state, ['reserved', 'unresolved'], true),
            'readback_url' => url('/api/v1/relationships/operations/'.$command->id)];
    }
}
