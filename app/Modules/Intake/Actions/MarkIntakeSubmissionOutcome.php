<?php

namespace App\Modules\Intake\Actions;

use App\Models\Core\User;
use App\Modules\Intake\Models\IntakeSubmission;
use Illuminate\Validation\ValidationException;

class MarkIntakeSubmissionOutcome
{
    public function handle(IntakeSubmission $submission, string $status, ?User $actor = null, ?string $reason = null, array $metadata = []): IntakeSubmission
    {
        if (! array_key_exists($status, $this->allowedStatuses())) {
            throw ValidationException::withMessages([
                'status' => 'Unsupported Intake submission outcome.',
            ]);
        }

        if ($submission->hasTarget() && in_array($status, [
            IntakeSubmission::STATUS_SPAM,
            IntakeSubmission::STATUS_DUPLICATE,
            IntakeSubmission::STATUS_REJECTED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => 'A routed submission can only be marked reviewed or archived.',
            ]);
        }

        $before = [
            'status' => $submission->status,
            'reviewed_at' => $submission->reviewed_at?->toISOString(),
            'reviewed_by' => $submission->reviewed_by,
        ];

        $submission->forceFill([
            'status' => $status === IntakeSubmission::STATUS_REVIEWED && $submission->status === IntakeSubmission::STATUS_ROUTED
                ? IntakeSubmission::STATUS_ROUTED
                : $status,
            'reviewed_at' => now(),
            'reviewed_by' => $actor?->id,
        ])->save();

        $submission->events()->create([
            'actor_id' => $actor?->id,
            'type' => $status === IntakeSubmission::STATUS_REVIEWED ? 'reviewed' : 'marked_'.$status,
            'message' => $reason ?: 'Submission marked as '.$this->allowedStatuses()[$status].'.',
            'before' => $before,
            'after' => [
                'status' => $submission->status,
                'reviewed_at' => $submission->reviewed_at?->toISOString(),
                'reviewed_by' => $submission->reviewed_by,
            ],
            'metadata' => $metadata,
        ]);

        return $submission->refresh();
    }

    private function allowedStatuses(): array
    {
        return [
            IntakeSubmission::STATUS_REVIEWED => 'reviewed',
            IntakeSubmission::STATUS_SPAM => 'spam',
            IntakeSubmission::STATUS_DUPLICATE => 'duplicate',
            IntakeSubmission::STATUS_REJECTED => 'rejected',
            IntakeSubmission::STATUS_ARCHIVED => 'archived',
        ];
    }
}
