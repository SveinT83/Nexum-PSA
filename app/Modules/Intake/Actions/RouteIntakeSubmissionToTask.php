<?php

namespace App\Modules\Intake\Actions;

use App\Models\Core\User;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Models\IntakeSubmissionEvent;
use App\Modules\Intake\Support\IntakeSubmissionTargetPayload;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class RouteIntakeSubmissionToTask
{
    public function __construct(
        private readonly StoreTask $storeTask,
        private readonly IntakeSubmissionTargetPayload $payload,
    ) {}

    public function handle(IntakeSubmission $submission, bool $force = false, ?User $actor = null): ?Task
    {
        $submission->loadMissing(['form.owner', 'attachments.field', 'matchedClient', 'matchedSite', 'matchedClientUser']);

        if (! $this->canRoute($submission, $force)) {
            return $this->existingTarget($submission);
        }

        $creator = $actor ?: $submission->form?->owner;

        if (! $creator) {
            $this->markSkipped($submission, 'Task routing requires a form owner or reviewing user.', [
                'reason' => 'task_creator_required',
                'target_type' => IntakeForm::TARGET_TASK,
            ], $actor);

            return null;
        }

        return DB::transaction(function () use ($submission, $creator): Task {
            $form = $submission->form;
            $owner = $this->ownerForTask($submission, $creator);
            $metadata = $this->payload->metadata($submission, IntakeForm::TARGET_TASK);

            $task = $this->storeTask->handle([
                'title' => $this->payload->title($submission),
                'description' => $this->payload->description($submission),
                'client_id' => $submission->matched_client_id,
                'site_id' => $submission->matched_site_id,
                'assigned_to' => $form?->owner_id,
                'source_type' => 'intake_submission',
                'source_id' => $submission->id,
                'metadata' => $metadata,
            ], $creator, $owner);

            $result = $metadata + [
                'action' => 'task_created',
                'task_id' => $task->id,
                'client_id' => $task->client_id,
            ];

            $submission->forceFill([
                'status' => IntakeSubmission::STATUS_ROUTED,
                'target_type' => Task::class,
                'target_id' => $task->id,
                'routing_result' => $result,
            ])->save();

            $submission->events()->create([
                'actor_id' => $creator->id,
                'type' => 'routed_to_task',
                'message' => 'Created task #'.$task->id.'.',
                'metadata' => $result,
            ]);

            return $task->fresh();
        });
    }

    private function canRoute(IntakeSubmission $submission, bool $force): bool
    {
        if ($submission->isClosedForRouting()) {
            return false;
        }

        if ($submission->target_type === Task::class && $submission->target_id) {
            return false;
        }

        if ($submission->hasTarget()) {
            return false;
        }

        return $force || $submission->form?->target_type === IntakeForm::TARGET_TASK;
    }

    private function existingTarget(IntakeSubmission $submission): ?Task
    {
        if ($submission->target_type === Task::class && $submission->target_id) {
            return Task::query()->find($submission->target_id);
        }

        return null;
    }

    private function ownerForTask(IntakeSubmission $submission, User $creator): Model
    {
        return $submission->matchedClient ?: $creator;
    }

    private function markSkipped(IntakeSubmission $submission, string $message, array $metadata = [], ?User $actor = null): void
    {
        $submission->forceFill([
            'status' => IntakeSubmission::STATUS_ROUTING_SKIPPED,
            'routing_result' => $metadata + ['message' => $message],
        ])->save();

        IntakeSubmissionEvent::query()->create([
            'intake_submission_id' => $submission->id,
            'actor_id' => $actor?->id,
            'type' => 'routing_skipped',
            'message' => $message,
            'metadata' => $metadata,
        ]);
    }
}
