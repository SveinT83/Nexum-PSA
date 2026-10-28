<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Task\Models\TaskTemplateGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Catalog mutation and its content-free administrative receipt commit together. */
class SaveTaskTemplateGroup
{
    public function handle(array $data, User $actor, ?TaskTemplateGroup $template = null): TaskTemplateGroup
    {
        return DB::transaction(function () use ($data, $actor, $template) {
            if ($template) {
                $template = TaskTemplateGroup::query()->lockForUpdate()->findOrFail($template->id);
            }
            $before = $template?->attributesToArray() ?? [];
            if (TaskTemplateGroup::withTrashed()->where('slug', $data['slug'])->when($template, fn ($q) => $q->where('id', '!=', $template->id))->exists()) {
                throw ValidationException::withMessages(['slug' => 'The normalized template slug is already in use.']);
            }
            if ($template) {
                $template->update($data);
            } else {
                $template = TaskTemplateGroup::query()->create($data);
            }
            app(DomainApiAudit::class)->record($actor, 'task', 'task.template.group.saved', $template, $before, $template->refresh()->attributesToArray(), ['template_group_id' => $template->id]);

            return $template;
        });
    }
}
