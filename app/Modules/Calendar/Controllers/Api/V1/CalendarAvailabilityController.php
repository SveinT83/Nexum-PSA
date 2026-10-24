<?php

namespace App\Modules\Calendar\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Calendar\Actions\FindAvailableSlots;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\Calendar\Support\CalendarApiContract as Contract;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'CalendarApiSlotSuggestions', type: 'object', properties: [
    new OA\Property(property: 'data', type: 'array', items: new OA\Items(type: 'object', properties: [
        new OA\Property(property: 'user_id', type: 'integer'), new OA\Property(property: 'calendar_id', type: 'integer'),
        new OA\Property(property: 'starts_at', type: 'string', format: 'date-time'), new OA\Property(property: 'ends_at', type: 'string', format: 'date-time'),
        new OA\Property(property: 'timezone', type: 'string'),
    ])),
    new OA\Property(property: 'meta', type: 'object', properties: [
        new OA\Property(property: 'result_kind', type: 'string', enum: ['suggestions']), new OA\Property(property: 'complete', type: 'boolean', enum: [false]),
        new OA\Property(property: 'limit', type: 'integer'), new OA\Property(property: 'returned_count', type: 'integer'),
        new OA\Property(property: 'from', type: 'string', format: 'date-time'), new OA\Property(property: 'to', type: 'string', format: 'date-time'),
    ]),
])]
class CalendarAvailabilityController extends Controller
{
    #[OA\Get(path: '/api/v1/calendar/availability/users', operationId: 'calendarParityCalendarAvailabilityControllerUsers', summary: 'users Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.free-busy.read']], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarApiOption')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page_url', type: 'string', nullable: true), new OA\Property(property: 'prev_page_url', type: 'string', nullable: true)])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function users(Request $request)
    {
        $actor = Contract::actor($request, 'calendar.view_free_busy');
        abort_unless($actor->can('calendar.view'), 403);
        Contract::fields($request, ['page', 'per_page']);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json(User::query()->where('status', User::STATUS_ACTIVE)->where('is_system_actor', false)
            ->orderBy('name')->paginate($request->integer('per_page', 25), ['id', 'name']));
    }

    #[OA\Get(path: '/api/v1/calendar/availability', operationId: 'calendarAvailableSlots', summary: 'Find authorized employee free slots without private busy details', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.free-busy.read']],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: true, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'timezone', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'duration', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 15, maximum: 480)),
            new OA\Parameter(name: 'limit', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 50)),
        ],
        responses: [new OA\Response(response: 200, description: 'Bounded suggestions, never an exhaustive availability range; meta.complete is false; range limited to 31 days', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiSlotSuggestions')), new OA\Response(response: 403, description: 'Missing free/busy permission or target visibility'), new OA\Response(response: 422, description: 'Invalid range or options')])]
    public function index(Request $request, FindAvailableSlots $slots, CalendarOverlayQuery $overlay)
    {
        $actor = Contract::actor($request, 'calendar.view_free_busy');
        abort_unless($actor->can('calendar.view'), 403);
        Contract::fields($request, ['user_id', 'from', 'to', 'timezone', 'duration', 'limit']);
        $data = $request->validate([
            'user_id' => ['sometimes', 'integer', 'min:1'], 'from' => ['required', 'date'], 'to' => ['required', 'date'],
            'timezone' => ['sometimes', 'timezone'], 'duration' => ['sometimes', 'integer', 'min:15', 'max:480'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);
        $target = User::query()->where('status', User::STATUS_ACTIVE)->findOrFail($data['user_id'] ?? $actor->id);
        abort_if($target->isSystemActor(), 403);
        $calendar = Calendar::query()->where('type', 'personal')->where('owner_type', User::class)->where('owner_id', $target->id)->where('is_active', true)->first();
        abort_unless($calendar && $overlay->visibleCalendars($actor)->contains('id', $calendar->id), 403);
        $tz = $data['timezone'] ?? $calendar->timezone;
        $from = Carbon::parse($data['from'], $tz);
        $to = Carbon::parse($data['to'], $tz);
        abort_if($to->lte($from) || $from->diffInDays($to) > 31, 422, 'Choose an increasing range of at most 31 days.');

        $suggestions = $slots->handle($target, $from, $to, $data['duration'] ?? 60, $data['limit'] ?? 12)
            ->map(fn ($slot) => [
                'user_id' => $target->id, 'calendar_id' => $calendar->id,
                'starts_at' => $slot['starts_at']->copy()->timezone($tz)->toIso8601String(),
                'ends_at' => $slot['ends_at']->copy()->timezone($tz)->toIso8601String(), 'timezone' => $tz,
            ])->values();

        return response()->json(['data' => $suggestions, 'meta' => [
            'result_kind' => 'suggestions', 'complete' => false, 'limit' => $data['limit'] ?? 12,
            'returned_count' => $suggestions->count(), 'from' => $from->toIso8601String(), 'to' => $to->toIso8601String(),
        ]]);
    }
}
