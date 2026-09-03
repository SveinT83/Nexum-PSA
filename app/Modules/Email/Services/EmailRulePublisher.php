<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleVersion;
use App\Modules\Taxonomy\Models\Tag;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EmailRulePublisher
{
    public function publishDraft(EmailRule $rule, User $actor, string $expectedChecksum): EmailRuleVersion
    {
        if (! $actor->isActive()
            || ! $actor->can('email.rule_manage')
            || ! $actor->can('email.rule_publish')) {
            throw ValidationException::withMessages(['draft' => 'Email rule publication permission is required.']);
        }

        return DB::transaction(function () use ($rule, $actor, $expectedChecksum): EmailRuleVersion {
            $rule = EmailRule::query()->with(['draft', 'accounts'])->lockForUpdate()->findOrFail($rule->id);
            $draft = $rule->draft;
            if (! $draft || ! hash_equals($draft->checksum, $expectedChecksum)) {
                throw ValidationException::withMessages([
                    'draft' => 'This Email rule draft changed. Reload the publication preview before publishing.',
                ]);
            }
            if ((int) ($draft->base_email_rule_version_id ?? 0) !== (int) ($rule->published_version_id ?? 0)) {
                throw ValidationException::withMessages([
                    'draft' => 'The published Email rule changed after this draft was started. Rebase the draft before publishing.',
                ]);
            }

            $payload = $draft->payload_json;
            $this->materializeTagTargets($payload['actions_json'] ?? []);
            $accountIds = collect($payload['account_ids'])->map(fn ($id): int => (int) $id)->sort()->values()->all();
            $rule->forceFill([
                'name' => $payload['name'],
                'description' => $payload['description'],
                'weight' => $payload['weight'],
                'routing_phase' => $payload['routing_phase'],
                'is_active' => $payload['is_active'],
                'stop_processing' => $payload['stop_processing'],
                'conditions_json' => $payload['conditions_json'],
                'actions_json' => $payload['actions_json'],
                'updated_by' => $actor->id,
            ])->save();
            $rule->accounts()->sync($accountIds);

            $version = $this->publishLockedRule($rule->fresh(['accounts']), $actor);
            $draft->delete();

            return $version;
        }, 3);
    }

    public function publish(EmailRule $rule, ?User $actor = null): EmailRuleVersion
    {
        return DB::transaction(function () use ($rule, $actor): EmailRuleVersion {
            $rule = EmailRule::query()
                ->with('accounts')
                ->lockForUpdate()
                ->findOrFail($rule->id);

            return $this->publishLockedRule($rule, $actor);
        });
    }

    private function publishLockedRule(EmailRule $rule, ?User $actor): EmailRuleVersion
    {
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
    }

    /** @param array<int, array<string, mixed>> $actions */
    private function materializeTagTargets(array $actions): void
    {
        foreach ($actions as $action) {
            if (! in_array($action['type'] ?? '', ['tag', 'tag_message', 'tag_conversation'], true)) {
                continue;
            }

            $name = trim((string) ($action['value'] ?? ''));
            if ($name === '') {
                continue;
            }

            $slug = Str::slug($name);
            $tag = Tag::withTrashed()
                ->where(fn ($tags) => $tags->where('name', $name)->orWhere('slug', $slug))
                ->first();
            if ($tag?->trashed() || ($tag && ! $tag->active)) {
                throw ValidationException::withMessages([
                    'actions' => 'Rule tag targets must be active Taxonomy tags.',
                ]);
            }

            if (! $tag) {
                Tag::create([
                    'name' => $name,
                    'slug' => $slug,
                    'color' => '#6c757d',
                    'active' => true,
                ]);
            }
        }
    }
}
