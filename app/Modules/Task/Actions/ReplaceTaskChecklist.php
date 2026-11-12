<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Task\Models\Task;
use Illuminate\Support\Facades\DB;

/** Preserve checked state and attribution when an edit resubmits identical checklist text. */
final class ReplaceTaskChecklist
{
    public function handle(Task $task, array $items, ?User $actor): void
    {
        DB::transaction(function () use ($task, $items, $actor): void {
            $normalize = static fn ($item): array => [
                'title' => $item['title'],
                'description' => $item['description'] ?? null,
                'sort_order' => (int) ($item['sort_order'] ?? 0),
            ];
            $before = $task->checklistItems()->get()->map(fn ($item) => $normalize($item->toArray()))->all();
            $after = array_map($normalize, $items);
            if ($before === $after) {
                return;
            }
            $task->checklistItems()->delete();
            foreach ($items as $item) {
                $task->checklistItems()->create($item);
            }
            $task->recordRelatedMutation($actor);
            $task->unsetRelation('checklistItems');
        });
    }
}
