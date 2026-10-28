<?php

namespace App\Modules\Task\Actions;

use App\Models\Core\User;
use App\Modules\Integration\Support\DomainApiAudit;
use App\Modules\Task\Models\TaskStatus;
use App\Modules\Task\Support\TaskSettings;
use Illuminate\Support\Facades\DB;

/** Task defaults, billing policy and the administrative receipt share one transaction. */
class UpdateTaskSettings
{
    public function handle(array $data, User $actor): array
    {
        return DB::transaction(function () use ($data, $actor) {
            $settings = app(TaskSettings::class);
            $before = $settings->get() + ['default_status_id' => TaskStatus::query()->default()->value('id')];
            $settings->updateDefaultStatus((int) $data['default_status_id']);
            $result = $settings->update(['default_priority_id' => $data['default_priority_id'] ?? null,
                'default_estimated_minutes' => $data['default_estimated_minutes'] ?? null, 'ticket_billing_minutes_mode' => $data['ticket_billing_minutes_mode']]);
            app(DomainApiAudit::class)->record($actor, 'task', 'task.settings.updated', null, $before, $result + ['default_status_id' => TaskStatus::query()->default()->value('id')]);

            return $result;
        });
    }
}
