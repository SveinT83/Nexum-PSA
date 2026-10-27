<?php

namespace App\Modules\Notification\Actions;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationApiCommand as Command;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Durable claim precedes external effects. Local change and result commit together. */
class ExecuteNotificationApiCommand
{
    public static function tokenHash(Request $request): string
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            return hash('sha256', 'token:'.$token->id);
        }
        abort_unless($request->hasSession() && $token instanceof \Laravel\Sanctum\TransientToken, 401);

        return hash('sha256', 'session:'.$request->session()->getId());
    }

    public static function etag($model): string
    {
        return '"'.hash_hmac('sha256', $model->getTable().':'.$model->getKey().':'.json_encode($model->getRawOriginal()), config('app.key')).'"';
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($v) => $this->canonical($v), $value);
    }

    public function handle(Request $request, Closure $next)
    {
        $defaults = $request->route()->defaults;
        if (! ($defaults['notification_command'] ?? false)) {
            return $next($request);
        }
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && strlen($key) >= 8 && strlen($key) <= 200, 428, 'An 8-200 character Idempotency-Key is required.');
        $actor = $request->user();
        $tokenHash = self::tokenHash($request);
        $requestHash = hash_hmac('sha256', json_encode($this->canonical([$request->method(), $request->path(), $request->all(), $request->header('If-Match')])), config('app.key'));
        [$command, $replay] = DB::transaction(function () use ($request, $defaults, $actor, $tokenHash, $requestHash, $key) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $existing = Command::where('actor_id', $actor->id)->where('token_hash', $tokenHash)
                ->where('key_hash', hash('sha256', $key))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'Idempotency-Key belongs to a different request.');

                return [$existing, true];
            }

            return [Command::create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                'token_hash' => $tokenHash, 'key_hash' => hash('sha256', $key),
                'request_hash' => $requestHash, 'operation' => $request->route()->getName(),
                'ability' => $defaults['notification_ability'], 'admin' => $defaults['notification_admin'] ?? false,
                'state' => 'claimed']), false];
        });
        if (! $replay) {
            $run = function () use ($request, $next, $command, $defaults) {
                // Refresh bound state only after taking its write lock.
                foreach ($request->route()->parameters() as $value) {
                    if ($value instanceof \Illuminate\Database\Eloquent\Model) {
                        $fresh = $value->newQuery()->whereKey($value->getKey())->lockForUpdate()->firstOrFail();
                        $value->setRawAttributes($fresh->getAttributes(), true);
                    }
                }
                if ($defaults['notification_if_match'] ?? false) {
                    $channel = $request->route('channel');
                    abort_unless($request->hasHeader('If-Match'), 428, 'If-Match is required. Read the channel first.');
                    abort_unless(hash_equals(self::etag($channel), $request->header('If-Match')), 412, 'Channel changed; reload before retrying.');
                }
                $response = $next($request);
                $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
                $unknown = ($body['data']['state'] ?? null) === 'unknown';
                if ($unknown) {
                    $body['data']['reconciliation_required'] = true;
                }
                $command->update(['state' => $unknown ? 'unknown' : 'completed',
                    'status' => $unknown ? 202 : $response->getStatusCode(), 'result' => $body]);

                return $response;
            };
            try {
                if ($defaults['notification_external'] ?? false) {
                    $run();
                } else {
                    DB::transaction($run);
                }
            } catch (ValidationException $error) {
                $command->update(['state' => 'failed', 'status' => 422,
                    'result' => ['message' => 'Invalid Notification request.', 'errors' => $error->errors()]]);
            } catch (HttpExceptionInterface $error) {
                $command->update(['state' => 'failed', 'status' => $error->getStatusCode(),
                    'result' => ['message' => $error->getMessage() ?: 'Notification action denied.']]);
            } catch (\Throwable) {
                // Provider/queue/commit ambiguity cannot cause the same key to run again.
                $command->update(['state' => 'unknown', 'status' => 202,
                    'result' => ['data' => ['state' => 'unknown', 'reconciliation_required' => true]]]);
            }
            $command->refresh();
        }
        $body = $command->result ?? ['data' => ['state' => 'unknown', 'reconciliation_required' => true]];
        $body['operation_id'] = $command->id;
        $body['replayed'] = $replay;

        return response()->json($body, $command->status ?? 202)->header('X-Operation-Id', $command->id);
    }
}
