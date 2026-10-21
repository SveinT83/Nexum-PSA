<?php

namespace App\Modules\Email\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Email\Services\EmailLiveCatchUpService;
use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use App\Modules\Email\Support\EmailApiAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\DB;

/** Private invalidation is opaque, actor-bound and gated; a route never enables the runtime. */
final class MailboxLiveApiController extends Controller
{
    public function catchUp(Request $request, EmailApiAccess $access, EmailLiveCatchUpService $service, EmailLiveRuntimeReadiness $readiness)
    {
        $actor = $access->reader($request);
        abort_unless($readiness->ready(), 503, 'Mail live invalidation is unavailable.');
        $data = $request->validate($this->versionRules());

        return response()->json(['data' => $service->catchUp($actor, $data['version'],
            $data['authorization_epoch'], $data['global_authorization_generation'])]);
    }

    public function acknowledge(Request $request, EmailApiAccess $access, EmailLiveCatchUpService $service, EmailLiveRuntimeReadiness $readiness)
    {
        $actor = $access->reader($request);
        abort_unless($readiness->ready(), 503, 'Mail live invalidation is unavailable.');
        $data = $request->validate($this->versionRules() + ['receipt' => ['required', 'string', 'size:64']]);
        abort_unless(hash_equals($service->receipt($actor, $data['version'], $data['authorization_epoch'],
            $data['global_authorization_generation']), $data['receipt']), 422, 'The applied receipt is invalid.');
        $service->acknowledgeAppliedVersion($actor, $data['version'], $data['authorization_epoch'],
            $data['global_authorization_generation'], $data['receipt']);
        $acknowledged = DB::table('email_live_projection_streams')->where('stream_type', 'user')
            ->where('user_id', $actor->id)->value('acknowledged_version');

        return response()->json(['data' => ['acknowledged_version' => (string) ($acknowledged ?? 0)]]);
    }

    public function broadcast(Request $request, EmailApiAccess $access, EmailLiveRuntimeReadiness $readiness)
    {
        $access->reader($request);
        abort_unless($readiness->ready(), 503, 'Mail live invalidation is unavailable.');
        $request->validate(['socket_id' => ['required', 'string', 'max:100'],
            'channel_name' => ['required', 'string', 'max:200']]);

        return Broadcast::auth($request);
    }

    private function versionRules(): array
    {
        return ['version' => ['required', 'string', 'regex:/^[0-9]{1,19}$/D'],
            'authorization_epoch' => ['required', 'string', 'regex:/^[0-9]{1,19}$/D'],
            'global_authorization_generation' => ['required', 'string', 'regex:/^[0-9]{1,19}$/D']];
    }
}
