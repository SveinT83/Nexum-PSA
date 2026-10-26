<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Models\AssetApiCommand;
use App\Modules\Asset\Support\AssetApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Serialize local writes; commit provider claims before any external execution. */
class ExecuteAssetCommand
{
    public function handle(Request $request, string $operation, callable $action, bool $provider = false): array
    {
        $actor = AssetApiAccess::actor();
        $key = $request->header('Idempotency-Key');
        abort_if($provider && ! $key, 428, 'Idempotency-Key is required for provider execution.');
        abort_if($key !== null && (strlen($key) < 8 || strlen($key) > 200), 422, 'Idempotency-Key must contain 8 to 200 characters.');
        $payload = $request->all();
        $sort = function (&$value) use (&$sort): void {
            if (! is_array($value)) {
                return;
            }
            if (! array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as &$child) {
                $sort($child);
            }
        };
        $sort($payload);
        $hash = hash('sha256', json_encode([$operation, $payload, $request->header('If-Match')], JSON_THROW_ON_ERROR));
        $tokenHash = hash('sha256', (string) ($actor->currentAccessToken()?->getKey() ?? 'session'));
        $keyHash = hash('sha256', $key ?? (string) Str::uuid());
        $scope = ['client_id' => $request->integer('client_id') ?: null, 'site_id' => $request->integer('site_id') ?: null,
            'asset_id' => $request->integer('asset_id') ?: null, 'provider' => $request->input('provider')];
        $claimed = DB::transaction(function () use ($actor, $operation, $hash, $tokenHash, $keyHash, $provider, $action, $scope) {
            DB::table('asset_api_mutex')->where('id', 1)->lockForUpdate()->first();
            $existing = AssetApiCommand::where('actor_id', $actor->id)->where('token_hash', $tokenHash)->where('key_hash', $keyHash)->first();
            if ($existing) {
                abort_unless($existing->request_hash === $hash, 409, 'Idempotency-Key was used for another Asset request.');

                return ['existing' => true, 'command' => $existing];
            }
            $command = AssetApiCommand::create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                'token_hash' => $tokenHash, 'key_hash' => $keyHash, 'operation' => $operation,
                'request_hash' => $hash, 'state' => $provider ? 'running' : 'completed',
                'result' => $provider ? ['state' => 'running', 'scope' => $scope] : null]);
            if (! $provider) {
                $command->result = $action();
                $command->save();
            }

            return ['existing' => false, 'command' => $command];
        });
        $command = $claimed['command'];
        if ($provider && ! $claimed['existing']) {
            try {
                $command->result = $action();
                $command->state = $command->result['state'] ?? 'completed';
            } catch (\Illuminate\Validation\ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $command->state = 'refused';
                $command->result = ['state' => 'refused', 'status' => method_exists($error, 'getStatusCode') ? $error->getStatusCode() : 422];
                $command->save();
                throw $error;
            } catch (\Throwable $error) {
                // Remote work may already have happened. Never turn an ambiguous attempt into a retry.
                $command->state = 'unknown';
                $command->result = ['state' => 'unknown', 'scope' => $scope, 'reconciliation_required' => true];
                report(new \RuntimeException('Asset provider execution ended without a verified result.'));
            }
            $command->save();
        }
        if ($command->state === 'refused') {
            abort($command->result['status'] ?? 409, 'The original Asset command was refused; obtain a new preview before a new command.');
        }

        return ['operation_id' => $command->id, 'replayed' => $claimed['existing'],
            'state' => $command->state, 'result' => $command->result,
            'reconciliation_required' => in_array($command->state, ['running', 'unknown'], true)];
    }
}
