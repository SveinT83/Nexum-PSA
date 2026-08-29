<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Fail closed when immutable rule evidence references a Custom Field that the
 * current operator cannot inspect. This prevents branch and outcome inference.
 */
final class TicketRuleEvidenceAccess
{
    private const OUTCOME_INDEX_VERSION_SCAN_LIMIT = 500;

    private const FULL_RERUN_DENIED = 'Full rerun is unavailable because restricted evidence or action authority prevents the operator from inspecting every decision target and holding every required action permission, including Custom Field edit authority.';

    /** @var array<string, bool> */
    private array $historyVisibilityCache = [];

    /** @var array<string, bool> */
    private array $outcomeIndexVisibilityCache = [];

    public function __construct(
        private readonly TicketCustomFieldTargetValidator $customFields,
        private readonly TicketRuleActionProviderRegistry $actions,
    ) {}

    public function runIsRestricted(TicketRuleRun $run, ?User $viewer): bool
    {
        foreach ($run->executions as $execution) {
            $version = $execution->version;
            if (! $version instanceof TicketRuleVersion
                || ! $this->canInspectHistoricalVersion($version, $viewer)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Outcome filters and ordering are themselves an inference channel. Enable
     * them only when every bounded historical version is inspectable.
     */
    public function canUseOutcomeIndexControls(?User $viewer): bool
    {
        if ($viewer === null) {
            return false;
        }

        $cacheKey = (string) ($viewer->getKey() ?: 'none');
        if (array_key_exists($cacheKey, $this->outcomeIndexVisibilityCache)) {
            return $this->outcomeIndexVisibilityCache[$cacheKey];
        }

        $versions = TicketRuleVersion::query()
            ->whereHas('executions')
            ->orderBy('id')
            ->limit(self::OUTCOME_INDEX_VERSION_SCAN_LIMIT + 1)
            ->get(['id', 'definition_checksum', 'definition_json']);
        if ($versions->count() > self::OUTCOME_INDEX_VERSION_SCAN_LIMIT) {
            return $this->outcomeIndexVisibilityCache[$cacheKey] = false;
        }

        return $this->outcomeIndexVisibilityCache[$cacheKey] = $versions
            ->every(fn (TicketRuleVersion $version): bool => $this->canInspectHistoricalVersion($version, $viewer));
    }

    public function denyFullRerun(): never
    {
        throw new RuntimeException(self::FULL_RERUN_DENIED);
    }

    /**
     * A receipt is issued only when the operator can inspect every immutable
     * decision target, hold each action provider's runtime permission, and view
     * plus edit each Custom Field action target in the frozen published set.
     *
     * @param  array<string, mixed>  $preview
     */
    public function assertFullRerunAccess(array $preview, User $operator): void
    {
        if (max(0, (int) ($preview['published_version_ids_omitted_count'] ?? 0)) > 0) {
            throw new RuntimeException(self::FULL_RERUN_DENIED);
        }

        $versionIds = collect((array) ($preview['published_version_ids'] ?? []))
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();
        $versions = $versionIds->isEmpty()
            ? collect()
            : TicketRuleVersion::query()
                ->whereIn('id', $versionIds)
                ->get(['id', 'definition_json']);

        if ($versions->count() !== $versionIds->count()) {
            throw new RuntimeException(self::FULL_RERUN_DENIED);
        }

        foreach ($versions as $version) {
            $definition = is_array($version->definition_json) ? $version->definition_json : null;
            if ($definition === null) {
                throw new RuntimeException(self::FULL_RERUN_DENIED);
            }

            $decision = [
                'trigger' => $definition['trigger'] ?? null,
                'trigger_filters' => $definition['trigger_filters'] ?? [],
                'conditions' => $definition['conditions'] ?? [],
            ];
            if (! $this->canInspectDecision($decision, $operator)
                || ! $this->canEditActionTargets($definition, $operator)) {
                throw new RuntimeException(self::FULL_RERUN_DENIED);
            }
        }
    }

    private function canInspectHistoricalVersion(
        TicketRuleVersion $version,
        ?User $viewer,
    ): bool {
        $viewerKey = $viewer?->getKey();
        $cacheKey = (string) ($viewerKey ?: 'none')
            .':'.(int) $version->id
            .':'.(string) $version->definition_checksum;
        if (array_key_exists($cacheKey, $this->historyVisibilityCache)) {
            return $this->historyVisibilityCache[$cacheKey];
        }

        $definition = is_array($version->definition_json) ? $version->definition_json : null;
        if ($definition === null) {
            return $this->historyVisibilityCache[$cacheKey] = false;
        }

        $targets = $this->targetsIn($definition);
        if ($this->containsCustomFieldReference($definition) && $targets->isEmpty()) {
            return $this->historyVisibilityCache[$cacheKey] = false;
        }
        if ($targets->isEmpty()) {
            return $this->historyVisibilityCache[$cacheKey] = true;
        }
        if ($viewer === null) {
            return $this->historyVisibilityCache[$cacheKey] = false;
        }

        return $this->historyVisibilityCache[$cacheKey] = $targets
            ->every(fn (array $target): bool => $this->customFields->canViewDefinitionId(
                $target['definition_id'] ?? null,
                $viewer,
            ));
    }

    /** @param array<string, mixed> $decision */
    private function canInspectDecision(array $decision, User $operator): bool
    {
        $targets = $this->targetsIn($decision);
        if ($this->containsCustomFieldReference($decision) && $targets->isEmpty()) {
            return false;
        }

        return $targets->every(fn (array $target): bool => (
            $this->customFields->resolve($target, 'view', $operator)['valid'] ?? false
        ) === true);
    }

    /** @param array<string, mixed> $definition */
    private function canEditActionTargets(array $definition, User $operator): bool
    {
        foreach (['then_actions', 'else_actions'] as $branch) {
            foreach ((array) ($definition[$branch] ?? []) as $action) {
                if (! is_array($action)) {
                    return false;
                }

                $type = $action['type'] ?? null;
                $permission = $this->actionPermission($type);
                if ($permission === null || ! $operator->can($permission)) {
                    return false;
                }

                if (! in_array($type, [
                    TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                    TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
                ], true)) {
                    continue;
                }

                $target = data_get($action, 'input.target');
                if (($this->customFields->resolve($target, 'view', $operator)['valid'] ?? false) !== true
                    || ($this->customFields->resolve($target, 'edit', $operator)['valid'] ?? false) !== true) {
                    return false;
                }
            }
        }

        return true;
    }

    private function actionPermission(mixed $type): ?string
    {
        $provider = $this->actions->definition($type);
        $permission = $provider['runtime_permission'] ?? null;
        if (is_string($permission) && $permission !== '') {
            return $permission;
        }

        return in_array($type, [
            'set_ticket_type',
            'set_priority',
            'set_sla',
            'set_category',
            'add_tag',
        ], true) ? 'ticket.update' : null;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function targetsIn(mixed $node): Collection
    {
        $targets = collect();
        $visit = function (mixed $value) use (&$visit, $targets): void {
            if (! is_array($value)) {
                return;
            }
            if (! array_is_list($value) && array_key_exists('definition_id', $value)) {
                $targets->push($value);
            }
            foreach ($value as $child) {
                $visit($child);
            }
        };
        $visit($node);

        return $targets
            ->filter(fn (mixed $target): bool => is_array($target))
            ->unique(fn (array $target): string => json_encode($target) ?: '')
            ->values();
    }

    private function containsCustomFieldReference(mixed $node): bool
    {
        if (is_string($node)) {
            return str_starts_with($node, 'custom_field.')
                || in_array($node, [
                    'ticket.custom_fields_changed',
                    TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                    TicketRuleActionProviderRegistry::CLEAR_CUSTOM_FIELD,
                ], true);
        }
        if (! is_array($node)) {
            return false;
        }

        foreach ($node as $value) {
            if ($this->containsCustomFieldReference($value)) {
                return true;
            }
        }

        return false;
    }
}
