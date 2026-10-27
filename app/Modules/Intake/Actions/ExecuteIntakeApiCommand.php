<?php

namespace App\Modules\Intake\Actions;

use App\Modules\Intake\Models\IntakeApiCommand;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Support\IntakeApiAccess;
use App\Modules\Intake\Support\IntakeApiInput;
use App\Modules\Intake\Support\IntakeWorkflowLock;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** A durable claim commits before effects. Interrupted claims require reconciliation, never blind retry. */
class ExecuteIntakeApiCommand
{
    public function execute(Request $request, Closure $effect, IntakeForm|IntakeSubmission|null $record = null, bool $public = false)
    {
        abort_unless(\Illuminate\Support\Facades\Schema::hasTable('intake_api_commands'), 503, 'The Intake command migration is required.');
        $key = IntakeApiInput::key($request, $public);
        $actorKey = $public ? 'public-form:'.$record->id : 'staff:'.$request->user()->id;
        $tokenHash = $public ? hash('sha256', 'public') : IntakeApiAccess::tokenHash($request);
        $hash = IntakeApiInput::hash([$request->method(), $request->path(), $request->all(), $request->header('If-Match')]);

        return IntakeWorkflowLock::run(function () use ($request, $effect, $record, $public, $key, $actorKey, $tokenHash, $hash) {
            $command = IntakeApiCommand::where('actor_key', $actorKey)->where('token_hash', $tokenHash)->where('key_hash', $key)->first();
            if ($command) {
                abort_unless(hash_equals($command->request_hash, $hash), 409, 'Idempotency-Key was used for different data.');

                return $this->response($request, $command, true, $public);
            }
            if ($record && ! $public) {
                abort_unless($request->hasHeader('If-Match'), 428, 'If-Match is required; read the current revision first.');
                abort_unless(hash_equals(IntakeApiInput::revision($record), $request->header('If-Match')), 412, 'Intake state changed; reload before retrying.');
            }
            // No enclosing transaction: provider intent and native submission survive uncertain effects.
            $command = IntakeApiCommand::create([
                'id' => (string) Str::uuid(), 'actor_key' => $actorKey, 'token_hash' => $tokenHash,
                'key_hash' => $key, 'request_hash' => $hash, 'operation' => $request->route()->getName(),
                'state' => 'running', 'result' => ['authority' => $request->attributes->get('intake_authority'),
                    'related_permissions' => [], 'status' => 202,
                    'body' => ['state' => 'unknown', 'reconciliation_required' => true]],
            ]);
            try {
                [$body, $status] = $effect();
                $command->forceFill(['state' => $body['state'] ?? 'completed',
                    'result' => ['authority' => $request->attributes->get('intake_authority'),
                        'related_permissions' => $request->attributes->get('intake_result_permissions', []),
                        'status' => $status, 'body' => $body,
                        'submission_id' => $request->attributes->get('intake_submission_id'),
                        'record_type' => $record ? $record::class : null, 'record_id' => $record?->id,
                    ]])->save();
            } catch (\Throwable $error) {
                // Validation before an effect is safe to report; native transactions protect local writes.
                // Provider/routing failures or afterCommit failures can have durable effects: keep the claim.
                report($error);
                $result = $command->result;
                $result['submission_id'] = $request->attributes->get('intake_persisted_submission_id');
                $result['body'] = $public ? ['accepted' => true,
                    'message' => $record->success_message ?: 'Thank you. Your request has been received.']
                    : ['state' => 'unknown', 'reconciliation_required' => true];
                $command->forceFill(['state' => 'unknown', 'result' => $result])->save();
                if ($error instanceof \Illuminate\Validation\ValidationException && ! $request->attributes->get('intake_effect_started')) {
                    $command->delete();
                    throw $error;
                }
            }

            return $this->response($request, $command, false, $public);
        });
    }

    public function response(Request $request, IntakeApiCommand $command, bool $replayed = false, bool $public = false)
    {
        $result = $command->result;
        if (! $public) {
            $authority = $result['authority'] ?? null;
            abort_unless($authority, 404);
            IntakeApiAccess::branch($request, [$authority]);
            foreach ($result['related_permissions'] ?? [] as $permission) {
                IntakeApiAccess::require($request->user(), $permission);
            }
            if ($command->state === 'running') {
                $result['body'] = ['state' => 'unknown', 'reconciliation_required' => true];
                $result['status'] = 202;
            }
            $body = $result['body'] + ['operation_id' => $command->id, 'replayed' => $replayed];
            $response = response()->json($body, $result['status']);
            $response->header('X-Operation-Id', $command->id);
            if ($revision = $body['data']['revision'] ?? null) {
                $response->header('ETag', $revision);
            }

            return $response;
        }
        // Public idempotency is not a capability to discover matches, audit, spam or routing state.
        if (in_array($command->state, ['running', 'unknown'], true) && empty($result['submission_id'])) {
            return response()->json(['message' => 'The inquiry could not be confirmed. Contact support before retrying.'], 503)
                ->header('Cache-Control', 'no-store');
        }
        $form = $request->route('form');

        return response()->json(['accepted' => true,
            'message' => $form->success_message ?: 'Thank you. Your request has been received.'], 202)->header('Cache-Control', 'no-store');
    }
}
