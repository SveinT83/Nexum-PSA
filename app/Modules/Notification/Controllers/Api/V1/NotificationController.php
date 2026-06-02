<?php

namespace App\Modules\Notification\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Resources\Api\V1\NotificationResource;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Notifications',
    description: 'API endpoints for user notifications.'
)]
class NotificationController extends Controller
{
    #[OA\Get(
        path: '/api/v1/notifications',
        operationId: 'getNotificationList',
        summary: 'Get user notifications',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'unread', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing notifications.read scope'),
        ]
    )]
    public function index(Request $request)
    {
        $query = $request->user()
            ->notifications()
            ->latest();

        if ($request->boolean('unread')) {
            $query->whereNull('read_at');
        }

        return NotificationResource::collection($query->paginate($request->integer('per_page') ?: 15));
    }

    #[OA\Post(
        path: '/api/v1/notifications/{notification}/read',
        operationId: 'markNotificationRead',
        summary: 'Mark one notification as read',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        parameters: [
            new OA\Parameter(name: 'notification', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Notification marked as read'),
            new OA\Response(response: 403, description: 'Missing notifications.update scope'),
            new OA\Response(response: 404, description: 'Notification not found'),
        ]
    )]
    public function markRead(Request $request, DatabaseNotification $notification)
    {
        abort_unless(
            $notification->notifiable_type === $request->user()::class
                && (int) $notification->notifiable_id === (int) $request->user()->id,
            404
        );

        $notification->markAsRead();
        $notification = $request->user()
            ->notifications()
            ->whereKey($notification->id)
            ->firstOrFail();

        return response()->json([
            'data' => [
                'id' => $notification->id,
                'type' => $notification->type,
                'data' => $notification->data,
                'read_at' => $notification->read_at,
                'created_at' => $notification->created_at,
                'updated_at' => $notification->updated_at,
                'links' => [
                    'mark_read' => route('api.v1.notifications.read', $notification->id),
                ],
            ],
        ]);
    }

    #[OA\Post(
        path: '/api/v1/notifications/read-all',
        operationId: 'markAllNotificationsRead',
        summary: 'Mark all user notifications as read',
        security: [['bearerAuth' => []]],
        tags: ['Notifications'],
        responses: [
            new OA\Response(response: 200, description: 'Notifications marked as read'),
            new OA\Response(response: 403, description: 'Missing notifications.update scope'),
        ]
    )]
    public function markAllRead(Request $request)
    {
        $updated = $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return response()->json([
            'data' => [
                'updated' => $updated,
            ],
        ]);
    }
}
