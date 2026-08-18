<?php

namespace App\Modules\Email\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Services\EmailRemoteOperationUndoEligibility;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UndoEmailRemoteOperation
{
    public function __construct(
        private readonly EmailRemoteOperationUndoEligibility $eligibility,
        private readonly RecordEmailRemoteOperation $recordRemoteOperation,
        private readonly RunEmailRemoteOperation $runRemoteOperation,
    ) {}

    public function handle(EmailRemoteOperation $source, User $actor): EmailRemoteOperation
    {
        [$inverse, $shouldRun] = DB::transaction(function () use ($source, $actor): array {
            /** @var EmailRemoteOperation $locked */
            $locked = EmailRemoteOperation::query()
                ->with(['account', 'folder', 'placement', 'inverseOperation', 'attemptRecords'])
                ->lockForUpdate()
                ->findOrFail($source->id);

            $eligibility = $this->eligibility->evaluate($locked, $actor);
            if (! $eligibility['eligible']
                && $eligibility['classification'] === EmailRemoteOperation::FAILURE_AUTHORIZATION) {
                throw new AuthorizationException($eligibility['reason_message']);
            }

            if ($existing = $locked->inverseOperation) {
                return [$existing, $existing->status === EmailRemoteOperation::STATUS_PENDING];
            }

            if (! $eligibility['eligible']) {
                throw ValidationException::withMessages([
                    'operation' => $eligibility['reason_message'],
                ]);
            }

            $context = $this->eligibility->inverseContext($locked);
            $placement = $context['placement'];
            $placement->loadMissing(['account', 'folder']);

            $inverse = $this->recordRemoteOperation->pending(
                $locked->account,
                $context['operation_type'],
                'mail-op:undo:'.$locked->id,
                $actor,
                $placement->folder,
                $placement,
                $context['request'],
                $locked,
            );

            if ((int) $inverse->inverse_of_email_remote_operation_id !== (int) $locked->id) {
                throw ValidationException::withMessages([
                    'operation' => 'The Undo idempotency key is already linked to a different operation.',
                ]);
            }

            return [$inverse, true];
        });

        if (! $shouldRun) {
            return $inverse->load(['inverseOf', 'attemptRecords']);
        }

        return $this->runRemoteOperation->handle(
            $inverse->fresh(['account', 'folder', 'placement.message', 'inverseOf']),
            'undo',
            $actor,
        );
    }
}
