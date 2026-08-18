<?php

namespace App\Modules\Email\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Resolve the unread schema as one deployment-safe contract.
 *
 * Mail code is deployed before the additive migrations are applied. The
 * legacy state table must therefore remain usable until migration 104000 has
 * completed its baseline backfill and Laravel has recorded that completion.
 * Any partial shape fails closed instead of mixing the two interpretations.
 */
class EmailUnreadSchemaState
{
    public const MODE_EPOCHS = 'epochs';

    public const MODE_LEGACY = 'legacy';

    public const MODE_UNAVAILABLE = 'unavailable';

    private const MIGRATION = '2026_08_16_104000_add_email_unread_access_baselines';

    private ?string $resolvedMode = null;

    public function mode(): string
    {
        // This cache belongs only to the short-lived resolved service. It is
        // deliberately not static so restarted workers and later requests see
        // a newly completed migration.
        return $this->resolvedMode ??= $this->detectMode();
    }

    public function usesAccessEpochs(): bool
    {
        return $this->mode() === self::MODE_EPOCHS;
    }

    public function usesLegacyState(): bool
    {
        return $this->mode() === self::MODE_LEGACY;
    }

    private function detectMode(): string
    {
        if (! Schema::hasTable('email_message_user_states')) {
            return self::MODE_UNAVAILABLE;
        }

        $hasBaselineTable = Schema::hasTable('email_account_user_read_baselines');
        $hasAccessEpoch = Schema::hasColumn('email_message_user_states', 'access_epoch');

        if (! $hasBaselineTable && ! $hasAccessEpoch) {
            return $this->hasLegacyStateShape() && ! $this->migrationCompleted()
                ? self::MODE_LEGACY
                : self::MODE_UNAVAILABLE;
        }

        if (! $hasBaselineTable
            || ! $hasAccessEpoch
            || ! $this->hasEpochStateShape()
            || ! $this->hasBaselineShape()
            || ! $this->migrationCompleted()) {
            return self::MODE_UNAVAILABLE;
        }

        return self::MODE_EPOCHS;
    }

    private function hasLegacyStateShape(): bool
    {
        return $this->hasColumns('email_message_user_states', [
            'id',
            'email_message_id',
            'user_id',
            'last_opened_placement_id',
            'is_unread',
            'opened_count',
            'first_opened_at',
            'last_opened_at',
            'marked_read_at',
            'marked_unread_at',
            'created_at',
            'updated_at',
        ]) && $this->hasIndexes('email_message_user_states', [
            'email_message_user_states_unique',
        ]);
    }

    private function hasEpochStateShape(): bool
    {
        return $this->hasColumns('email_message_user_states', [
            'id',
            'email_message_id',
            'user_id',
            'access_epoch',
            'last_opened_placement_id',
            'is_unread',
            'opened_count',
            'first_opened_at',
            'last_opened_at',
            'marked_read_at',
            'marked_unread_at',
            'created_at',
            'updated_at',
        ]) && $this->hasIndexes('email_message_user_states', [
            'em_msg_state_message_user_epoch_uq',
            'em_msg_state_user_epoch_unread_ix',
        ]);
    }

    private function hasBaselineShape(): bool
    {
        return $this->hasColumns('email_account_user_read_baselines', [
            'id',
            'email_account_id',
            'user_id',
            'access_epoch',
            'baseline_message_id',
            'ordinary_view_entitled',
            'source',
            'source_reference',
            'recorded_by',
            'recorded_at',
            'entitlement_changed_at',
            'created_at',
            'updated_at',
        ]) && $this->hasIndexes('email_account_user_read_baselines', [
            'em_read_base_account_user_uq',
            'em_read_base_user_entitled_ix',
        ]);
    }

    /** @param array<int, string> $columns */
    private function hasColumns(string $table, array $columns): bool
    {
        $available = array_fill_keys(
            array_map('strtolower', Schema::getColumnListing($table)),
            true,
        );

        foreach ($columns as $column) {
            if (! isset($available[strtolower($column)])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<int, string> $indexes */
    private function hasIndexes(string $table, array $indexes): bool
    {
        $available = [];

        foreach (Schema::getIndexes($table) as $index) {
            $name = $index['name'] ?? null;

            if (is_string($name)) {
                $available[strtolower($name)] = true;
            }
        }

        foreach ($indexes as $index) {
            if (! isset($available[strtolower($index)])) {
                return false;
            }
        }

        return true;
    }

    private function migrationCompleted(): bool
    {
        return Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where('migration', self::MIGRATION)
                ->exists();
    }
}
