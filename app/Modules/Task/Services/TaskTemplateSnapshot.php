<?php

namespace App\Modules\Task\Services;

use App\Modules\Task\Models\TaskTemplateGroup;

/** Fingerprints bind an API preview to the complete current graph without generating any Tasks. */
class TaskTemplateSnapshot
{
    public function fingerprint(TaskTemplateGroup $template): string
    {
        $items = $template->allItems()->with(['checklistItems', 'dependencies', 'tags'])->get();

        return hash('sha256', json_encode([
            'template' => $template->getAttributes(),
            'items' => $items->map(fn ($item) => [
                'item' => $item->getAttributes(), 'checklist' => $item->checklistItems->sortBy('id')->map->getAttributes()->values()->all(),
                'dependencies' => $item->dependencies->sortBy('id')->map->getAttributes()->values()->all(),
                'tags' => $item->tags->sortBy('id')->map->getAttributes()->values()->all(),
            ])->all(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
