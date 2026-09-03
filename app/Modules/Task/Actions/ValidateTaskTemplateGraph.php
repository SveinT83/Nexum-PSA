<?php

namespace App\Modules\Task\Actions;

use App\Modules\Task\Models\TaskDependency;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class ValidateTaskTemplateGraph
{
    public function handle(Collection $items): void
    {
        if ($items->isEmpty()) {
            throw ValidationException::withMessages(['template' => 'Add at least one Task to the template.']);
        }

        $ids = $items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $allowed = array_fill_keys($ids, true);
        $edges = array_fill_keys($ids, []);

        foreach ($items as $item) {
            $itemId = (int) $item->id;
            if ($item->parent_id) {
                $this->assertReference($allowed, (int) $item->parent_id, 'A parent Task belongs to another template.');
                $edges[$itemId][] = (int) $item->parent_id;
            }

            foreach ($item->dependencies as $dependency) {
                $target = (int) $dependency->depends_on_template_item_id;
                $this->assertReference($allowed, $target, 'A dependency belongs to another template.');
                if ($target === $itemId) {
                    throw ValidationException::withMessages(['dependencies' => 'A Task cannot depend on itself.']);
                }
                if (! in_array($dependency->dependency_type, [TaskDependency::TYPE_BLOCKS_START, TaskDependency::TYPE_BLOCKS_COMPLETION], true)) {
                    throw ValidationException::withMessages(['dependencies' => 'Select a supported dependency type.']);
                }
                $edges[$itemId][] = $target;
            }
        }

        $visiting = [];
        $visited = [];
        $visit = function (int $id) use (&$visit, &$visiting, &$visited, $edges): void {
            if (isset($visiting[$id])) {
                throw ValidationException::withMessages(['dependencies' => 'Task template relationships cannot contain a cycle.']);
            }
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($edges[$id] as $target) {
                $visit($target);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
        };

        foreach ($ids as $id) {
            $visit($id);
        }
    }

    private function assertReference(array $allowed, int $id, string $message): void
    {
        if (! isset($allowed[$id])) {
            throw ValidationException::withMessages(['template' => $message]);
        }
    }
}
