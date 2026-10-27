<?php

namespace App\Modules\CustomField\Actions;

use App\Modules\CustomField\Models\CustomFieldApiCommand;
use App\Modules\CustomField\Support\CustomFieldApiAccess;
use App\Modules\CustomField\Support\CustomFieldRevision;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Local mutation and encrypted replay receipt commit atomically under one shared mutex. */
class ExecuteCustomFieldCommand
{
    public function handle(Request $request, callable $apply)
    {
        CustomFieldApiAccess::authorize($request);
        $key = $request->header('Idempotency-Key');
        abort_unless($key, 428, 'Idempotency-Key is required.');
        abort_unless(strlen($key) >= 8 && strlen($key) <= 200, 422, 'Idempotency-Key must contain 8 to 200 characters.');
        abort_unless(Schema::hasTable('custom_field_api_commands') && Schema::hasTable('custom_field_api_mutex'),
            503, 'Apply the CustomField API command migration first.');
        $hash = CustomFieldRevision::hash([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]);
        [$command, $replayed] = DB::transaction(function () use ($request, $apply, $key, $hash) {
            DB::table('custom_field_api_mutex')->where('id', 1)->lockForUpdate()->firstOrFail();
            $actor = CustomFieldApiAccess::authorize($request);
            $existing = CustomFieldApiCommand::where('actor_id', $actor->id)
                ->where('token_hash', CustomFieldApiAccess::tokenHash($request))->where('key_hash', hash('sha256', $key))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This key was already used for a different request.');
                abort_unless($actor->tokenCan($existing->result['ability']), 403);

                return [$existing, true];
            }
            $response = $apply();
            $body = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            $command = CustomFieldApiCommand::create(['id' => (string) Str::uuid(), 'actor_id' => $actor->id,
                'token_hash' => CustomFieldApiAccess::tokenHash($request), 'key_hash' => hash('sha256', $key),
                'operation' => $request->route()->getName(), 'request_hash' => $hash, 'state' => 'completed',
                'result' => ['ability' => $request->route()->defaults['customfield_ability'], 'status' => $response->getStatusCode(),
                    'body' => $body, 'etag' => $response->headers->get('ETag')]]);

            return [$command, false];
        }, 3);
        CustomFieldApiAccess::authorize($request);
        $result = $command->result;
        $response = response()->json($result['body'] + ['operation_id' => $command->id, 'replayed' => $replayed], $result['status'])
            ->header('X-Operation-Id', $command->id);
        if ($result['etag']) {
            $response->header('ETag', $result['etag']);
        }

        return $response;
    }
}
