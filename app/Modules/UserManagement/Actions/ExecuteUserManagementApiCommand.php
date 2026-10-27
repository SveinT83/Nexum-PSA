<?php

namespace App\Modules\UserManagement\Actions;

use App\Modules\UserManagement\Models\UserManagementApiCommand;
use App\Modules\UserManagement\Support\UserManagementApiAccess;
use App\Modules\UserManagement\Support\UserManagementApiState;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local writes and sanitized encrypted results commit atomically; credentials never enter here. */
class ExecuteUserManagementApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        $fields = $defaults['um_fields'] ?? null;
        if ($fields !== null) {
            $unknown = array_diff(array_keys($request->all()), array_merge($fields, ['_token']));
            abort_if($unknown !== [], 422, 'Unsupported request fields: '.implode(', ', $unknown));
        }
        if ($request->isMethod('get') || ($defaults['um_owner_replay'] ?? false)) {
            $response = $next($request);
            if ($etag = UserManagementApiState::etag($request)) {
                $response->headers->set('ETag', $etag);
            }

            return $response;
        }
        $key = $request->header('Idempotency-Key');
        abort_if(($defaults['um_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $actor = $request->user();
        $tokenHash = UserManagementApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $input = $request->except('_token');
        $normalize = function ($value) use (&$normalize) {
            if ($value instanceof UploadedFile) {
                return ['sha256' => hash_file('sha256', $value->getRealPath()), 'name' => $value->getClientOriginalName()];
            }
            if (is_array($value)) {
                ksort($value);

                return array_map($normalize, $value);
            }

            return $value;
        };
        $requestHash = hash_hmac('sha256', json_encode([$request->method(), $request->path(), $normalize($input), $request->header('If-Match')], JSON_THROW_ON_ERROR), config('app.key'));
        try {
            [$command, $replayed] = DB::transaction(function () use ($request, $next, $defaults, $actor, $tokenHash, $keyHash, $requestHash) {
                abort_unless(DB::table('user_management_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'UserManagement API migration is required.');
                $fresh = UserManagementApiAccess::actor($request);
                UserManagementApiAccess::authorize($fresh, $defaults['um_permissions'] ?? []);
                abort_unless($fresh->tokenCan($defaults['um_ability']), 403);
                $command = UserManagementApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($command) {
                    abort_unless(hash_equals($command->request_hash, $requestHash), 409, 'Idempotency-Key was used for another request.');

                    return [$command, true];
                }
                // Binding ran before the mutex. Refresh every exact target before validation/persistence.
                foreach ($request->route()->parameters() as $target) {
                    if ($target instanceof \Illuminate\Database\Eloquent\Model) {
                        $target->refresh();
                    }
                }
                $etag = UserManagementApiState::etag($request);
                abort_if(($defaults['um_revision_required'] ?? false) && ! $request->hasHeader('If-Match'), 428, 'If-Match is required.');
                if ($request->hasHeader('If-Match')) {
                    abort_unless($etag && hash_equals($etag, $request->header('If-Match')), 412, 'State changed; read the record again.');
                }
                $request->attributes->set('um_command_transaction', true);
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                }
                $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $command = UserManagementApiCommand::create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                    'token_hash' => $tokenHash, 'key_hash' => $keyHash, 'request_hash' => $requestHash,
                    'operation' => $request->route()->getName(), 'state' => 'completed',
                    'result' => ['permissions' => $defaults['um_permissions'] ?? [], 'ability' => $defaults['um_ability'],
                        'status' => $response->getStatusCode(), 'body' => $body, 'etag' => UserManagementApiState::etag($request)]]);
                activity('user-management-api')->causedBy($fresh)->withProperties(['operation_id' => $command->id,
                    'operation' => $command->operation, 'state' => 'completed'])->log('local_command_completed');

                return [$command, false];
            });
        } catch (\Throwable $error) {
            $persisted = UserManagementApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if (! $persisted || ! hash_equals($persisted->request_hash, $requestHash)
                || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $error instanceof \Illuminate\Validation\ValidationException) {
                if (! $persisted) {
                    foreach ($request->attributes->get('um_created_avatars', []) as $path) {
                        \Illuminate\Support\Facades\Storage::disk('public')->delete($path);
                    }
                }
                throw $error;
            }
            // A dispatch exception after commit must not cause an automatic duplicate invitation.
            $persisted->state = 'unknown';
            $result = $persisted->result;
            $result['status'] = 202;
            $result['body'] = ['data' => ['state' => 'unknown', 'reconciliation_required' => true]];
            $persisted->result = $result;
            $persisted->save();
            [$command, $replayed] = [$persisted, false];
        }
        $response = response()->json($command->result['body'], $command->result['status']);
        $response->header('X-Operation-Id', $command->id)->header('X-Idempotent-Replay', $replayed ? 'true' : 'false');
        if ($etag = $command->result['etag']) {
            $response->header('ETag', $etag);
        }

        return $response;
    }
}
