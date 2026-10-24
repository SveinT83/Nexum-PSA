<?php

namespace App\Modules\Nextcloud\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Actions\ExecuteCalendarApiCommand;
use App\Modules\Calendar\Support\CalendarApiContract as Contract;
use App\Modules\Nextcloud\Actions\CalendarApiAccess as Access;
use App\Modules\Nextcloud\Actions\CheckNextcloudConnectionHealth;
use App\Modules\Nextcloud\Actions\DispatchDueNextcloudCalendars as Due;
use App\Modules\Nextcloud\Actions\ManageNextcloudCalendarMapping;
use App\Modules\Nextcloud\Actions\NextcloudCalendarLease;
use App\Modules\Nextcloud\Actions\PreviewNextcloudCalendarOperation as Preview;
use App\Modules\Nextcloud\Jobs\RunRequestedCalendarSync;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
/** Only configured global calendar integration operations; credentials, Talk/users/groups/files are excluded. */
use OpenApi\Attributes as OA;

class CalendarOperationsController extends Controller
{
    #[OA\Get(path: '/api/v1/nextcloud/calendar/connections', operationId: 'calendarParityCalendarOperationsControllerConnections', summary: 'connections Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.read']], parameters: [new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/NextcloudCalendarConnection')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page_url', type: 'string', nullable: true), new OA\Property(property: 'prev_page_url', type: 'string', nullable: true)])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function connections(Request $request)
    {
        Access::actor($request->user());
        Contract::fields($request, ['page', 'per_page']);
        $this->pagination($request);

        return response()->json(NextcloudConnection::where('scope', 'global')->orderBy('id')->paginate($request->integer('per_page', 25))
            ->through(fn ($connection) => $this->safeConnection($connection)));
    }

    #[OA\Get(path: '/api/v1/nextcloud/calendar/connections/{connection}', operationId: 'calendarParityCalendarOperationsControllerShow', summary: 'show Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.read']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarConnection')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function show(Request $request, NextcloudConnection $connection)
    {
        Access::actor($request->user());
        Contract::fields($request, []);
        $this->global($connection);

        return response()->json(['data' => $this->safeConnection($connection) + [
            'revision' => Access::revision($connection),
            'mapping_count' => $connection->calendarMappings()->count(),
            'open_conflict_count' => $connection->conflicts()->where('remote_object_type', 'calendar_event')->where('status', 'open')->count(),
            'mappings_url' => url('/api/v1/nextcloud/calendar/connections/'.$connection->id.'/mappings'),
            'attempts_url' => url('/api/v1/nextcloud/calendar/connections/'.$connection->id.'/attempts'),
            'conflicts_url' => url('/api/v1/nextcloud/calendar/connections/'.$connection->id.'/conflicts'),
        ]]);
    }

    #[OA\Get(path: '/api/v1/nextcloud/calendar/connections/{connection}/mappings', operationId: 'calendarParityCalendarOperationsControllerMappings', summary: 'mappings Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.read']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/NextcloudCalendarMapping')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page_url', type: 'string', nullable: true), new OA\Property(property: 'prev_page_url', type: 'string', nullable: true)])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function mappings(Request $request, NextcloudConnection $connection)
    {
        Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['page', 'per_page']);
        $this->pagination($request);

        return response()->json($connection->calendarMappings()->orderBy('id')->paginate($request->integer('per_page', 25))
            ->through(fn ($mapping) => $mapping->only(['id', 'connection_id', 'calendar_id', 'user_id', 'remote_calendar_id', 'remote_display_name', 'sync_direction', 'is_active', 'last_synced_at'])));
    }

    #[OA\Get(path: '/api/v1/nextcloud/calendar/connections/{connection}/attempts', operationId: 'calendarParityCalendarOperationsControllerAttempts', summary: 'attempts Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.read']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/NextcloudCalendarAttempt')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page_url', type: 'string', nullable: true), new OA\Property(property: 'prev_page_url', type: 'string', nullable: true)])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function attempts(Request $request, NextcloudConnection $connection)
    {
        Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['page', 'per_page']);
        $this->pagination($request);

        return response()->json($connection->syncLogs()->where('context->calendar_only', true)->orderByDesc('id')->paginate($request->integer('per_page', 25))
            ->through(fn ($log) => $this->attemptData($log)));
    }

    #[OA\Get(path: '/api/v1/nextcloud/calendar/connections/{connection}/attempts/{attempt}', operationId: 'calendarParityCalendarOperationsControllerAttempt', summary: 'attempt Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.read']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'attempt', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarAttempt')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function attempt(Request $request, NextcloudConnection $connection, int $attempt)
    {
        Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, []);
        $log = $connection->syncLogs()->where('context->calendar_only', true)->findOrFail($attempt);

        return response()->json(['data' => $this->attemptData($log)]);
    }

    #[OA\Get(path: '/api/v1/nextcloud/calendar/connections/{connection}/conflicts', operationId: 'calendarParityCalendarOperationsControllerConflicts', summary: 'conflicts Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.read']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', type: 'array', items: new OA\Items(ref: '#/components/schemas/NextcloudCalendarConflict')), new OA\Property(property: 'current_page', type: 'integer'), new OA\Property(property: 'per_page', type: 'integer'), new OA\Property(property: 'total', type: 'integer'), new OA\Property(property: 'last_page', type: 'integer'), new OA\Property(property: 'next_page_url', type: 'string', nullable: true), new OA\Property(property: 'prev_page_url', type: 'string', nullable: true)])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function conflicts(Request $request, NextcloudConnection $connection)
    {
        Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['page', 'per_page']);
        $this->pagination($request);

        return response()->json($connection->conflicts()->where('remote_object_type', 'calendar_event')->orderByDesc('id')->paginate($request->integer('per_page', 25))
            ->through(fn ($row) => ['id' => $row->id, 'connection_id' => $row->connection_id, 'event_id' => $row->conflictable_id,
                'mapping_id' => data_get($row->local_snapshot, 'mapping_id'), 'status' => $row->status,
                'resolution' => $row->resolution, 'resolved_at' => $row->resolved_at,
                // Stored historical messages/snapshots can contain private provider data. Return only stable safe metadata.
                'reason' => 'Calendar identity or content requires explicit reconciliation.']));
    }

    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/preview', operationId: 'calendarParityCalendarOperationsControllerPreview', summary: 'preview Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.preview']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarPreviewInput')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarPreview')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function preview(Request $request, NextcloudConnection $connection, Preview $previews, ManageNextcloudCalendarMapping $mappings)
    {
        $actor = Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['operation', 'mapping_ids', 'enabled', 'mapping', 'mapping_id']);
        $data = $request->validate([
            'operation' => ['required', Rule::in(['activation', 'sync', 'mapping', 'remove_mapping'])],
            'mapping_ids' => ['sometimes', 'array', 'max:10'], 'mapping_ids.*' => ['integer', 'min:1', 'distinct'],
            'enabled' => ['required_if:operation,activation', 'boolean'],
            'mapping' => ['required_if:operation,mapping', 'array:remote_calendar_id,remote_display_name,calendar_id,user_id,sync_direction'],
            'mapping_id' => ['required_if:operation,remove_mapping', 'integer', 'min:1'],
        ]);
        $operation = $data['operation'];
        $allowed = match ($operation) {
            'mapping' => ['operation', 'mapping'], 'remove_mapping' => ['operation', 'mapping_id'],
            'activation' => ['operation', 'enabled', 'mapping_ids'], 'sync' => ['operation', 'mapping_ids'],
        };
        Contract::fields($request, $allowed);
        $choices = match ($operation) {
            'mapping' => $mappings->validate(Request::create('/', 'POST', $data['mapping'])),
            'remove_mapping' => ['mapping_id' => $data['mapping_id']],
            'activation' => ['enabled' => (bool) $data['enabled'], 'mapping_ids' => $data['mapping_ids'] ?? []],
            'sync' => ['mapping_ids' => $data['mapping_ids'] ?? []],
        };

        return response()->json(['data' => $previews->build($actor, $connection, $operation, $choices)]);
    }

    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/mappings', operationId: 'calendarParityCalendarOperationsControllerUpsertmapping', summary: 'upsertMapping Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.mapping']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarMappingCommand')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarMapping')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function upsertMapping(Request $request, NextcloudConnection $connection, ExecuteCalendarApiCommand $commands, ManageNextcloudCalendarMapping $mappings, Preview $previews)
    {
        $actor = Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['mapping', 'fingerprint', 'expires_at', 'preview']);
        $request->validate(['mapping' => ['required', 'array:remote_calendar_id,remote_display_name,calendar_id,user_id,sync_direction']]);
        $input = Request::create('/', 'POST', $request->input('mapping'));
        $choices = $mappings->validate($input);
        $mapping = $commands->handle($request, 'nextcloud.mapping.upsert', ['connection_id' => $connection->id, 'input' => $request->all()], fn () => Access::actor($request->user()), function () use ($request, $actor, $connection, $choices, $previews, $mappings, $input) {
            $previews->verify($actor, $connection, 'mapping', $choices, $this->proof($request));

            return $mappings->upsert($input, $connection);
        });

        return response()->json(['data' => $mapping->fresh()->only(['id', 'connection_id', 'calendar_id', 'user_id', 'remote_calendar_id', 'remote_display_name', 'sync_direction', 'is_active'])]);
    }

    #[OA\Delete(path: '/api/v1/nextcloud/calendar/connections/{connection}/mappings/{mapping}', operationId: 'calendarParityCalendarOperationsControllerRemovemapping', summary: 'removeMapping Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.mapping']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'mapping', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarProof')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarMappingRemoval')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function removeMapping(Request $request, NextcloudConnection $connection, int $mapping, ExecuteCalendarApiCommand $commands, ManageNextcloudCalendarMapping $mappings, Preview $previews)
    {
        $actor = Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['fingerprint', 'expires_at', 'preview']);
        $commands->handle($request, 'nextcloud.mapping.remove', ['connection_id' => $connection->id, 'mapping_id' => $mapping, 'input' => $request->all()], fn () => Access::actor($request->user()), function () use ($request, $actor, $connection, $mapping, $previews, $mappings) {
            $previews->verify($actor, $connection, 'remove_mapping', ['mapping_id' => $mapping], $this->proof($request));

            return $mappings->remove($connection->calendarMappings()->findOrFail($mapping));
        });

        return response()->json(['data' => ['id' => $mapping, 'removed' => ! $connection->calendarMappings()->whereKey($mapping)->exists(), 'events_retained' => true]]);
    }

    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/activation', operationId: 'calendarParityCalendarOperationsControllerActivation', summary: 'activation Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.activate']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarActivation')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarConnection')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function activation(Request $request, NextcloudConnection $connection, ExecuteCalendarApiCommand $commands, Preview $previews)
    {
        $actor = Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['enabled', 'mapping_ids', 'fingerprint', 'expires_at', 'preview']);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'mapping_ids' => ['sometimes', 'array', 'max:10'], 'mapping_ids.*' => ['integer', 'min:1', 'distinct']]);
        $choices = ['enabled' => (bool) $data['enabled'], 'mapping_ids' => $data['mapping_ids'] ?? []];
        $commands->handle($request, 'nextcloud.activation', ['connection_id' => $connection->id, 'input' => $request->all()], fn () => Access::actor($request->user()), function () use ($request, $actor, $connection, $choices, $previews) {
            $previews->verify($actor, $connection, 'activation', $choices, $this->proof($request));
            $connection = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            if ($choices['enabled']) {
                Access::idle($connection);
            }
            $settings = array_replace($connection->settings ?? [], ['automatic_calendar_sync_enabled' => $choices['enabled']]);
            if ($choices['enabled']) {
                $settings['automatic_calendar_mapping_ids'] = $choices['mapping_ids'];
                $connection->settings = $settings;
                $settings['automatic_calendar_target_fingerprint'] = Due::fingerprint($connection);
            }
            $connection->forceFill(['settings' => $settings])->save();

            return $connection;
        });

        return response()->json(['data' => $this->safeConnection($connection->fresh())]);
    }

    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/sync', operationId: 'calendarParityCalendarOperationsControllerSync', summary: 'sync Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.sync']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarSync')), responses: [new OA\Response(response: 202, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarAttempt')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function sync(Request $request, NextcloudConnection $connection, ExecuteCalendarApiCommand $commands, Preview $previews)
    {
        $actor = Access::actor($request->user());
        $this->global($connection);
        Contract::fields($request, ['mapping_ids', 'fingerprint', 'expires_at', 'preview']);
        $data = $request->validate(['mapping_ids' => ['required', 'array', 'min:1', 'max:10'], 'mapping_ids.*' => ['integer', 'min:1', 'distinct']]);
        $log = $commands->handle($request, 'nextcloud.sync', ['connection_id' => $connection->id, 'input' => $request->all()], fn () => Access::actor($request->user()), function () use ($request, $actor, $connection, $data, $previews) {
            $previews->verify($actor, $connection, 'sync', $data, $this->proof($request));
            $claim = app(NextcloudCalendarLease::class)->claim($connection, false, $data['mapping_ids']);
            abort_unless($claim, 409, 'Calendar synchronization is already queued or running.');
            $log = NextcloudSyncLog::findOrFail($claim['log_id']);
            $log->forceFill(['context' => ['calendar_only' => true, 'mapping_ids' => $data['mapping_ids'], 'actor_id' => $actor->id]])->save();
            $revision = Access::revision($connection);
            DB::afterCommit(function () use ($connection, $claim, $actor, $data, $revision) {
                try {
                    RunRequestedCalendarSync::dispatch($connection->id, $claim['token'], $claim['log_id'], $actor->id, $data['mapping_ids'], $revision);
                } catch (\Throwable) {
                    NextcloudSyncLog::whereKey($claim['log_id'])->update(['status' => 'failed', 'finished_at' => now(), 'message' => 'Calendar job dispatch failed; no provider write was performed.']);
                    app(NextcloudCalendarLease::class)->finish($connection, $claim['token'], false);
                }
            });

            return $log;
        });

        return response()->json(['data' => $this->attemptData($log->fresh())], 202);
    }

    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/health', operationId: 'calendarParityCalendarOperationsControllerHealth', summary: 'health Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.health']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarConnection')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function health(Request $request, NextcloudConnection $connection, ExecuteCalendarApiCommand $commands, CheckNextcloudConnectionHealth $health)
    {
        Access::actor($request->user(), 'nextcloud.connection_manage');
        $this->global($connection);
        Contract::fields($request, []);
        $commands->handle($request, 'nextcloud.health', ['connection_id' => $connection->id], fn () => Access::actor($request->user(), 'nextcloud.connection_manage'), function () use ($connection, $health) {
            $health->handle($connection);

            return $connection;
        });

        return response()->json(['data' => $this->safeConnection($connection->fresh())]);
    }

    private function safeConnection(NextcloudConnection $connection): array
    {
        return ['id' => $connection->id, 'name' => $connection->name, 'scope' => $connection->scope,
            'active' => (bool) $connection->is_active, 'calendar_sync_enabled' => (bool) ($connection->settings['calendar_sync_enabled'] ?? false),
            'automatic_enabled' => Due::enabled($connection), 'reviewed_mapping_ids' => Due::mappingIds($connection),
            'health_status' => $connection->health_status, 'last_health_check_at' => $connection->last_health_check_at,
            'last_successful_sync_at' => $connection->last_successful_sync_at,
            'blocked_reason' => ! $connection->is_active ? 'connection_inactive' : (! ($connection->settings['calendar_sync_enabled'] ?? false) ? 'calendar_sync_paused' : null)];
    }

    public function attemptData(NextcloudSyncLog $log): array
    {
        return $log->only(['id', 'connection_id', 'operation', 'status', 'started_at', 'finished_at', 'records_seen', 'records_changed', 'records_failed'])
            + ['calendar_only' => true, 'status_url' => url('/api/v1/nextcloud/calendar/connections/'.$log->connection_id.'/attempts/'.$log->id)];
    }

    private function proof(Request $request): array
    {
        return $request->validate(['fingerprint' => ['required', 'string', 'size:64'], 'expires_at' => ['required', 'date'], 'preview' => ['required', 'string', 'size:64']]);
    }

    private function pagination(Request $request): void
    {
        $request->validate(['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
    }

    private function global(NextcloudConnection $connection): void
    {
        abort_unless($connection->scope === 'global', 422, 'Select the configured global calendar connection.');
    }
}
