<?php

namespace App\Modules\Knowledge\Actions;

use App\Modules\Knowledge\Models\KnowledgeApiCommand;
use App\Modules\Knowledge\Support\KnowledgeApiAccess;
use App\Modules\Knowledge\Support\KnowledgeApiInput;
use Illuminate\Http\Request;

/** Only an exact authorized retained DELETE result can outlive implicit model binding. */
class ReplayDeletedKnowledgeCommand
{
    public static function handle(Request $request)
    {
        $actor = KnowledgeApiAccess::actor();
        $request->setUserResolver(fn () => $actor);
        $key = $request->header('Idempotency-Key');
        abort_unless($key && $request->isMethod('delete'), 404);
        KnowledgeApiInput::allowed($request, $request->route()->defaults['knowledge_fields'] ?? []);
        $command = KnowledgeApiCommand::where('actor_id', $actor->id)
            ->where('token_hash', KnowledgeApiAccess::tokenHash($request))
            ->where('key_hash', hash('sha256', $key))->firstOrFail();
        $hash = trim(KnowledgeApiInput::revision([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]), '"');
        abort_unless($command->operation === $request->route()->getName() && hash_equals($command->request_hash, $hash), 409);
        ExecuteKnowledgeApiCommand::authorizeReceipt($request, $command);
        $result = $command->result;
        abort_unless(in_array($result['status'], [204, 202], true), 404);

        return ($result['status'] === 204 ? response()->noContent() : response()->json($result['body'] + [
            'operation_id' => $command->id, 'replayed' => true], 202))->header('X-Operation-Id', $command->id);
    }
}
