<?php

namespace App\Modules\DataExchange\Support;

use App\Modules\DataExchange\Actions\SaveDataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeImportPreview;
use App\Modules\DataExchange\Models\DataExchangeProfile;
use App\Modules\DataExchange\Models\DataExchangeRun;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use App\Modules\Economy\Actions\EconomyExportWorkflow;
use App\Modules\Economy\Support\EconomyDataExchangeAccess;

/** Explicit projections never serialize credential configuration, arbitrary audit payloads or paths. */
final class DataExchangeProjection
{
    public static function profile(DataExchangeProfile $p): array
    {
        return ['id' => $p->id, 'revision' => DataExchangeRevision::of($p), 'runs_count' => $p->runs()->count(), 'files_count' => $p->files()->count()] + SaveDataExchangeProfile::editable($p);
    }

    public static function file(DataExchangeFile $f): array
    {
        return ['id' => $f->id, 'run_id' => $f->run_id, 'profile_id' => $f->profile_id, 'filename' => $f->filename, 'format' => $f->format, 'mime_type' => $f->mime_type, 'size_bytes' => $f->size_bytes, 'checksum' => $f->checksum, 'retention_until' => $f->retention_until?->toIso8601String(), 'downloaded_at' => $f->downloaded_at?->toIso8601String()];
    }

    public static function run(DataExchangeRun $r): array
    {
        $profile = DataExchangeProfile::withTrashed()->findOrFail($r->profile_id);
        if (EconomyDataExchangeAccess::isEconomy($profile)) {
            return EconomyExportWorkflow::projection($r);
        }

        return ['id' => $r->id, 'profile_id' => $r->profile_id, 'profile' => ['id' => $profile->id, 'key' => $profile->key, 'name' => $profile->name], 'direction' => $r->direction, 'status' => $r->status, 'trigger_type' => $r->trigger_type, 'started_at' => $r->started_at?->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(), 'summary' => array_intersect_key($r->summary ?? [], array_flip(['rows', 'format', 'file_id', 'filename', 'preview_id', 'valid', 'invalid', 'created', 'updated', 'skipped'])), 'error_message' => $r->status === 'failed' ? 'The operation failed. Review the authorized operational log.' : null, 'files' => $r->files()->orderBy('id')->get()->map(fn ($f) => self::file($f))->all()];
    }

    public static function preview(DataExchangeImportPreview $p, int $offset = 0, int $limit = 100): array
    {
        return ['id' => $p->id, 'profile_id' => $p->profile_id, 'run_id' => $p->run_id, 'status' => $p->status, 'source_key' => $p->source_key, 'format' => $p->format, 'original_filename' => $p->original_filename, 'row_count' => $p->row_count, 'valid_count' => $p->valid_count, 'invalid_count' => $p->invalid_count, 'mapping' => $p->mapping, 'rows' => array_slice($p->rows ?? [], $offset, $limit), 'errors' => array_slice($p->errors ?? [], $offset, $limit, true), 'summary' => $p->summary, 'revision' => DataExchangeRevision::of($p), 'rows_offset' => $offset, 'rows_limit' => $limit, 'committed_at' => $p->committed_at?->toIso8601String()];
    }

    public static function schedule(DataExchangeSchedule $s): array
    {
        return ['id' => $s->id, 'profile_id' => $s->profile_id, 'delivery_target_id' => $s->delivery_target_id, 'direction' => $s->direction, 'active' => $s->active, 'frequency' => $s->frequency, 'run_time' => $s->run_time, 'weekdays' => $s->weekdays ?? [], 'timezone' => config('app.timezone'), 'next_run_at' => $s->next_run_at?->toIso8601String(), 'last_run_at' => $s->last_run_at?->toIso8601String(), 'last_run_id' => $s->last_run_id, 'revision' => DataExchangeRevision::of($s)];
    }

    public static function target(DataExchangeDeliveryTarget $t): array
    {
        return ['id' => $t->id, 'profile_id' => $t->profile_id, 'name' => $t->name, 'type' => $t->type, 'direction' => $t->direction, 'active' => $t->active, 'credential_reference' => $t->credential_reference, 'filesystem_disk' => $t->filesystem_disk, 'remote_path' => $t->remote_path, 'notes' => data_get($t->settings, 'notes'), 'revision' => DataExchangeRevision::of($t)];
    }

    public static function attempt(DataExchangeDeliveryAttempt $a): array
    {
        return ['id' => $a->id, 'file_id' => $a->file_id, 'run_id' => $a->run_id, 'delivery_target_id' => $a->delivery_target_id, 'schedule_id' => $a->schedule_id, 'status' => $a->status, 'attempted_at' => $a->attempted_at?->toIso8601String(), 'finished_at' => $a->finished_at?->toIso8601String(), 'verified' => (bool) data_get($a->metadata, 'verified', false), 'safe_to_retry' => (bool) data_get($a->metadata, 'safe_to_retry', false), 'error_code' => data_get($a->metadata, 'error_code'), 'revision' => DataExchangeRevision::of($a)];
    }
}
