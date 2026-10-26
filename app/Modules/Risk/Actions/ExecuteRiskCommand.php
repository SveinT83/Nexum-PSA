<?php

namespace App\Modules\Risk\Actions;

use App\Modules\Risk\Http\Middleware\RiskApiGuard;
use App\Modules\Risk\Models\RiskApiCommand;
use App\Modules\Risk\Support\RiskMutex;
use App\Modules\Risk\Support\RiskPolicy;
use App\Modules\Risk\Support\RiskRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Local command identity, effects and receipt commit atomically under the browser lock. */
final class ExecuteRiskCommand
{
    public static function actorKey(Request $r): string
    {
        return hash('sha256', json_encode([$r->user()->id, $r->user()->currentAccessToken()?->id ?? 'session'], JSON_THROW_ON_ERROR));
    }

    public function handle(Request $r, array $context, callable $apply, bool $required = false): array
    {
        $key = $r->header('Idempotency-Key');
        if (($required && ! $key) || ($key !== null && ! preg_match('/^[\x21-\x7e]{8,200}$/D', $key))) {
            throw ValidationException::withMessages(['Idempotency-Key' => 'Use a stable Idempotency-Key of 8 to 200 printable characters.']);
        }
        $context['permissions'] = $r->route()->defaults['risk_permissions'] ?? [];
        $context['scope'] = $r->route()->defaults['risk_scope'];
        $hash = hash('sha256', json_encode(RiskRevision::stable([$r->method(), $r->path(), $r->all(), $r->header('If-Match')]), JSON_THROW_ON_ERROR));

        return app(RiskMutex::class)->run(function () use ($r, $context, $apply, $key, $hash) {
            RiskApiGuard::authorize($r);
            $receipt = $key ? RiskApiCommand::where('actor_key', self::actorKey($r))->where('operation', $r->route()->getName())->where('key_hash', hash('sha256', $key))->first() : null;
            if ($receipt) {
                abort_unless(hash_equals($receipt->request_hash, $hash), 409, 'This Risk command key was used with different input.');
                RiskPolicy::context($receipt->context, $receipt->result);

                return ['result' => $receipt->result, 'operation' => ['id' => $receipt->id, 'replayed' => true]];
            }
            RiskPolicy::context($context);
            $result = $apply();
            $id = null;
            if ($key) {
                $id = (string) Str::uuid();
                RiskApiCommand::create(['id' => $id, 'actor_key' => self::actorKey($r), 'operation' => $r->route()->getName(), 'key_hash' => hash('sha256', $key), 'request_hash' => $hash, 'context' => $context, 'result' => $result, 'state' => 'completed']);
            }

            return ['result' => $result, 'operation' => ['id' => $id, 'replayed' => false]];
        });
    }
}
