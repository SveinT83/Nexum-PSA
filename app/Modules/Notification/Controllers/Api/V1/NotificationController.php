<?php

namespace App\Modules\Notification\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Actions\ManageNotificationReads;
use App\Modules\Notification\Actions\OpenNotification;
use Illuminate\Http\Request;

/** Official own-copy reads and acknowledgements; GET has no read-state side effect. */
class NotificationController extends Controller
{
    private function portal(Request $request): bool
    {
        return (bool) ($request->route()->defaults['notification_portal'] ?? false);
    }

    public function index(Request $request)
    {
        $request->validate(['unread' => 'sometimes|boolean', 'per_page' => 'sometimes|integer|min:1|max:100', 'page' => 'sometimes|integer|min:1']);
        $reads = app(ManageNotificationReads::class);
        $query = $reads->query($request->user(), $this->portal($request));
        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }
        $page = $query->latest()->orderBy('id')->paginate($request->integer('per_page') ?: 15);
        $data = $page->getCollection()->map(fn ($n) => $reads->serialize($n, $this->portal($request)))->values()->all();

        return response()->json(['data' => $data,
            'meta' => ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
            'links' => ['first' => $page->url(1), 'last' => $page->url($page->lastPage()), 'prev' => $page->previousPageUrl(), 'next' => $page->nextPageUrl()]]);
    }

    public function show(Request $request, string $notification)
    {
        $reads = app(ManageNotificationReads::class);

        return response()->json(['data' => $reads->serialize($reads->find($request->user(), $notification, $this->portal($request)), $this->portal($request))]);
    }

    public function summary(Request $request)
    {
        $reads = app(ManageNotificationReads::class);
        $query = $reads->query($request->user(), $this->portal($request))->whereNull('read_at');

        return response()->json(['data' => ['unread_count' => (clone $query)->count(),
            'recent' => $query->latest()->limit(10)->get()->map(fn ($n) => $reads->serialize($n, $this->portal($request)))->all()]]);
    }

    public function markRead(Request $request, string $notification)
    {
        $reads = app(ManageNotificationReads::class);

        return response()->json(['data' => $reads->serialize($reads->read($request->user(), $notification, $this->portal($request)), $this->portal($request))]);
    }

    public function markAllRead(Request $request)
    {
        return response()->json(['data' => ['updated' => app(ManageNotificationReads::class)->readAll($request->user(), $this->portal($request))]]);
    }

    public function open(Request $request, string $notification)
    {
        return response()->json(['data' => app(OpenNotification::class)->handle($request->user(), $notification, $this->portal($request))]);
    }
}
