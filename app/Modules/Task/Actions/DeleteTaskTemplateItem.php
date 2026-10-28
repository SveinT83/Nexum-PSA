<?php

namespace App\Modules\Task\Actions;

use App\Modules\Task\Models\TaskTemplateDependency;
use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Keep parent and dependency references intact during a locked graph edit. */
class DeleteTaskTemplateItem
{
    public function handle(TaskTemplateGroup $template, TaskTemplateItem $item, \App\Models\Core\User $actor): void
    {
        abort_unless((int) $item->template_group_id === (int) $template->id, 404);
        DB::transaction(function () use ($template, $item, $actor) {
            TaskTemplateGroup::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->allItems()->where('parent_id', $item->id)->exists()
                || TaskTemplateDependency::query()->where('depends_on_template_item_id', $item->id)->exists()) {
                throw ValidationException::withMessages(['item' => 'Remove child and dependency references before deleting this Template Task.']);
            }
            $before = $item->attributesToArray();
            $item->delete();
            app(\App\Modules\Integration\Support\DomainApiAudit::class)->record($actor, 'task', 'task.template.item.deleted', $item, $before, [], ['template_group_id' => $template->id]);
        });
    }
}
