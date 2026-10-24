<?php

namespace App\Modules\Calendar\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Durable receipt metadata is independent of activity-log retention; no private snapshots are stored. */
class ExecuteCalendarApiCommand
{
    public function handle(Request $request, string $operation, array $payload, callable $authorize, callable $execute, bool $required = true): Model
    {
        $key = $request->header('Idempotency-Key');
        abort_if($required && ! $key, 422, 'Idempotency-Key is required.');
        abort_if($key !== null && (! is_string($key) || strlen($key) < 8 || strlen($key) > 200), 422, 'Use an Idempotency-Key of 8 to 200 characters.');

        return DB::transaction(function () use ($request, $operation, $payload, $authorize, $execute, $key) {
            $actor = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $authorize($actor);
            $audit = app(DomainApiAudit::class);
            $hash = hash('sha256', json_encode($this->stable($payload), JSON_THROW_ON_ERROR));
            $event = 'calendar.api.'.$operation;
            $receipt = $key ? DB::table('calendar_api_command_receipts')->where('actor_id', $actor->id)->where('operation', $operation)
                ->where('key_hash', hash('sha256', $key))->first() : null;
            if ($receipt) {
                abort_unless(hash_equals((string) $receipt->request_hash, $hash), 409, 'The request key was used with different input.');
                $class = $receipt->target_type;
                // Only identities written by the explicit operation are accepted; no arbitrary model endpoint.
                $allowed = [
                    \App\Modules\Calendar\Models\CalendarEvent::class, \App\Modules\Calendar\Models\Calendar::class,
                    \App\Modules\Calendar\Models\CalendarAccess::class, \App\Modules\Calendar\Models\CalendarSetting::class,
                    \App\Modules\Nextcloud\Models\NextcloudConnection::class, \App\Modules\Nextcloud\Models\NextcloudCalendarMapping::class,
                    \App\Modules\Nextcloud\Models\NextcloudSyncLog::class,
                ];
                abort_unless(in_array($class, $allowed, true), 409, 'Receipt identity is unavailable.');
                $query = $class::query();
                if (in_array(\Illuminate\Database\Eloquent\SoftDeletes::class, class_uses_recursive($class), true)) {
                    $query->withTrashed();
                }
                $result = $query->find($receipt->target_id);
                abort_unless($result, 409, 'The original result has been removed.');

                return $result;
            }
            $result = $execute($actor);
            if ($key) {
                DB::table('calendar_api_command_receipts')->insert([
                    'actor_id' => $actor->id, 'operation' => $operation, 'key_hash' => hash('sha256', $key),
                    'request_hash' => $hash, 'target_type' => $result->getMorphClass(), 'target_id' => $result->id, 'created_at' => now(),
                ]);
            }
            $audit->record($actor, 'calendar', $event, $result, [], ['command_applied' => true, 'result_id' => $result->id], [
                'idempotency_key_hash' => $key ? hash('sha256', $key) : null, 'request_fingerprint' => $hash,
            ]);

            return $result;
        }, 3);
    }

    private function stable(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->stable($item);
            }
        }

        return $value;
    }
}
