<?php

namespace App\Modules\Email\Actions;

use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperationAttempt;
use App\Modules\Email\Services\EmailRemoteOperationAttemptRecorder;
use Illuminate\Support\Facades\DB;

class RunDueEmailRemoteOperations
{
    public function __construct(
        private readonly RunEmailRemoteOperation $runRemoteOperation,
        private readonly EmailRemoteOperationAttemptRecorder $attemptRecorder,
    ) {}

    /** @return array{recovered_running: int, processed: int} */
    public function handle(int $limit = 50): array
    {
        $recovered = $this->recoverStaleRunning(max(1, $limit));

        $failedIds = EmailRemoteOperation::query()
            ->where('status', EmailRemoteOperation::STATUS_FAILED)
            ->whereNotNull('next_attempt_at')
            ->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')
            ->limit(max(1, $limit))
            ->pluck('id');

        $remaining = max(0, $limit - $failedIds->count());
        $pendingIds = $remaining > 0
            ? EmailRemoteOperation::query()
                ->where('status', EmailRemoteOperation::STATUS_PENDING)
                ->where('created_at', '<=', now()->subMinute())
                ->orderBy('created_at')
                ->limit($remaining)
                ->pluck('id')
            : collect();

        $processed = 0;
        foreach ($failedIds->merge($pendingIds)->unique() as $operationId) {
            $operation = EmailRemoteOperation::query()->find($operationId);
            if (! $operation || ! $operation->canBeRetried()) {
                continue;
            }

            $this->runRemoteOperation->handle($operation, 'scheduled');
            $processed++;
        }

        return ['recovered_running' => $recovered, 'processed' => $processed];
    }

    private function recoverStaleRunning(int $limit): int
    {
        $ids = EmailRemoteOperation::query()
            ->where('status', EmailRemoteOperation::STATUS_RUNNING)
            ->where('started_at', '<=', now()->subMinutes(10))
            ->orderBy('started_at')
            ->limit($limit)
            ->pluck('id');
        $recovered = 0;

        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$recovered): void {
                /** @var EmailRemoteOperation|null $operation */
                $operation = EmailRemoteOperation::query()->lockForUpdate()->find($id);
                if (! $operation || $operation->status !== EmailRemoteOperation::STATUS_RUNNING) {
                    return;
                }

                $attempt = EmailRemoteOperationAttempt::query()
                    ->where('email_remote_operation_id', $operation->id)
                    ->where('status', EmailRemoteOperationAttempt::STATUS_RUNNING)
                    ->latest('attempt_number')
                    ->first();

                if ($attempt) {
                    $this->attemptRecorder->finish(
                        $attempt,
                        'interrupted',
                        EmailRemoteOperation::FAILURE_AMBIGUOUS,
                        'REMOTE_OPERATION_STALE_RUNNING',
                        'The worker stopped before the provider outcome was recorded.',
                    );
                }

                $operation->forceFill([
                    'status' => EmailRemoteOperation::STATUS_FAILED,
                    'failure_classification' => EmailRemoteOperation::FAILURE_AMBIGUOUS,
                    'error_code' => 'REMOTE_OPERATION_STALE_RUNNING',
                    'error_message' => 'The worker stopped before the provider outcome was recorded.',
                    'status_reason_code' => 'REMOTE_OPERATION_STALE_RUNNING',
                    'status_reason_message' => 'Provider reconciliation is required before any retry.',
                    'failed_at' => now(),
                    'next_attempt_at' => now(),
                    'reconciliation_required_at' => now(),
                ])->save();
                $recovered++;
            });
        }

        return $recovered;
    }
}
