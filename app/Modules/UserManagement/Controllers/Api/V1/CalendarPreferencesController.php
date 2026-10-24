<?php

namespace App\Modules\UserManagement\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Calendar\Support\CalendarApiContract as Contract;
use App\Modules\UserManagement\Actions\UpdateUserPreferences;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
/** Own display defaults only; weekly availability remains owned by UserWorkPlan. */
use OpenApi\Attributes as OA;

class CalendarPreferencesController extends Controller
{
    #[OA\Get(path: '/api/v1/users/me/calendar-preferences', operationId: 'calendarParityCalendarPreferencesControllerShow', summary: 'show Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['users.calendar-preferences.read']], parameters: [], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiPreferences')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function show(Request $request)
    {
        $actor = Contract::actor($request);
        abort_if($actor->isSystemActor(), 403, 'An active human is required.');
        Contract::fields($request, []);

        return response()->json(['data' => $this->read($request->user())]);
    }

    #[OA\Patch(path: '/api/v1/users/me/calendar-preferences', operationId: 'calendarParityCalendarPreferencesControllerUpdate', summary: 'update Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['users.calendar-preferences.update']], parameters: [], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiPreferencesWrite')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiPreferences')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function update(Request $request, UpdateUserPreferences $preferences)
    {
        $actor = Contract::actor($request);
        abort_if($actor->isSystemActor(), 403, 'An active human is required.');
        Contract::fields($request, ['revision', 'timezone', 'default_calendar_view', 'workday_start', 'workday_end']);
        $data = $request->validate([
            'revision' => ['required', 'string', 'size:64'], 'timezone' => ['sometimes', 'timezone'],
            'default_calendar_view' => ['sometimes', Rule::in(['day', 'week', 'month', 'list'])],
            'workday_start' => ['sometimes', 'date_format:H:i'], 'workday_end' => ['sometimes', 'date_format:H:i'],
        ]);
        DB::transaction(function () use ($actor, $data, $preferences) {
            User::query()->lockForUpdate()->findOrFail($actor->id);
            UserPreference::query()->where('user_id', $actor->id)->lockForUpdate()->first();
            $current = $this->read($actor);
            abort_unless(hash_equals($current['revision'], $data['revision']), 409, 'Preferences changed; read them again.');
            $input = array_merge($current, $data);
            abort_unless($input['workday_end'] > $input['workday_start'], 422, 'Workday end must follow start.');
            $preferences->handle($actor, $input);
        });

        return response()->json(['data' => $this->read($actor)]);
    }

    private function read(User $actor): array
    {
        $row = UserPreference::query()->where('user_id', $actor->id)->first();
        $data = ['timezone' => $row?->timezone ?: 'Europe/Oslo', 'default_calendar_view' => $row?->default_calendar_view ?: 'week',
            'workday_start' => $row?->workday_start ?: '08:00', 'workday_end' => $row?->workday_end ?: '16:00'];

        return $data + ['revision' => hash('sha256', json_encode([$row?->getRawOriginal(), $data]))];
    }
}
