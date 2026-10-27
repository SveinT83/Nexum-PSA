<?php

namespace App\Modules\LeadIntelligence\Actions;

use App\Modules\LeadIntelligence\Jobs\ExecuteLeadResearchRunJob;
use App\Modules\LeadIntelligence\Models\LeadApiCommand;
use App\Modules\LeadIntelligence\Support\LeadApiAccess;
use App\Modules\LeadIntelligence\Support\LeadApiInput;
use App\Modules\LeadIntelligence\Support\LeadPromotionInput;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Local changes are atomic with receipts; external intent commits before execution. */
class ExecuteLeadApiCommand
{
    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        LeadApiInput::allowed($request, $defaults['lead_fields'] ?? []);
        if ($request->isMethod('get')) {
            $response = $next($request);
            if ($etag = LeadApiInput::etag($request)) {
                $response->headers->set('ETag', $etag);
            }

            return $response;
        }
        $promotion = ($defaults['lead_external'] ?? null) === 'promotion';
        if ($promotion) {
            LeadPromotionInput::validate($request);
            if ($request->boolean('dry_run')) {
                return $next($request);
            }
        }
        if (($defaults['lead_external'] ?? null) === 'ai') {
            $request->validate(['prompt' => 'required|string|max:5000', 'lead_segment_id' => 'nullable|integer|exists:lead_segments,id']);
            abort_unless(config('lead-intelligence.api_external_execution', false), 409, 'API external execution is disabled.');
        }
        if ($promotion && app(\App\Modules\DataExchange\Services\TripletexCustomerNumbers::class)->connection()) {
            abort_unless(config('lead-intelligence.api_external_execution', false), 409, 'API provider execution is disabled.');
        }
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 200, 428, 'An Idempotency-Key of 8 to 200 characters is required.');
        $tokenHash = LeadApiAccess::tokenHash($request);
        $hash = hash_hmac('sha256', LeadApiInput::revision([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]), config('app.key'));
        $keyHash = hash('sha256', $key);
        $external = (bool) ($defaults['lead_external'] ?? false);
        $promotionTargets = $promotion ? LeadPromotionInput::targets($request) : [];
        [$command, $replayed] = DB::transaction(function () use ($request, $next, $tokenHash, $hash, $keyHash, $external, $defaults, $promotionTargets) {
            abort_unless(DB::table('lead_intelligence_api_mutex')->where('id', 1)->lockForUpdate()->first(), 503, 'Lead Intelligence API migration is required.');
            $old = LeadApiCommand::where('actor_id', $request->user()->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if ($old) {
                abort_unless(hash_equals($old->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                return [$old, true];
            }
            foreach ($request->route()->parameters() as $value) {
                if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                    $value->refresh();
                }
            }
            if ($request->hasHeader('If-Match')) {
                $etag = LeadApiInput::etag($request);
                abort_unless($etag && hash_equals($etag, $request->header('If-Match')), 412, 'State changed; reload before retrying.');
            }
            if (($defaults['lead_external'] ?? null) === 'promotion') {
                $unresolved = LeadApiCommand::where('operation', $request->route()->getName())
                    ->whereIn('state', ['running', 'unknown'])->get();
                foreach ($unresolved as $attempt) {
                    if (! array_intersect($promotionTargets, $attempt->result['promotion_targets'] ?? [])) {
                        continue;
                    }
                    abort_if($attempt->state === 'running' || LeadPromotionInput::pendingProvider($attempt->id, $attempt->actor_id),
                        409, 'Reconcile the existing provider customer attempt before submitting another key.');
                }
            }
            $id = (string) Str::uuid();
            $request->attributes->set('lead_command_id', $id);
            $token = $request->user()->currentAccessToken();
            $command = LeadApiCommand::create(['id' => $id, 'actor_id' => $request->user()->id,
                'token_hash' => $tokenHash, 'key_hash' => $keyHash, 'request_hash' => $hash,
                'operation' => $request->route()->getName(), 'ability' => $defaults['lead_ability'], 'state' => 'running',
                'result' => ['status' => 202, 'body_json' => json_encode(['data' => ['state' => 'running', 'reconciliation_required' => true]]),
                    'promotion_targets' => $promotionTargets, 'token_id' => $token && method_exists($token, 'getKey') ? $token->getKey() : null, 'etag' => null]]);
            if (! $external) {
                $this->execute($command, $request, $next);
            }

            return [$command, false];
        });
        if ($external && ! $replayed) {
            // A committed claim is never automatically retried after an ambiguous provider call.
            $request->attributes->set('lead_command_id', $command->id);
            try {
                $this->execute($command, $request, $next);
            } catch (\Throwable $error) {
                $status = $error instanceof ValidationException ? 422 : ($error instanceof HttpExceptionInterface ? $error->getStatusCode() : ($error instanceof \Illuminate\Http\Exceptions\HttpResponseException ? $error->getResponse()->getStatusCode() : 202));
                if ($status >= 500) {
                    $status = 202;
                }
                $result = $command->result;
                $result['status'] = $status;
                $errorBody = $status === 422 ? ['message' => 'Validation failed.', 'errors' => $error instanceof ValidationException ? $error->errors() : ['operation' => ['Command input or precondition was rejected.']]]
                    : ['data' => ['state' => $status === 202 ? 'unknown' : 'failed', 'reconciliation_required' => $status === 202], 'message' => 'External command did not complete; inspect the operation before retrying.'];
                $result['body_json'] = json_encode($errorBody, JSON_THROW_ON_ERROR);
                $command->update(['state' => $status === 202 ? 'unknown' : 'failed', 'result' => $result]);
            }
        }
        $command->refresh();
        if (! $replayed && $command->state === 'dispatch_pending') {
            $this->dispatch($command);
        }

        return $this->response($command->fresh(), $request, $replayed);
    }

    private function execute(LeadApiCommand $command, Request $request, Closure $next): void
    {
        $response = app(LeadApiAccess::class)->handle($request, $next);
        if ($response->getStatusCode() >= 400) {
            throw new \Illuminate\Http\Exceptions\HttpResponseException($response);
        }
        $result = $command->result;
        $result['body_json'] = $response->getContent();
        $result['status'] = $response->getStatusCode();
        $result['etag'] = LeadApiInput::etag($request);
        $result['dispatch_run_id'] = $request->attributes->get('lead_dispatch_run');
        $command->update(['state' => $request->attributes->get('lead_unknown') ? 'unknown' : ($result['dispatch_run_id'] ? 'dispatch_pending' : 'completed'), 'result' => $result]);
    }

    private function dispatch(LeadApiCommand $command): void
    {
        $claimed = LeadApiCommand::whereKey($command->id)->where('state', 'dispatch_pending')->update(['state' => 'dispatching']);
        if (! $claimed) {
            return;
        }
        try {
            ExecuteLeadResearchRunJob::dispatch($command->result['dispatch_run_id']);
            $command->update(['state' => 'completed']);
        } catch (\Throwable) {
            // Submission may have succeeded remotely. Preserve exact run ID and do not push twice.
            $result = $command->result;
            $result['status'] = 202;
            $body = json_decode($result['body_json']);
            $body->dispatch_state = 'unknown';
            $result['body_json'] = json_encode($body, JSON_THROW_ON_ERROR);
            $command->update(['state' => 'unknown', 'result' => $result]);
        }
    }

    public function response(LeadApiCommand $command, Request $request, bool $replayed)
    {
        LeadApiAccess::authorize($request->user());
        abort_unless($command->actor_id === $request->user()->id && hash_equals($command->token_hash, LeadApiAccess::tokenHash($request))
            && $request->user()->tokenCan($command->ability), 403);
        $result = $command->result;
        $body = json_decode($result['body_json'], false, 512, JSON_THROW_ON_ERROR);
        $body->operation_id = $command->id;
        $body->replayed = $replayed;
        $body->command_state = $command->state;
        $response = response()->json($body, $result['status'])->header('X-Operation-Id', $command->id);
        if ($result['etag'] ?? null) {
            $response->header('ETag', $result['etag']);
        }

        return $response;
    }
}
