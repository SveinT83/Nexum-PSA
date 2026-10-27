<?php

namespace App\Modules\Nextcloud\Support;

use App\Modules\Nextcloud\Models\NextcloudApiCommand;
use Illuminate\Http\Request;

/** Deleted-resource replay is exact actor/token/key/path/payload/revision authority. */
class ReplayMissingNextcloudDeletion
{
    public function handle(Request $request)
    {
        abort_unless($request->isMethod('delete') && $request->hasHeader('Idempotency-Key'), 404);
        $actor = NextcloudApiAccess::actor();
        $request->setUserResolver(fn () => $actor);
        $command = NextcloudApiCommand::where('actor_id', $actor->id)->where('token_hash', NextcloudApiAccess::tokenHash($request))
            ->where('key_hash', hash('sha256', $request->header('Idempotency-Key')))->first();
        abort_unless($command && $command->state === 'completed', 404);
        $hash = trim(NextcloudApiInput::revision([$request->method(), $request->path(), $request->query(), $request->all(), $request->header('If-Match')]), '"');
        abort_unless(hash_equals($command->request_hash, $hash), 409, 'Replay does not match the original command.');
        NextcloudApiAccess::authorize($actor, $command->result['permissions'] ?? []);
        foreach ($command->result['abilities'] ?? [] as $ability) {
            abort_unless($actor->tokenCan($ability), 403);
        }
        $body = $command->result['body'];
        $body['operation_id'] = $command->id;
        $body['replayed'] = true;

        return response()->json($body, $command->result['status'])->header('X-Operation-Id', $command->id);
    }
}
