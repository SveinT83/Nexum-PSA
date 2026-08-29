<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

final class BackfillTicketRuleCompatibilityVersions
{
    public function __construct(
        private readonly TicketRuleCatalogFingerprint $fingerprint,
        private readonly TicketRuleDefinitionCanonicalizer $canonicalizer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(
        int $expectedGeneration,
        string $expectedChecksum,
        ?string $provenanceKey = null,
    ): array {
        $expectedChecksum = strtolower(trim($expectedChecksum));
        $provenanceKey = filled($provenanceKey) ? trim((string) $provenanceKey) : null;

        if (! preg_match('/\A[a-f0-9]{64}\z/', $expectedChecksum)) {
            throw new InvalidArgumentException('Expected checksum must be a lowercase SHA-256 value.');
        }
        if ($expectedGeneration < 0) {
            throw new InvalidArgumentException('Expected generation cannot be negative.');
        }
        if ($provenanceKey !== null
            && ! preg_match('/\A[a-zA-Z0-9._:@\/-]{1,120}\z/', $provenanceKey)) {
            throw new InvalidArgumentException('Provenance key contains unsupported characters.');
        }
        if (! Schema::hasTable('ticket_rule_versions')
            || ! Schema::hasTable('ticket_rule_authority_fences')) {
            throw new RuntimeException('Ticket Rule compatibility schema is not installed.');
        }

        return DB::transaction(function () use (
            $expectedGeneration,
            $expectedChecksum,
            $provenanceKey,
        ): array {
            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fence->runtime_authority !== TicketRuleAuthorityFence::AUTHORITY_LEGACY) {
                throw new RuntimeException('Compatibility backfill requires legacy Ticket Rule runtime authority.');
            }

            $catalogChecksum = $this->fingerprint->checksum();
            if ((int) $fence->catalog_generation !== $expectedGeneration
                || ! hash_equals($expectedChecksum, $catalogChecksum)
                || ! hash_equals((string) $fence->catalog_checksum, $catalogChecksum)) {
                throw new RuntimeException(
                    'Ticket Rule catalogue changed after preflight; run the read-only preflight again.',
                );
            }

            $batchUuid = (string) Str::uuid();
            $recordedAt = now();
            $counts = [
                'total' => 0,
                'created' => 0,
                'skipped' => 0,
                'valid' => 0,
                'invalid' => 0,
                'ambiguous' => 0,
                'drifted' => 0,
                'deleted' => 0,
            ];

            $rules = TicketRule::withTrashed()
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($rules as $rule) {
                $counts['total']++;
                if ($rule->trashed()) {
                    $counts['deleted']++;
                }

                $result = $this->canonicalizer->inspect($rule);
                if ($result['status'] !== TicketRuleDefinitionCanonicalizer::STATUS_VALID) {
                    $compatibilityStatus = $result['status'] === TicketRuleDefinitionCanonicalizer::STATUS_INVALID
                        ? TicketRule::COMPATIBILITY_INVALID
                        : TicketRule::COMPATIBILITY_AMBIGUOUS;
                    $counts[$compatibilityStatus]++;

                    $this->updateRuleMetadata($rule, [
                        'lifecycle_status' => $rule->trashed()
                            ? TicketRule::LIFECYCLE_DELETED
                            : TicketRule::LIFECYCLE_LEGACY,
                        'definition_schema_version' => 1,
                        'definition_checksum' => null,
                        'compatibility_status' => $compatibilityStatus,
                        'compatibility_reason_code' => $result['reason_code'],
                        'compatibility_checked_at' => $recordedAt,
                    ]);

                    continue;
                }

                $counts['valid']++;
                $version = TicketRuleVersion::query()
                    ->where('ticket_rule_id', $rule->id)
                    ->where('version_number', 1)
                    ->lockForUpdate()
                    ->first();

                if ($version && ! hash_equals(
                    (string) $version->definition_checksum,
                    (string) $result['checksum'],
                )) {
                    $counts['drifted']++;
                    $this->updateRuleMetadata($rule, [
                        'lifecycle_status' => $rule->trashed()
                            ? TicketRule::LIFECYCLE_DELETED
                            : TicketRule::LIFECYCLE_LEGACY,
                        'definition_schema_version' => 1,
                        'definition_checksum' => $result['checksum'],
                        'compatibility_status' => TicketRule::COMPATIBILITY_DRIFTED,
                        'compatibility_reason_code' => 'definition_checksum_mismatch',
                        'compatibility_checked_at' => $recordedAt,
                    ]);

                    continue;
                }

                if (! $version) {
                    $version = TicketRuleVersion::query()->create([
                        'ticket_rule_id' => $rule->id,
                        'version_number' => 1,
                        'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
                        'definition_schema_version' => $result['definition']['schema_version'],
                        'trigger_key' => $result['definition']['trigger'],
                        'weight' => $result['definition']['order']['weight'],
                        'stop_processing' => $result['definition']['flow']['stop_processing'],
                        'name' => $rule->name,
                        'description' => $rule->description,
                        'definition_json' => $result['definition'],
                        'definition_checksum' => $result['checksum'],
                        'source_is_active' => $rule->is_active,
                        'source_trigger' => $rule->trigger,
                        'source_hit_count' => $rule->hit_count,
                        'source_last_hit_at' => $rule->getRawOriginal('last_hit_at'),
                        'source_created_by' => $rule->created_by,
                        'source_updated_by' => $rule->updated_by,
                        'source_created_at' => $rule->getRawOriginal('created_at'),
                        'source_updated_at' => $rule->getRawOriginal('updated_at'),
                        'source_deleted_at' => $rule->getRawOriginal('deleted_at'),
                        'published_by' => null,
                        'published_at' => null,
                        'provenance' => TicketRuleVersion::PROVENANCE_LEGACY_BACKFILL,
                        'provenance_batch_uuid' => $batchUuid,
                        'provenance_key' => $provenanceKey,
                        'provenance_recorded_at' => $recordedAt,
                    ]);
                    $counts['created']++;
                } else {
                    $counts['skipped']++;
                }

                $this->updateRuleMetadata($rule, [
                    'lifecycle_status' => $rule->trashed()
                        ? TicketRule::LIFECYCLE_DELETED
                        : ($rule->is_active
                            ? TicketRule::LIFECYCLE_PUBLISHED
                            : TicketRule::LIFECYCLE_DISABLED),
                    'published_version_id' => $version->id,
                    'published_by' => null,
                    'published_at' => null,
                    'definition_schema_version' => 1,
                    'definition_checksum' => $result['checksum'],
                    'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
                    'compatibility_reason_code' => null,
                    'compatibility_checked_at' => $recordedAt,
                ]);
            }

            if (! hash_equals($catalogChecksum, $this->fingerprint->checksum())) {
                throw new RuntimeException('Ticket Rule catalogue changed while compatibility versions were being recorded.');
            }

            return [
                'status' => 'complete',
                'runtime_authority' => $fence->runtime_authority,
                'catalog_generation' => (int) $fence->catalog_generation,
                'catalog_checksum' => $catalogChecksum,
                'provenance_batch_uuid' => $batchUuid,
                'provenance_key' => $provenanceKey,
                'counts' => $counts,
            ];
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function updateRuleMetadata(TicketRule $rule, array $metadata): void
    {
        // Query Builder intentionally avoids changing legacy updated_at evidence.
        DB::table('ticket_rules')->where('id', $rule->id)->update($metadata);
        $rule->forceFill($metadata);
    }
}
