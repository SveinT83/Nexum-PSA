<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\WorkContext\Models\WorkContext;
use App\Modules\WorkContext\Support\WorkContextType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class CreateInternalTaskFromWork
{
    /** Keep the normal Task grants, including source read-back, at the Task domain boundary. */
    public function authorize(User $actor): void
    {
        foreach (['view' => 'read', 'create' => 'create', 'update' => 'update'] as $permission => $ability) {
            abort_unless($actor->can('task.'.$permission), 403);
            abort_if($actor->currentAccessToken() && ! $actor->tokenCan('tasks.'.$ability), 403);
        }
    }

    /** Preview only existing supported defaults. No Task or time is created by this read. */
    public function target(User $actor, bool $lock = false): array
    {
        $this->authorize($actor);
        $contexts = WorkContext::query()->where('type', WorkContextType::INTERNAL)->whereNull('client_id')->where('is_default', true);
        $statuses = TaskStatus::query()->active()->where('slug', 'open')->where('is_done', false)->where('is_cancelled', false);
        if ($lock) {
            $contexts->lockForUpdate();
            $statuses->lockForUpdate();
        }
        $context = $contexts->first();
        $status = $statuses->first();
        abort_unless($context && $status, 409, 'The internal Work Context and open Task status must be available before conversion.');

        return ['work_context_id' => $context->id, 'context_type' => WorkContextType::INTERNAL, 'context_name' => $context->name,
            'status_id' => $status->id, 'status_name' => $status->name, 'owner_id' => $actor->id, 'assigned_to' => $actor->id,
            'billable' => false, 'visibility' => 'internal'];
    }

    /** Workday supplies a reviewed immutable payload inside its employee-serialized transaction. */
    public function handle(User $actor, array $payload, string $conversionToken): TaskTimeEntry
    {
        return DB::transaction(function () use ($actor, $payload, $conversionToken) {
            abort_unless($payload['target'] === $this->target($actor, true), 409, 'The Task target changed. Preview again.');
            $task = app(StoreTask::class)->handle([
                'title' => $payload['title'], 'description' => $payload['description'],
                'assigned_to' => $actor->id, 'status_id' => $payload['target']['status_id'],
                'estimated_minutes' => $payload['minutes'], 'visibility' => 'internal',
                'metadata' => ['workday_conversion' => $conversionToken],
            ], $actor, $actor);
            // Fail closed if shared Task creation ever changes the internal/standalone contract.
            abort_unless($task->owner instanceof User && $task->owner_id === $actor->id && $task->client_id === null
                && $task->work_context_id === $payload['target']['work_context_id'] && ! $task->completed_at
                && ! $task->status->is_done && ! $task->status->is_cancelled, 409, 'Task creation no longer supports this internal target.');
            abort_unless($payload['target'] === $this->target($actor, true), 409, 'Task defaults changed the reviewed target. Review the Task configuration.');
            $entry = app(RegisterTaskTimeEntry::class)->handle($task, $actor, [
                'work_date' => $payload['work_date'], 'minutes' => $payload['minutes'], 'note' => $payload['description'],
            ]);
            // Multiple segments separated by excluded breaks remain date-level Task time, with exact Workday placements.
            if (count($payload['ranges']) === 1) {
                $entry->update(['started_at' => CarbonImmutable::parse($payload['ranges'][0]['start']),
                    'ended_at' => CarbonImmutable::parse($payload['ranges'][0]['end'])]);
            }
            abort_if($entry->billable || $entry->source_type !== 'manual', 409, 'Conversion requires non-billable actual Task time.');

            return $entry->fresh('task');
        });
    }
}
