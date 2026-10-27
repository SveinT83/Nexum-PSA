<?php

namespace App\Modules\Nextcloud\Actions;

use App\Modules\Nextcloud\Models\NextcloudApiCommand;
use App\Modules\Nextcloud\Support\NextcloudApiAccess;
use App\Modules\Nextcloud\Support\NextcloudApiInput;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local writes and receipts commit together; external claims commit before execution. */
class ExecuteNextcloudApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        if (array_key_exists('nextcloud_fields', $defaults)) {
            NextcloudApiInput::allowed($request, $defaults['nextcloud_fields']);
        }
        if ($request->isMethod('get') || ($defaults['nextcloud_preview'] ?? false)) {
            $response = $next($request);
            if ($revision = NextcloudApiInput::etag($request)) {
                $response->headers->set('ETag', $revision);
            }

            return $response;
        }
        $actor = $request->user();
        $key = $request->header('Idempotency-Key');
        abort_if(($defaults['nextcloud_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $external = $defaults['nextcloud_external'] ?? false;
        if ($external && ($defaults['nextcloud_provider_write'] ?? false)) {
            abort_unless(config('nextcloud_api.provider_execution_enabled'), 409, 'Nextcloud API provider execution is disabled.');
            abort_unless($request->boolean('confirm'), 422, 'Explicit confirmation is required.');
        }
        $payload = $request->all();
        $hash = NextcloudApiInput::revision([$request->method(), $request->path(), $request->query(), $payload, $request->header('If-Match')]);
        $hash = trim($hash, '"');
        $tokenHash = NextcloudApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $permissions = $defaults['nextcloud_permissions'] ?? [];
        $any = $defaults['nextcloud_any_permissions'] ?? [];
        $abilities = $defaults['nextcloud_abilities'] ?? [];
        if ($request->exists('service_password') || $request->exists('talk_bot_secret')) {
            $abilities[] = 'nextcloud.credentials.write';
        }
        try {
            $claimed = DB::transaction(function () use ($request, $actor, $hash, $tokenHash, $keyHash, $external, $next, $permissions, $any, $abilities) {
                abort_unless(DB::table('nextcloud_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Nextcloud API mutex is not installed.');
                // Authority may have changed while this request waited for the mutex.
                $actor = NextcloudApiAccess::actor();
                $request->setUserResolver(fn () => $actor);
                NextcloudApiAccess::authorize($actor, $permissions);
                foreach ($abilities as $ability) {
                    abort_unless($actor->tokenCan($ability), 403);
                }
                $existing = NextcloudApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another Nextcloud request.');

                    return ['replayed' => true, 'command' => $existing];
                }
                $connection = $request->route('connection');
                if ($connection instanceof \App\Modules\Nextcloud\Models\NextcloudConnection) {
                    $connection = $connection->newQuery()->lockForUpdate()->findOrFail($connection->id);
                    abort_if(NextcloudApiCommand::where('connection_id', $connection->id)->where('state', 'running')->exists(), 409, 'A provider operation is pending reconciliation.');
                }
                abort_unless(config('activitylog.enabled', true), 503, 'Audit logging is required.');
                // Models were bound before the mutex; refresh before validation/persistence.
                foreach ($request->route()->parameters() as $value) {
                    if ($value instanceof Model) {
                        $value->refresh();
                    }
                }
                $revision = NextcloudApiInput::etag($request);
                abort_if($revision && ! $request->hasHeader('If-Match'), 428, 'If-Match is required; read the connection first.');
                if ($request->hasHeader('If-Match')) {
                    abort_unless($revision && hash_equals($revision, $request->header('If-Match')), 412, 'Nextcloud revision changed; reload before retrying.');
                }
                $command = NextcloudApiCommand::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                    'token_hash' => $tokenHash, 'key_hash' => $keyHash,
                    'operation' => $request->route()->getName(), 'request_hash' => $hash,
                    'connection_id' => $request->route('connection')?->id,
                    'state' => $external ? 'running' : 'completed',
                    'result' => ['permissions' => $permissions, 'any_permissions' => $any, 'abilities' => $abilities],
                ]);
                $request->attributes->set('nextcloud_operation_id', $command->id);
                activity('nextcloud_api')->causedBy($actor)->withProperties(['operation_id' => $command->id, 'operation' => $command->operation])->log('Nextcloud API command claimed');
                if (! $external) {
                    $this->complete($command, $next($request), $request);
                }

                return ['replayed' => false, 'command' => $command];
            });
        } catch (\Throwable $error) {
            if ($error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $error instanceof \Illuminate\Validation\ValidationException
                || $error instanceof \Illuminate\Http\Exceptions\HttpResponseException) {
                throw $error;
            }
            // afterCommit queue publication may fail after the local transaction committed.
            // Retain the original key and make the ambiguous handoff explicit.
            $persisted = NextcloudApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)
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
                $actor = NextcloudApiAccess::actor();
                $request->setUserResolver(fn () => $actor);
                NextcloudApiAccess::authorize($actor, $permissions);
                foreach ($abilities as $ability) {
                    abort_unless($request->user()->tokenCan($ability), 403);
                }
                $request->attributes->set('nextcloud_operation_id', $command->id);
                $response = $next($request);
                if ($response->getStatusCode() >= 500) {
                    throw new \RuntimeException('Nextcloud provider result is unknown.');
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
                report(new \RuntimeException('Nextcloud provider command ended without a verified result.'));
            }
        }
        $actor = NextcloudApiAccess::actor();
        $request->setUserResolver(fn () => $actor);
        NextcloudApiAccess::authorize($actor, $command->result['permissions'] ?? []);
        foreach ($command->result['abilities'] ?? [] as $ability) {
            abort_unless($actor->tokenCan($ability), 403, 'The original result scope is required.');
        }
        if ($command->state === 'refused') {
            abort($command->result['status'] ?? 409, 'The original Nextcloud command was refused; obtain a new preview before another command.');
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

    private function complete(NextcloudApiCommand $command, $response, Request $request): void
    {
        if ($response->getStatusCode() >= 400) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }
        $decoded = $response->getStatusCode() === 204 ? null : json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $command->state = is_array($decoded) && ($decoded['state'] ?? null) === 'unknown' ? 'unknown' : 'completed';
        $authority = $command->result;
        $authority['permissions'] = array_values(array_unique(array_merge($authority['permissions'] ?? [],
            $request->attributes->get('nextcloud_response_permissions', []))));
        $authority['abilities'] = array_values(array_unique(array_merge($authority['abilities'] ?? [],
            $request->attributes->get('nextcloud_response_abilities', []))));
        $command->result = $authority + [
            'status' => $response->getStatusCode(),
            'body' => $decoded,
            'etag' => NextcloudApiInput::etag($request),
        ];
        $command->save();
    }
}
