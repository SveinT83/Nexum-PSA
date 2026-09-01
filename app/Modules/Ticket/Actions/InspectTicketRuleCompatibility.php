<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRuleCompatibilityTargetValidator;
use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use Illuminate\Support\Facades\Schema;

final class InspectTicketRuleCompatibility
{
    public function __construct(
        private readonly TicketRuleCatalogFingerprint $fingerprint,
        private readonly TicketRuleDefinitionCanonicalizer $canonicalizer,
        private readonly TicketRuleCompatibilityTargetValidator $targetValidator,
    ) {}

    /**
     * Return bounded, definition-free compatibility evidence without writes.
     *
     * @return array<string, mixed>
     */
    public function handle(int $detailLimit = 100): array
    {
        $detailLimit = max(1, min(500, $detailLimit));

        if (! Schema::hasTable('ticket_rule_authority_fences')
            || ! Schema::hasTable('ticket_rule_versions')
            || ! Schema::hasColumn('ticket_rules', 'compatibility_status')) {
            return [
                'status' => 'not_installed',
                'message' => 'Ticket Rule compatibility schema is not installed.',
            ];
        }

        $fence = TicketRuleAuthorityFence::query()->find(TicketRuleAuthorityFence::SCOPE);
        if (! $fence) {
            return [
                'status' => 'not_installed',
                'message' => 'Ticket Rule authority fence is missing.',
            ];
        }

        $counts = [
            'total' => 0,
            'valid' => 0,
            'invalid' => 0,
            'ambiguous' => 0,
            'eligible' => 0,
            'already_versioned' => 0,
            'unversioned' => 0,
            'drifted' => 0,
            'skipped' => 0,
            'deleted' => 0,
        ];
        $details = [];
        $mappingComplete = true;

        TicketRule::withTrashed()
            ->orderBy('id')
            ->get()
            ->each(function (TicketRule $rule) use (&$counts, &$details, &$mappingComplete, $detailLimit): void {
                $counts['total']++;
                if ($rule->trashed()) {
                    $counts['deleted']++;
                }

                $result = $this->canonicalizer->inspect($rule);
                $status = $result['status'];
                $reasonCode = $result['reason_code'];
                if ($status === TicketRuleDefinitionCanonicalizer::STATUS_VALID
                    && ($targetFailure = $this->actionTargetFailure((array) $result['definition'])) !== null) {
                    $status = TicketRuleDefinitionCanonicalizer::STATUS_INVALID;
                    $reasonCode = $targetFailure;
                }

                $version = TicketRuleVersion::query()
                    ->where('ticket_rule_id', $rule->id)
                    ->where('version_number', 1)
                    ->first();

                if ($status === TicketRuleDefinitionCanonicalizer::STATUS_INVALID) {
                    $counts['invalid']++;
                    if (! $rule->trashed()) {
                        $mappingComplete = false;
                    }
                } elseif ($status === TicketRuleDefinitionCanonicalizer::STATUS_AMBIGUOUS) {
                    $counts['ambiguous']++;
                    if (! $rule->trashed()) {
                        $mappingComplete = false;
                    }
                } else {
                    $counts['valid']++;

                    if (! $version) {
                        $status = TicketRule::COMPATIBILITY_UNVERSIONED;
                        $counts['unversioned']++;
                        if (! $rule->trashed()) {
                            $mappingComplete = false;
                        }
                    } else {
                        $counts['already_versioned']++;
                        if (! hash_equals((string) $version->definition_checksum, (string) $result['checksum'])) {
                            $status = TicketRule::COMPATIBILITY_DRIFTED;
                            $reasonCode = 'definition_checksum_mismatch';
                            $counts['drifted']++;
                            if (! $rule->trashed()) {
                                $mappingComplete = false;
                            }
                        } else {
                            $status = TicketRule::COMPATIBILITY_ELIGIBLE;
                            $counts['eligible']++;
                            $counts['skipped']++;

                            if (! $rule->trashed()
                                && ((int) $rule->published_version_id !== (int) $version->id
                                    || $rule->compatibility_status !== TicketRule::COMPATIBILITY_ELIGIBLE)) {
                                $mappingComplete = false;
                            }
                        }
                    }
                }

                if (count($details) < $detailLimit
                    && ($status !== TicketRule::COMPATIBILITY_ELIGIBLE || $rule->trashed())) {
                    $details[] = [
                        'rule_id' => (int) $rule->id,
                        'status' => $status,
                        'reason_code' => $reasonCode,
                        'deleted' => $rule->trashed(),
                    ];
                }
            });

        $catalogChecksum = $this->fingerprint->checksum();
        $fenceMatches = hash_equals((string) $fence->catalog_checksum, $catalogChecksum);
        $blocked = ! $fenceMatches
            || $counts['invalid'] > 0
            || $counts['ambiguous'] > 0
            || $counts['drifted'] > 0;

        return [
            'status' => $blocked
                ? 'blocked'
                : ($mappingComplete ? 'compatible' : 'ready_for_backfill'),
            'runtime_authority' => $fence->runtime_authority,
            'catalog_generation' => (int) $fence->catalog_generation,
            'catalog_checksum' => $catalogChecksum,
            'stored_catalog_checksum' => $fence->catalog_checksum,
            'fence_matches_catalog' => $fenceMatches,
            'mapping_complete' => $mappingComplete,
            'counts' => $counts,
            'details' => $details,
            'details_truncated' => count($details) >= $detailLimit
                && ($counts['total'] - $counts['eligible']) > $detailLimit,
        ];
    }

    /** @param array<string, mixed> $definition */
    private function actionTargetFailure(array $definition): ?string
    {
        $actions = array_merge(
            (array) ($definition['then_actions'] ?? []),
            (array) ($definition['else_actions'] ?? []),
        );

        foreach ($actions as $action) {
            $failure = $this->targetValidator->failureCode(is_array($action) ? $action : []);
            if ($failure !== null) {
                return $failure;
            }
        }

        return null;
    }
}
