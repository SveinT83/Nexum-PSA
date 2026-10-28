<?php

namespace App\Modules\Nextcloud\Services;

use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudTalkDestination as Destination;
use App\Modules\Nextcloud\Support\NextcloudApiInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Connection-scoped discovery/configuration; no global enablement or notification send occurs here. */
class TalkDestinationRegistry
{
    public function installed(): bool
    {
        return Schema::hasTable('nextcloud_talk_destinations') && Schema::hasTable('nextcloud_talk_deliveries')
            && Schema::hasColumn('nextcloud_talk_destinations', 'provider_binding');
    }

    public function requireInstalled(): void
    {
        abort_unless($this->installed(), 503, 'Talk notifications require the database update.');
    }

    public function settings(NextcloudConnection $connection): array
    {
        $settings = data_get($connection->settings, 'talk_notifications', []);

        return is_array($settings) ? $settings : [];
    }

    public function enabled(NextcloudConnection $connection): bool
    {
        return $connection->is_active && $connection->canWrite()
            && ($this->settings($connection)['enabled'] ?? false) === true;
    }

    public function revision(NextcloudConnection $connection): string
    {
        $rows = $this->installed() ? Destination::where('connection_id', $connection->id)->orderBy('id')->get()->map->getAttributes()->all() : [];

        return NextcloudApiInput::revision([$connection->getRawOriginal(), $rows]);
    }

    public function context(NextcloudConnection $connection): array
    {
        $connection = $connection->fresh() ?? $connection;
        $ready = $this->installed();
        $settings = $this->settings($connection);

        return ['connection_id' => $connection->id, 'schema_ready' => $ready,
            'enabled' => (bool) ($settings['enabled'] ?? false), 'write_capable' => $connection->canWrite(),
            'active' => (bool) $connection->is_active,
            'default_destination_id' => isset($settings['default_destination_id']) ? (int) $settings['default_destination_id'] : null,
            'last_refreshed_at' => $settings['last_refreshed_at'] ?? null,
            'revision' => $this->revision($connection),
            'destinations' => $ready ? Destination::where('connection_id', $connection->id)->orderBy('name')->orderBy('id')
                ->get()->map(fn ($row) => $this->summary($connection, $row))->all() : []];
    }

    public function refresh(NextcloudConnection $connection, ?string $expected = null): array
    {
        $this->requireInstalled();
        $connection = $connection->fresh();
        abort_unless($connection?->is_active, 409, 'The selected Nextcloud connection is inactive.');
        $this->checkRevision($connection, $expected);
        $before = $this->revision($connection);
        try {
            $rooms = app(NextcloudTalkClient::class)->listConversations($connection);
        } catch (\Throwable) {
            // A failed discovery invalidates this connection's cached eligibility without guessing provider state.
            DB::transaction(function () use ($connection, $before) {
                $fresh = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
                if (hash_equals($before, $this->revision($fresh))) {
                    Destination::where('connection_id', $fresh->id)->update(['health_status' => 'unknown']);
                    $this->saveSettings($fresh, []);
                }
            }, 3);
            throw ValidationException::withMessages(['talk' => 'Talk conversations could not be refreshed. Check service-user access and trusted TLS.']);
        }
        DB::transaction(function () use ($connection, $before, $rooms) {
            $fresh = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            abort_unless(hash_equals($before, $this->revision($fresh)), 412, 'Connection or Talk settings changed; refresh the page.');
            Destination::where('connection_id', $fresh->id)->update(['is_available' => false, 'health_status' => 'unavailable']);
            foreach ($rooms as $room) {
                $available = in_array($room['participantType'], [1, 2, 3, 5], true) && $room['readOnly'] === 0
                    && ($room['permissions'] === null || ($room['permissions'] & 128) === 128);
                $destination = Destination::firstOrNew(['connection_id' => $fresh->id, 'conversation_token' => $room['token']]);
                $destination->fill(['name' => $room['displayName'] ?: $room['name'] ?: $room['token'],
                    'provider_binding' => $this->binding($fresh), 'room_type' => $room['type'], 'is_available' => $available,
                    'health_status' => $available ? 'available' : 'unavailable', 'last_discovered_at' => now()]);
                $destination->save();
            }
            $this->saveSettings($fresh, ['last_refreshed_at' => now()->toIso8601String()]);
        }, 3);

        return $this->context($connection->fresh());
    }

    public function update(NextcloudConnection $connection, bool $enabled, array $ids, ?int $default, string $expected): array
    {
        $this->requireInstalled();
        $ids = array_values(array_unique(array_map('intval', $ids)));
        DB::transaction(function () use ($connection, $enabled, $ids, $default, $expected) {
            $fresh = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            $this->checkRevision($fresh, $expected);
            $rows = Destination::where('connection_id', $fresh->id)->lockForUpdate()->get()->keyBy('id');
            if (count($ids) > 2000 || collect($ids)->contains(fn ($id) => $id < 1 || ! $rows->has($id))
                || ($default !== null && ! $rows->has($default))) {
                throw ValidationException::withMessages(['destination_ids' => 'Choose destinations belonging to this connection.']);
            }
            if ($enabled && (! $fresh->is_active || ! $fresh->canWrite() || $ids === []
                || $default === null || ! in_array($default, $ids, true)
                || collect($ids)->contains(fn ($id) => (! $rows[$id]->is_available || $rows[$id]->health_status !== 'available'
                    || ! hash_equals($this->binding($fresh), (string) $rows[$id]->provider_binding))))) {
                throw ValidationException::withMessages(['enabled' => 'Enable an active write-capable connection with available destinations and one selected default.']);
            }
            foreach ($rows as $row) {
                $row->is_enabled = in_array($row->id, $ids, true);
                $row->save();
            }
            $this->saveSettings($fresh, ['enabled' => $enabled, 'default_destination_id' => $default]);
        }, 3);

        return $this->context($connection->fresh());
    }

    /** An explicit create action makes only a private group; enabling/routing is a separate local choice. */
    public function create(NextcloudConnection $connection, string $name, string $expected): array
    {
        $this->requireInstalled();
        $connection = $connection->fresh();
        $this->checkRevision($connection, $expected);
        abort_unless($connection->is_active && $connection->canManageRemote(), 409, 'Creating a Talk group requires an active managed connection.');
        try {
            $room = app(NextcloudTalkClient::class)->createConversation($connection, $name, 2);
        } catch (\Throwable) {
            throw new \RuntimeException('Talk group creation outcome is unknown; reconcile before creating again.');
        }
        $token = $room['token'] ?? null;
        if (! is_string($token) || ! preg_match('/^[A-Za-z0-9_-]{1,128}$/', $token)) {
            throw new \RuntimeException('Talk group creation outcome is unknown; reconcile before creating again.');
        }
        try {
            $verified = app(NextcloudTalkClient::class)->getConversation($connection, $token);
            if (($verified['token'] ?? null) !== $token || (int) ($verified['type'] ?? 0) !== 2) {
                throw new \RuntimeException('Created Talk group could not be independently verified.');
            }
        } catch (\Throwable) {
            throw new \RuntimeException('Talk group creation read-back is unknown; reconcile before creating again.');
        }
        // Recheck the exact provider identity after the remote effect; never reroute its local binding.
        DB::transaction(function () use ($connection, $room, $token) {
            $fresh = NextcloudConnection::lockForUpdate()->findOrFail($connection->id);
            abort_unless(hash_equals($this->binding($connection), $this->binding($fresh)), 409, 'Connection changed after creation; reconcile the new group.');
            Destination::updateOrCreate(['connection_id' => $fresh->id, 'conversation_token' => $token],
                ['name' => mb_substr((string) ($room['displayName'] ?? $room['name'] ?? $token), 0, 512),
                    'provider_binding' => $this->binding($fresh), 'room_type' => 2, 'is_available' => true, 'health_status' => 'available', 'last_discovered_at' => now()]);
            $this->saveSettings($fresh, []);
        }, 3);

        return ['context' => $this->context($connection->fresh()),
            'destination' => Destination::where('connection_id', $connection->id)->where('conversation_token', $token)->firstOrFail()->safeSummary()];
    }

    public function globalChoices(): array
    {
        if (! $this->installed()) {
            return [];
        }

        return NextcloudConnection::orderBy('name')->get()->map(function ($connection) {
            return ['id' => $connection->id, 'name' => $connection->name, 'active' => (bool) $connection->is_active,
                'enabled' => $this->enabled($connection),
                'settings_url' => route('tech.admin.nextcloud.connections.show', $connection),
                'destinations' => Destination::where('connection_id', $connection->id)->orderBy('name')->get()
                    ->map(fn ($destination) => $this->summary($connection, $destination) + ['eligible' => $this->eligible($connection, $destination)])->all()];
        })->all();
    }

    /** Report effective health after a provider identity change without exposing its fingerprint. */
    private function summary(NextcloudConnection $connection, Destination $destination): array
    {
        $summary = $destination->safeSummary();
        if (! hash_equals($this->binding($connection), (string) $destination->provider_binding)) {
            $summary['available'] = false;
            $summary['health'] = 'stale';
        }

        return $summary;
    }

    public function eligible(NextcloudConnection $connection, Destination $destination): bool
    {
        return $this->enabled($connection) && (int) $destination->connection_id === (int) $connection->id
            && hash_equals($this->binding($connection), (string) $destination->provider_binding)
            && $destination->is_enabled && $destination->is_available && $destination->health_status === 'available';
    }

    public function binding(NextcloudConnection $connection): string
    {
        return NextcloudApiInput::revision($connection->only(['id', 'uuid', 'base_url', 'service_username', 'mode', 'is_active'])
            + ['credential_ciphertext' => $connection->getRawOriginal('service_password')]);
    }

    public function checkRevision(NextcloudConnection $connection, ?string $expected): void
    {
        abort_unless(is_string($expected) && hash_equals($this->revision($connection), $expected), 412, 'Talk settings changed; refresh before saving.');
    }

    /** Merge only this domain-owned settings key; bump opaque revision even within one timestamp tick. */
    public function saveSettings(NextcloudConnection $connection, array $values): void
    {
        $settings = $connection->settings ?? [];
        $settings['talk_notifications'] = array_replace($this->settings($connection), $values, ['revision' => (string) Str::uuid()]);
        $connection->settings = $settings;
        $connection->save();
    }
}
