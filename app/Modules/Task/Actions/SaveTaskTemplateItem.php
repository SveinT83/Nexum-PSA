<?php

namespace App\Modules\Task\Actions;

use App\Modules\Task\Models\TaskTemplateGroup;
use App\Modules\Task\Models\TaskTemplateItem;
use App\Modules\Task\Services\TaskTemplateForm;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Validate/save the complete graph edit atomically, as the browser editor already does. */
class SaveTaskTemplateItem
{
    public function handle(TaskTemplateGroup $template, Request $request, ?TaskTemplateItem $item = null): TaskTemplateItem
    {
        if ($item) {
            abort_unless((int) $item->template_group_id === (int) $template->id, 404);
        }

        return DB::transaction(function () use ($template, $request, $item) {
            $template = TaskTemplateGroup::query()->lockForUpdate()->findOrFail($template->id);
            if ($item) {
                $item = TaskTemplateItem::query()->where('template_group_id', $template->id)->lockForUpdate()->findOrFail($item->id);
            }
            $before = $item?->load(['checklistItems', 'dependencies', 'tags'])->toArray() ?? [];
            $form = app(TaskTemplateForm::class);
            $data = $form->itemData($request, $template, $item);
            if ($item) {
                $item->update($data);
            } else {
                $item = $template->allItems()->create($data);
            }
            $form->syncItemDetails($item, $request, $template);
            app(ValidateTaskTemplateGraph::class)->handle($template->allItems()->with('dependencies')->get());

            $item->refresh()->load(['checklistItems', 'dependencies', 'tags']);
            app(\App\Modules\Integration\Support\DomainApiAudit::class)->record($request->user(), 'task', 'task.template.item.saved', $item, $before, $item->toArray(), ['template_group_id' => $template->id]);

            return $item;
        });
    }
}
