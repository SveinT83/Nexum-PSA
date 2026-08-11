<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const POLICY_TABLE = 'storage_purchase_order_automation_policies';

    private const VERSION_TABLE = 'storage_purchase_order_import_profile_versions';

    private const PROFILE_TABLE = 'storage_purchase_order_import_profiles';

    private const ATTEMPT_TABLE = 'storage_purchase_order_import_attempts';

    private const POLICY_CURRENT_INDEX = 'spo_auto_one_current_unique';

    private const PROFILE_ACTIVE_INDEX = 'spo_profile_one_active_unique';

    private const ATTEMPT_TRACE_INDEX = 'spo_import_attempt_trace_idx';

    /**
     * Add database-backed singleton/current guards and make import-attempt rows append-only.
     */
    public function up(): void
    {
        $this->normalizeCurrentRows();
        $this->dropAttemptStageUniqueness();
        $this->restrictAttemptParentDeletion();
        $this->createCurrentRowConstraints();
        $this->createAttemptImmutabilityTriggers();
    }

    /**
     * Remove only the integrity mechanisms introduced by this migration.
     */
    public function down(): void
    {
        $this->assertAttemptStageUniquenessCanBeRestored();
        $this->dropAttemptImmutabilityTriggers();
        $this->dropCurrentRowConstraints();
        $this->restoreAttemptCascadeDeletion();

        Schema::table(self::ATTEMPT_TABLE, function ($table): void {
            $table->unique(
                ['import_id', 'attempt_number', 'stage'],
                'storage_po_import_attempt_stage_unique',
            );
            $table->dropIndex(self::ATTEMPT_TRACE_INDEX);
        });
    }

    /**
     * Refuse a partial rollback once append-only start/finish events use the same trace key.
     */
    private function assertAttemptStageUniquenessCanBeRestored(): void
    {
        $hasAppendHistory = DB::table(self::ATTEMPT_TABLE)
            ->select(['import_id', 'attempt_number', 'stage'])
            ->groupBy('import_id', 'attempt_number', 'stage')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($hasAppendHistory) {
            throw new \RuntimeException(
                'Cannot roll back supplier-order integrity after append-only attempt history has been recorded.',
            );
        }
    }

    /**
     * Keep the newest current policy and align version status with each profile pointer.
     */
    private function normalizeCurrentRows(): void
    {
        $currentPolicyIds = DB::table(self::POLICY_TABLE)
            ->where('is_current', true)
            ->orderByDesc('revision_number')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->pluck('id');

        if ($currentPolicyIds->count() > 1) {
            DB::table(self::POLICY_TABLE)
                ->where('is_current', true)
                ->where('id', '<>', $currentPolicyIds->first())
                ->update(['is_current' => false]);
        }

        DB::table(self::PROFILE_TABLE)
            ->select(['id', 'active_version_id'])
            ->orderBy('id')
            ->chunkById(250, function ($profiles): void {
                foreach ($profiles as $profile) {
                    $activeVersionId = $profile->active_version_id !== null
                        ? (int) $profile->active_version_id
                        : null;
                    $activeBelongsToProfile = $activeVersionId !== null
                        && DB::table(self::VERSION_TABLE)
                            ->where('id', $activeVersionId)
                            ->where('profile_id', $profile->id)
                            ->exists();

                    if (! $activeBelongsToProfile) {
                        DB::table(self::PROFILE_TABLE)
                            ->where('id', $profile->id)
                            ->update(['active_version_id' => null]);
                        $activeVersionId = null;
                    }

                    DB::table(self::VERSION_TABLE)
                        ->where('profile_id', $profile->id)
                        ->where('status', 'active')
                        ->when(
                            $activeVersionId !== null,
                            fn ($query) => $query->where('id', '<>', $activeVersionId),
                        )
                        ->update(['status' => 'superseded']);

                    if ($activeVersionId !== null) {
                        DB::table(self::VERSION_TABLE)
                            ->where('id', $activeVersionId)
                            ->where('profile_id', $profile->id)
                            ->update(['status' => 'active']);
                    }
                }
            });
    }

    private function dropAttemptStageUniqueness(): void
    {
        Schema::table(self::ATTEMPT_TABLE, function ($table): void {
            $table->index(
                ['import_id', 'attempt_number', 'stage'],
                self::ATTEMPT_TRACE_INDEX,
            );
            $table->dropUnique('storage_po_import_attempt_stage_unique');
        });
    }

    /**
     * A parent import must not bypass append-only attempt history through a cascading delete.
     */
    private function restrictAttemptParentDeletion(): void
    {
        Schema::table(self::ATTEMPT_TABLE, function (Blueprint $table): void {
            $table->dropForeign(['import_id']);
            $table->foreign('import_id', 'spo_import_attempt_import_fk')
                ->references('id')
                ->on('storage_purchase_order_imports')
                ->restrictOnDelete();
        });
    }

    private function restoreAttemptCascadeDeletion(): void
    {
        Schema::table(self::ATTEMPT_TABLE, function (Blueprint $table): void {
            $table->dropForeign('spo_import_attempt_import_fk');
            $table->foreign('import_id', 'storage_po_import_attempt_import_fk')
                ->references('id')
                ->on('storage_purchase_order_imports')
                ->cascadeOnDelete();
        });
    }

    private function createCurrentRowConstraints(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement(
                'ALTER TABLE '.self::POLICY_TABLE
                .' ADD COLUMN current_policy_guard TINYINT GENERATED ALWAYS AS '
                .'(CASE WHEN is_current = 1 THEN 1 ELSE NULL END) VIRTUAL INVISIBLE',
            );
            DB::statement(
                'CREATE UNIQUE INDEX '.self::POLICY_CURRENT_INDEX
                .' ON '.self::POLICY_TABLE.' (current_policy_guard)',
            );
            DB::statement(
                'ALTER TABLE '.self::VERSION_TABLE
                .' ADD COLUMN active_profile_guard BIGINT UNSIGNED GENERATED ALWAYS AS '
                ."(CASE WHEN status = 'active' THEN profile_id ELSE NULL END) VIRTUAL INVISIBLE",
            );
            DB::statement(
                'CREATE UNIQUE INDEX '.self::PROFILE_ACTIVE_INDEX
                .' ON '.self::VERSION_TABLE.' (active_profile_guard)',
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::POLICY_CURRENT_INDEX.' ON '.self::POLICY_TABLE
                .' (is_current) WHERE is_current = 1',
            );
            DB::statement(
                'CREATE UNIQUE INDEX '.self::PROFILE_ACTIVE_INDEX.' ON '.self::VERSION_TABLE
                ." (profile_id) WHERE status = 'active'",
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX '.self::POLICY_CURRENT_INDEX.' ON '.self::POLICY_TABLE
                .' (is_current) WHERE is_current IS TRUE',
            );
            DB::statement(
                'CREATE UNIQUE INDEX '.self::PROFILE_ACTIVE_INDEX.' ON '.self::VERSION_TABLE
                ." (profile_id) WHERE status = 'active'",
            );

            return;
        }

        throw new \RuntimeException("Supplier-order current-row constraints are unsupported for [{$driver}].");
    }

    private function createAttemptImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared(
                'CREATE TRIGGER spo_import_attempt_update_guard BEFORE UPDATE ON '.self::ATTEMPT_TABLE
                ." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier-order import attempts are append-only'",
            );
            DB::unprepared(
                'CREATE TRIGGER spo_import_attempt_delete_guard BEFORE DELETE ON '.self::ATTEMPT_TABLE
                ." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Supplier-order import attempts are append-only'",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                'CREATE TRIGGER spo_import_attempt_update_guard BEFORE UPDATE ON '.self::ATTEMPT_TABLE
                ." BEGIN SELECT RAISE(ABORT, 'Supplier-order import attempts are append-only'); END",
            );
            DB::unprepared(
                'CREATE TRIGGER spo_import_attempt_delete_guard BEFORE DELETE ON '.self::ATTEMPT_TABLE
                ." BEGIN SELECT RAISE(ABORT, 'Supplier-order import attempts are append-only'); END",
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION spo_import_attempt_append_only() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Supplier-order import attempts are append-only';
END;
$$ LANGUAGE plpgsql
SQL);
            DB::unprepared(
                'CREATE TRIGGER spo_import_attempt_update_guard BEFORE UPDATE ON '.self::ATTEMPT_TABLE
                .' FOR EACH ROW EXECUTE FUNCTION spo_import_attempt_append_only()',
            );
            DB::unprepared(
                'CREATE TRIGGER spo_import_attempt_delete_guard BEFORE DELETE ON '.self::ATTEMPT_TABLE
                .' FOR EACH ROW EXECUTE FUNCTION spo_import_attempt_append_only()',
            );

            return;
        }

        throw new \RuntimeException("Supplier-order attempt immutability is unsupported for [{$driver}].");
    }

    private function dropAttemptImmutabilityTriggers(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS spo_import_attempt_update_guard ON '.self::ATTEMPT_TABLE);
            DB::statement('DROP TRIGGER IF EXISTS spo_import_attempt_delete_guard ON '.self::ATTEMPT_TABLE);
            DB::statement('DROP FUNCTION IF EXISTS spo_import_attempt_append_only()');

            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS spo_import_attempt_update_guard');
        DB::statement('DROP TRIGGER IF EXISTS spo_import_attempt_delete_guard');
    }

    private function dropCurrentRowConstraints(): void
    {
        $driver = DB::connection()->getDriverName();
        DB::statement('DROP INDEX '.self::PROFILE_ACTIVE_INDEX.($driver === 'mysql' ? ' ON '.self::VERSION_TABLE : ''));
        DB::statement('DROP INDEX '.self::POLICY_CURRENT_INDEX.($driver === 'mysql' ? ' ON '.self::POLICY_TABLE : ''));

        if ($driver === 'mysql') {
            Schema::table(self::VERSION_TABLE, function ($table): void {
                $table->dropColumn('active_profile_guard');
            });
            Schema::table(self::POLICY_TABLE, function ($table): void {
                $table->dropColumn('current_policy_guard');
            });
        }
    }
};
