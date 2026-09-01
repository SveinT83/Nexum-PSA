<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use App\Modules\Ticket\Services\TicketRuleDefinitionCanonicalizer;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class MutateLegacyTicketRuleCatalog
{
    /**
     * Immutable publication and draft ownership belongs to the v2 actions.
     * The legacy boundary must never accept those columns as mutation input.
     */
    private const GOVERNED_ATTRIBUTES = [
        'lifecycle_status',
        'published_version_id',
        'published_by',
        'published_at',
        'definition_schema_version',
        'definition_checksum',
        'compatibility_status',
        'compatibility_reason_code',
        'compatibility_checked_at',
        'draft_payload_json',
        'draft_checksum',
        'draft_updated_by',
        'draft_updated_at',
        'draft_creation_token',
    ];

    public function __construct(
        private readonly TicketRuleCatalogFingerprint $fingerprint,
        private readonly TicketRuleDefinitionCanonicalizer $canonicalizer,
    ) {}

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TicketRule
    {
        $this->assertLegacyAttributes($attributes);

        return $this->withinFence(function () use ($attributes): TicketRule {
            $rule = TicketRule::query()->create($attributes);
            $this->reconcileCompatibility($rule);

            return $rule->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(TicketRule $rule, array $attributes): TicketRule
    {
        $this->assertLegacyAttributes($attributes);

        return $this->withinFence(function () use ($rule, $attributes): TicketRule {
            $locked = TicketRule::query()->with('publishedVersion')->lockForUpdate()->findOrFail($rule->id);
            $this->assertLegacyMutable($locked);
            $locked->update($attributes);
            $this->reconcileCompatibility($locked);

            return $locked->refresh();
        });
    }

    /**
     * @param  (Closure(TicketRule, bool): void)|null  $authorizeLocked
     */
    public function toggle(TicketRule $rule, ?Closure $authorizeLocked = null): TicketRule
    {
        return $this->withinFence(function () use ($rule, $authorizeLocked): TicketRule {
            $locked = TicketRule::query()->with('publishedVersion')->lockForUpdate()->findOrFail($rule->id);
            $this->assertLegacyMutable($locked);

            if ($authorizeLocked !== null) {
                $authorizeLocked($locked, ! $locked->is_active);
            }

            $locked->forceFill(['is_active' => ! $locked->is_active])->save();
            $this->reconcileCompatibility($locked);

            return $locked->refresh();
        });
    }

    public function delete(TicketRule $rule): void
    {
        $this->withinFence(function () use ($rule): TicketRule {
            $locked = TicketRule::query()->with('publishedVersion')->lockForUpdate()->findOrFail($rule->id);
            $this->assertLegacyMutable($locked);
            $locked->delete();
            $deleted = TicketRule::withTrashed()->findOrFail($locked->id);
            $this->reconcileCompatibility($deleted);

            return $deleted;
        });
    }

    /**
     * Update lifecycle metadata without changing legacy definition timestamps.
     */
    private function reconcileCompatibility(TicketRule $rule): void
    {
        $result = $this->canonicalizer->inspect($rule);
        $version = $rule->published_version_id
            ? $rule->publishedVersion()->first()
            : null;

        if ($result['status'] !== TicketRuleDefinitionCanonicalizer::STATUS_VALID) {
            $compatibilityStatus = $result['status'] === TicketRuleDefinitionCanonicalizer::STATUS_INVALID
                ? TicketRule::COMPATIBILITY_INVALID
                : TicketRule::COMPATIBILITY_AMBIGUOUS;

            $metadata = [
                'lifecycle_status' => $rule->trashed()
                    ? TicketRule::LIFECYCLE_DELETED
                    : TicketRule::LIFECYCLE_LEGACY,
                'definition_schema_version' => 1,
                'definition_checksum' => null,
                'compatibility_status' => $compatibilityStatus,
                'compatibility_reason_code' => $result['reason_code'],
                'compatibility_checked_at' => now(),
            ];
        } else {
            $matchesVersion = $version !== null
                && hash_equals((string) $version->definition_checksum, (string) $result['checksum']);

            $metadata = [
                'lifecycle_status' => $rule->trashed()
                    ? TicketRule::LIFECYCLE_DELETED
                    : ($version
                        ? ($rule->is_active ? TicketRule::LIFECYCLE_PUBLISHED : TicketRule::LIFECYCLE_DISABLED)
                        : TicketRule::LIFECYCLE_LEGACY),
                'definition_schema_version' => 1,
                'definition_checksum' => $result['checksum'],
                'compatibility_status' => $version
                    ? ($matchesVersion ? TicketRule::COMPATIBILITY_ELIGIBLE : TicketRule::COMPATIBILITY_DRIFTED)
                    : TicketRule::COMPATIBILITY_UNVERSIONED,
                'compatibility_reason_code' => $version && ! $matchesVersion
                    ? 'definition_checksum_mismatch'
                    : null,
                'compatibility_checked_at' => now(),
            ];
        }

        DB::table('ticket_rules')->where('id', $rule->id)->update($metadata);
        $rule->forceFill($metadata);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function assertLegacyAttributes(array $attributes): void
    {
        if (array_intersect(array_keys($attributes), self::GOVERNED_ATTRIBUTES) !== []) {
            throw ValidationException::withMessages([
                'rule' => 'Draft and immutable Ticket Rule fields cannot be changed through the legacy boundary.',
            ]);
        }
    }

    private function assertLegacyMutable(TicketRule $rule): void
    {
        $hasDraftEvidence = $rule->draft_payload_json !== null
            || $rule->draft_checksum !== null
            || $rule->draft_updated_by !== null
            || $rule->draft_updated_at !== null
            || $rule->draft_creation_token !== null;
        $publishedVersion = $rule->publishedVersion;
        $schemaIsLegacy = (int) $rule->definition_schema_version
            === TicketRuleDefinitionRegistry::SCHEMA_VERSION;

        if ($hasDraftEvidence || ! $schemaIsLegacy) {
            $this->rejectNonLegacyMutation();
        }

        if ($rule->published_version_id === null) {
            if ($rule->lifecycle_status !== TicketRule::LIFECYCLE_LEGACY) {
                $this->rejectNonLegacyMutation();
            }

            return;
        }

        if (! $publishedVersion
            || (int) $publishedVersion->ticket_rule_id !== (int) $rule->id
            || (int) $publishedVersion->definition_schema_version
                !== TicketRuleDefinitionRegistry::SCHEMA_VERSION
            || $publishedVersion->status !== TicketRuleVersion::STATUS_COMPATIBILITY) {
            $this->rejectNonLegacyMutation();
        }
    }

    private function rejectNonLegacyMutation(): never
    {
        throw ValidationException::withMessages([
            'rule' => 'Draft-bearing or schema-2 published Ticket Rules cannot be changed through the legacy boundary.',
        ]);
    }

    private function withinFence(Closure $mutation): mixed
    {
        return DB::transaction(function () use ($mutation): mixed {
            $fence = TicketRuleAuthorityFence::query()
                ->whereKey(TicketRuleAuthorityFence::SCOPE)
                ->lockForUpdate()
                ->firstOrFail();

            if ($fence->runtime_authority !== TicketRuleAuthorityFence::AUTHORITY_LEGACY) {
                throw new RuntimeException('Legacy Ticket Rule mutations are disabled after runtime authority changes.');
            }

            $beforeChecksum = $this->fingerprint->checksum();
            $result = $mutation();
            $afterChecksum = $this->fingerprint->checksum();

            if (! hash_equals((string) $fence->catalog_checksum, $beforeChecksum)
                || ! hash_equals($beforeChecksum, $afterChecksum)) {
                $fence->forceFill([
                    'catalog_generation' => $fence->catalog_generation + 1,
                    'catalog_checksum' => $afterChecksum,
                ])->save();
            }

            return $result;
        }, 3);
    }
}
