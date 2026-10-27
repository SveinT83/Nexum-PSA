<?php

namespace App\Modules\System\Actions;

use App\Modules\System\Models\SystemApiCommand;
use App\Modules\System\Support\CompanyProfileSettings;
use App\Modules\System\Support\SystemApiAccess;
use App\Modules\System\Support\SystemApiInput;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** A local settings mutation and its encrypted exact result commit in the same transaction. */
final class ExecuteSystemSettingsCommand
{
    public function execute(Request $request, string $operation, callable $apply)
    {
        abort_unless(Schema::hasTable('system_api_mutex'), 503, 'System API migration is required.');
        $key = SystemApiInput::commandKey($request);
        $token = SystemApiAccess::tokenHash($request);
        $hash = SystemApiInput::hash([$operation, $request->method(), $request->all(), $request->header('If-Match')]);
        try {
            [$command, $replayed] = DB::transaction(function () use ($request, $operation, $apply, $key, $token, $hash) {
                DB::table('system_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
                $actor = SystemApiAccess::actor($request);
                abort_unless(SystemApiAccess::allows($actor, 'system.settings_manage')
                    && $actor->tokenCan('system.settings.write'), 403, 'Current settings authority is required.');
                $existing = SystemApiCommand::where('actor_id', $actor->id)->where('token_hash', $token)->where('key_hash', $key)->first();
                if ($existing) {
                    abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                    return [$existing, true];
                }
                $settings = app(CompanyProfileSettings::class);
                $match = $request->header('If-Match');
                abort_unless($match, 428, 'If-Match is required.');
                abort_unless(hash_equals($settings->version(), $match), 412, 'System settings changed; reload before retrying.');
                $body = $apply($settings);
                $etag = $settings->version();
                $body['version'] = $etag;
                $command = SystemApiCommand::create([
                    'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'token_hash' => $token,
                    'key_hash' => $key, 'request_hash' => $hash, 'operation' => $operation,
                    'scope' => 'system.settings.write', 'native_permission' => 'system.settings_manage',
                    'state' => 'completed', 'result' => ['status' => 200, 'body' => $body, 'etag' => $etag],
                ]);

                return [$command, false];
            });
        } catch (\Throwable $error) {
            app(CompanyProfileSettings::class)->discardUnreferenced($request->attributes->get('system_staged_files', []));
            throw $error;
        }
        SystemApiAccess::receipt($request, $command);

        return response()->json(array_merge($command->result['body'], [
            'operation_id' => $command->id, 'replayed' => $replayed,
        ]), $command->result['status'])->header('ETag', $command->result['etag'])->header('X-Operation-Id', $command->id);
    }
}
