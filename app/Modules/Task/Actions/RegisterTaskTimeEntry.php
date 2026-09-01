<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Task\Support\TaskSettings;
use App\Modules\Ticket\Actions\RegisterTicketTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterTaskTimeEntry
{
    public function __construct(
        private readonly RegisterTicketTimeEntry $registerTicketTimeEntry,
        private readonly TaskSettings $taskSettings,
    ) {}

    /**
     * Register actual Task time while preserving Ticket as the billing source of truth.
     */
    public function handle(Task $task, User $user, array $data, ?array $rateOption = null): TaskTimeEntry
    {
        if ($task->completed_at) {
            throw ValidationException::withMessages([
                'time_entry' => 'Time cannot be registered on a completed task.',
            ]);
        }

        return DB::transaction(function () use ($task, $user, $data, $rateOption) {
            $task = Task::query()->with('owner')->lockForUpdate()->findOrFail($task->id);
            $sourceType = 'manual';
            $billable = false;
            $taskNote = $data['note'] ?? null;
            $ticketEntry = null;
            $billableDelta = 0;

            if ($task->owner instanceof Ticket) {
                if (! $rateOption) {
                    throw ValidationException::withMessages([
                        'time_rate_key' => 'Select an available time rate for this ticket task.',
                    ]);
                }

                $sourceType = 'ticket_time_entry';
                $billable = true;
            }

            $entry = TaskTimeEntry::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'source_type' => $sourceType,
                'work_date' => $data['work_date'],
                'minutes' => $data['minutes'],
                'billable' => $billable,
                'note' => $taskNote,
            ]);

            if ($task->owner instanceof Ticket) {
                $actualTotal = (int) TaskTimeEntry::query()
                    ->where('task_id', $task->id)
                    ->sum('minutes');
                $desiredBillableTotal = $this->taskSettings->ticketBillingMinutes($task, $actualTotal);
                $existingBillableTotal = (int) TicketTimeEntry::query()
                    ->where('ticket_id', $task->owner->id)
                    ->where('task_id', $task->id)
                    ->sum('minutes');
                $billableDelta = max(0, $desiredBillableTotal - $existingBillableTotal);

                if ($billableDelta > 0) {
                    $ticketEntry = $this->registerTicketTimeEntry->handle($task->owner, [
                        'work_date' => $data['work_date'],
                        'minutes' => $billableDelta,
                        'invoice_text' => $data['invoice_text'],
                        'note' => $data['note'] ?? null,
                        'type' => 'task_billing',
                        'task_id' => $task->id,
                    ], $rateOption, $user);
                }
            }

            TaskActivity::query()->create([
                'task_id' => $task->id,
                'user_id' => $user->id,
                'type' => 'time_entry_added',
                'visibility' => Task::VISIBILITY_INTERNAL,
                'body' => $data['minutes'].' minutes registered.',
                'changes' => [
                    'time_entry_id' => $entry->id,
                    'minutes' => (int) $entry->minutes,
                    'source_type' => $entry->source_type,
                    'ticket_time_entry_id' => $ticketEntry?->id,
                    'billable_minutes_added' => $billableDelta,
                ],
            ]);

            return $entry;
        });
    }
}
