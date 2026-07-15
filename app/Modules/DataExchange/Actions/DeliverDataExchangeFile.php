<?php

namespace App\Modules\DataExchange\Actions;

use App\Modules\DataExchange\Models\DataExchangeAuditEvent;
use App\Modules\DataExchange\Models\DataExchangeDeliveryAttempt;
use App\Modules\DataExchange\Models\DataExchangeDeliveryTarget;
use App\Modules\DataExchange\Models\DataExchangeFile;
use App\Modules\DataExchange\Models\DataExchangeSchedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DeliverDataExchangeFile
{
    public function handle(DataExchangeFile $file, DataExchangeDeliveryTarget $target, ?DataExchangeSchedule $schedule = null): DataExchangeDeliveryAttempt
    {
        $attempt = DataExchangeDeliveryAttempt::query()->create([
            'schedule_id' => $schedule?->id,
            'delivery_target_id' => $target->id,
            'run_id' => $file->run_id,
            'file_id' => $file->id,
            'direction' => 'export',
            'status' => DataExchangeDeliveryAttempt::STATUS_QUEUED,
            'attempted_at' => now(),
        ]);

        try {
            $disk = $target->filesystem_disk ?: ($target->type === DataExchangeDeliveryTarget::TYPE_LOCAL ? 'local' : null);

            if (! $disk) {
                throw new \RuntimeException('Delivery target has no filesystem disk reference. Credentials must remain outside Data Exchange.');
            }

            $remotePath = trim((string) ($target->remote_path ?: 'data-exchange-delivery'), '/');
            $destination = $remotePath.'/'.Str::of($file->filename)->replace(['/', '\\'], '_');
            $contents = Storage::disk($file->disk)->get($file->path);

            Storage::disk($disk)->put($destination, $contents);

            $attempt->forceFill([
                'status' => DataExchangeDeliveryAttempt::STATUS_SUCCEEDED,
                'finished_at' => now(),
                'metadata' => [
                    'disk' => $disk,
                    'path' => $destination,
                    'target_type' => $target->type,
                    'credential_reference' => $target->credential_reference,
                ],
            ])->save();

            DataExchangeAuditEvent::query()->create([
                'profile_id' => $file->profile_id,
                'run_id' => $file->run_id,
                'file_id' => $file->id,
                'event_type' => 'file_delivered',
                'outcome' => 'succeeded',
                'metadata' => ['target_id' => $target->id, 'attempt_id' => $attempt->id, 'disk' => $disk],
                'occurred_at' => now(),
            ]);
        } catch (Throwable $exception) {
            $attempt->forceFill([
                'status' => DataExchangeDeliveryAttempt::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();

            DataExchangeAuditEvent::query()->create([
                'profile_id' => $file->profile_id,
                'run_id' => $file->run_id,
                'file_id' => $file->id,
                'event_type' => 'file_delivery_failed',
                'outcome' => 'failed',
                'metadata' => ['target_id' => $target->id, 'attempt_id' => $attempt->id, 'error' => $exception->getMessage()],
                'occurred_at' => now(),
            ]);
        }

        return $attempt->refresh();
    }
}
