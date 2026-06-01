<?php

namespace App\Modules\Task\Actions;

use App\Modules\Task\Models\TaskStatus;

class EnsureTaskDefaults
{
    /**
     * Keep required task statuses available without making labels hardcoded.
     */
    public function handle(): void
    {
        foreach ($this->statuses() as $status) {
            $existing = TaskStatus::query()->where('slug', $status['slug'])->first();

            if ($existing) {
                $existing->forceFill(collect($status)->except('is_default')->all())->save();
                continue;
            }

            TaskStatus::query()->create($status);
        }

        if (! TaskStatus::query()->default()->exists()) {
            $openStatus = TaskStatus::query()
                ->where('slug', 'open')
                ->first();

            if ($openStatus) {
                $openStatus->forceFill(['is_default' => true])->save();
            }
        }
    }

    private function statuses(): array
    {
        return [
            [
                'name' => 'Open',
                'slug' => 'open',
                'description' => 'Task is waiting to be started.',
                'is_default' => true,
                'is_active' => true,
                'is_open' => true,
                'sort_order' => 10,
            ],
            [
                'name' => 'In Progress',
                'slug' => 'in-progress',
                'description' => 'Task is currently being worked on.',
                'is_active' => true,
                'is_in_progress' => true,
                'sort_order' => 20,
            ],
            [
                'name' => 'Blocked',
                'slug' => 'blocked',
                'description' => 'Task cannot continue before another task or condition is completed.',
                'is_active' => true,
                'is_blocked' => true,
                'sort_order' => 30,
            ],
            [
                'name' => 'Done',
                'slug' => 'done',
                'description' => 'Task is completed.',
                'is_active' => true,
                'is_done' => true,
                'sort_order' => 90,
            ],
            [
                'name' => 'Cancelled',
                'slug' => 'cancelled',
                'description' => 'Task was cancelled and should not be completed.',
                'is_active' => true,
                'is_cancelled' => true,
                'sort_order' => 100,
            ],
        ];
    }
}
