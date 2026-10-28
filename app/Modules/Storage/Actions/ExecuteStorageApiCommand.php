<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\StorageApiCommand;
use App\Modules\Storage\Support\StorageApiAccess;
use App\Modules\Storage\Support\StorageApiInput;
use App\Modules\Storage\Support\StorageApiRevision;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local writes and receipts commit together; external claims commit before execution. */
class ExecuteStorageApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        StorageApiInput::legacyShape($request);
        if (array_key_exists('storage_fields', $defaults)) {
            StorageApiInput::allowed($request, $defaults['storage_fields']);
        }
        if ($request->isMethod('get') || ($defaults['storage_preview'] ?? false)) {
            $response = $next($request);
            if ($revision = StorageApiRevision::forRequest($request)) {
                $response->headers->set('ETag', $revision);
            }

            return $response;
        }
        $actor = StorageApiAccess::actor();
        $key = $request->header('Idempotency-Key');
        abort_if(($defaults['storage_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $external = $defaults['storage_external'] ?? false;
        if ($external) {
            abort_unless(config('storage_api.ai_repair_enabled'), 409, 'Storage API AI repair is disabled.');
            abort_unless($request->boolean('confirm'), 422, 'Explicit confirmation is required.');
        }
        $payload = StorageApiInput::payload($request);
        $hash = StorageApiInput::revision([$request->method(), $request->path(), $request->query(), $payload, $request->header('If-Match')]);
        $hash = trim($hash, '"');
        $tokenHash = StorageApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $permissions = $defaults['storage_permissions'] ?? [];
        $any = $defaults['storage_any_permissions'] ?? [];
        $abilities = $defaults['storage_abilities'] ?? [];
        try {
            $claimed = DB::transaction(function () use ($request, $actor, $hash, $tokenHash, $keyHash, $external, $next, $permissions, $any, $abilities) {
                abort_unless(DB::table('storage_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Storage API mutex is not installed.');
                $existing = StorageApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another Storage request.');

                    return ['replayed' => true, 'command' => $existing];
                }
                // Finalization shares the worker/browser lock order: numbering before the import target.
                if (($request->route()->defaults['storage_operation'] ?? null) === 'import-finalize') {
                    app(AllocatePurchaseOrderNumber::class)->lockForCreation();
                }
                StorageApiRevision::lockTarget($request);
                // Models were bound before the mutex; refresh before validation/persistence.
                foreach ($request->route()->parameters() as $value) {
                    if ($value instanceof Model) {
                        $value->refresh();
                    }
                }
                $revision = StorageApiRevision::forRequest($request);
                if ($request->hasHeader('If-Match')) {
                    abort_unless($revision && hash_equals($revision, $request->header('If-Match')), 412, 'Storage revision changed; reload before retrying.');
                }
                $command = StorageApiCommand::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                    'token_hash' => $tokenHash, 'key_hash' => $keyHash,
                    'operation' => $request->route()->getName(), 'request_hash' => $hash,
                    'state' => $external ? 'running' : 'completed',
                    'result' => ['permissions' => $permissions, 'any_permissions' => $any, 'abilities' => $abilities],
                ]);
                if (! $external) {
                    $this->complete($command, $next($request), $request);
                }

                return ['replayed' => false, 'command' => $command];
            });
        } catch (\Throwable $error) {
            // afterCommit queue publication may fail after the local transaction committed.
            // Retain the original key and make the ambiguous handoff explicit.
            $persisted = StorageApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)
                ->where('key_hash', $keyHash)->first();
            if (! $external && $persisted && hash_equals($persisted->request_hash, $hash)) {
                $persisted->state = 'unknown';
                $retained = $persisted->result;
                $retained['status'] = 202;
                $retained['body'] = ['state' => 'unknown', 'reconciliation_required' => true];
                $persisted->result = $retained;
                $persisted->save();
                $claimed = ['replayed' => false, 'command' => $persisted];
            } else {
                throw $error;
            }
        }
        $command = $claimed['command'];
        if ($external && ! $claimed['replayed']) {
            try {
                $response = $next($request);
                if ($response->getStatusCode() >= 500) {
                    throw new \RuntimeException('Storage provider result is unknown.');
                }
                $this->complete($command, $response, $request);
            } catch (\Illuminate\Http\Exceptions\HttpResponseException $error) {
                $command->state = 'refused';
                $command->result = $command->result + ['status' => $error->getResponse()->getStatusCode()];
                $command->save();
                throw $error;
            } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $command->state = 'refused';
                $command->result = $command->result + ['status' => method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 422];
                $command->save();
                throw $error;
            } catch (\Throwable) {
                $command->state = 'unknown';
                $command->result = $command->result + ['status' => 202, 'body' => ['state' => 'unknown', 'reconciliation_required' => true]];
                $command->save();
                report(new \RuntimeException('Storage AI command ended without a verified result.'));
            }
        }
        StorageApiAccess::authorize($actor, $command->result['permissions'] ?? [], $command->result['any_permissions'] ?? []);
        foreach ($command->result['abilities'] ?? [] as $ability) {
            abort_unless($actor->tokenCan($ability), 403, 'The original result scope is required.');
        }
        if ($command->state === 'refused') {
            abort($command->result['status'] ?? 409, 'The original Storage command was refused; obtain a new preview before another command.');
        }
        $body = $command->result['body'] ?? ['state' => $command->state, 'reconciliation_required' => true];
        if (is_array($body)) {
            $body['operation_id'] = $command->id;
            $body['replayed'] = $claimed['replayed'];
        }
        $response = ($command->result['status'] ?? null) === 204
            ? response()->noContent()
            : response()->json($body, $command->result['status'] ?? 202);
        $response->header('X-Operation-Id', $command->id);
        if ($etag = $command->result['etag'] ?? null) {
            $response->header('ETag', $etag);
        }

        return $response;
    }

    private function complete(StorageApiCommand $command, $response, Request $request): void
    {
        if ($response->getStatusCode() >= 400) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }
        $decoded = $response->getStatusCode() === 204 ? null : json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $command->state = is_array($decoded) && ($decoded['state'] ?? null) === 'unknown' ? 'unknown' : 'completed';
        $authority = $command->result;
        $authority['permissions'] = array_values(array_unique(array_merge($authority['permissions'] ?? [],
            $request->attributes->get('storage_response_permissions', []))));
        $authority['abilities'] = array_values(array_unique(array_merge($authority['abilities'] ?? [],
            $request->attributes->get('storage_response_abilities', []))));
        $command->result = $authority + [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
            'etag' => StorageApiRevision::forRequest($request),
        ];
        $command->save();
    }
}
