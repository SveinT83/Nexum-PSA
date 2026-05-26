<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Actions\RegisterTicketTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CompleteTask
{
    public function __construct(
        private readonly EnsureTaskDefaults $ensureDefaults,
        private readonly RegisterTicketTimeEntry $registerTicketTimeEntry,
    )
    {
    }

    /**
     * Mark a task done and create estimated time when no actual time exists.
     */
    public function handle(Task $task, User $user, array $billingData = [], ?array $rateOption = null): Task
    {
        $this->ensureDefaults->handle();

        return DB::transaction(function () use ($task, $user, $billingData, $rateOption) {
            $task->loadMissing(['owner', 'dependencies.dependsOnTask.status', 'children.status', 'checklistItems']);

            $blockingDependencies = $task->dependencies
                ->where('is_required', true)
                ->filter(fn ($dependency) => blank($dependency->dependsOnTask?->completed_at));

            if ($blockingDependencies->isNotEmpty()) {
                throw new RuntimeException('Task has required dependencies that are not complete.');
            }

            $blockingChildren = $task->children
                ->filter(fn (Task $child) => blank($child->completed_at));

            if ($blockingChildren->isNotEmpty()) {
                throw new RuntimeException('Task has child tasks that are not complete.');
            }

            $uncheckedChecklistItems = $task->checklistItems
                ->filter(fn ($item) => ! $item->is_checked);

            if ($uncheckedChecklistItems->isNotEmpty()) {
                throw new RuntimeException('Task has checklist items that are not complete.');
            }

            if ($task->completed_at) {
                throw new RuntimeException('Task is already complete.');
            }

            if ($task->owner instanceof Ticket) {
                if (! $rateOption) {
                    throw new RuntimeException('Select an available time rate before completing this ticket task.');
                }

                foreach (['work_date', 'minutes', 'invoice_text'] as $requiredKey) {
                    if (blank($billingData[$requiredKey] ?? null)) {
                        throw new RuntimeException('Ticket-owned tasks require ticket time registration before completion.');
                    }
                }

                $ticketEntry = $this->registerTicketTimeEntry->handle($task->owner, [
                    'work_date' => $billingData['work_date'],
                    'minutes' => $billingData['minutes'],
                    'invoice_text' => $billingData['invoice_text'],
                    'note' => $billingData['note'] ?? null,
                ], $rateOption, $user);

                TaskTimeEntry::query()->create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'source_type' => 'ticket_time_entry',
                    'work_date' => $billingData['work_date'],
                    'minutes' => $billingData['minutes'],
                    'billable' => true,
                    'note' => 'Registered on ticket time entry #' . $ticketEntry->id . '.',
                ]);
            } elseif ($task->timeEntries()->sum('minutes') === 0 && $task->estimated_minutes) {
                TaskTimeEntry::query()->create([
                    'task_id' => $task->id,
                    'user_id' => $user->id,
                    'source_type' => 'estimated',
                    'work_date' => now()->toDateString(),
                    'minutes' => $task->estimated_minutes,
                    'note' => 'Created from task estimate when the task was completed.',
                ]);
            }

            $doneStatusId = TaskStatus::query()->where('is_done', true)->value('id');

            $task->forceFill([
                'status_id' => $doneStatusId,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ])->save();

            TaskActivity::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'type' => 'completed',
                'visibility' => Task::VISIBILITY_INTERNAL,
                'body' => 'Task completed.',
            ]);

            return $task->fresh(['status', 'timeEntries', 'activities']);
        });
    }
}
