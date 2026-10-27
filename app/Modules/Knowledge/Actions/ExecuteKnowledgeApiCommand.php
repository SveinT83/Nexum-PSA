<?php

namespace App\Modules\Knowledge\Actions;

use App\Modules\Knowledge\Models\KnowledgeApiCommand;
use App\Modules\Knowledge\Support\KnowledgeApiAccess;
use App\Modules\Knowledge\Support\KnowledgeApiInput;
use App\Modules\Knowledge\Support\KnowledgeApiProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Atomic local receipts; provider claims commit before remote work and never blindly replay. */
class ExecuteKnowledgeApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        KnowledgeApiInput::allowed($request, $defaults['knowledge_fields'] ?? []);
        if ($request->isMethod('get')) {
            $response = $next($request);
            if ($etag = KnowledgeApiInput::etag($request)) {
                $response->headers->set('ETag', $etag);
            }

            return $response;
        }
        $key = $request->header('Idempotency-Key');
        abort_if(($defaults['knowledge_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $actor = $request->user();
        $tokenHash = KnowledgeApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $hash = trim(KnowledgeApiInput::revision([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]), '"');
        $external = KnowledgeApiProvider::needed($request);
        $claimed = false;
        try {
            [$command, $replayed] = DB::transaction(function () use ($request, $next, $actor, $defaults, $tokenHash, $keyHash, $hash, $external, $key, &$claimed) {
                abort_unless(DB::table('knowledge_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Knowledge API migration is required.');
                $existing = KnowledgeApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                    return [$existing, true];
                }
                $target = KnowledgeApiInput::target($request);
                $targetKey = hash('sha256', $target ? $target::class.':'.$target->getKey() : $request->route()->getName());
                abort_if(KnowledgeApiCommand::where('target_key', $targetKey)->whereIn('state', ['running', 'unknown'])->exists(),
                    409, 'An unresolved provider claim requires reconciliation before another write.');
                KnowledgeApiInput::assertCurrent($request);
                if ($external) {
                    abort_unless($key, 428, 'Idempotency-Key is required for provider operations.');
                    KnowledgeApiProvider::authorize($request);
                }
                $result = ['permissions' => $defaults['knowledge_permissions'] ?? [],
                    'ability' => $defaults['knowledge_ability'], 'external' => $external,
                    'target_type' => ($target = KnowledgeApiInput::target($request)) ? $target::class : null,
                    'target_id' => $target?->getKey(), 'revision_access' => $defaults['knowledge_revision_access'] ?? null,
                    'status' => 202, 'etag' => null,
                    'body' => ['state' => 'running', 'reconciliation_required' => true]];
                if (! $external) {
                    $domainConflict = false;
                    try {
                        $response = $next($request);
                    } catch (\Illuminate\Validation\ValidationException $error) {
                        $revision = $request->route('revision');
                        if (! $revision instanceof \App\Models\Knowledge\ArticleRevision || $revision->fresh()->state !== 'conflict') {
                            throw $error;
                        }
                        // Preserve the native audited stale-base transition, including its failed receipt.
                        $domainConflict = true;
                        $response = response()->json(['message' => 'The published base changed.', 'errors' => $error->errors()], 422);
                    }
                    $revision = $request->route('revision');
                    $domainConflict = $domainConflict || ($response->getStatusCode() === 422
                        && $revision instanceof \App\Models\Knowledge\ArticleRevision && $revision->fresh()->state === 'conflict');
                    if ($response->getStatusCode() >= 400 && ! $domainConflict) {
                        throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
                    }
                    $result = array_merge($result, $this->capture($response, $request));
                } else {
                    $claimed = true;
                }

                return [KnowledgeApiCommand::create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                    'token_hash' => $tokenHash, 'key_hash' => $keyHash, 'operation' => $request->route()->getName(),
                    'request_hash' => $hash, 'target_key' => $targetKey,
                    'state' => $external ? 'running' : ($result['status'] >= 400 ? 'failed' : 'completed'), 'result' => $result]), false];
            });
        } catch (\Throwable $error) {
            $persisted = KnowledgeApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if (! $persisted || ! hash_equals($persisted->request_hash, $hash)
                || $error instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface
                || $error instanceof \Illuminate\Validation\ValidationException) {
                throw $error;
            }
            // An after-commit callback may fail after persistence. Never repeat the mutation.
            $persisted->state = 'unknown';
            $persisted->result = array_merge($persisted->result, ['status' => 202,
                'body' => ['state' => 'unknown', 'reconciliation_required' => true]]);
            $persisted->save();
            [$command, $replayed] = [$persisted, false];
        }
        if ($claimed) {
            // A crash leaves a durable running claim. It cannot execute again under this key.
            try {
                KnowledgeApiInput::assertCurrent($request);
                $response = $next($request);
                if ($response->getStatusCode() >= 500) {
                    // Laravel's routing pipeline may render an exception before returning here.
                    $command->state = 'unknown';
                    $result = ['status' => 202, 'etag' => null, 'body' => ['state' => 'unknown', 'reconciliation_required' => true]];
                } else {
                    $result = $this->capture($response, $request);
                    $command->state = $response->getStatusCode() < 400 ? 'completed' : 'failed';
                }
            } catch (\Illuminate\Validation\ValidationException $error) {
                $command->state = 'failed';
                $result = ['status' => 422, 'etag' => null, 'body' => ['message' => 'Knowledge validation failed.', 'errors' => $error->errors()]];
            } catch (\Throwable $error) {
                $command->state = 'unknown';
                $result = ['status' => 202, 'etag' => null, 'body' => ['state' => 'unknown', 'reconciliation_required' => true]];
            }
            $command->result = array_merge($command->result, $result);
            $command->save();
        }
        self::authorizeReceipt($request, $command);
        $result = $command->result;
        $body = $result['body'];
        if (is_array($body)) {
            $body += ['operation_id' => $command->id, 'replayed' => $replayed];
        }
        $response = $result['status'] === 204 ? response()->noContent() : response()->json($body, $result['status']);
        $response->header('X-Operation-Id', $command->id);
        if ($etag = $result['etag']) {
            $response->header('ETag', $etag);
        }

        return $response;
    }

    public static function authorizeReceipt(Request $request, KnowledgeApiCommand $command): void
    {
        $result = $command->result;
        abort_unless((int) $command->actor_id === (int) $request->user()->id
            && hash_equals($command->token_hash, KnowledgeApiAccess::tokenHash($request)), 404);
        KnowledgeApiAccess::authorize($request->user(), $result['permissions']);
        abort_unless($request->user()->tokenCan($result['ability']), 403);
        if ($result['external']) {
            abort_unless($request->user()->tokenCan('knowledge.providers.execute'), 403);
            KnowledgeApiAccess::authorize($request->user(), ['knowledge.sync_bookstack']);
        }
        if ($result['target_type'] === \App\Models\Knowledge\ArticleRevision::class) {
            $revision = \App\Models\Knowledge\ArticleRevision::findOrFail($result['target_id']);
            $access = app(\App\Modules\Knowledge\Support\KnowledgeRevisionAccess::class);
            if ($result['revision_access'] === 'approve') {
                abort_unless($access->canApprove($request->user(), $revision), 403);
            }
            if ($result['revision_access'] === 'view') {
                abort_unless($access->canView($request->user(), $revision), 403);
            }
        }
    }

    private function capture($response, Request $request): array
    {
        return ['status' => $response->getStatusCode(),
            'body' => $response->getStatusCode() === 204 ? null : json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR),
            'etag' => $response->getStatusCode() === 204 ? null : KnowledgeApiInput::etag($request)];
    }
}
