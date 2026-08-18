<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRemoteOperation;
use App\Modules\Email\Models\EmailRemoteOperationAttempt;

class EmailRemoteOperationAttemptRecorder
{
    public function __construct(
        private readonly EmailRemoteOperationEvidenceSanitizer $evidenceSanitizer,
    ) {}

    public function start(
        EmailRemoteOperation $operation,
        string $kind,
        string $trigger,
        ?User $triggeredBy = null,
    ): EmailRemoteOperationAttempt {
        $number = ((int) $operation->attemptRecords()->max('attempt_number')) + 1;

        return EmailRemoteOperationAttempt::query()->create([
            'email_remote_operation_id' => $operation->id,
            'attempt_number' => $number,
            'attempt_kind' => $kind,
            'trigger' => $trigger,
            'triggered_by' => $triggeredBy?->id,
            'status' => EmailRemoteOperationAttempt::STATUS_RUNNING,
            'request_json' => $this->evidenceSanitizer->sanitize($operation->request_json ?? []),
            'started_at' => now(),
            'provider_started_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $response */
    public function finish(
        EmailRemoteOperationAttempt $attempt,
        string $outcome,
        ?string $classification = null,
        ?string $reasonCode = null,
        ?string $reasonMessage = null,
        array $response = [],
        ?\Throwable $error = null,
        ?string $attemptKind = null,
    ): EmailRemoteOperationAttempt {
        $message = $this->evidenceSanitizer->message($reasonMessage ?: $error?->getMessage());
        $diagnostic = $error;

        while ($diagnostic?->getPrevious()) {
            $diagnostic = $diagnostic->getPrevious();
        }

        $attempt->forceFill([
            'attempt_kind' => $attemptKind ?: $attempt->attempt_kind,
            'status' => EmailRemoteOperationAttempt::STATUS_FINISHED,
            'outcome' => $outcome,
            'failure_classification' => $classification,
            'reason_code' => $reasonCode,
            'reason_message' => $message,
            'response_json' => $this->evidenceSanitizer->sanitize($response),
            'error_json' => $diagnostic ? [
                'type' => class_basename($diagnostic),
                'code' => (string) $diagnostic->getCode(),
                'message' => $message,
            ] : null,
            'provider_finished_at' => now(),
            'finished_at' => now(),
        ])->save();

        return $attempt->refresh();
    }

    public function markMutationStarted(EmailRemoteOperationAttempt $attempt): EmailRemoteOperationAttempt
    {
        $attempt->forceFill([
            'attempt_kind' => EmailRemoteOperationAttempt::KIND_MUTATION,
        ])->save();

        return $attempt->refresh();
    }
}
