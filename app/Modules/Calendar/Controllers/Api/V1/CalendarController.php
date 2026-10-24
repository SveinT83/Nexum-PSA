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
    #[OA\Get(path: '/api/v1/calendar/context', operationId: 'calendarContext', summary: 'Read effective Calendar context without initialization', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.read']], responses: [new OA\Response(response: 200, description: 'Effective actor context', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiContext')])), new OA\Response(response: 403, description: 'Inactive actor or missing Calendar view permission', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function context(Request $request)
    {
        $actor = \App\Modules\Calendar\Support\CalendarApiContract::actor($request);
        \App\Modules\Calendar\Support\CalendarApiContract::fields($request, []);

        return response()->json(['data' => app(\App\Modules\Calendar\Queries\CalendarApiWorkspace::class)->context($actor, $request)]);
    }

    #[OA\Post(path: '/api/v1/calendar/initialize', operationId: 'calendarInitialize', summary: 'Explicit administrator initialization of Calendar defaults and own personal calendar', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], responses: [new OA\Response(response: 200, description: 'Initialized context', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiContext')])), new OA\Response(response: 403, description: 'Requires administrator and calendar.manage_settings', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function initialize(Request $request, EnsureCalendarDefaults $defaults)
    {
        \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.manage_settings', true);
        \App\Modules\Calendar\Support\CalendarApiContract::fields($request, []);
        app(\App\Modules\Calendar\Actions\ExecuteCalendarApiCommand::class)->handle($request, 'initialize', [],
            fn () => \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.manage_settings', true),
            fn ($actor) => $defaults->handle($actor)['calendar']);

        return response()->json(['data' => app(\App\Modules\Calendar\Queries\CalendarApiWorkspace::class)->context($request->user(), $request)]);
    }

    #[OA\Get(
        path: '/api/v1/calendars',
        operationId: 'getCalendarList',
        x: ['required-scopes' => ['calendar.read']],
        summary: 'Get visible calendars',
        parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 500))],
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarApiContainer'))])),
            new OA\Response(response: 403, description: 'Missing calendar.read scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
        ]
    )]
    public function calendars(Request $request, CalendarOverlayQuery $overlayQuery, EnsureCalendarDefaults $defaults)
    {
        \App\Modules\Calendar\Support\CalendarApiContract::actor($request);

        \App\Modules\Calendar\Support\CalendarApiContract::fields($request, ['page', 'per_page']);
        $data = $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:500']]);
        $calendars = $overlayQuery->visibleCalendars($request->user());
        $page = $data['page'] ?? 1;
        $perPage = $data['per_page'] ?? 100;

        return CalendarResource::collection(new \Illuminate\Pagination\LengthAwarePaginator(
            $calendars->forPage($page, $perPage)->values(), $calendars->count(), $perPage, $page, ['path' => $request->url()]
        ));
    }

    #[OA\Get(
        path: '/api/v1/calendar/events',
        operationId: 'getCalendarEventList',
        x: ['required-scopes' => ['calendar.read']],
        summary: 'Get calendar events',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date-time')),
            new OA\Parameter(name: 'timezone', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'calendar_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'calendars', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'integer'))),
            new OA\Parameter(name: 'ownership_groups', in: 'query', schema: new OA\Schema(type: 'array', items: new OA\Items(type: 'string'))),
            new OA\Parameter(name: 'only_mine', in: 'query', schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'event_search', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'event_sort', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'event_direction', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'view', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'date', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'work_context_id', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'context_type', in: 'query', schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Masked filtered occurrences', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiEventList')),
            new OA\Response(response: 403, description: 'Missing calendar.read scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
        ]
    )]
    public function events(Request $request, CalendarOverlayQuery $overlayQuery, EnsureCalendarDefaults $defaults)
    {
        $actor = \App\Modules\Calendar\Support\CalendarApiContract::actor($request);

        return response()->json(app(\App\Modules\Calendar\Queries\CalendarApiWorkspace::class)->events($request, $actor));
    }

    #[OA\Get(
        path: '/api/v1/calendar/events/{event}',
        operationId: 'getCalendarEventById',
        x: ['required-scopes' => ['calendar.read']],
        summary: 'Get calendar event',
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Successful operation', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiEventDetail')])),
            new OA\Response(response: 403, description: 'Missing calendar.read scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
            new OA\Response(response: 404, description: 'Event not found', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
        ]
    )]
    public function showEvent(Request $request, CalendarEvent $event, CalendarOverlayQuery $overlayQuery)
    {
        \App\Modules\Calendar\Support\CalendarApiContract::actor($request);
        abort_unless($overlayQuery->visibleCalendars($request->user())->contains('id', $event->calendar_id), 403);

        return new CalendarEventResource($this->loadEvent($event));
    }

    #[OA\Post(
        path: '/api/v1/calendar/events',
        operationId: 'createCalendarEvent',
        x: ['required-scopes' => ['calendar.create']],
        summary: 'Create calendar event',
        description: 'Requires calendar.create actor permission and calendar management rights. Optional Idempotency-Key on this legacy route; send it for safe retries. Participant records do not send invitations.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiEventCreate')),
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: false, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))],
        responses: [
            new OA\Response(response: 201, description: 'Persisted event', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiEventDetail')])),
            new OA\Response(response: 403, description: 'Missing calendar.create scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
        ]
    )]
    public function storeEvent(
        Request $request,
        StoreCalendarEvent $storeEvent,
        CalendarVisibility $visibility,
        EnsureCalendarDefaults $defaults
    ) {
        $actor = \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.create');
        $data = $this->validatedEvent($request, creating: true);
        $result = app(\App\Modules\Calendar\Actions\ExecuteCalendarApiCommand::class)->handle($request, 'event.create', $data,
            function ($current) use ($request, $data, $visibility) {
                \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.create');
                if (! empty($data['calendar_id'])) {
                    $calendar = Calendar::findOrFail($data['calendar_id']);
                    abort_unless($calendar->is_active && $visibility->canManageCalendar($current, $calendar), 403);
                }
            },
            function ($current) use ($data, $defaults, $storeEvent) {
                $data['calendar_id'] ??= $defaults->ensurePersonalCalendar($current)->id;
                $data = $this->withEventDefaults($data);
                $this->validateWindow($data);

                return $storeEvent->handle($data, $current);
            }, false);
        // Reproject the current record after authorization; no cached private payload is replayed.
        abort_unless(! $result->trashed() && $visibility->canManageCalendar($actor, $result->calendar), 403);

        return (new CalendarEventResource($this->loadEvent($result)))->response()->setStatusCode(201);
    }

    #[OA\Patch(
        path: '/api/v1/calendar/events/{event}',
        operationId: 'updateCalendarEvent',
        x: ['required-scopes' => ['calendar.update']],
        summary: 'Update calendar event',
        description: 'PUT and PATCH retain legacy partial-update semantics. Omitted fields are preserved; nullable text/participants may be cleared. Supply revision for stale-write protection and Idempotency-Key for retries. Recurrence-definition edits are rejected.',
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiEventWrite')),
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Event updated', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiEventDetail')])),
            new OA\Response(response: 403, description: 'Missing calendar.update scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
            new OA\Response(response: 404, description: 'Event not found', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
        ]
    )]
    #[OA\Put(path: '/api/v1/calendar/events/{event}', operationId: 'replaceCalendarEventCompatibility',
        x: ['required-scopes' => ['calendar.update']], summary: 'Legacy partial event update (same contract as PATCH)', security: [['bearerAuth' => []]], tags: ['Calendar'],
        parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiEventWrite')),
        responses: [new OA\Response(response: 200, description: 'Persisted event', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiEventDetail')])), new OA\Response(response: 409, description: 'Stale revision or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function updateEvent(Request $request, CalendarEvent $event, UpdateCalendarEvent $updateEvent, CalendarVisibility $visibility)
    {
        \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.update');
        abort_unless($visibility->canManageCalendar($request->user(), $event->calendar), 403);
        \App\Modules\Calendar\Actions\ProjectWorkdayAbsence::assertCalendarEditable($event);
        abort_if($event->source === 'work_plan', 422, 'Edit this block from Profile > Work plan.');
        $input = $this->validatedEvent($request, creating: false);
        $result = app(\App\Modules\Calendar\Actions\ExecuteCalendarApiCommand::class)->handle($request, 'event.update', ['event_id' => $event->id, 'input' => $input],
            function ($actor) use ($event, $input, $visibility, $request) {
                \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.update');
                $event->refresh();
                abort_unless(! $event->trashed() && $visibility->canManageCalendar($actor, $event->calendar()->firstOrFail()), 403);
                $target = Calendar::findOrFail($input['calendar_id'] ?? $event->calendar_id);
                abort_unless($target->is_active && $visibility->canManageCalendar($actor, $target), 403);
            },
            function ($actor) use ($event, $input, $updateEvent) {
                $event = CalendarEvent::query()->lockForUpdate()->findOrFail($event->id);
                $this->assertRevision($event, $input['revision'] ?? null);
                abort_if($event->source === 'work_plan', 422, 'Edit this block from Profile > Work plan.');
                \App\Modules\Calendar\Actions\ProjectWorkdayAbsence::assertCalendarEditable($event);
                $data = array_merge($this->payloadFromEvent($event), $input);
                $this->validateWindow($data);

                // The browser has no supported recurrence-definition editor. Never silently ignore it.
                return $updateEvent->handle($event, $data, $actor);
            }, false);

        return new CalendarEventResource($this->loadEvent($result));
    }

    #[OA\Delete(
        path: '/api/v1/calendar/events/{event}',
        operationId: 'deleteCalendarEvent',
        x: ['required-scopes' => ['calendar.delete']],
        summary: 'Delete calendar event',
        description: 'Ordinary legacy record deletion returns 204. Occurrence or series commands require Idempotency-Key, revision and a fresh delete preview.',
        requestBody: new OA\RequestBody(content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiDelete')),
        security: [['bearerAuth' => []]],
        tags: ['Calendar'],
        parameters: [
            new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 204, description: 'Event deleted'),
            new OA\Response(response: 403, description: 'Missing calendar.delete scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
            new OA\Response(response: 404, description: 'Event not found', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')),
        ]
    )]
    public function destroyEvent(Request $request, int $event, CalendarVisibility $visibility)
    {
        \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.delete');
        $record = CalendarEvent::withTrashed()->findOrFail($event);
        abort_unless($visibility->canManageCalendar($request->user(), $record->calendar), 403);
        \App\Modules\Calendar\Actions\ProjectWorkdayAbsence::assertCalendarEditable($record);
        abort_if($record->source === 'work_plan', 422, 'Edit this block from Profile > Work plan.');
        \App\Modules\Calendar\Support\CalendarApiContract::fields($request, ['scope', 'original_starts_at', 'revision', 'preview']);
        $data = $request->validate([
            'scope' => ['sometimes', Rule::in(['record', 'event', 'series'])],
            'original_starts_at' => ['sometimes', 'required', 'date'],
            'revision' => ['sometimes', 'string', 'size:64'], 'preview' => ['sometimes', 'string', 'size:64'],
        ]);
        $scope = $data['scope'] ?? 'record';
        $record = CalendarEvent::withTrashed()->findOrFail($event);
        app(\App\Modules\Calendar\Actions\ExecuteCalendarApiCommand::class)->handle($request, 'event.delete', ['event_id' => $event, 'input' => $data],
            function ($actor) use ($record, $visibility, $request) {
                \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.delete');
                $record->refresh();
                abort_unless($visibility->canManageCalendar($actor, $record->calendar()->firstOrFail()), 403);
            },
            function ($actor) use ($record, $scope, $data) {
                abort_if($record->trashed(), 404);
                $record = CalendarEvent::query()->lockForUpdate()->findOrFail($record->id);
                $this->assertRevision($record, $data['revision'] ?? null);
                if ($scope !== 'record') {
                    abort_unless(isset($data['revision'], $data['preview']), 422, 'Obtain a delete preview first.');
                    abort_unless(hash_equals($this->deletePreviewToken($actor, $record, $scope, $data['original_starts_at'] ?? null), $data['preview']), 409, 'Delete preview is stale.');
                    abort_if($scope === 'event' && empty($data['original_starts_at']), 422, 'Select the original occurrence start.');
                }

                return app(\App\Modules\Calendar\Actions\DeleteCalendarEvent::class)->handle($actor, $record, $scope, $data['original_starts_at'] ?? null);
            }, $scope !== 'record');

        return response()->noContent();
    }

    #[OA\Post(path: '/api/v1/calendar/events/{event}/delete-preview', operationId: 'calendarDeletePreview', summary: 'Preview record, occurrence or series deletion', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.delete']],
        parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiDeletePreviewInput')),
        responses: [new OA\Response(response: 200, description: 'Actor-bound current preview', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiDeletePreview')])), new OA\Response(response: 403, description: 'Permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported source or input', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function deletePreview(Request $request, CalendarEvent $event, CalendarVisibility $visibility)
    {
        $actor = \App\Modules\Calendar\Support\CalendarApiContract::actor($request, 'calendar.delete');
        \App\Modules\Calendar\Support\CalendarApiContract::fields($request, ['scope', 'original_starts_at']);
        $data = $request->validate(['scope' => ['required', Rule::in(['record', 'event', 'series'])], 'original_starts_at' => ['required_if:scope,event', 'nullable', 'date']]);
        abort_unless($visibility->canManageCalendar($actor, $event->calendar), 403);
        abort_if($event->source === 'work_plan', 422, 'Edit this block from Profile > Work plan.');
        \App\Modules\Calendar\Actions\ProjectWorkdayAbsence::assertCalendarEditable($event);
        abort_if($data['scope'] !== 'record' && ! $event->series_id, 422, 'This event is not a series.');

        return response()->json(['data' => [
            'event_id' => $event->id, 'calendar_id' => $event->calendar_id, 'series_id' => $event->series_id,
            'scope' => $data['scope'], 'original_starts_at' => $data['original_starts_at'] ?? null,
            'revision' => \App\Modules\Calendar\Support\CalendarApiContract::revision($event),
            'preview' => $this->deletePreviewToken($actor, $event, $data['scope'], $data['original_starts_at'] ?? null),
        ]]);
    }

    private function deletePreviewToken($actor, CalendarEvent $event, string $scope, ?string $at): string
    {
        return hash_hmac('sha256', json_encode([$actor->id, $event->id, $scope, $at, \App\Modules\Calendar\Support\CalendarApiContract::revision($event)]), config('app.key'));
    }

    private function assertRevision(CalendarEvent $event, ?string $revision): void
    {
        abort_if($revision !== null && ! hash_equals(\App\Modules\Calendar\Support\CalendarApiContract::revision($event), $revision), 409, 'Event changed; read it again.');
    }

    private function validateWindow(array $data): void
    {
        $start = Carbon::parse($data['starts_at'], $data['timezone'] ?? 'Europe/Oslo');
        $end = Carbon::parse($data['ends_at'], $data['timezone'] ?? 'Europe/Oslo');
        if ($end->lte($start)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['ends_at' => 'End must be after start.']);
        }
    }

    private function validatedEvent(Request $request, bool $creating): array
    {
        $allowed = ['calendar_id', 'title', 'description', 'location', 'meeting_url', 'starts_at', 'ends_at', 'timezone', 'all_day', 'status', 'transparency', 'visibility', 'participants'];
        if ($creating) {
            $allowed = array_merge($allowed, ['recurrence_frequency', 'recurrence_ends_at']);
        } else {
            $allowed[] = 'revision';
        }
        \App\Modules\Calendar\Support\CalendarApiContract::fields($request, $allowed);
        if (is_string($request->input('participants'))) {
            $request->merge(['participants' => collect(preg_split('/[\n,;]+/', $request->input('participants')))->map(fn ($email) => trim($email))->filter()->map(fn ($email) => ['email' => $email])->values()->all()]);
        }

        return $request->validate([
            'revision' => ['sometimes', 'string', 'size:64'],
            'calendar_id' => ['sometimes', 'integer', Rule::exists('calendars', 'id')],
            'title' => [$creating ? 'required' : 'sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'meeting_url' => ['sometimes', 'nullable', 'url', 'max:255'],
            'starts_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'ends_at' => [$creating ? 'required' : 'sometimes', 'date'],
            'timezone' => ['sometimes', 'timezone'],
            'all_day' => ['sometimes', 'boolean'],
            'status' => ['sometimes', Rule::in(['confirmed', 'tentative', 'cancelled'])],
            'transparency' => ['sometimes', Rule::in(['busy', 'free', 'tentative', 'out_of_office', 'working_elsewhere'])],
            'visibility' => ['sometimes', Rule::in(['default', 'public', 'private', 'confidential'])],
            'participants' => ['sometimes', 'nullable', 'array', 'max:100'],
            'participants.*' => ['array:participant_type,name,email,role,notify'],
            'participants.*.participant_type' => ['sometimes', Rule::in(['email'])],
            'participants.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'participants.*.email' => ['required', 'email', 'max:255'],
            'participants.*.role' => ['sometimes', Rule::in(['required', 'optional', 'resource'])],
            'participants.*.notify' => ['sometimes', 'boolean'],
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
            'calendar_type' => $event['calendar_type'],
            'calendar_type_label' => $event['calendar_type_label'],
            'owner_kind' => $event['owner_kind'],
            'owner_id' => $event['owner_id'],
            'owner_label' => $event['owner_label'],
            'owner_initials' => $event['owner_initials'],
            'owner_badge' => $event['owner_badge'],
            'ownership_badge' => $event['ownership_badge'],
            'ownership_group' => $event['ownership_group'],
            'is_owned_by_viewer' => $event['is_owned_by_viewer'],
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
        return $event->load(['calendar.owner', 'workContext', 'participants', 'series.exceptions', 'links']);
    }
}
