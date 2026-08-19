<?php

namespace App\Modules\Email\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Email\Models\EmailLiveProjectionStream;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class EmailBroadcastingController extends Controller
{
    /**
     * Authenticate the user for the private email channel.
     * We follow the Laravel Echo convention for channel authorization.
     */
    public function __invoke(Request $request)
    {
        $channelName = $request->input('channel_name');

        if (! $channelName || ! str_starts_with($channelName, 'private-email.user.')) {
            return response()->json(['message' => 'Invalid channel name'], 403);
        }

        $userId = (int) str_replace('private-email.user.', '', $channelName);

        if ($request->user()->id !== $userId) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        // Additional domain check: Ensure user has a valid mail projection stream
        // or is allowed to use mail.
        // For now, we just check if they are the owner of the ID.

        return broadcast($request);
    }
}
