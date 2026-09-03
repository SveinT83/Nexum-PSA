<?php

namespace App\Modules\Email\Services;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailRule;
use App\Modules\Email\Models\EmailRuleDraft;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EmailRuleDraftService
{
    /** @param array<string, mixed> $payload */
    public function save(
        EmailRule $rule,
        array $payload,
        User $actor,
        ?int $expectedLockVersion = null,
    ): EmailRuleDraft {
        if (! $actor->isActive() || ! $actor->can('email.rule_manage')) {
            throw ValidationException::withMessages(['draft' => 'Email rule management permission is required.']);
        }
        $payload = $this->canonicalPayload($payload);
        $checksum = $this->checksum($payload);

        return DB::transaction(function () use ($rule, $payload, $actor, $expectedLockVersion, $checksum): EmailRuleDraft {
            $rule = EmailRule::query()->lockForUpdate()->findOrFail($rule->id);
            $draft = EmailRuleDraft::query()
                ->where('email_rule_id', $rule->id)
                ->lockForUpdate()
                ->first();

            if ($draft && $expectedLockVersion !== null && $draft->lock_version !== $expectedLockVersion) {
                throw ValidationException::withMessages([
                    'draft' => 'This Email rule draft changed in another session. Reload before saving.',
                ]);
            }

            if ($draft && hash_equals($draft->checksum, $checksum)) {
                return $draft;
            }

            if (! $draft) {
                return EmailRuleDraft::query()->create([
                    'email_rule_id' => $rule->id,
                    'base_email_rule_version_id' => $rule->published_version_id,
                    'lock_version' => 1,
                    'payload_json' => $payload,
                    'checksum' => $checksum,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);
            }

            $draft->forceFill([
                'lock_version' => $draft->lock_version + 1,
                'payload_json' => $payload,
                'checksum' => $checksum,
                'updated_by' => $actor->id,
            ])->save();

            return $draft->refresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function publicationPreview(EmailRule $rule): array
    {
        $rule->loadMissing(['draft', 'publishedVersion']);
        $draft = $rule->draft;
        if (! $draft) {
            throw ValidationException::withMessages(['draft' => 'There is no saved draft to publish.']);
        }

        $published = $rule->publishedVersion;
        $current = $published ? [
            'name' => $published->name,
            'description' => $published->description,
            'weight' => $published->weight,
            'routing_phase' => $published->routing_phase,
            'is_active' => $published->is_active,
            'stop_processing' => $published->stop_processing,
            'conditions_json' => $published->conditions_json,
            'actions_json' => $published->actions_json,
            'account_ids' => $published->account_ids_json,
        ] : null;
        $next = $draft->payload_json;

        return [
            'rule_id' => $rule->id,
            'base_version_id' => $draft->base_email_rule_version_id,
            'base_version_number' => $published?->version_number,
            'draft_checksum' => $draft->checksum,
            'lock_version' => $draft->lock_version,
            'changed_fields' => collect(array_keys($next))
                ->filter(fn (string $key): bool => $current === null || ($current[$key] ?? null) !== ($next[$key] ?? null))
                ->values()
                ->all(),
            'account_ids' => $next['account_ids'],
            'actions' => collect($next['actions_json'])->values()->map(
                fn (array $action, int $position): array => [
                    'position' => $position,
                    'type' => (string) ($action['type'] ?? 'unknown'),
                    'provider_mutation' => in_array($action['type'] ?? '', ['provider_archive', 'provider_move'], true),
                    'reversible' => in_array($action['type'] ?? '', ['provider_archive', 'provider_move'], true),
                ],
            )->all(),
        ];
    }

    /** @param array<string, mixed> $payload */
    private function canonicalPayload(array $payload): array
    {
        $required = [
            'name', 'description', 'weight', 'routing_phase', 'is_active', 'stop_processing',
            'conditions_json', 'actions_json', 'account_ids',
        ];
        if (array_diff($required, array_keys($payload)) !== []) {
            throw ValidationException::withMessages(['draft' => 'The Email rule draft is incomplete.']);
        }

        $payload['account_ids'] = collect($payload['account_ids'])->map(fn ($id): int => (int) $id)->unique()->sort()->values()->all();

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function checksum(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
