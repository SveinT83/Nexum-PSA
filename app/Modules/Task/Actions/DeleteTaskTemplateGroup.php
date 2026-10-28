<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Task\Models\TaskTemplateGroup;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Protect used/scheduled history and retain an atomic deletion receipt for an unused template. */
class DeleteTaskTemplateGroup
{
    public function handle(TaskTemplateGroup $template, User $actor): void
    {
        DB::transaction(function () use ($template, $actor) {
            $template = TaskTemplateGroup::query()->lockForUpdate()->findOrFail($template->id);
            if ($template->runs()->exists() || $template->recurringTemplates()->exists()) {
                throw ValidationException::withMessages(['template' => 'Deactivate a used or scheduled template instead of deleting it.']);
            }
            $before = $template->attributesToArray();
            $template->delete();
            app(DomainApiAudit::class)->record($actor, 'task', 'task.template.group.deleted', $template, $before, $template->attributesToArray(), ['template_group_id' => $template->id]);
        });
    }
}
