<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRulePublicationTargetValidator;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class PublishTicketRuleDraft
{
    public function __construct(
        private readonly TicketRulePublishedDefinitionValidator $validator,
        private readonly TicketRulePublicationTargetValidator $targets,
        private readonly TicketRuleActionProviderRegistry $providers,
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRuleCatalogFingerprint $fingerprint,
    ) {}

    public function handle(
        TicketRule $rule,
        User $publisher,
        string $expectedDraftChecksum,
    ): TicketRuleVersion {
        $this->authorizePublisher($publisher);

        return DB::transaction(function () use ($rule, $publisher, $expectedDraftChecksum): TicketRuleVersion {
            $actor = $this->runtimeGate->requireExistingActor();
            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->lockForUpdate()
                ->firstOrFail();
            $rule = TicketRule::query()->lockForUpdate()->findOrFail($rule->getKey());
            $payload = is_array($rule->draft_payload_json) ? $rule->draft_payload_json : null;

            if ($payload === null
                || blank($rule->draft_checksum)
                || ! hash_equals((string) $rule->draft_checksum, $expectedDraftChecksum)) {
                throw ValidationException::withMessages([
                    'draft' => 'This draft changed in another session. Reload before publishing.',
                ]);
            }

            $result = $this->validator->validateForPublication(
                is_array($payload['definition'] ?? null) ? $payload['definition'] : [],
            );
            if (($result['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                || ! ($result['publishable'] ?? false)) {
                throw ValidationException::withMessages([
                    'definition' => (string) ($result['message'] ?? $result['reason_code'] ?? 'The definition is not publishable.'),
                ]);
            }

            $definition = $result['definition'];
            $this->authorizeProviders($definition, $publisher, $actor);
            $this->targets->validate($definition);
            $this->targets->validateCustomFieldAccess($definition, $publisher);

            $beforeCatalog = $this->fingerprint->checksum();
            if (! hash_equals((string) $fence->catalog_checksum, $beforeCatalog)) {
                throw new RuntimeException('Ticket Rule catalog drift must be reconciled before publication.');
            }

            $versionNumber = (int) TicketRuleVersion::query()
                ->where('ticket_rule_id', $rule->id)
                ->max('version_number') + 1;
            $publishedAt = now();
            $version = TicketRuleVersion::query()->create([
                'ticket_rule_id' => $rule->id,
                'version_number' => $versionNumber,
                'status' => TicketRuleVersion::STATUS_PUBLISHED,
                'definition_schema_version' => (int) $result['schema_version'],
                'trigger_key' => (string) $definition['trigger'],
                'weight' => (int) data_get($definition, 'order.weight', 0),
                'stop_processing' => (bool) data_get($definition, 'flow.stop_processing', false),
                'name' => (string) $payload['name'],
                'description' => $payload['description'] ?? null,
                'definition_json' => $definition,
                'definition_checksum' => (string) $result['checksum'],
                'source_is_active' => (bool) $rule->is_active,
                'source_trigger' => (string) $rule->trigger,
                'source_hit_count' => (int) $rule->hit_count,
                'source_last_hit_at' => $rule->last_hit_at,
                'source_created_by' => $rule->created_by,
                'source_updated_by' => $rule->updated_by,
                'source_created_at' => $rule->created_at,
                'source_updated_at' => $rule->updated_at,
                'source_deleted_at' => null,
                'published_by' => $publisher->id,
                'published_at' => $publishedAt,
                'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
                'provenance_batch_uuid' => (string) Str::uuid(),
                'provenance_key' => 'admin-publish-'.$rule->id.'-'.$versionNumber,
                'provenance_recorded_at' => $publishedAt,
            ]);

            $rule->forceFill([
                'name' => (string) $payload['name'],
                'description' => $payload['description'] ?? null,
                'updated_by' => $publisher->id,
                'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
                'published_version_id' => $version->id,
                'published_by' => $publisher->id,
                'published_at' => $publishedAt,
                'definition_schema_version' => (int) $result['schema_version'],
                'definition_checksum' => (string) $result['checksum'],
                'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
                'compatibility_reason_code' => null,
                'compatibility_checked_at' => $publishedAt,
                'draft_payload_json' => null,
                'draft_checksum' => null,
                'draft_updated_by' => null,
                'draft_updated_at' => null,
            ])->save();

            $afterCatalog = $this->fingerprint->checksum();
            if (! hash_equals($beforeCatalog, $afterCatalog)) {
                $fence->forceFill([
                    'catalog_generation' => (int) $fence->catalog_generation + 1,
                    'catalog_checksum' => $afterCatalog,
                ])->save();
            }

            return $version->fresh();
        }, 3);
    }

    private function authorizePublisher(User $publisher): void
    {
        if (! $publisher->isActive()
            || ! $publisher->can('ticket.manage_rules')
            || ! $publisher->can('ticket.rule_publish')) {
            throw new RuntimeException('Ticket Rule publication permission is required.');
        }
        if (! (bool) config('ticket_rules.v2_enabled', false)) {
            throw new RuntimeException('Ticket Rule v2 capability is disabled.');
        }
    }

    /** @param array<string, mixed> $definition */
    private function authorizeProviders(array $definition, User $publisher, User $actor): void
    {
        foreach (array_merge(
            (array) ($definition['then_actions'] ?? []),
            (array) ($definition['else_actions'] ?? []),
        ) as $action) {
            $provider = $this->providers->definition($action['type'] ?? null);
            if ($provider === null
                || ! $publisher->can((string) $provider['publication_permission'])
                || ! $actor->can((string) $provider['runtime_permission'])) {
                throw ValidationException::withMessages([
                    'definition' => 'The publisher or protected runtime actor lacks an action permission.',
                ]);
            }
        }
    }
}
