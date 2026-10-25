<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\DataExchange\Support\DataExchangeAuthority;
use App\Modules\DataExchange\Support\DataExchangeMutex;
use App\Modules\DataExchange\Support\DataExchangeRevision;
use Illuminate\Support\Facades\Storage;

/** Freeze intent before I/O, persist write-start, and require destination bytes for verified success. */
class VerifiedDataExchangeDelivery
{
    public function preview(DataExchangeFile $file, DataExchangeDeliveryTarget $target, bool $api = true): array
    {
        abort_unless($target->active && in_array($target->direction, ['export', 'both'], true) && (! $target->profile_id || $target->profile_id === $file->profile_id), 422, 'The active export target must belong to this profile or be shared.');
        $disk = $target->filesystem_disk ?: ($target->type === 'local' ? 'local' : null);
        abort_unless($disk && config('filesystems.disks.'.$disk), 422, 'The target has no configured filesystem.');
        if ($api) {
            abort_unless(in_array($disk, config('data_exchange.delivery_disks', []), true), 422, 'The delivery disk must be explicitly approved.');
        }
        $directory = trim($target->remote_path ?: 'data-exchange-delivery', '/');
        abort_unless(preg_match('~^[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*$~D', $directory), 422, 'Use an approved relative destination directory.');
        abort_unless($file->run_id && $file->profile_id && $file->run?->profile_id === $file->profile_id && $file->run?->status === 'succeeded', 422, 'Use a file from a successful run.');
        $snapshot = ['file_id' => $file->id, 'checksum' => $file->checksum, 'target_id' => $target->id, 'target_revision' => DataExchangeRevision::of($target), 'disk' => $disk, 'path' => $directory.'/'.basename(str_replace('\\', '/', $file->filename)), 'disk_revision' => DataExchangeRevision::hash(config('filesystems.disks.'.$disk))];

        return ['file_id' => $file->id, 'target_id' => $target->id, 'checksum' => $file->checksum, 'preview_hash' => DataExchangeRevision::hash($snapshot), 'api_delivery_enabled' => (bool) config('data_exchange.api_delivery_enabled'), 'snapshot' => $snapshot];
    }

    public function prepare(DataExchangeFile $file, DataExchangeDeliveryTarget $target, ?DataExchangeSchedule $schedule = null, bool $api = true, ?\App\Models\Core\User $actor = null): DataExchangeDeliveryAttempt
    {
        return app(DataExchangeMutex::class)->run(function () use ($file, $target, $schedule, $api, $actor) {
            $actor ??= \App\Models\Core\User::find($schedule?->created_by ?: $file->generated_by);
            DataExchangeAuthority::runtime(DataExchangeProfile::findOrFail($file->profile_id), $actor, 'delivery');
            $preview = $this->preview($file->fresh(), $target->fresh(), $api);
            $previous = DataExchangeDeliveryAttempt::where('file_id', $file->id)->where('delivery_target_id', $target->id)->latest('id')->first();
            if ($previous && in_array($previous->status, ['queued', 'writing', 'unknown', 'succeeded'], true)) {
                abort_unless(data_get($previous->metadata, 'intent_hash') === $preview['preview_hash'], 409, 'A previous delivery intent exists; inspect and reconcile it.');

                return $previous;
            }
            if ($previous) {
                abort_unless(data_get($previous->metadata, 'safe_to_retry', false), 409, 'Reconcile the previous delivery before retrying.');
            }

            return DataExchangeDeliveryAttempt::create([
                'schedule_id' => $schedule?->id, 'delivery_target_id' => $target->id, 'run_id' => $file->run_id, 'file_id' => $file->id,
                'direction' => 'export', 'status' => 'queued', 'attempted_at' => now(),
                'metadata' => ['actor_id' => $actor->id, 'token_id' => $api && $actor->currentAccessToken() instanceof \Laravel\Sanctum\PersonalAccessToken ? $actor->currentAccessToken()->getKey() : null, 'intent_hash' => $preview['preview_hash'], 'snapshot' => $preview['snapshot'], 'verified' => false, 'safe_to_retry' => false],
            ]);
        });
    }

    public function perform(DataExchangeDeliveryAttempt $attempt): DataExchangeDeliveryAttempt
    {
        $claim = app(DataExchangeMutex::class)->run(function () use ($attempt) {
            $current = DataExchangeDeliveryAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($current->status !== 'queued') {
                return false;
            }
            $current->forceFill(['status' => 'writing'])->save();

            return true;
        });
        if (! $claim) {
            return $attempt->fresh();
        }
        $attempt = $attempt->fresh();
        $snapshot = data_get($attempt->metadata, 'snapshot', []);
        $started = false;
        try {
            $file = DataExchangeFile::findOrFail($attempt->file_id);
            $actor = \App\Models\Core\User::find(data_get($attempt->metadata, 'actor_id'));
            $profile = DataExchangeProfile::findOrFail($file->profile_id);
            DataExchangeAuthority::runtime($profile, $actor, 'delivery');
            if ($tokenId = data_get($attempt->metadata, 'token_id')) {
                $token = \Laravel\Sanctum\PersonalAccessToken::find($tokenId);
                abort_unless($token && $token->tokenable_id === $actor->id && (! $token->expires_at || $token->expires_at->isFuture()) && $token->can('data_exchange.delivery'), 403);
                if (\App\Modules\Economy\Support\EconomyDataExchangeAccess::isEconomy($profile)) {
                    abort_unless($token->can('economy.export'), 403);
                }
            }
            $target = DataExchangeDeliveryTarget::findOrFail($attempt->delivery_target_id);
            abort_unless(hash_equals($snapshot['target_revision'], DataExchangeRevision::of($target)) && hash_equals($snapshot['disk_revision'], DataExchangeRevision::hash(config('filesystems.disks.'.$snapshot['disk']))), 409, 'Destination configuration changed.');
            abort_unless(Storage::disk($file->disk)->exists($file->path), 404, 'Source file missing.');
            $contents = Storage::disk($file->disk)->get($file->path);
            abort_unless(hash_equals($snapshot['checksum'], hash('sha256', $contents)), 409, 'Source checksum changed.');
            $started = true;
            abort_unless(Storage::disk($snapshot['disk'])->put($snapshot['path'], $contents), 503, 'Destination write was not confirmed.');
            abort_unless($this->matches($snapshot), 503, 'Destination checksum was not confirmed.');

            return $this->finish($attempt, 'succeeded', true, false, null);
        } catch (\Throwable $error) {
            // An exception after write-start may represent a successful remote write; never blindly repeat it.
            return $this->finish($attempt, $started ? 'unknown' : 'failed', false, ! $started, $started ? 'destination_outcome_unknown' : 'delivery_preflight_failed');
        }
    }

    public function reconcile(DataExchangeDeliveryAttempt $attempt): DataExchangeDeliveryAttempt
    {
        abort_unless(in_array($attempt->status, ['writing', 'unknown', 'failed', 'succeeded'], true), 422, 'There is no remote outcome to reconcile.');
        $snapshot = data_get($attempt->metadata, 'snapshot', []);
        abort_unless(isset($snapshot['disk'], $snapshot['path'], $snapshot['checksum'], $snapshot['disk_revision']), 409, 'This historical attempt has no frozen verification evidence.');
        abort_unless(hash_equals($snapshot['disk_revision'], DataExchangeRevision::hash(config('filesystems.disks.'.$snapshot['disk']))), 409, 'The original destination configuration is unavailable.');
        try {
            if ($this->matches($snapshot)) {
                return $this->finish($attempt, 'succeeded', true, false, null);
            }
        } catch (\Throwable) {
            // An unreadable destination is an unknown outcome, never proof that nothing was written.
        }

        return $this->finish($attempt, 'unknown', false, false, 'destination_outcome_unknown');
    }

    private function matches(array $snapshot): bool
    {
        return Storage::disk($snapshot['disk'])->exists($snapshot['path']) && hash_equals($snapshot['checksum'], hash('sha256', Storage::disk($snapshot['disk'])->get($snapshot['path'])));
    }

    private function finish(DataExchangeDeliveryAttempt $attempt, string $status, bool $verified, bool $retry, ?string $error): DataExchangeDeliveryAttempt
    {
        return app(DataExchangeMutex::class)->run(function () use ($attempt, $status, $verified, $retry, $error) {
            $attempt = DataExchangeDeliveryAttempt::lockForUpdate()->findOrFail($attempt->id);
            $attempt->forceFill(['status' => $status, 'finished_at' => now(), 'error_message' => $error, 'metadata' => array_merge($attempt->metadata ?? [], ['verified' => $verified, 'safe_to_retry' => $retry, 'error_code' => $error])])->save();
            DataExchangeAuditEvent::create(['profile_id' => $attempt->file?->profile_id, 'run_id' => $attempt->run_id, 'file_id' => $attempt->file_id, 'event_type' => 'file_delivery_'.$status, 'outcome' => $status, 'metadata' => ['attempt_id' => $attempt->id, 'target_id' => $attempt->delivery_target_id], 'occurred_at' => now()]);

            return $attempt;
        });
    }
}
