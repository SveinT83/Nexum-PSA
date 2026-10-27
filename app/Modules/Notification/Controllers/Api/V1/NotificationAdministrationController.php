<?php

namespace App\Modules\Notification\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Notification\Actions\ExecuteNotificationApiCommand;
use App\Modules\Notification\Actions\ManageNotificationChannels;
use App\Modules\Notification\Actions\RemoveWebPushSubscription;
use App\Modules\Notification\Models\NotificationApiCommand;
use App\Modules\Notification\Models\NotificationChannel;
use App\Modules\Notification\Models\WebPushSubscription;
use Illuminate\Http\Request;

/** Native admin rights are enforced before every action, including receipt read-back. */
class NotificationAdministrationController extends Controller
{
    public function index(Request $request)
    {
        return response()->json(['data' => NotificationChannel::orderBy('name')->get()
            ->map(fn ($c) => app(ManageNotificationChannels::class)->summary($c))->all()]);
    }

    public function show(Request $request, NotificationChannel $channel)
    {
        return response()->json(['data' => app(ManageNotificationChannels::class)->summary($channel)])
            ->header('ETag', ExecuteNotificationApiCommand::etag($channel));
    }

    public function context(Request $request, NotificationChannel $channel)
    {
        return response()->json(['data' => app(ManageNotificationChannels::class)->context($channel)])
            ->header('ETag', ExecuteNotificationApiCommand::etag($channel));
    }

    public function update(Request $request, NotificationChannel $channel)
    {
        return response()->json(['data' => app(ManageNotificationChannels::class)->update($request, $channel)]);
    }

    public function test(Request $request, NotificationChannel $channel)
    {
        return response()->json(['data' => app(ManageNotificationChannels::class)->test($request, $channel)]);
    }

    public function devices(Request $request)
    {
        $request->validate(['search' => 'sometimes|string|max:100', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $query = WebPushSubscription::query()->with('subscribable')->where('subscribable_type', (new User)->getMorphClass());
        if ($search = trim((string) $request->query('search', ''))) {
            $query->whereHasMorph('subscribable', [User::class], fn ($q) => $q->where('name', 'like', '%'.$search.'%')->orWhere('email', 'like', '%'.$search.'%'));
        }
        $page = $query->latest('created_at')->paginate($request->integer('per_page') ?: 50);

        return response()->json(['data' => $page->getCollection()->map(fn ($d) => $d->safeSummary()
            + ['user' => ['id' => $d->subscribable->id, 'name' => $d->subscribable->name, 'email' => $d->subscribable->email]])->all(),
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()]]);
    }

    public function revoke(Request $request, string $subscription)
    {
        $subscription = WebPushSubscription::where('public_id', $subscription)->firstOrFail();
        $target = $subscription->subscribable;
        abort_unless($target instanceof User, 404);
        app(RemoveWebPushSubscription::class)->handle($subscription, $target, $request->user(), RemoveWebPushSubscription::ACTION_ADMINISTRATOR_REVOKED);

        return response()->json(['removed' => true]);
    }

    public function operation(Request $request, string $operation)
    {
        $command = NotificationApiCommand::whereKey($operation)->where('actor_id', $request->user()->id)
            ->where('token_hash', ExecuteNotificationApiCommand::tokenHash($request))->firstOrFail();
        abort_unless($request->user()->tokenCan($command->ability), 403);
        if ($command->admin) {
            abort_unless(app(\App\Http\Middleware\EnforceTechRoutePermission::class)->allowsActor($request->user(), 'notification.manage_channels'), 403);
        }

        return response()->json(['data' => ['id' => $command->id, 'operation' => $command->operation,
            'state' => $command->state, 'status' => $command->status, 'result' => $command->result,
            'reconciliation_required' => in_array($command->state, ['unknown', 'claimed'], true)]]);
    }
}
