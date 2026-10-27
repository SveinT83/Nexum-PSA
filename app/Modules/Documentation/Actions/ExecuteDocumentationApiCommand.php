<?php

namespace App\Modules\Documentation\Actions;

use App\Modules\Documentation\Models\DocumentationApiCommand;
use App\Modules\Documentation\Support\DocumentationApiAccess;
use App\Modules\Documentation\Support\DocumentationApiInput;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local mutation and encrypted receipt share a transaction; replays recheck authority. */
class ExecuteDocumentationApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        DocumentationApiInput::allowed($request, $defaults['documentation_fields'] ?? []);
        if ($request->isMethod('get') || ($defaults['documentation_preview'] ?? false)) {
            $response = $next($request);
            if ($etag = DocumentationApiInput::etag($request)) {
                $response->headers->set('ETag', $etag);
            }

            return $response;
        }
        $key = $request->header('Idempotency-Key');
        abort_if(($defaults['documentation_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $actor = $request->user();
        $tokenHash = DocumentationApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $hash = trim(DocumentationApiInput::revision([$request->method(), $request->path(),
            $request->all(), $request->header('If-Match')]), '"');
        try {
            [$command, $replayed] = DB::transaction(function () use ($request, $next, $defaults, $actor, $tokenHash, $keyHash, $hash) {
                abort_unless(DB::table('documentation_api_mutex')->where('id', 1)->lockForUpdate()->first(),
                    503, 'Documentation API migration is required.');
                $existing = DocumentationApiCommand::where('actor_id', $actor->id)
                    ->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                    return [$existing, true];
                }
                DocumentationApiInput::target($request, true);
                // Route binding preceded the mutex; never save an older bound snapshot.
                foreach ($request->route()->parameters() as $value) {
                    if ($value instanceof Model) {
                        $value->refresh();
                    }
                }
                if ($request->hasHeader('If-Match')) {
                    $etag = DocumentationApiInput::etag($request);
                    abort_unless($etag && hash_equals($etag, $request->header('If-Match')),
                        412, 'Documentation state changed; reload before retrying.');
                }
                $response = $next($request);
                if ($response->getStatusCode() >= 400) {
                    throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                }
                $body = $response->getStatusCode() === 204 ? null : json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $command = DocumentationApiCommand::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                    'token_hash' => $tokenHash, 'key_hash' => $keyHash,
                    'operation' => $request->route()->getName(), 'request_hash' => $hash,
                    'state' => 'completed',
                    'result' => ['permissions' => $defaults['documentation_permissions'] ?? [],
                        'ability' => $defaults['documentation_ability'], 'status' => $response->getStatusCode(),
                        'body' => $body, 'etag' => $response->getStatusCode() === 204 ? null : DocumentationApiInput::etag($request)],
                ]);

                return [$command, false];
            });
        } catch (\Throwable $error) {
            // An afterCommit notification may fail after a local commit: retain the
            // key and require reconciliation instead of duplicating the mutation.
            $persisted = DocumentationApiCommand::where('actor_id', $actor->id)
                ->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if (! $persisted || ! hash_equals($persisted->request_hash, $hash)
                || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $error instanceof \Illuminate\Validation\ValidationException) {
                throw $error;
            }
            $persisted->state = 'unknown';
            $result = $persisted->result;
            $result['status'] = 202;
            $result['body'] = ['state' => 'unknown', 'reconciliation_required' => true];
            $persisted->result = $result;
            $persisted->save();
            [$command, $replayed] = [$persisted, false];
        }
        DocumentationApiAccess::authorize($actor, $command->result['permissions']);
        abort_unless($actor->tokenCan($command->result['ability']), 403);
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
