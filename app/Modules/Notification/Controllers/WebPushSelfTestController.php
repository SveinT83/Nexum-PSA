<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Notification\Jobs\SendWebPushDeviceTest;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Requests\ResolveWebPushSubscriptionRequest;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Http\JsonResponse;

class WebPushSelfTestController extends Controller
{
    public function store(
        ResolveWebPushSubscriptionRequest $request,
        WebPushReadiness $readiness,
    ): JsonResponse {
        if (! $readiness->isReady()) {
            return response()->json([
                'message' => 'Web Push is not ready in this environment.',
                'readiness' => $readiness->toArray(),
            ], 503);
        }

        /** @var User $user */
        $user = $request->user();
        $subscription = WebPushSubscription::findByEndpoint($request->validated('endpoint'));

        if (! $subscription || ! $subscription->belongsToUser($user)) {
            return response()->json([
                'message' => 'The current browser is not registered for Web Push.',
            ], 404);
        }

        SendWebPushDeviceTest::dispatch(
            userId: (int) $user->id,
            subscriptionPublicId: $subscription->public_id,
        );

        return response()->json([
            'queued' => true,
            'message' => 'A generic test notification was queued for this device.',
        ], 202);
    }
}
