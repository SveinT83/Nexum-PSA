<?php

namespace App\Modules\Calendar\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use App\Modules\Calendar\Actions\UpdateCalendarEvent;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Queries\CalendarOverlayQuery;
use App\Modules\Calendar\Resources\Api\V1\CalendarEventResource;
use App\Modules\Calendar\Resources\Api\V1\CalendarResource;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\WorkContext\Support\WorkContextType;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Calendar',
    description: 'API endpoints for calendars and calendar events.'
)]
class CalendarController extends Controller
{
    #[OA\Get(
        path: '/api/v1/calendars',
        operationId: 'getCalendarList',
        summary: 'Get visible calendars',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing calendar.read scope'),
        ]
    )]
    public function calendars(Request $request, CalendarOverlayQuery $overlayQuery, EnsureCalendarDefaults $defaults)
    {
        $defaults->handle($request->user());

        return CalendarResource::collection($overlayQuery->visibleCalendars($request->user()));
    }

    #[OA\Get(
        path: '/api/v1/calendar/events',
        operationId: 'getCalendarEventList',
        summary: 'Get calendar events',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'calendar_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing calendar.read scope'),
        ]
    )]
    public function events(Request $request, CalendarOverlayQuery $overlayQuery, EnsureCalendarDefaults $defaults)
    {
        $defaults->handle($request->user());

        $timezone = $request->input('timezone', 'Europe/Oslo');
        $startsAt = Carbon::parse($request->input('from', now($timezone)->startOfDay()), $timezone);
        $endsAt = Carbon::parse($request->input('to', now($timezone)->addDays(30)->endOfDay()), $timezone);
        $calendarIds = $request->filled('calendar_id') ? [$request->integer('calendar_id')] : [];
        $contextType = $request->filled('context_type') && WorkContextType::isSupported($request->input('context_type'))
            ? $request->input('context_type')
            : null;

        return response()->json([
            'data' => $overlayQuery->eventsForRange(
                $request->user(),
                $startsAt,
                $endsAt,
                $calendarIds,
                $request->filled('work_context_id') ? $request->integer('work_context_id') : null,
                $contextType,
            )
                ->map(fn (array $event) => $this->eventArray($event))
                ->values(),
        ]);
    }

    #[OA\Get(
        path: '/api/v1/calendar/events/{event}',
        operationId: 'getCalendarEventById',
        summary: 'Get calendar event',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation'),
            new OA\Response(response: 403, description: 'Missing calendar.read scope'),
            new OA\Response(response: 404, description: 'Event not found'),
        ]
    )]
    public function showEvent(Request $request, CalendarEvent $event, CalendarOverlayQuery $overlayQuery)
    {
        abort_unless($overlayQuery->visibleCalendars($request->user())->contains('id', $event->calendar_id), 403);

        return new CalendarEventResource($this->loadEvent($event));
    }

    #[OA\Post(
        path: '/api/v1/calendar/events',
        operationId: 'createCalendarEvent',
        summary: 'Create calendar event',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        responses: [
            new OA\Response(response: 201, description: 'Event created'),
            new OA\Response(response: 403, description: 'Missing calendar.create scope'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function storeEvent(
        Request $request,
        StoreCalendarEvent $storeEvent,
        CalendarVisibility $visibility,
        EnsureCalendarDefaults $defaults
    ) {
        $data = $this->validatedEvent($request, creating: true);
        $data['calendar_id'] ??= $defaults->ensurePersonalCalendar($request->user())->id;
        $data = $this->withEventDefaults($data);
        $calendar = Calendar::findOrFail($data['calendar_id']);

        abort_unless($visibility->canManageCalendar($request->user(), $calendar), 403);

        $event = $storeEvent->handle($data, $request->user());

        return (new CalendarEventResource($this->loadEvent($event)))
            ->response()
            ->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/calendar/events/{event}',
        operationId: 'updateCalendarEvent',
        summary: 'Update calendar event',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Event updated'),
            new OA\Response(response: 403, description: 'Missing calendar.update scope'),
            new OA\Response(response: 404, description: 'Event not found'),
            new OA\Response(response: 422, description: 'Validation error'),
        ]
    )]
    public function updateEvent(Request $request, CalendarEvent $event, UpdateCalendarEvent $updateEvent, CalendarVisibility $visibility)
    {
        abort_unless($visibility->canManageCalendar($request->user(), $event->calendar), 403);

        $data = array_merge($this->payloadFromEvent($event), $this->validatedEvent($request, creating: false));
        $data = $this->withEventDefaults($data);
        $targetCalendar = Calendar::findOrFail($data['calendar_id']);

        abort_unless($visibility->canManageCalendar($request->user(), $targetCalendar), 403);

        $event = $updateEvent->handle($event, $data, $request->user());

        return new CalendarEventResource($this->loadEvent($event));
    }

    #[OA\Delete(
        path: '/api/v1/calendar/events/{event}',
        operationId: 'deleteCalendarEvent',
        summary: 'Delete calendar event',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Event deleted'),
            new OA\Response(response: 403, description: 'Missing calendar.delete scope'),
            new OA\Response(response: 404, description: 'Event not found'),
        ]
    )]
    public function destroyEvent(Request $request, CalendarEvent $event, CalendarVisibility $visibility)
    {
        abort_unless($visibility->canManageCalendar($request->user(), $event->calendar), 403);

        $event->delete();

        return response()->noContent();
    }

    private function validatedEvent(Request $request, bool $creating): array
    {
        return $request->validate([
            'calendar_id' => ['sometimes', 'nullable', 'integer', Rule::exists('calendars', 'id')],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$creating ? 'required' : 'sometimes', 'date', 'after:starts_at'],
            'timezone' => ['sometimes', 'timezone'],
            'all_day' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['confirmed', 'tentative', 'cancelled'])],
            'transparency' => ['sometimes', Rule::in(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'])],
            'visibility' => ['sometimes', Rule::in(['default', 'public', 'private', 'confidential'])],
            'participants' => ['sometimes', 'nullable'],
            'recurrence_frequency' => ['sometimes', 'nullable', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'recurrence_ends_at' => ['sometimes', 'nullable', 'date', 'after_or_equal:starts_at'],
        ]);
    }

    private function withEventDefaults(array $data): array
    {
        $data['timezone'] ??= 'Europe/Oslo';
        $data['status'] ??= 'confirmed';
        $data['transparency'] ??= 'busy';
        $data['visibility'] ??= 'default';
        $data['recurrence_frequency'] ??= 'none';

        return $data;
    }

    private function payloadFromEvent(CalendarEvent $event): array
    {
        return [
            'calendar_id' => $event->calendar_id,
            'title' => $event->title,
            'description' => $event->description,
            'location' => $event->location,
            'meeting_url' => $event->meeting_url,
            'starts_at' => $event->starts_at?->timezone($event->timezone)->toDateTimeString(),
            'ends_at' => $event->ends_at?->timezone($event->timezone)->toDateTimeString(),
            'timezone' => $event->timezone,
            'all_day' => $event->all_day,
            'status' => $event->status,
            'transparency' => $event->transparency,
            'visibility' => $event->visibility,
        ];
    }

    /**
     * CalendarOverlayQuery returns privacy-aware arrays, not models.
     */
    private function eventArray(array $event): array
    {
        return [
            'id' => $event['id'],
            'uuid' => $event['uuid'],
            'calendar_id' => $event['calendar_id'],
            'calendar_name' => $event['calendar_name'],
            'calendar_color' => $event['calendar_color'],
            'ownership_badge' => $event['ownership_badge'],
            'work_context_id' => $event['work_context_id'],
            'work_context_type' => $event['work_context_type'],
            'title' => $event['title'],
            'description' => $event['description'],
            'location' => $event['location'],
            'meeting_url' => $event['meeting_url'],
            'starts_at' => $event['starts_at'],
            'ends_at' => $event['ends_at'],
            'timezone' => $event['timezone'],
            'all_day' => $event['all_day'],
            'status' => $event['status'],
            'transparency' => $event['transparency'],
            'visibility' => $event['visibility'],
            'is_private' => $event['is_private'],
            'details_visible' => $event['details_visible'],
            'is_recurring' => $event['is_recurring'],
            'occurrence_key' => $event['occurrence_key'],
        ];
    }

    private function loadEvent(CalendarEvent $event): CalendarEvent
    {
        return $event->load(['calendar', 'workContext', 'participants']);
    }
}
