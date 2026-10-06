<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Services\CalendarRecurrenceExpander;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WorkPlanController extends Controller
{
    #[OA\Get(path: '/api/v1/users/me/work-plan', operationId: 'employeeWorkPlanshow', summary: 'show employee work plan', description: 'Requires users.work-plan.read; own active internal human only. Workday must be enabled.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['users.work-plan.read']], tags: ['Work plan'],

        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkPlanRead')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Wrong scope, employee identity or Calendar permission'), new OA\Response(response: 404, description: 'Disabled or missing'), new OA\Response(response: 409, description: 'Version or idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function show(Request $request, UserWorkPlan $plans, CalendarRecurrenceExpander $recurrence)
    {
        $plan = $plans->read($request->user());
        if ($request->is('api/*')) {
            return response()->json(['data' => $plan]);
        }
        $calendar = $plans->calendar($request->user());
        $from = now($plan['timezone'])->startOfDay();
        $to = $from->copy()->addWeeks(6);
        $blocks = collect();
        if ($calendar && $request->user()->can('calendar.view')) {
            $blocks = CalendarEvent::query()->where('calendar_id', $calendar->id)
                ->where('source', 'work_plan')->whereNull('series_id')->where('status', '!=', 'cancelled')
                ->where('ends_at', '>', $from->copy()->utc())->where('starts_at', '<', $to->copy()->utc())
                ->get()->map(fn ($event) => ['event' => $event, 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at]);
            $blocks = $blocks->merge($recurrence->visibleOccurrences([$calendar->id], $from, $to)
                ->filter(fn ($row) => $row['event']->source === 'work_plan'))->sortBy('starts_at')->values();
        }

        return view('usermanagement::profile.work-plan', compact('plan', 'blocks'));
    }

    #[OA\Patch(path: '/api/v1/users/me/work-plan', operationId: 'employeeWorkPlanupdate', summary: 'update employee work plan', description: 'Requires users.work-plan.update; own active internal human only. Workday must be enabled.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['users.work-plan.update']], tags: ['Work plan'],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkPlanWrite')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkPlanRead')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Wrong scope, employee identity or Calendar permission'), new OA\Response(response: 404, description: 'Disabled or missing'), new OA\Response(response: 409, description: 'Version or idempotency conflict'), new OA\Response(response: 422, description: 'Validation error')])]
    public function update(Request $request, UserWorkPlan $plans)
    {
        $this->rejectUnexpectedFields($request, ['revision', 'timezone', 'working_hours', 'accept_calendar_conflicts']);
        $plan = $plans->update($request->user(), $request->all());

        return $request->is('api/*') ? response()->json(['data' => $plan])
            : back()->with('success', 'Work plan saved.');
    }

    private function rejectUnexpectedFields(Request $request, array $allowed): void
    {
        $extra = array_diff(array_keys($request->all()), array_merge($allowed, ['_token', '_method']));
        abort_if($extra !== [], 422, 'Only work-plan fields may be submitted.');
    }
}
