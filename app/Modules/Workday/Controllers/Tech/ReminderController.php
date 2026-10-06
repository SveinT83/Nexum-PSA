<?php

namespace App\Modules\Workday\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Notification\Actions\WorkdayReminderPreferences;
use App\Modules\Workday\Actions\WorkdayReminders;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayReminder;
use App\Modules\Workday\Support\ReminderEligibility;
use App\Modules\Workday\Support\WorkdayAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/** Own-only UI/API actions; no user selector or oversight shortcut. */
class ReminderController extends Controller
{
    public function __construct(private WorkdayReminders $reminders, private WorkdayReminderPreferences $preferences) {}

    private function authorize(Request $request): void
    {
        app(WorkdayAccess::class)->authorize($request->user(), 'view_own');
        abort_unless(app(ReminderEligibility::class)->allowed($request->user()), 403);
    }

    #[OA\Get(path: '/api/v1/workday-reminders', operationId: 'workdayReminderIndex', summary: 'Read own pending in-app reminders', description: 'Requires workday-reminders.read and explicit view_own/manage_own/confirm_own permissions. Returns up to ten eligible unread reminders from the fifty most recent retained receipts. No user selector; coordinator tokens denied. Off by default.', tags: ['Workday'], security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-reminders.read']],
        responses: [new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 200, description: 'Current personal reminders', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/WorkdayReminder'))])), new OA\Response(response: 403, description: 'Permission/scope denied'), new OA\Response(response: 404, description: 'Workday disabled')])]
    public function index(Request $request)
    {
        $this->authorize($request);

        return response()->json(['data' => $this->reminders->pending($request->user())])->header('Cache-Control', 'private, no-store');
    }

    #[OA\Get(path: '/api/v1/workday-reminder-preferences', operationId: 'workdayReminderPreferences', summary: 'Read own Workday notification preferences and readiness', description: 'Requires workday-reminders.read and own employee permissions. Defaults: in-app on, Email/Web Push off. Shared with Profile > Notifications.', tags: ['Workday'], security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-reminders.read']],
        responses: [new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 200, description: 'Preferences and configured channel readiness', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayReminderPreferencesResponse')), new OA\Response(response: 403, description: 'Permission/scope denied'), new OA\Response(response: 404, description: 'Disabled')])]
    public function preferences(Request $request)
    {
        $this->authorize($request);

        return response()->json(['data' => $this->preferences->read($request->user()),
            'readiness' => $this->preferences->readiness($request->user())])->header('Cache-Control', 'private, no-store');
    }

    #[OA\Put(path: '/api/v1/workday-reminder-preferences', operationId: 'workdayReminderPreferencesUpdate', summary: 'Set own reminder channels', description: 'Requires workday-reminders.write and own employee permissions. Replace all three booleans; repeated identical writes are idempotent. All false disables reminders. Enabling Email requires configured system Email; Push requires readiness and an own device. Unsupported channels are rejected. GET reads current state.', tags: ['Workday'], security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-reminders.write']],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayReminderPreferences')),
        responses: [new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 200, description: 'Persisted read-back', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkdayReminderPreferences')])), new OA\Response(response: 403, description: 'Permission/scope denied'), new OA\Response(response: 404, description: 'Disabled'), new OA\Response(response: 422, description: 'Unsupported channel or channel not ready')])]
    public function updatePreferences(Request $request)
    {
        $this->authorize($request);

        return response()->json(['data' => $this->preferences->update($request->user(), $request->all())])
            ->header('Cache-Control', 'private, no-store');
    }

    #[OA\Post(path: '/api/v1/workday-reminders/{id}/snooze', operationId: 'workdayReminderSnooze', summary: 'Snooze own reminder for 30 minutes', description: 'Requires workday-reminders.write. Supply current generation. Only explicit snooze advances it; retry of the preceding generation returns current state without extending snooze. Other stale generations return 409. Confirmation or full absence suppresses the next delivery.', tags: ['Workday'], security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-reminders.write']],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(type: 'object', required: ['generation'], properties: [new OA\Property(property: 'generation', type: 'integer', minimum: 1)])),
        responses: [new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 200, description: 'Current reminder receipt', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/WorkdayReminder')])), new OA\Response(response: 403, description: 'Permission/scope denied'), new OA\Response(response: 404, description: 'Missing, foreign, expired or disabled'), new OA\Response(response: 409, description: 'Stale or no longer due'), new OA\Response(response: 422, description: 'Invalid generation')])]
    public function snooze(Request $request, string $id)
    {
        $this->authorize($request);
        $input = $request->validate(['generation' => ['required', 'integer', 'min:1']]);
        $data = $this->reminders->snooze($request->user(), $id, (int) $input['generation']);

        return $request->routeIs('api.*') ? response()->json(['data' => $data])->header('Cache-Control', 'private, no-store')
            : redirect()->route('tech.workdays.index')->with('success', 'Workday reminder snoozed for 30 minutes.');
    }

    public function open(Request $request, string $id)
    {
        $this->authorize($request);
        $reminder = WorkdayReminder::where('uuid', $id)->where('user_id', $request->user()->id)->firstOrFail();
        abort_unless($this->reminders->current($request->user(), $reminder)
            && in_array(true, $this->preferences->read($request->user()), true), 404);
        $request->user()->notifications()->whereKey($reminder->notification_id)->update(['read_at' => now()]);
        $day = Workday::where('user_id', $request->user()->id)->where('work_date', $reminder->work_date)->first();

        $workdayUrl = $day ? route('tech.workdays.show', $day->uuid)
            : route('tech.workdays.create', ['work_date' => $reminder->work_date]);

        return response()->view('workday::Tech.reminders.open', [
            'reminder' => $this->reminders->serialize($reminder), 'workdayUrl' => $workdayUrl,
        ])->header('Cache-Control', 'private, no-store');
    }
}
