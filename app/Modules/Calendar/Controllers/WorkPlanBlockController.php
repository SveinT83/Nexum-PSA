<?php

namespace App\Modules\Calendar\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Actions\ManageWorkPlanBlock;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WorkPlanBlockController extends Controller
{
    #[OA\Get(path: '/api/v1/calendar/work-plan/blocks', operationId: 'workPlanBlockindex', summary: 'index employee work plan', description: 'Requires calendar.work-plan.read; own active internal human only. Workday must be enabled.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['calendar.work-plan.read']], tags: ['Work plan'], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))],

        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkPlanBlockList')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Wrong scope, employee identity or Calendar permission'), new OA\Response(response: 404, description: 'Disabled or missing'), new OA\Response(response: 409, description: 'Version or idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function index(Request $request, UserWorkPlan $plans)
    {
        abort_unless($request->user()->can('calendar.view'), 403);
        $calendar = $plans->calendar($request->user());

        // Bounded master list; Calendar's event API supplies occurrence expansion.
        return CalendarEvent::query()->where('calendar_id', $calendar?->id ?? 0)
            ->where('source', 'work_plan')->orderByDesc('starts_at')->paginate(30)
            ->through(fn ($event) => $this->data($event));
    }

    #[OA\Post(path: '/api/v1/calendar/work-plan/blocks', operationId: 'workPlanBlockstore', summary: 'store employee work plan', description: 'Requires calendar.work-plan.write; own active internal human only. Workday must be enabled.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['calendar.work-plan.write']], tags: ['Work plan'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkPlanBlockWrite')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkPlanBlockRead')])), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Wrong scope, employee identity or Calendar permission'), new OA\Response(response: 404, description: 'Disabled or missing'), new OA\Response(response: 409, description: 'Version or idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function store(Request $request, ManageWorkPlanBlock $blocks)
    {
        $this->validateKeys($request);

        return $this->respond($request, $blocks->create($request->user(), $request->all()));
    }

    #[OA\Patch(path: '/api/v1/calendar/work-plan/blocks/{event}', operationId: 'workPlanBlockupdate', summary: 'update employee work plan', description: 'Requires calendar.work-plan.write; own active internal human only. Workday must be enabled.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['calendar.work-plan.write']], tags: ['Work plan'], parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(allOf: [new OA\Schema(ref: '#/components/schemas/WorkPlanBlockWrite'), new OA\Schema(ref: '#/components/schemas/WorkPlanBlockControl')])),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkPlanBlockRead')])), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Wrong scope, employee identity or Calendar permission'), new OA\Response(response: 404, description: 'Disabled or missing'), new OA\Response(response: 409, description: 'Version or idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(Request $request, CalendarEvent $event, ManageWorkPlanBlock $blocks)
    {
        $this->validateKeys($request);

        return $this->respond($request, $blocks->change($request->user(), $event, $request->all()));
    }

    #[OA\Delete(path: '/api/v1/calendar/work-plan/blocks/{event}', operationId: 'workPlanBlockdestroy', summary: 'destroy employee work plan', description: 'Requires calendar.work-plan.write; own active internal human only. Workday must be enabled.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['calendar.work-plan.write']], tags: ['Work plan'], parameters: [new OA\Parameter(name: 'event', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkPlanBlockControl')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkPlanBlockRead')])), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Wrong scope, employee identity or Calendar permission'), new OA\Response(response: 404, description: 'Disabled or missing'), new OA\Response(response: 409, description: 'Version or idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function destroy(Request $request, CalendarEvent $event, ManageWorkPlanBlock $blocks)
    {
        $this->validateKeys($request);

        return $this->respond($request, $blocks->change($request->user(), $event, $request->all(), true));
    }

    private function respond(Request $request, CalendarEvent $event)
    {
        return $request->is('api/*') ? response()->json(['data' => $this->data($event)])
            : redirect()->route('tech.profile.work-plan')->with('success', 'Plan block saved.');
    }

    private function data(CalendarEvent $event): array
    {
        return [
            'id' => $event->id, 'uuid' => $event->uuid, 'title' => $event->title,
            'starts_at' => $event->starts_at->toIso8601String(), 'ends_at' => $event->ends_at->toIso8601String(),
            'timezone' => $event->timezone, 'status' => $event->status,
            'activity' => data_get($event->metadata, 'activity'),
            'phone_duty_available' => data_get($event->metadata, 'phone_duty_available'),
            'blocks_booking' => $event->transparency === 'busy', 'version' => data_get($event->metadata, 'version', 1),
            'recurrence_frequency' => data_get($event->series?->metadata, 'frequency', 'none'),
            'recurrence_ends_at' => $event->series?->recurrence_ends_at?->toIso8601String(),
        ];
    }

    private function validateKeys(Request $request): void
    {
        $allowed = ['_token', '_method', 'request_id', 'activity', 'title', 'timezone', 'starts_at',
            'ends_at', 'phone_duty_available', 'blocks_booking', 'recurrence_frequency', 'recurrence_ends_at',
            'version', 'scope', 'occurrence_starts_at'];
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 422, 'Only plan-block fields may be submitted.');
    }
}
