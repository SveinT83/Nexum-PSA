<?php

namespace App\Modules\Signal\Actions;

use App\Modules\Signal\Models\SignalApiCommand;
use App\Modules\Signal\Support\SignalApiAccess;
use App\Modules\Signal\Support\SignalApiInput;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local mutation and encrypted receipt share a transaction; replays recheck authority. */
class ExecuteSignalApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        SignalApiInput::allowed($request, $defaults['signal_fields'] ?? []);
        if ($request->isMethodSafe() || ($defaults['signal_preview'] ?? false)) {
            $response = $next($request);
            if ($etag = SignalApiInput::etag($request)) {
                $response->headers->set('ETag', $etag);
            }

            return $response;
        }
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('signal_api_mutex'), 503, 'Signal API migration is required.');
        $key = $request->header('Idempotency-Key');
        abort_if(($defaults['signal_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $actor = $request->user();
        $tokenHash = SignalApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $hash = trim(SignalApiInput::revision([$request->method(), $request->path(),
            $request->all(), $request->header('If-Match')]), '"');
        try {
            [$command, $replayed] = DB::transaction(function () use ($request, $next, $defaults, $actor, $tokenHash, $keyHash, $hash) {
                abort_unless(DB::table('signal_api_mutex')->where('id', 1)->lockForUpdate()->first(),
                    503, 'Signal API migration is required.');
                $existing = SignalApiCommand::where('actor_id', $actor->id)
                    ->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                    return [$existing, true];
                }
                SignalApiInput::target($request, true);
                // Route binding preceded the mutex; never save an older bound snapshot.
                foreach ($request->route()->parameters() as $value) {
                    if ($value instanceof Model) {
                        $value->refresh();
                    }
                }
                abort_if(($defaults['signal_match_required'] ?? false) && ! $request->hasHeader('If-Match'), 428, 'If-Match is required.');
                if ($request->hasHeader('If-Match')) {
                    $etag = SignalApiInput::etag($request);
                    abort_unless($etag && hash_equals($etag, $request->header('If-Match')),
                        412, 'Signal state changed; reload before retrying.');
                }
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                }
                $body = $response->getStatusCode() === 204 ? null : json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $command = SignalApiCommand::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                    'token_hash' => $tokenHash, 'key_hash' => $keyHash,
                    'operation' => $request->route()->getName(), 'request_hash' => $hash,
                    'state' => 'completed',
                    'result' => ['permissions' => $defaults['signal_permissions'] ?? [],
                        'ability' => $defaults['signal_ability'], 'status' => $response->getStatusCode(),
                        'body' => $body, 'extra_permissions' => $request->attributes->get('signal_extra_permissions', []),
                        'extra_abilities' => $request->attributes->get('signal_extra_abilities', []),
                        'source_email_ids' => $request->attributes->get('signal_source_email_ids', []), 'etag' => $response->getStatusCode() === 204 ? null : SignalApiInput::etag($request)],
                ]);

                return [$command, false];
            });
        } catch (\Throwable $error) {
            // An afterCommit notification may fail after a local commit: retain the
            // key and require reconciliation instead of duplicating the mutation.
            $persisted = SignalApiCommand::where('actor_id', $actor->id)
                ->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if (! $persisted || ! hash_equals($persisted->request_hash, $hash)
                || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $error instanceof \Illuminate\Validation\ValidationException) {
                throw $error;
            }
            $persisted->state = 'unknown';
            $result = $persisted->result;
            $result['status'] = 202;
            $result['body'] = ['state' => 'unknown', 'reconciliation_required' => true, 'local_result' => $result['body']];
            $persisted->result = $result;
            $persisted->save();
            [$command, $replayed] = [$persisted, false];
        }
        SignalApiAccess::authorizeReceipt($request, $command);
        $body = $command->result['body'];
        if (is_array($body)) {
            $body['operation_id'] = $command->id;
            $body['replayed'] = $replayed;
        }
        $response = $command->result['status'] === 204 ? response()->noContent()
            : response()->json($body, $command->result['status']);
        $response->header('X-Operation-Id', $command->id);
        if ($etag = $command->result['etag']) {
            $response->header('ETag', $etag);
        }

        return $response;
    }
}
