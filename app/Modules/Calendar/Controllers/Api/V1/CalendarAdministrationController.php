<?php

namespace App\Modules\Calendar\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Calendar\Actions\CalendarAdministration;
use App\Modules\Calendar\Actions\ExecuteCalendarApiCommand;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\Calendar\Support\CalendarApiContract as Contract;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
/** Explicit admin commands use the same actions as the browser. No arbitrary setting/model writes. */
use Spatie\Permission\Models\Role;

class CalendarAdministrationController extends Controller
{
    #[OA\Get(path: '/api/v1/calendar/admin/settings', operationId: 'calendarParityCalendarAdministrationControllerIndex', summary: 'index Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiAdmin')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function index(Request $request)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, []);

        return response()->json(['data' => $this->context()]);
    }

    #[OA\Get(path: '/api/v1/calendar/admin/references', operationId: 'calendarParityCalendarAdministrationControllerReferences', summary: 'references Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'kind', in: 'query', schema: new OA\Schema(type: 'string', enum: ['users', 'roles', 'calendars'])), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/CalendarApiOption')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page_url', type: 'string', nullable: true), new OA\Property(property: 'prev_page_url', type: 'string', nullable: true)])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function references(Request $request)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, ['kind', 'page', 'per_page']);
        $data = $request->validate(['kind' => ['required', Rule::in(['users', 'roles', 'calendars'])],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $query = match ($data['kind']) {
            'users' => User::where('status', User::STATUS_ACTIVE), 'roles' => Role::query(), 'calendars' => Calendar::query(),
        };

        return response()->json($query->orderBy('id')->paginate($request->integer('per_page', 25), ['id', 'name']));
    }

    #[OA\Patch(path: '/api/v1/calendar/admin/settings', operationId: 'calendarParityCalendarAdministrationControllerUpdatesettings', summary: 'updateSettings Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiSettingsWrite')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiAdmin')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function updateSettings(Request $request, ExecuteCalendarApiCommand $commands, CalendarAdministration $admin)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        $allowed = ['default_timezone', 'week_starts_on', 'default_view', 'default_event_duration_minutes', 'default_workday_start', 'default_workday_end', 'allow_private_events', 'show_other_calendars_by_default', 'revision'];
        Contract::fields($request, $allowed);
        $request->validate(['revision' => ['required', 'string', 'size:64']]);
        $commands->handle($request, 'settings.update', $request->all(), fn () => Contract::actor($request, 'calendar.manage_settings', true), function () use ($request, $admin) {
            CalendarSetting::query()->where('scope_type', 'system')->whereNull('scope_id')->lockForUpdate()->get();
            abort_unless(hash_equals($this->settingsRevision(), $request->input('revision')), 409, 'Settings changed; read them again.');
            $input = clone $request;
            $input->replace(array_merge($this->settings(), $request->except('revision')));

            return $admin->update($input);
        });

        return response()->json(['data' => $this->context()]);
    }

    #[OA\Post(path: '/api/v1/calendar/admin/calendars', operationId: 'calendarParityCalendarAdministrationControllerCreatecalendar', summary: 'createCalendar Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiContainerCreate')), responses: [new OA\Response(response: 201, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiContainerRead')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function createCalendar(Request $request, ExecuteCalendarApiCommand $commands, CalendarAdministration $admin)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, ['name', 'type', 'description', 'color', 'timezone', 'is_visible_by_default', 'visibility_default', 'transparency_default']);
        $calendar = $commands->handle($request, 'calendar.create', $request->all(), fn () => Contract::actor($request, 'calendar.manage_settings', true), fn () => $admin->storeCalendar($request));
        abort_if($calendar->trashed(), 409, 'The created calendar was subsequently archived.');

        return response()->json(['data' => $calendar->fresh()->only(['id', 'uuid', 'name', 'type', 'description', 'color', 'timezone', 'is_active', 'is_visible_by_default', 'visibility_default', 'transparency_default'])], 201);
    }

    #[OA\Get(path: '/api/v1/calendar/admin/calendars/{calendar}/access', operationId: 'calendarParityCalendarAdministrationControllerAccess', summary: 'access Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'calendar', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiAccessList')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function access(Request $request, Calendar $calendar)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, []);

        return response()->json(['data' => ['calendar_id' => $calendar->id, 'revision' => $this->calendarRevision($calendar), 'entries' => $calendar->access()->orderBy('id')->get()]]);
    }

    #[OA\Post(path: '/api/v1/calendar/admin/calendars/{calendar}/preview', operationId: 'calendarParityCalendarAdministrationControllerPreview', summary: 'preview Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'calendar', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiAdminPreviewInput')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiAdminPreview')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function preview(Request $request, Calendar $calendar)
    {
        $actor = Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, ['operation', 'changes', 'access_id']);
        $data = $request->validate([
            'operation' => ['required', Rule::in(['archive', 'upsert_access', 'revoke_access'])],
            'changes' => ['required_if:operation,upsert_access', 'array:subject_ref,access_level,can_view_private_details,can_share,can_manage_access'],
            'access_id' => ['required_if:operation,revoke_access', 'integer'],
        ]);
        Contract::fields($request, match ($data['operation']) {
            'archive' => ['operation'], 'upsert_access' => ['operation', 'changes'], 'revoke_access' => ['operation', 'access_id'],
        });
        if ($data['operation'] === 'upsert_access') {
            $request->validate([
                'changes.subject_ref' => ['required', 'regex:/^(user|role):[1-9][0-9]*$/'],
                'changes.access_level' => ['required', Rule::in(['owner', 'manager', 'editor', 'contributor', 'viewer', 'free_busy'])],
                'changes.can_view_private_details' => ['sometimes', 'boolean'], 'changes.can_share' => ['sometimes', 'boolean'], 'changes.can_manage_access' => ['sometimes', 'boolean'],
            ]);
            [$type, $id] = explode(':', $data['changes']['subject_ref']);
            abort_unless(($type === 'user' ? User::query() : Role::query())->whereKey($id)->exists(), 422, 'Access subject does not exist.');
        }
        if (isset($data['access_id'])) {
            abort_unless($calendar->access()->whereKey($data['access_id'])->exists(), 404);
        }
        $revision = $this->calendarRevision($calendar);

        return response()->json(['data' => [
            'calendar_id' => $calendar->id, 'operation' => $data['operation'], 'revision' => $revision,
            'event_count' => $calendar->events()->count(), 'access_count' => $calendar->access()->count(),
            'preview' => $this->previewToken($actor, $calendar, $data['operation'], $data['changes'] ?? [], $data['access_id'] ?? null),
        ]]);
    }

    #[OA\Delete(path: '/api/v1/calendar/admin/calendars/{calendar}', operationId: 'calendarParityCalendarAdministrationControllerArchive', summary: 'archive Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'calendar', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiPreviewProof')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiArchiveResult')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function archive(Request $request, int $calendar, ExecuteCalendarApiCommand $commands, CalendarAdministration $admin)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, ['revision', 'preview']);
        $record = Calendar::withTrashed()->findOrFail($calendar);
        $commands->handle($request, 'calendar.archive', ['calendar_id' => $calendar, 'input' => $request->all()], fn () => Contract::actor($request, 'calendar.manage_settings', true), function () use ($request, $record, $admin) {
            abort_if($record->trashed(), 404);
            $this->checkPreview($request, $record, 'archive');
            $admin->destroyCalendar($record);

            return $record;
        });

        return response()->json(['data' => ['id' => $calendar, 'archived' => Calendar::withTrashed()->findOrFail($calendar)->trashed()]]);
    }

    #[OA\Post(path: '/api/v1/calendar/admin/calendars/{calendar}/access', operationId: 'calendarParityCalendarAdministrationControllerUpsertaccess', summary: 'upsertAccess Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'calendar', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiAccessCommand')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiAccess')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function upsertAccess(Request $request, Calendar $calendar, ExecuteCalendarApiCommand $commands, CalendarAdministration $admin)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, ['subject_ref', 'access_level', 'can_view_private_details', 'can_share', 'can_manage_access', 'revision', 'preview']);
        $changes = $request->except(['revision', 'preview']);
        $access = $commands->handle($request, 'access.upsert', ['calendar_id' => $calendar->id, 'input' => $request->all()], fn () => Contract::actor($request, 'calendar.manage_settings', true), function () use ($request, $calendar, $changes, $admin) {
            $this->checkPreview($request, $calendar, 'upsert_access', $changes);
            $input = clone $request;
            $input->replace($changes);

            return $admin->storeAccess($input, $calendar);
        });

        return response()->json(['data' => $access->fresh()]);
    }

    #[OA\Delete(path: '/api/v1/calendar/admin/calendars/{calendar}/access/{access}', operationId: 'calendarParityCalendarAdministrationControllerRevokeaccess', summary: 'revokeAccess Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['calendar.manage']], parameters: [new OA\Parameter(name: 'calendar', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'access', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiPreviewProof')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/CalendarApiRevokeResult')])), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function revokeAccess(Request $request, Calendar $calendar, int $access, ExecuteCalendarApiCommand $commands, CalendarAdministration $admin)
    {
        Contract::actor($request, 'calendar.manage_settings', true);
        Contract::fields($request, ['revision', 'preview']);
        $commands->handle($request, 'access.revoke', ['calendar_id' => $calendar->id, 'access_id' => $access, 'input' => $request->all()], fn () => Contract::actor($request, 'calendar.manage_settings', true), function () use ($request, $calendar, $access, $admin) {
            $this->checkPreview($request, $calendar, 'revoke_access', [], $access);
            $entry = $calendar->access()->whereKey($access)->firstOrFail();
            $admin->destroyAccess($entry);

            return $calendar;
        });

        return response()->json(['data' => ['id' => $access, 'calendar_id' => $calendar->id, 'revoked' => ! $calendar->access()->whereKey($access)->exists()]]);
    }

    private function checkPreview(Request $request, Calendar $calendar, string $operation, array $changes = [], ?int $accessId = null): void
    {
        Calendar::query()->whereKey($calendar->id)->lockForUpdate()->firstOrFail();
        $calendar->access()->lockForUpdate()->get();
        $request->validate(['revision' => ['required', 'string', 'size:64'], 'preview' => ['required', 'string', 'size:64']]);
        abort_unless(hash_equals($this->calendarRevision($calendar), $request->input('revision'))
            && hash_equals($this->previewToken($request->user(), $calendar, $operation, $changes, $accessId), $request->input('preview')), 409, 'Calendar preview changed; obtain a new preview.');
    }

    private function calendarRevision(Calendar $calendar): string
    {
        return hash('sha256', json_encode([$calendar->fresh()->getRawOriginal(), $calendar->access()->orderBy('id')->get()->toArray(), $calendar->events()->count()]));
    }

    private function previewToken(User $actor, Calendar $calendar, string $operation, array $changes, ?int $accessId): string
    {
        ksort($changes);

        return hash_hmac('sha256', json_encode([$actor->id, $calendar->id, $operation, $changes, $accessId, $this->calendarRevision($calendar)]), config('app.key'));
    }

    private function settings(): array
    {
        $data = CalendarSetting::query()->where('scope_type', 'system')->whereNull('scope_id')
            ->whereIn('name', ['default_timezone', 'week_starts_on', 'default_view', 'default_event_duration_minutes', 'default_workday_start', 'default_workday_end', 'allow_private_events', 'show_other_calendars_by_default'])
            ->orderBy('name')->pluck('value', 'name')->all();
        foreach (['allow_private_events', 'show_other_calendars_by_default'] as $name) {
            if (array_key_exists($name, $data)) {
                $data[$name] = (bool) $data[$name];
            }
        }
        if (array_key_exists('default_event_duration_minutes', $data)) {
            $data['default_event_duration_minutes'] = (int) $data['default_event_duration_minutes'];
        }

        return $data;
    }

    private function settingsRevision(): string
    {
        return hash('sha256', json_encode($this->settings()));
    }

    private function context(): array
    {
        return ['settings' => $this->settings(), 'revision' => $this->settingsRevision(),
            'references_url' => url('/api/v1/calendar/admin/references'),
            'references_complete' => Calendar::count() <= 100 && User::where('status', User::STATUS_ACTIVE)->count() <= 100 && Role::count() <= 100,
            'calendars' => Calendar::query()->orderBy('id')->limit(100)->get(['id', 'name', 'type', 'timezone', 'is_active']),
            'users' => User::query()->where('status', User::STATUS_ACTIVE)->orderBy('name')->limit(100)->get(['id', 'name']),
            'roles' => Role::query()->orderBy('name')->limit(100)->get(['id', 'name']),
        ];
    }
}
