<?php

namespace App\Modules\Marketing\Actions;

use App\Modules\Marketing\Models\MarketingApiCommand;
use App\Modules\Marketing\Support\MarketingApiAccess;
use App\Modules\Marketing\Support\MarketingApiState;
use App\Modules\Marketing\Support\MarketingMutationLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local writes commit with their receipt; external work is reserved before execution. */
class ExecuteMarketingApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $rules = $request->route()->defaults;
        $unknown = array_diff(array_keys($request->all()), $rules['marketing_fields'] ?? []);
        abort_if($unknown !== [], 422, 'Unknown Marketing input fields: '.implode(', ', $unknown));
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'q' => ['sometimes', 'nullable', 'string', 'max:255'], 'include_recipients' => ['sometimes', 'boolean']]);
        if (in_array($request->method(), ['GET', 'HEAD'], true) || ($rules['marketing_preview'] ?? false)) {
            $response = $next($request);
            if ($etag = MarketingApiState::etag($request)) {
                $response->headers->set('ETag', $etag);
            }

            return $response;
        }
        $key = $request->header('Idempotency-Key');
        abort_if(($rules['marketing_key_required'] ?? false) && ! $key, 428, 'Idempotency-Key is required.');
        abort_if($key !== null && ! preg_match('/^[\x21-\x7e]{8,200}$/D', $key), 422, 'Idempotency-Key must contain 8 to 200 visible ASCII characters.');
        $actor = $request->user();
        $tokenHash = MarketingApiAccess::tokenHash($request);
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $hash = trim(MarketingApiState::revision([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]), '"');
        $external = (bool) ($rules['marketing_provider'] ?? false);
        [$command, $replayed] = DB::transaction(function () use ($request, $next, $rules, $actor, $tokenHash, $keyHash, $hash, $external, $key) {
            MarketingMutationLock::lock($request);
            $existing = MarketingApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                return [$existing, true];
            }
            $etag = MarketingApiState::etag($request);
            // Legacy requests remain accepted without a key/precondition; keyed writes are guarded.
            abort_if($key && $etag && ! $request->hasHeader('If-Match'), 428, 'If-Match is required for a keyed state-dependent command.');
            if ($request->hasHeader('If-Match')) {
                abort_unless($etag && hash_equals($etag, $request->header('If-Match')), 412, 'Marketing state changed; reload before retrying.');
            }
            $command = MarketingApiCommand::create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'token_hash' => $tokenHash,
                'key_hash' => $keyHash, 'request_hash' => $hash, 'operation' => $request->route()->getName(), 'state' => 'running',
                'result' => ['permissions' => $rules['marketing_permissions'] ?? [], 'ability' => $rules['marketing_ability'],
                    'provider' => $external, 'target_type' => $request->route('campaign') ? 'campaign' : ($request->route('list') ? 'list' : null),
                    'target_id' => $request->route('campaign')?->id ?? $request->route('list')?->id,
                    'status' => 202, 'body' => ['data' => ['state' => 'running', 'reconciliation_required' => $external]], 'etag' => $etag],
            ]);
            $request->attributes->set('marketing_operation_id', $command->id);
            if (! $external) {
                $this->complete($command, $next($request), $request);
            }

            return [$command, false];
        });
        if ($external && ! $replayed) {
            try {
                $response = $next($request);
                // Provider controllers cannot prove that an exception preceded transmission.
                if ($response->getStatusCode() >= 400) {
                    if ($request->attributes->get('marketing_provider_started', false)) {
                        $this->unknown($command);
                    } else {
                        $result = $command->result;
                        $decoded = json_decode($response->getContent(), true) ?: [];
                        $result['status'] = $response->getStatusCode();
                        $result['body'] = ['message' => 'Marketing provider request was rejected before execution.'];
                        if (! empty($decoded['errors'])) {
                            $result['body']['errors'] = $decoded['errors'];
                        }
                        $command->forceFill(['state' => 'rejected', 'result' => $result])->save();
                    }
                } else {
                    $this->complete($command, $response, $request);
                }
            } catch (\Illuminate\Validation\ValidationException $error) {
                $result = $command->result;
                $result['status'] = 422;
                $result['body'] = ['message' => 'Invalid Marketing provider input.', 'errors' => $error->errors()];
                $command->forceFill(['state' => 'rejected', 'result' => $result])->save();
            } catch (\Throwable $error) {
                $this->unknown($command);
            }
        }
        MarketingApiAccess::authorize($actor, $command->result['permissions']);
        $result = $command->result;
        abort_unless($actor->tokenCan($result['ability']), 403);
        $body = $result['body'];
        if (is_array($body)) {
            $body['operation_id'] = $command->id;
            $body['replayed'] = $replayed;
        }
        $response = $result['status'] === 204 ? response()->noContent() : response()->json($body, $result['status']);
        $response->headers->set('X-Operation-Id', $command->id);
        if (! $key) {
            $response->headers->set('Warning', '299 Nexum "Use Idempotency-Key and If-Match for safe retries"');
        }
        if ($result['etag']) {
            $response->headers->set('ETag', $result['etag']);
        }

        return $response;
    }

    private function complete(MarketingApiCommand $command, $response, Request $request): void
    {
        if ($response->getStatusCode() >= 400) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }
        $result = $command->result;
        $result['status'] = $response->getStatusCode();
        $result['body'] = $result['status'] === 204 ? null : json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $result['etag'] = MarketingApiState::etag($request);
        $command->forceFill(['state' => 'completed', 'result' => $result])->save();
    }

    private function unknown(MarketingApiCommand $command): void
    {
        $result = $command->result;
        $result['status'] = 202;
        $result['body'] = ['data' => ['state' => 'unknown', 'reconciliation_required' => true]];
        $command->forceFill(['state' => 'unknown', 'result' => $result])->save();
    }
}
