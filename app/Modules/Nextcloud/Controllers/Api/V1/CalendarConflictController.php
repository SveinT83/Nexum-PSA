<?php

namespace App\Modules\Nextcloud\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Calendar\Actions\ExecuteCalendarApiCommand;
use App\Modules\Calendar\Support\CalendarApiContract as Contract;
use App\Modules\Nextcloud\Actions\CalendarApiAccess as Access;
use App\Modules\Nextcloud\Actions\NextcloudCalendarLease;
use App\Modules\Nextcloud\Actions\ReconcileNextcloudCalendarConflict;
use App\Modules\Nextcloud\Jobs\ReconcileRequestedCalendarConflict;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CalendarConflictController extends Controller
{
    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/preview', operationId: 'calendarParityCalendarConflictControllerPreview', summary: 'preview Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.reconcile']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'conflict', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarResolutionInput')), responses: [new OA\Response(response: 200, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarResolutionPreview')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function preview(Request $request, NextcloudConnection $connection, NextcloudSyncConflict $conflict, ReconcileNextcloudCalendarConflict $resolver)
    {
        $actor = Access::actor($request->user());
        Contract::fields($request, ['resolution', 'event_id']);
        $data = $request->validate(['resolution' => ['required', Rule::in(['bind_existing', 'keep_local', 'keep_remote'])], 'event_id' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(['data' => $resolver->preview($actor, $connection, $conflict, $data['resolution'], $data['event_id'] ?? null)]);
    }

    #[OA\Post(path: '/api/v1/nextcloud/calendar/connections/{connection}/conflicts/{conflict}/apply', operationId: 'calendarParityCalendarConflictControllerApply', summary: 'apply Calendar operation', description: 'Token scope plus fresh active actor, domain and record authority. New commands require durable replay keys. Provider 202 is an attempt receipt, not completed sync.', security: [['bearerAuth' => []]], tags: ['Calendar'], x: ['required-scopes' => ['nextcloud.calendar.reconcile']], parameters: [new OA\Parameter(name: 'connection', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'conflict', in: 'path', required: true, schema: new OA\Schema(type: 'integer')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 200))], requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/NextcloudCalendarResolution')), responses: [new OA\Response(response: 202, description: 'Authorized persisted result or current query projection', content: new OA\JsonContent(type: 'object', properties: [new OA\Property(property: 'data', ref: '#/components/schemas/NextcloudCalendarAttempt')])), new OA\Response(response: 503, description: 'Provider preflight unavailable; no change performed', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 401, description: 'Unauthenticated', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 403, description: 'Current scope, actor or object permission denied', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 404, description: 'Record unavailable in this scope', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 409, description: 'Stale preview/revision, active lease or request-key conflict', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError')), new OA\Response(response: 422, description: 'Unsupported field, runtime or resource', content: new OA\JsonContent(ref: '#/components/schemas/CalendarApiError'))])]
    public function apply(Request $request, NextcloudConnection $connection, NextcloudSyncConflict $conflict, ReconcileNextcloudCalendarConflict $resolver, ExecuteCalendarApiCommand $commands)
    {
        $actor = Access::actor($request->user());
        Contract::fields($request, ['resolution', 'event_id', 'fingerprint', 'expires_at', 'preview']);
        $input = $request->validate([
            'resolution' => ['required', Rule::in(['bind_existing', 'keep_local', 'keep_remote'])], 'event_id' => ['required', 'integer', 'min:1'],
            'fingerprint' => ['required', 'string', 'size:64'], 'expires_at' => ['required', 'date'], 'preview' => ['required', 'string', 'size:64'],
        ]);
        abort_unless((int) $conflict->connection_id === (int) $connection->id, 404);
        $log = $commands->handle($request, 'nextcloud.conflict', ['connection_id' => $connection->id, 'conflict_id' => $conflict->id, 'input' => $input],
            function () use ($request, $input) {
                $actor = Access::actor($request->user());
                $event = \App\Modules\Calendar\Models\CalendarEvent::findOrFail($input['event_id']);
                abort_unless($actor->can('calendar.update') && app(\App\Modules\Calendar\Services\CalendarVisibility::class)->canManageCalendar($actor, $event->calendar), 403);
            },
            function () use ($actor, $connection, $conflict, $resolver, $input) {
                $verified = $resolver->verify($actor, $connection, $conflict, $input);
                $claim = app(NextcloudCalendarLease::class)->claim($connection, false, [$verified['mapping_id']]);
                abort_unless($claim, 409, 'Calendar operation already queued or running.');
                $log = NextcloudSyncLog::findOrFail($claim['log_id']);
                $log->forceFill(['operation' => 'calendar_conflict_reconciliation', 'context' => [
                    'calendar_only' => true, 'conflict_id' => $conflict->id, 'actor_id' => $actor->id, 'resolution' => $input['resolution'],
                ]])->save();
                DB::afterCommit(function () use ($connection, $claim, $actor, $conflict, $log, $input) {
                    try {
                        ReconcileRequestedCalendarConflict::dispatch($connection->id, $conflict->id, $actor->id, $claim['token'], $log->id, $input);
                    } catch (\Throwable) {
                        NextcloudSyncLog::whereKey($claim['log_id'])->update(['status' => 'failed', 'finished_at' => now(), 'message' => 'Calendar job dispatch failed; no provider write was performed.']);
                        app(NextcloudCalendarLease::class)->finish($connection, $claim['token'], false);
                    }
                });

                return $log;
            });

        return response()->json(['data' => app(CalendarOperationsController::class)->attemptData($log->fresh())], 202);
    }
}
