<?php

namespace App\Modules\Clients\Actions;

use App\Modules\Clients\Models\ClientApiCommand;
use App\Modules\Commercial\Support\CommercialRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/** Local atomic commands and external intent have deliberately distinct transaction boundaries. */
class ExecuteClientCommand
{
    public static function actorKey(Request $request): string
    {
        $portal = $request->attributes->get('customerPortalContext');
        $token = $request->user()?->currentAccessToken();
        $identity = [$request->user()?->id, $token?->id ?? 'session',
            $portal?->account->id, $portal?->membership->id, $portal?->client->id];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }

    public function handle(Request $request, callable $authorize, callable $apply, bool $required = true, bool $external = false): array
    {
        $originalAuthorize = $authorize;
        $authorize = function () use ($request, $originalAuthorize) {
            if (! ($request->route()->defaults['clients_portal'] ?? false)) {
                \App\Modules\Clients\Http\Middleware\ClientApiGuard::authorize($request);
            }
            $originalAuthorize();
        };
        $key = $request->header('Idempotency-Key');
        if (($required && ! $key) || ($key !== null && (strlen($key) < 8 || strlen($key) > 200))) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use an Idempotency-Key of 8 to 200 characters.']);
        }
        $authorize();
        if (! $key) {
            return $apply() + ['operation_id' => null, 'replayed' => false];
        }
        abort_unless(Schema::hasTable('client_api_commands'), 503, 'Apply the Client command migration before using command keys.');
        $hash = hash('sha256', json_encode(CommercialRevision::stable([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]), JSON_THROW_ON_ERROR));
        $claim = function () use ($request, $authorize, $apply, $key, $hash, $external) {
            DB::table('client_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            $authorize();
            $receipt = ClientApiCommand::query()->where('actor_key', self::actorKey($request))
                ->where('operation', $request->route()->getName())->where('key_hash', hash('sha256', $key))->first();
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This key was already used with different input.');
                if ($receipt->state !== 'completed') {
                    return ['operation_id' => $receipt->id, 'state' => $receipt->state, 'replayed' => true,
                        'reconciliation_required' => in_array($receipt->state, ['running', 'unknown'], true)];
                }

                return ($receipt->result ?? []) + ['operation_id' => $receipt->id, 'replayed' => true];
            }
            $resource = $request->attributes->get('client_external_resource');
            if ($external && $resource) {
                abort_if(ClientApiCommand::query()->where('resource_key', $resource)->whereIn('state', ['running', 'unknown'])->exists(), 409, 'A provider operation is unresolved; reconcile it before another write.');
            }
            $receipt = ClientApiCommand::query()->create(['id' => (string) Str::uuid(), 'actor_key' => self::actorKey($request),
                'operation' => $request->route()->getName(), 'key_hash' => hash('sha256', $key), 'request_hash' => $hash,
                'state' => $external ? 'running' : 'pending', 'resource_key' => $resource, 'client_id' => $request->route('client') instanceof \App\Models\Clients\Client ? $request->route('client')->id : null]);
            if ($external) {
                return ['claimed' => $receipt->id];
            }
            $result = $apply();
            $receipt->update(['state' => 'completed', 'result' => $result]);

            return $result + ['operation_id' => $receipt->id, 'replayed' => false];
        };
        $result = DB::transaction($claim, 3);
        if (! isset($result['claimed'])) {
            return $result;
        }
        $receipt = ClientApiCommand::query()->findOrFail($result['claimed']);
        try {
            // Intent is committed before any provider transport. Never retry this callback automatically.
            $result = $apply();
            $receipt->update(['state' => 'completed', 'result' => $result]);

            return $result + ['operation_id' => $receipt->id, 'replayed' => false];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
            $receipt->update(['state' => 'rejected', 'result' => ['error_code' => 'precondition_failed']]);
            throw $error;
        } catch (ValidationException $error) {
            $receipt->update(['state' => 'rejected', 'result' => ['error_code' => 'validation_failed']]);
            throw $error;
        } catch (Throwable $error) {
            $receipt->update(['state' => 'unknown', 'result' => ['error_code' => 'external_outcome_unverified']]);
            report($error);

            return ['operation_id' => $receipt->id, 'state' => 'unknown', 'reconciliation_required' => true, 'replayed' => false];
        }
    }
}
