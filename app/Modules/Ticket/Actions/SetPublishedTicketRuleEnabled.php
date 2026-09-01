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
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Changes only the enabled state of a current immutable published definition.
 *
 * Schema 2 execution is meaningful only after the separately controlled v2
 * authority transition. This boundary never performs that transition and
 * refuses stale browser state before changing the catalog.
 */
final class SetPublishedTicketRuleEnabled
{
    public function __construct(
        private readonly TicketRulePublishedDefinitionValidator $validator,
        private readonly TicketRulePublicationTargetValidator $targets,
        private readonly TicketRuleCatalogFingerprint $fingerprint,
        private readonly TicketRuleActionProviderRegistry $providers,
        private readonly TicketRuleRuntimeGate $runtimeGate,
    ) {}

    public function handle(
        TicketRule $requestedRule,
        User $operator,
        int $expectedVersionId,
        string $expectedDefinitionChecksum,
        bool $expectedEnabled,
    ): TicketRule {
        if (! $operator->isActive()
            || ! $operator->can('ticket.manage_rules')
            || ! $operator->can('ticket.rule_publish')) {
            throw new RuntimeException('Ticket Rule publication permission is required.');
        }
        if (! (bool) config('ticket_rules.v2_enabled', false)) {
            throw new RuntimeException('Ticket Rule v2 capability is disabled.');
        }

        return DB::transaction(function () use (
            $requestedRule,
            $operator,
            $expectedVersionId,
            $expectedDefinitionChecksum,
            $expectedEnabled,
        ): TicketRule {
            $this->runtimeGate->assertMutationCapabilities(true);

            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fence->runtime_authority !== TicketRuleAuthorityFence::AUTHORITY_V2
                || ! $this->runtimeGate->enabled()) {
                throw new RuntimeException(
                    'Published Ticket Rules can be enabled or disabled only while v2 runtime authority is active.'
                );
            }

            $actor = $this->runtimeGate->requireExistingActor();
            $beforeChecksum = $this->fingerprint->checksum();
            if (! hash_equals((string) $fence->catalog_checksum, $beforeChecksum)) {
                throw ValidationException::withMessages([
                    'rule' => 'The Ticket Rule catalog changed. Reload before changing its enabled state.',
                ]);
            }

            $rule = TicketRule::query()
                ->with('publishedVersion')
                ->lockForUpdate()
                ->findOrFail($requestedRule->getKey());
            $version = $rule->publishedVersion;

            if (! $version
                || (int) $rule->published_version_id !== $expectedVersionId
                || (int) $version->id !== $expectedVersionId
                || $version->status !== TicketRuleVersion::STATUS_PUBLISHED
                || ! hash_equals((string) $version->definition_checksum, $expectedDefinitionChecksum)
                || ! hash_equals((string) $rule->definition_checksum, $expectedDefinitionChecksum)
                || (bool) $rule->is_active !== $expectedEnabled
                || (int) $rule->definition_schema_version !== (int) $version->definition_schema_version
                || (int) $version->definition_schema_version
                    !== TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION
                || $rule->compatibility_status !== TicketRule::COMPATIBILITY_ELIGIBLE) {
                throw ValidationException::withMessages([
                    'rule' => 'This published Ticket Rule changed. Reload before changing its enabled state.',
                ]);
            }

            $definition = is_array($version->definition_json) ? $version->definition_json : [];
            $validation = $this->validator->validateForPublication($definition);
            if (($validation['status'] ?? null) !== TicketRulePublishedDefinitionValidator::STATUS_VALID
                || ! ($validation['publishable'] ?? false)
                || ! hash_equals(
                    (string) $version->definition_checksum,
                    (string) ($validation['checksum'] ?? '')
                )) {
                throw ValidationException::withMessages([
                    'rule' => 'The immutable published definition is no longer eligible for activation.',
                ]);
            }

            $definition = $validation['definition'];
            $this->authorizeProviders($definition, $operator, $actor);
            $this->targets->validate($definition);
            $this->targets->validateCustomFieldAccess($definition, $operator);

            $enabled = ! $expectedEnabled;
            $rule->forceFill([
                'is_active' => $enabled,
                'lifecycle_status' => $enabled
                    ? TicketRule::LIFECYCLE_PUBLISHED
                    : TicketRule::LIFECYCLE_DISABLED,
            ])->save();

            $afterChecksum = $this->fingerprint->checksum();
            $fence->forceFill([
                'catalog_generation' => (int) $fence->catalog_generation + 1,
                'catalog_checksum' => $afterChecksum,
            ])->save();

            return $rule->fresh(['publishedVersion']);
        }, 3);
    }

    /** @param array<string, mixed> $definition */
    private function authorizeProviders(array $definition, User $operator, User $actor): void
    {
        foreach (array_merge(
            (array) ($definition['then_actions'] ?? []),
            (array) ($definition['else_actions'] ?? []),
        ) as $action) {
            $provider = $this->providers->definition($action['type'] ?? null);
            if ($provider === null
                || ! $operator->can((string) $provider['publication_permission'])
                || ! $actor->can((string) $provider['runtime_permission'])) {
                throw ValidationException::withMessages([
                    'rule' => 'The publisher or protected runtime actor lacks an action permission.',
                ]);
            }
        }
    }
}
