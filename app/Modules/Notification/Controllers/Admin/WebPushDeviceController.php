<?php

namespace App\Modules\Notification\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Notification\Actions\RemoveWebPushSubscription;
use App\Modules\Notification\Models\WebPushSubscription;
use App\Modules\Notification\Support\WebPushReadiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebPushDeviceController extends Controller
{
    public function index(Request $request, WebPushReadiness $readiness): View
    {
        $search = trim((string) $request->query('search', ''));
        $userMorphClass = (new User)->getMorphClass();

        $devices = WebPushSubscription::query()
            ->with('subscribable')
            ->where('subscribable_type', $userMorphClass)
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->whereHasMorph(
                    'subscribable',
                    [User::class],
                    fn (Builder $userQuery) => $userQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"),
                );
            })
            ->latest('created_at')
            ->paginate(50)
            ->withQueryString();

        return view('notification::Admin.web-push.devices', [
            'devices' => $devices,
            'readiness' => $readiness->toArray(),
            'search' => $search,
        ]);
    }

    public function destroy(
        Request $request,
        WebPushSubscription $subscription,
        RemoveWebPushSubscription $removeSubscription,
    ): RedirectResponse {
        $targetUser = $subscription->subscribable;

        if (! $targetUser instanceof User) {
            abort(404);
        }

        /** @var User $actor */
        $actor = $request->user();
        $removeSubscription->handle(
            subscription: $subscription,
            targetUser: $targetUser,
            actor: $actor,
            action: RemoveWebPushSubscription::ACTION_ADMINISTRATOR_REVOKED,
        );

        return redirect()
            ->route('tech.admin.notification-channels.web-push.devices.index')
            ->with('success', 'Web Push device revoked.');
    }
}
