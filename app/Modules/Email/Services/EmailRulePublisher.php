<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleVersion;
use Illuminate\Support\Facades\DB;

class EmailRulePublisher
{
    public function publish(EmailRule $rule, ?User $actor = null): EmailRuleVersion
    {
        return DB::transaction(function () use ($rule, $actor): EmailRuleVersion {
            $rule = EmailRule::query()
                ->with('accounts')
                ->lockForUpdate()
                ->findOrFail($rule->id);

            $nextVersion = ((int) $rule->versions()->max('version_number')) + 1;
            $accountIds = $rule->accounts
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->sort()
                ->values()
                ->all();

            $snapshot = [
                'rule_id' => (int) $rule->id,
                'version_number' => $nextVersion,
                'trigger' => $rule->trigger,
                'routing_phase' => $rule->routing_phase,
                'rule_kind' => $rule->rule_kind ?? EmailRule::KIND_ADMIN,
                'owner_id' => $rule->owner_id ? (int) $rule->owner_id : null,
                'weight' => (int) $rule->weight,
                'is_active' => (bool) $rule->is_active,
                'stop_processing' => (bool) $rule->stop_processing,
                'conditions' => $rule->conditions_json ?? [],
                'actions' => $rule->actions_json ?? [],
                'account_ids' => $accountIds,
            ];

            $rule->versions()
                ->where('status', EmailRuleVersion::STATUS_PUBLISHED)
                ->update(['status' => EmailRuleVersion::STATUS_SUPERSEDED]);

            $version = $rule->versions()->create([
                'version_number' => $nextVersion,
                'status' => EmailRuleVersion::STATUS_PUBLISHED,
                'published_by' => $actor?->id,
                'published_at' => now(),
                'name' => $rule->name,
                'description' => $rule->description,
                'trigger' => $rule->trigger,
                'routing_phase' => $rule->routing_phase,
                'rule_kind' => $rule->rule_kind ?? EmailRule::KIND_ADMIN,
                'owner_id' => $rule->owner_id,
                'weight' => $rule->weight,
                'is_active' => $rule->is_active,
                'stop_processing' => $rule->stop_processing,
                'conditions_json' => $rule->conditions_json ?? [],
                'actions_json' => $rule->actions_json ?? [],
                'account_ids_json' => $accountIds,
                'snapshot_hash' => hash('sha256', json_encode($snapshot)),
            ]);

            $rule->forceFill([
                'lifecycle_status' => EmailRule::LIFECYCLE_PUBLISHED,
                'published_version_id' => $version->id,
                'published_by' => $actor?->id,
                'published_at' => $version->published_at,
            ])->save();

            return $version;
        });
    }
}
