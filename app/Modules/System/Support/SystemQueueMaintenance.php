<?php

namespace App\Modules\System\Support;

use App\Modules\System\Actions\QueueMaintenanceAction;
use App\Modules\System\Models\SystemApiCommand;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Durable dispatch fencing spans browser and API. A crash after claiming never permits blind replay. */
final class SystemQueueMaintenance
{
    public const ACTIONS = ['restart', 'clear', 'retry', 'flush'];

    public function target(Request $request, string $action): array
    {
        $data = $request->validate([
            'queue' => [$action === 'clear' ? 'required' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Za-z0-9_.:\/-]+$/'],
            'job_id' => [$action === 'retry' ? 'nullable' : 'prohibited', 'string', 'max:100', 'regex:/^[A-Za-z0-9-]+$/', Rule::notIn(['all'])],
            'all' => [$action === 'retry' ? 'sometimes' : 'prohibited', 'boolean'],
        ]);
        if ($action === 'retry') {
            $all = $request->boolean('all');
            if (($all && ! empty($data['job_id'])) || (! $all && empty($data['job_id']))) {
                throw ValidationException::withMessages(['job_id' => 'Choose one job_id or explicit all=true.']);
            }

            return $all ? ['all' => true] : ['job_id' => $data['job_id'], 'all' => false];
        }

        return $action === 'clear' ? ['queue' => $data['queue']] : [];
    }

    public function preview(Request $request): array
    {
        $data = $request->validate(['action' => ['required', Rule::in(self::ACTIONS)]]);
        $action = $data['action'];
        $target = $this->target($request, $action);
        $snapshot = $this->snapshot($action, $target);
        $expires = now()->addSeconds((int) config('system_api.confirmation_seconds', 120));
        $confirmation = Crypt::encryptString(json_encode([
            'actor' => $request->user()->id, 'token' => SystemApiAccess::tokenHash($request),
            'action' => $action, 'target' => $target, 'snapshot' => $snapshot['hash'],
            'version' => $this->version(), 'expires' => $expires->getTimestamp(),
        ], JSON_THROW_ON_ERROR));

        return ['action' => $action, 'target' => (object) $target, 'connection' => config('queue.default'),
            'affected_count' => $snapshot['count'], 'evidence' => $snapshot['evidence'],
            'confirmation' => $confirmation, 'expires_at' => $expires->toIso8601String(),
            'execution_enabled' => (bool) config('system_api.maintenance_enabled', false),
            'warning' => $action === 'retry' ? 'Re-enqueued jobs may perform business actions. Re-enqueued is not processed or delivered.'
                : ($action === 'restart' ? 'A restart signal does not prove a worker restarted.' : 'This operation removes the selected queued or failed work.'),
        ];
    }

    public function api(Request $request, string $action)
    {
        $target = $this->target($request, $action);
        $key = SystemApiInput::commandKey($request);
        $token = SystemApiAccess::tokenHash($request);
        $hash = SystemApiInput::hash([$action, $target, $request->input('confirmation')]);
        // The live installation switch is checked even for execution replays; receipt GET remains available.
        abort_unless(config('system_api.maintenance_enabled', false), 409, 'System API maintenance execution is disabled.');
        [$command, $replayed] = $this->claim($request, $action, $target, $token, $key, $hash, true);
        if (! $replayed) {
            $command = $this->dispatch($command, $action, $target);
        }
        SystemApiAccess::receipt($request, $command);

        return $this->response($command, $replayed);
    }

    /** Existing browser commands retain availability and use the same durable lane. */
    public function browser(string $action, array $target): string
    {
        $request = request();
        $actor = $request->user();
        abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
            && SystemApiAccess::allows($actor->fresh(), 'system.queue_manage'), 403);
        $token = hash_hmac('sha256', 'browser:'.($request->hasSession() ? $request->session()->getId() : $actor->id), config('app.key'));
        [$command] = $this->claim($request, $action, $target, $token, hash('sha256', (string) Str::uuid()),
            SystemApiInput::hash([$action, $target, Str::uuid()->toString()]), false);
        $command = $this->dispatch($command, $action, $target);
        if (! in_array($command->state, ['accepted', 'completed'], true)) {
            throw ValidationException::withMessages(['maintenance' => 'Maintenance outcome is '.$command->state.'. Reconcile the recorded operation before retrying.']);
        }

        return $command->result['body']['message'];
    }

    private function claim(Request $request, string $action, array $target, string $token, string $key, string $hash, bool $api): array
    {
        abort_unless(Schema::hasTable('system_api_mutex'), 503, 'System API migration is required.');

        return DB::transaction(function () use ($request, $action, $target, $token, $key, $hash, $api) {
            DB::table('system_api_mutex')->where('id', 2)->lockForUpdate()->firstOrFail();
            $actor = $api ? SystemApiAccess::actor($request) : $request->user()->fresh();
            abort_unless($actor && $actor->isActive() && ! $actor->isSystemActor()
                && SystemApiAccess::allows($actor, 'system.queue_manage'), 403, 'Current queue authority is required.');
            abort_if($api && ! $actor->tokenCan('system.queue.'.$action), 403, 'Current action scope is required.');
            $existing = SystemApiCommand::where('actor_id', $actor->id)->where('token_hash', $token)->where('key_hash', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Idempotency-Key was used for another request.');

                return [$existing, true];
            }
            abort_if(SystemApiCommand::where('native_permission', 'system.queue_manage')->where('state', 'unresolved')->exists(),
                409, 'A maintenance operation is in progress or unresolved; reconcile it before further dispatch.');
            $snapshot = $this->snapshot($action, $target);
            if ($api) {
                try {
                    $confirmation = json_decode(Crypt::decryptString((string) $request->input('confirmation')), true, 512, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    abort(422, 'A valid preview confirmation is required.');
                }
                abort_unless(($confirmation['actor'] ?? null) === $actor->id
                    && hash_equals($confirmation['token'] ?? '', $token)
                    && ($confirmation['action'] ?? null) === $action
                    && ($confirmation['target'] ?? null) === $target, 422, 'Confirmation does not match this actor, token or target.');
                abort_unless(($confirmation['expires'] ?? 0) > now()->getTimestamp(), 409, 'Confirmation expired; preview again.');
                abort_unless(($confirmation['version'] ?? null) === $this->version()
                    && hash_equals($confirmation['snapshot'] ?? '', $snapshot['hash']), 409, 'Maintenance target changed; preview again.');
            }
            // The unresolved claim commits before the non-transactional command. It fences all other dispatch.
            $command = SystemApiCommand::create([
                'id' => (string) Str::uuid(), 'actor_id' => $actor->id, 'token_hash' => $token,
                'key_hash' => $key, 'request_hash' => $hash, 'operation' => 'queue.'.$action,
                'scope' => 'system.queue.'.$action, 'native_permission' => 'system.queue_manage',
                'state' => 'unresolved', 'result' => ['status' => 202, 'etag' => null, 'body' => [
                    'action' => $action, 'target' => $target, 'state' => 'unresolved',
                    'reconciliation_required' => true, 'message' => 'Dispatch is in progress or its outcome is unresolved.',
                    'before_count' => $snapshot['count'], 'evidence' => $snapshot['evidence'],
                ]],
            ]);
            DB::table('system_api_mutex')->where('id', 2)->increment('version');

            return [$command, false];
        });
    }

    private function dispatch(SystemApiCommand $command, string $action, array $target): SystemApiCommand
    {
        try {
            $exitCode = app(QueueMaintenanceAction::class)->invoke($action, $target);
            $state = $exitCode === 0 ? (in_array($action, ['restart', 'retry'], true) ? 'accepted' : 'completed') : 'unresolved';
            $messages = [
                'restart' => 'Graceful worker restart signal was issued; actual process restart is not verified.',
                'retry' => 'The selected failed jobs were released back to their queues; processing and delivery are not verified.',
                'clear' => 'The named queue clear command completed.',
                'flush' => 'The failed-job store flush command completed.',
            ];
            $result = $command->result;
            $result['status'] = $state === 'unresolved' ? 202 : 200;
            $result['body'] = array_merge($result['body'], [
                'state' => $state, 'exit_code' => $exitCode,
                'message' => $state === 'unresolved' ? 'Command reported failure; partial effects require reconciliation.' : $messages[$action],
                'reconciliation_required' => $state === 'unresolved', 'observed_at' => now()->toIso8601String(),
            ]);
            $command->update(['state' => $state, 'result' => $result]);
        } catch (\Throwable) {
            // Do not log a backend exception: it may contain credentials or serialized jobs.
            // The pre-dispatch receipt remains unresolved and cannot be replayed as a new command.
        }

        return $command->fresh();
    }

    public function response(SystemApiCommand $command, bool $replayed)
    {
        $body = $command->result['body'];
        $body['target'] = (object) $body['target'];

        return response()->json(array_merge($body, [
            'operation_id' => $command->id, 'replayed' => $replayed,
        ]), $command->result['status'])->header('X-Operation-Id', $command->id);
    }

    private function version(): int
    {
        return Schema::hasTable('system_api_mutex') ? (int) DB::table('system_api_mutex')->where('id', 2)->value('version') : 0;
    }

    /** Exact DB cohorts are hashed in memory; other drivers provide only explicit count evidence. */
    public function snapshot(string $action, array $target): array
    {
        $connection = (string) config('queue.default');
        $config = [config('queue'), config('cache.default')];
        if ($action === 'restart') {
            return ['hash' => SystemApiInput::hash([$config, Cache::get('illuminate:queue:restart')]),
                'count' => null, 'evidence' => 'restart_signal_only'];
        }
        if (in_array($action, ['retry', 'flush'], true)) {
            $failer = app('queue.failer');
            abort_if(in_array(config('queue.failed.driver'), [null, 'null'], true), 409, 'Failed-job storage is not configured.');
            if ($action === 'retry' && ! ($target['all'] ?? false)) {
                $job = $failer->find($target['job_id']);
                abort_unless($job, 404, 'Failed job is unavailable.');
                $rows = [$job];
            } else {
                $rows = $failer->all();
            }
            $cohort = [];
            foreach ($rows as $job) {
                // Never persist or return serialized payloads or exception contents.
                $row = (array) $job;
                if ($action === 'retry') {
                    abort_unless(is_string($row['connection'] ?? null)
                        && config('queue.connections.'.$row['connection'].'.driver'), 409, 'A failed job references an unavailable connection.');
                }
                $cohort[] = [$row['uuid'] ?? $row['id'] ?? null, $row['connection'] ?? null,
                    $row['queue'] ?? null, $row['failed_at'] ?? null, hash('sha256', $row['payload'] ?? '')];
            }
            usort($cohort, fn ($a, $b) => strcmp(json_encode($a), json_encode($b)));

            return ['hash' => SystemApiInput::hash([$config, $cohort]), 'count' => count($cohort), 'evidence' => 'failed_store_cohort'];
        }
        $backend = Queue::connection($connection);
        abort_unless(method_exists($backend, 'clear'), 409, 'The configured queue driver does not support clear.');
        if (config('queue.connections.'.$connection.'.driver') === 'database') {
            $database = DB::connection(config('queue.connections.'.$connection.'.connection'));
            $rows = $database->table(config('queue.connections.'.$connection.'.table', 'jobs'))
                ->where('queue', $target['queue'])->orderBy('id')->get(['id', 'reserved_at', 'available_at', 'payload'])
                ->map(fn ($row) => [$row->id, $row->reserved_at, $row->available_at, hash('sha256', $row->payload)])->all();

            return ['hash' => SystemApiInput::hash([$config, $target, $rows]), 'count' => count($rows), 'evidence' => 'database_queue_cohort'];
        }
        $size = $backend->size($target['queue']);

        return ['hash' => SystemApiInput::hash([$config, $target, $size]), 'count' => $size, 'evidence' => 'driver_count_only'];
    }
}
