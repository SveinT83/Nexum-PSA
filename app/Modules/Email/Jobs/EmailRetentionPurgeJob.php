<?php

namespace App\Modules\Email\Jobs;

use App\Models\Settings\CommonSetting;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailRetentionPurgeAttempt;
use App\Modules\Email\Models\EmailRetentionPurgeRun;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class EmailRetentionPurgeJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 600;

    public function __construct(public int $months = 24) {}

    /**
     * Keep two queued purge workers from racing while still rechecking each message under a row lock.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('email-retention-purge'))
                ->expireAfter($this->timeout + 60)
                ->dontRelease(),
        ];
    }

    public function uniqueId(): string
    {
        return 'email-retention-purge';
    }

    public function handle(EmailRetentionEligibilityService $eligibility): void
    {
        $configuredMonths = CommonSetting::query()
            ->where('type', 'emailhub')
            ->where('name', 'retention_months')
            ->value('value');
        $months = max(1, (int) ($configuredMonths ?? $this->months));
        $cutoff = $eligibility->cutoff($months);

        $run = EmailRetentionPurgeRun::query()->create([
            'retention_months' => $months,
            'cutoff_at' => $cutoff,
            'status' => EmailRetentionPurgeRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $stats = [
            'scanned_count' => 0,
            'eligible_count' => 0,
            'protected_count' => 0,
            'purged_count' => 0,
            'failed_count' => 0,
            'skipped_count' => 0,
        ];
        $reasonCounts = [];

        try {
            EmailMessage::query()
                ->withTrashed()
                ->where('received_at', '<', $cutoff)
                ->select(['id', 'account_id'])
                ->chunkById(100, function ($candidates) use (
                    $eligibility,
                    $cutoff,
                    $run,
                    &$stats,
                    &$reasonCounts,
                ): void {
                    foreach ($candidates as $candidate) {
                        $stats['scanned_count']++;
                        $attempt = EmailRetentionPurgeAttempt::query()->create([
                            'email_retention_purge_run_id' => $run->id,
                            'email_message_id' => $candidate->id,
                            'account_id' => $candidate->account_id,
                            'status' => EmailRetentionPurgeAttempt::STATUS_CHECKING,
                            'started_at' => now(),
                        ]);
                        $eligibleForDeletion = false;

                        try {
                            $result = DB::transaction(function () use (
                                $attempt,
                                $candidate,
                                $eligibility,
                                $cutoff,
                                &$eligibleForDeletion,
                            ): array {
                                $message = EmailMessage::query()
                                    ->withTrashed()
                                    ->whereKey($candidate->id)
                                    ->lockForUpdate()
                                    ->first();

                                if (! $message) {
                                    $attempt->forceFill([
                                        'status' => EmailRetentionPurgeAttempt::STATUS_SKIPPED,
                                        'reasons_json' => ['already_missing'],
                                        'finished_at' => now(),
                                    ])->save();

                                    return ['status' => EmailRetentionPurgeAttempt::STATUS_SKIPPED, 'reasons' => []];
                                }

                                $message->load([
                                    'attachments:id,message_id,disk,path',
                                    'placements:id,email_message_id,email_conversation_id,local_state,sync_status,provider_deleted,provider_missing_at',
                                ]);
                                $assessment = $eligibility->assess($message, $cutoff);
                                $attempt->forceFill([
                                    'account_id' => $message->account_id,
                                    'had_raw_payload' => filled($message->raw_path),
                                    'local_attachment_file_count' => $message->attachments
                                        ->where('disk', 'local')
                                        ->filter(fn ($attachment): bool => filled($attachment->path))
                                        ->count(),
                                    'reasons_json' => $assessment['reasons'],
                                ]);

                                if (! $assessment['eligible']) {
                                    $attempt->forceFill([
                                        'status' => EmailRetentionPurgeAttempt::STATUS_PROTECTED,
                                        'finished_at' => now(),
                                    ])->save();

                                    return [
                                        'status' => EmailRetentionPurgeAttempt::STATUS_PROTECTED,
                                        'reasons' => $assessment['reasons'],
                                    ];
                                }

                                $eligibleForDeletion = true;
                                $this->deleteLocalPayloads($message);

                                // Morph pivots have no message foreign key, so remove only this message's assignments.
                                $message->tags()->detach();

                                if (! $message->forceDelete()) {
                                    throw new RuntimeException('database_delete_failed');
                                }

                                $attempt->forceFill([
                                    'status' => EmailRetentionPurgeAttempt::STATUS_PURGED,
                                    'finished_at' => now(),
                                ])->save();

                                return ['status' => EmailRetentionPurgeAttempt::STATUS_PURGED, 'reasons' => []];
                            }, 3);

                            if ($result['status'] === EmailRetentionPurgeAttempt::STATUS_PROTECTED) {
                                $stats['protected_count']++;

                                foreach ($result['reasons'] as $reason) {
                                    $reasonCounts[$reason] = ($reasonCounts[$reason] ?? 0) + 1;
                                }
                            } elseif ($result['status'] === EmailRetentionPurgeAttempt::STATUS_PURGED) {
                                $stats['eligible_count']++;
                                $stats['purged_count']++;
                            } else {
                                $stats['skipped_count']++;
                            }
                        } catch (Throwable $exception) {
                            if ($eligibleForDeletion) {
                                $stats['eligible_count']++;
                            }

                            $stats['failed_count']++;
                            $attempt->forceFill([
                                'status' => EmailRetentionPurgeAttempt::STATUS_FAILED,
                                'failure_code' => $exception->getMessage() === 'storage_delete_failed'
                                    ? 'storage_delete_failed'
                                    : 'purge_transaction_failed',
                                'retry_after' => now()->addMonthNoOverflow(),
                                'finished_at' => now(),
                            ])->save();
                        }
                    }
                });

            ksort($reasonCounts);
            $run->forceFill([
                ...$stats,
                'reason_counts_json' => $reasonCounts,
                'status' => $stats['failed_count'] > 0
                    ? EmailRetentionPurgeRun::STATUS_PARTIAL_FAILURE
                    : EmailRetentionPurgeRun::STATUS_COMPLETED,
                'finished_at' => now(),
            ])->save();
        } catch (Throwable $exception) {
            $run->forceFill([
                ...$stats,
                'reason_counts_json' => $reasonCounts,
                'status' => EmailRetentionPurgeRun::STATUS_FAILED,
                'failure_code' => 'purge_run_failed',
                'finished_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    private function deleteLocalPayloads(EmailMessage $message): void
    {
        $disk = Storage::disk('local');

        foreach ($message->attachments as $attachment) {
            if ($attachment->disk !== 'local' || blank($attachment->path)) {
                continue;
            }

            // Flysystem treats an already-missing file as an idempotent delete. Calling delete
            // directly also avoids mistaking an unreadable path for a proven-missing payload.
            if (! $disk->delete($attachment->path)) {
                throw new RuntimeException('storage_delete_failed');
            }
        }

        if (filled($message->raw_path)
            && ! $disk->delete($message->raw_path)) {
            throw new RuntimeException('storage_delete_failed');
        }
    }
}
