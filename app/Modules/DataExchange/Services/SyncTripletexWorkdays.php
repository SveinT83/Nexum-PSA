<?php

namespace App\Modules\DataExchange\Services;

use App\Models\Core\User;
use App\Models\System\Integrations\Integration;
use App\Modules\DataExchange\Models\TripletexWorkdaySyncState as State;
use App\Modules\Integration\Exceptions\TripletexException;
use App\Modules\Integration\Services\Tripletex\TripletexClient;
use App\Modules\UserManagement\Actions\EnsureSystemActor;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use App\Modules\Workday\Support\WorkdayDuration;
use App\Modules\Workday\Support\WorkdaySettings;
use App\Modules\Workday\Support\WorkdayTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A three-way, date-scoped reconciler. Intent is committed before HTTP; baselines only after read-back.
 * The same distributed lock is used by the switch, so its successful response fences active work.
 */
final class SyncTripletexWorkdays
{
    public static function lockKey(string $id): string
    {
        return 'tripletex-time:'.$id;
    }

    public function day(string $connectionId, int $userId, string $date): void
    {
        Cache::lock(self::lockKey($connectionId), 300)->block(30, function () use ($connectionId, $userId, $date) {
            $connection = Integration::findOrFail($connectionId);
            if (! $this->active($connection)) {
                return;
            }
            $mapping = $connection->config['time_mappings'][(string) $userId] ?? null;
            $user = User::find($userId);
            if (! $mapping || ! $user || $user->status !== User::STATUS_ACTIVE || $user->isSystemActor()) {
                return;
            }
            $timezone = $mapping['timezone'];
            $expiry = app(WorkdayTime::class)->expiresAt($date, $timezone);
            if ($date < $mapping['start_date'] || $date > now($timezone)->toDateString() || $expiry->lte(now())) {
                return;
            }
            $state = State::firstOrCreate(['connection_id' => $connectionId, 'user_id' => $userId, 'work_date' => $date],
                ['expires_at' => $expiry]);
            try {
                $this->reconcile($connection, $mapping, $state);
            } catch (TripletexException $e) {
                $state->refresh()->update(['status' => 'attention', 'error_code' => $e->reason, 'checked_at' => now()]);
            }
        });
    }

    private function active(Integration $connection): bool
    {
        return config('tripletex.enabled') && config('tripletex.writes_enabled')
            && app(WorkdaySettings::class)->enabled() && $connection->type === 'tripletex'
            && $connection->status === 'active' && ($connection->config['write_contract_verified'] ?? false)
            && ! empty($connection->config['read_verified_at']);
    }

    private function guard(Integration $connection): void
    {
        if (! $this->active($connection->fresh())) {
            throw new TripletexException('synchronization_paused');
        }
    }

    private function reconcile(Integration $connection, array $mapping, State $state): void
    {
        $day = Workday::where('user_id', $state->user_id)->where('work_date', $state->work_date)->first();
        if ($day && ($day->expires_at->lte(now()) || $day->currentRevision->state === 'draft')) {
            throw new TripletexException('private_draft_or_expired');
        }
        // Retention or a restore must never cause an external deletion.
        if (! $day && $state->baseline !== null && ($state->baseline['remote'] || $state->baseline['local'] !== $this->hash([]))) {
            throw new TripletexException('local_record_missing');
        }
        $local = $day ? $this->local($day->currentRevision->snapshot, $mapping) : [];
        $localHash = $this->hash($local);
        $version = $day?->version ?? 0;
        $client = new TripletexClient($connection);
        $client->verifyCompany();
        $remote = $this->scan($client, $mapping, $state->work_date);
        $baseline = $state->baseline;
        $intent = $state->intent;
        if ($intent) {
            // Resume the exact durable intent, even if a newer local save has arrived.
            $remote = $this->deliver($client, $connection, $mapping, $state, $remote, $intent);
            $state->update(['intent' => null]);
            $this->complete($state, $intent['local'], $remote);

            return;
        }
        $remoteChanged = $baseline !== null && $this->hash($this->identities($remote)) !== $this->hash($this->identities($baseline['remote']));
        $localChanged = $baseline === null ? count($local) > 0 : $localHash !== $baseline['local'];

        if ($this->same($local, $remote)) {
            $this->complete($state, $localHash, $remote);

            return;
        }
        if ($baseline === null && $local && $remote) {
            throw new TripletexException('initial_records_conflict');
        }
        if ($localChanged && $remoteChanged) {
            throw new TripletexException('both_sides_changed');
        }
        if ($remoteChanged || ($baseline === null && $remote)) {
            // A complete list is required, plus direct absence checks for previously known IDs.
            foreach ($baseline['remote'] ?? [] as $previous) {
                if (! in_array($previous['id'], array_column($remote, 'id'), true)) {
                    try {
                        $found = $client->entry($previous['id']);
                        // A legitimate date/activity move is visible in the direct read.
                        if (($found['date'] ?? null) === $state->work_date
                            && (int) data_get($found, 'employee.id') === (int) $mapping['employee_id']) {
                            throw new TripletexException('inconsistent_remote_scan');
                        }
                    } catch (TripletexException $e) {
                        if ($e->status !== 404) {
                            throw $e;
                        }
                        $client->verifyCompany();
                        if ($this->hash($this->scan($client, $mapping, $state->work_date)) !== $this->hash($remote)) {
                            throw new TripletexException('unstable_remote_scan');
                        }
                    }
                }
            }
            $this->guard($connection);
            $this->import($state, $mapping, $remote, $version);
            $this->complete($state, $this->hash($this->values($remote)), $remote);
        } elseif ($localChanged) {
            $intent = ['local' => $localHash, 'desired' => $local, 'expected' => $remote];
            $state->update(['intent' => $intent, 'status' => 'delivering', 'error_code' => null]);
            $remote = $this->deliver($client, $connection, $mapping, $state, $remote, $intent);
            $state->update(['intent' => null]);
            $this->complete($state, $localHash, $remote);
        }
    }

    private function deliver(TripletexClient $client, Integration $connection, array $mapping, State $state, array $remote, array $intent): array
    {
        $expected = $intent['expected'];
        $desired = $intent['desired'];
        $keys = array_unique(array_merge(array_keys($expected), array_keys($desired), array_keys($remote)));
        // Validate the whole observed mixture before making any more writes after a crash.
        foreach ($keys as $key) {
            $now = isset($remote[$key]) ? $this->value($remote[$key]) : null;
            $before = isset($expected[$key]) ? $this->value($expected[$key]) : null;
            $after = $desired[$key] ?? null;
            if ($now !== $before && $now !== $after) {
                throw new TripletexException('intent_remote_conflict');
            }
            if (isset($expected[$key], $remote[$key]) && $expected[$key]['id'] !== $remote[$key]['id']) {
                throw new TripletexException('remote_identity_changed');
            }
        }
        foreach ($keys as $key) {
            $before = $remote[$key] ?? null;
            $after = $desired[$key] ?? null;
            if (($before ? $this->value($before) : null) === $after) {
                continue;
            }
            $this->guard($connection);
            if (! $after) {
                $client->deleteEntry($before['id'], $before['version']);
                unset($remote[$key]);
            } else {
                $payload = ['employee' => ['id' => (int) $mapping['employee_id']],
                    'activity' => ['id' => $after['activity_id']],
                    'project' => $after['project_id'] ? ['id' => $after['project_id']] : null,
                    'date' => $state->work_date, 'hours' => $after['units'] / 100, 'comment' => $after['comment']];
                $written = $before ? $client->updateEntry($before['id'], $before['version'], $payload) : $client->createEntry($payload);
                $remote[$key] = $this->remoteRow($written, $mapping, $state->work_date);
            }
            ksort($remote);
            // Acknowledged partial progress becomes the next recovery precondition.
            $intent['expected'] = $remote;
            $state->update(['intent' => $intent]);
        }
        $readback = $this->scan($client, $mapping, $state->work_date);
        if (! $this->same($desired, $readback)) {
            throw new TripletexException('delivery_readback_changed');
        }

        return $readback;
    }

    private function scan(TripletexClient $client, array $mapping, string $date): array
    {
        $result = [];
        foreach ($client->entries($date, CarbonImmutable::parse($date)->addDay()->toDateString(), (int) $mapping['employee_id']) as $raw) {
            $row = $this->remoteRow($raw, $mapping, $date);
            $key = $row['activity_id'].':'.($row['project_id'] ?? 0);
            if (isset($result[$key])) {
                throw new TripletexException('duplicate_business_key');
            }
            $result[$key] = $row;
        }
        ksort($result);

        return $result;
    }

    private function remoteRow(array $raw, array $mapping, string $date): array
    {
        $units = (int) round((float) ($raw['hours'] ?? -1) * 100);
        if (($raw['date'] ?? null) !== $date || (int) data_get($raw, 'employee.id') !== (int) $mapping['employee_id']
            || ! is_int($raw['id'] ?? null) || ! is_int($raw['version'] ?? null)
            || ! is_bool($raw['locked'] ?? null) || $units < 1 || $units > 2400
            || abs($units / 100 - (float) $raw['hours']) > 0.000001
            || (int) data_get($raw, 'activity.id') < 1) {
            throw new TripletexException('unsupported_remote_entry');
        }

        return ['activity_id' => (int) data_get($raw, 'activity.id'),
            'project_id' => data_get($raw, 'project.id') ? (int) data_get($raw, 'project.id') : null,
            'units' => $units, 'comment' => (string) ($raw['comment'] ?? ''),
            'id' => $raw['id'], 'version' => $raw['version'], 'locked' => $raw['locked']];
    }

    private function local(array $snapshot, array $mapping): array
    {
        if (array_key_exists('durations', $snapshot)) {
            $rows = $snapshot['durations'];
        } elseif ($snapshot['actual_minutes'] > 0) {
            // Clocks remain local detail; the common duration is deliberately rounded once.
            $rows = [['activity_id' => (int) $mapping['activity_id'], 'project_id' => null,
                'units' => (int) round($snapshot['actual_minutes'] * 100 / 60),
                'comment' => $snapshot['description']]];
        } else {
            $rows = [];
        }
        $result = [];
        foreach ($rows as $row) {
            $result[$row['activity_id'].':'.($row['project_id'] ?? 0)] = $this->value($row);
        }
        ksort($result);

        return $result;
    }

    private function import(State $state, array $mapping, array $rows, int $expectedVersion): void
    {
        $actor = app(EnsureSystemActor::class)->handle('tripletex-workday-sync', 'Tripletex time synchronization',
            'tripletex-workday-sync@system.invalid', []);
        DB::transaction(function () use ($state, $mapping, $rows, $expectedVersion, $actor) {
            User::whereKey($state->user_id)->lockForUpdate()->firstOrFail();
            $day = Workday::where('user_id', $state->user_id)->where('work_date', $state->work_date)->first();
            if (($day?->version ?? 0) !== $expectedVersion) {
                throw new TripletexException('local_changed_during_import');
            }
            $previous = $day?->currentRevision?->snapshot;
            $preserveClocks = $previous && ! empty($previous['intervals']) && count($rows) === 1
                && isset($rows[$mapping['activity_id'].':0']) && array_values($rows)[0]['comment'] !== ''
                && abs($previous['actual_minutes'] - array_values($rows)[0]['units'] * 0.6) < 0.000001;
            if (! $preserveClocks && $day && count($day->currentRevision->snapshot['allocations'] ?? [])) {
                throw new TripletexException('source_allocations_need_reconciliation');
            }
            $snapshot = app(WorkdayDuration::class)->snapshot($state->work_date, [
                'timezone' => $mapping['timezone'], 'description' => 'Work recorded in Tripletex',
                'durations' => array_map(fn ($r) => ['activity_id' => $r['activity_id'], 'project_id' => $r['project_id'],
                    'hours' => $r['units'] / 100, 'comment' => $r['comment']], array_values($rows)),
            ]);
            if ($preserveClocks) {
                $snapshot = $previous;
                $snapshot['description'] = array_values($rows)[0]['comment'] ?: 'Work - unspecified';
            }
            $day ??= Workday::create(['uuid' => (string) Str::uuid(), 'user_id' => $state->user_id,
                'work_date' => $state->work_date, 'timezone' => $mapping['timezone'], 'version' => 0,
                'expires_at' => $state->expires_at]);
            $revision = WorkdayRevision::create(['uuid' => (string) Str::uuid(), 'workday_id' => $day->id,
                'version' => $day->version + 1, 'state' => 'recorded', 'snapshot' => $snapshot,
                'author_id' => $actor->id, 'origin' => 'sync', 'created_at' => now()]);
            foreach ($snapshot['allocations'] ?? [] as $allocation) {
                DB::table('workday_source_allocations')->insert(['revision_id' => $revision->id,
                    'source_key' => $allocation['source_key'], 'minutes' => $allocation['minutes']]);
            }
            $day->update(['version' => $revision->version, 'current_revision_id' => $revision->id, 'confirmed_revision_id' => $revision->id]);
            // Import and its baseline commit together; a crash cannot replay the import.
            $this->complete($state, $this->hash($this->values($rows)), $rows);
        });
    }

    private function complete(State $state, string $localHash, array $remote): void
    {
        $day = Workday::where('user_id', $state->user_id)->where('work_date', $state->work_date)->first();
        $mapping = Integration::findOrFail($state->connection_id)->config['time_mappings'][(string) $state->user_id];
        $current = $day ? $this->local($day->currentRevision->snapshot, $mapping) : [];
        $status = $this->hash($current) === $localHash ? 'synced' : 'pending';
        $state->update(['baseline' => ['local' => $localHash, 'remote' => $remote], 'status' => $status,
            'error_code' => null, 'checked_at' => now()]);
    }

    private function value(array $r): array
    {
        return ['activity_id' => (int) $r['activity_id'], 'project_id' => $r['project_id'] === null ? null : (int) $r['project_id'],
            'units' => (int) $r['units'], 'comment' => (string) $r['comment']];
    }

    private function identities(array $rows): array
    {
        return array_map(fn ($r) => $this->value($r) + ['id' => $r['id']], $rows);
    }

    private function values(array $rows): array
    {
        return array_map(fn ($r) => $this->value($r), $rows);
    }

    private function same(array $local, array $remote): bool
    {
        return $this->hash($local) === $this->hash($this->values($remote));
    }

    private function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }
}
