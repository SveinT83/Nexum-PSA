<?php

namespace App\Modules\Notification\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Notification\Actions\RecordWebPushSubscriptionEvent;
use App\Modules\Notification\Actions\RemoveWebPushSubscription;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Requests\ResolveWebPushSubscriptionRequest;
use App\Modules\Notification\Requests\StoreWebPushSubscriptionRequest;
use App\Modules\Notification\Support\WebPushDeviceDetector;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WebPushSubscriptionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'devices' => $user->pushSubscriptions()
                ->latest('created_at')
                ->get()
                ->filter(fn ($subscription) => $subscription instanceof WebPushSubscription)
                ->map(fn (WebPushSubscription $subscription) => $subscription->safeSummary())
                ->values(),
        ]);
    }

    public function current(ResolveWebPushSubscriptionRequest $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $subscription = WebPushSubscription::findByEndpoint($request->validated('endpoint'));

        if (! $subscription || ! $subscription->belongsToUser($user)) {
            return response()->json(['device' => null]);
        }

        $subscription->forceFill(['last_seen_at' => now()])->saveQuietly();

        return response()->json([
            'device' => $subscription->fresh()->safeSummary(),
        ]);
    }

    public function store(
        StoreWebPushSubscriptionRequest $request,
        WebPushReadiness $readiness,
        WebPushDeviceDetector $deviceDetector,
        RecordWebPushSubscriptionEvent $recordEvent,
    ): JsonResponse {
        if (! $readiness->isReady()) {
            return response()->json([
                'message' => 'Web Push is not ready in this environment.',
                'readiness' => $readiness->toArray(),
            ], 503);
        }

        /** @var User $user */
        $user = $request->user();
        $data = $request->validated();
        $existing = WebPushSubscription::findByEndpoint($data['endpoint']);

        if ($existing && ! $existing->belongsToUser($user)) {
            return response()->json([
                'message' => 'This browser subscription belongs to another account and cannot be transferred.',
            ], 409);
        }

        $created = $existing === null;
        $device = $deviceDetector->detect($request->userAgent());
        $subscription = $user->updatePushSubscription(
            endpoint: $data['endpoint'],
            key: $data['keys']['p256dh'],
            token: $data['keys']['auth'],
            contentEncoding: $data['content_encoding'] ?? 'aes128gcm',
        );

        if (! $subscription instanceof WebPushSubscription) {
            abort(500, 'The configured Web Push subscription model is invalid.');
        }

        $subscription->forceFill([
            'device_label' => $device['label'],
            'browser_family' => $device['browser'],
            'platform_family' => $device['platform'],
            'last_seen_at' => now(),
        ])->save();

        if ($created) {
            $recordEvent->handle(
                subscription: $subscription,
                targetUser: $user,
                actor: $user,
                action: 'registered',
            );
        }

        return response()->json([
            'device' => $subscription->fresh()->safeSummary(),
        ], $created ? 201 : 200);
    }

    public function destroy(
        Request $request,
        WebPushSubscription $subscription,
        RemoveWebPushSubscription $removeSubscription,
    ): JsonResponse {
        /** @var User $user */
        $user = $request->user();

        if (! $subscription->belongsToUser($user)) {
            abort(404);
        }

        $removeSubscription->handle(
            subscription: $subscription,
            targetUser: $user,
            actor: $user,
            action: RemoveWebPushSubscription::ACTION_USER_REVOKED,
        );

        return response()->json(['removed' => true]);
    }
}
