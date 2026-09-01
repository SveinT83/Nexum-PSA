<?php

use App\Modules\Ticket\Services\TicketRuleCatalogFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const FENCE_SCOPE = 'ticket_rules';

    private const VERSION_TABLE = 'ticket_rule_versions';

    private const FENCE_TABLE = 'ticket_rule_authority_fences';

    private const UPDATE_TRIGGER = 'ticket_rule_versions_update_guard';

    private const DELETE_TRIGGER = 'ticket_rule_versions_delete_guard';

    public function up(): void
    {
        if (! Schema::hasTable('ticket_rules')) {
            throw new RuntimeException('The ticket_rules table must exist before Ticket Rule versioning is installed.');
        }

        if (! Schema::hasColumn('ticket_rules', 'lifecycle_status')) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->string('lifecycle_status', 32)->default('legacy')->index();
                $table->unsignedBigInteger('published_version_id')->nullable()->index();
                $table->unsignedBigInteger('published_by')->nullable()->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->unsignedSmallInteger('definition_schema_version')->default(1);
                $table->char('definition_checksum', 64)->nullable()->index();
                $table->string('compatibility_status', 32)->default('unversioned')->index();
                $table->string('compatibility_reason_code', 80)->nullable()->index();
                $table->timestamp('compatibility_checked_at')->nullable();
            });
        }

        if (! Schema::hasTable(self::VERSION_TABLE)) {
            Schema::create(self::VERSION_TABLE, function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('ticket_rule_id');
                $table->unsignedInteger('version_number');
                $table->string('status', 32)->default('compatibility')->index();
                $table->unsignedSmallInteger('definition_schema_version');
                $table->string('trigger_key', 80)->index();
                $table->unsignedInteger('weight')->index();
                $table->boolean('stop_processing');
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('definition_json');
                $table->char('definition_checksum', 64)->index();
                $table->boolean('source_is_active');
                $table->string('source_trigger', 80);
                $table->unsignedInteger('source_hit_count')->default(0);
                $table->timestamp('source_last_hit_at')->nullable();
                $table->unsignedBigInteger('source_created_by')->nullable()->index();
                $table->unsignedBigInteger('source_updated_by')->nullable()->index();
                $table->timestamp('source_created_at')->nullable();
                $table->timestamp('source_updated_at')->nullable();
                $table->timestamp('source_deleted_at')->nullable();
                $table->unsignedBigInteger('published_by')->nullable()->index();
                $table->timestamp('published_at')->nullable()->index();
                $table->string('provenance', 32)->index();
                $table->uuid('provenance_batch_uuid')->index();
                $table->string('provenance_key', 120)->nullable();
                $table->dateTime('provenance_recorded_at');
                $table->timestamps();

                $table->unique(['ticket_rule_id', 'version_number'], 'trv_rule_version_uq');
                $table->index(['trigger_key', 'weight', 'ticket_rule_id'], 'trv_trigger_weight_rule_ix');
                $table->foreign('ticket_rule_id', 'trv_rule_fk')
                    ->references('id')
                    ->on('ticket_rules')
                    ->restrictOnDelete();
            });
        }

        if (! $this->hasPublishedVersionForeignKey()) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->foreign('published_version_id', 'ticket_rules_published_version_fk')
                    ->references('id')
                    ->on(self::VERSION_TABLE)
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable(self::FENCE_TABLE)) {
            Schema::create(self::FENCE_TABLE, function (Blueprint $table): void {
                $table->string('scope', 64)->primary();
                $table->string('runtime_authority', 32)->default('legacy');
                $table->unsignedBigInteger('catalog_generation')->default(0);
                $table->char('catalog_checksum', 64);
                $table->timestamps();
            });
        }

        $now = now();
        $catalogChecksum = app(TicketRuleCatalogFingerprint::class)->checksum();
        DB::table(self::FENCE_TABLE)->insertOrIgnore([
            'scope' => self::FENCE_SCOPE,
            'runtime_authority' => 'legacy',
            'catalog_generation' => 0,
            'catalog_checksum' => $catalogChecksum,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $fence = DB::table(self::FENCE_TABLE)->where('scope', self::FENCE_SCOPE)->first();
        if (! $fence || $fence->runtime_authority !== 'legacy' || (int) $fence->catalog_generation !== 0) {
            throw new RuntimeException('Ticket Rule versioning cannot resume after runtime authority evidence exists.');
        }

        if (DB::table(self::VERSION_TABLE)->exists()) {
            throw new RuntimeException('Ticket Rule versioning cannot resume after immutable versions exist.');
        }

        if (! hash_equals((string) $fence->catalog_checksum, $catalogChecksum)) {
            DB::table(self::FENCE_TABLE)->where('scope', self::FENCE_SCOPE)->update([
                'catalog_checksum' => $catalogChecksum,
                'updated_at' => $now,
            ]);
        }

        // A failed DDL run may have created only one guard. Rebuild both while the table is empty.
        $this->dropImmutabilityGuards();
        $this->createImmutabilityGuards();
    }

    public function down(): void
    {
        $this->assertNoVersioningEvidenceWouldBeDeleted();
        $this->dropImmutabilityGuards();

        if ($this->hasPublishedVersionForeignKey()) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->dropForeign('ticket_rules_published_version_fk');
            });
        }

        Schema::dropIfExists(self::VERSION_TABLE);
        Schema::dropIfExists(self::FENCE_TABLE);

        if (Schema::hasTable('ticket_rules')) {
            Schema::table('ticket_rules', function (Blueprint $table): void {
                $table->dropColumn([
                    'lifecycle_status',
                    'published_version_id',
                    'published_by',
                    'published_at',
                    'definition_schema_version',
                    'definition_checksum',
                    'compatibility_status',
                    'compatibility_reason_code',
                    'compatibility_checked_at',
                ]);
            });
        }
    }

    private function assertNoVersioningEvidenceWouldBeDeleted(): void
    {
        if (Schema::hasTable(self::VERSION_TABLE) && DB::table(self::VERSION_TABLE)->exists()) {
            throw new RuntimeException(
                'Cannot roll back Ticket Rule versioning after immutable versions have been recorded.',
            );
        }

        if (Schema::hasTable('ticket_rules') && Schema::hasColumn('ticket_rules', 'lifecycle_status')) {
            $hasRuleEvidence = DB::table('ticket_rules')
                ->where(function ($query): void {
                    $query
                        ->where('lifecycle_status', '<>', 'legacy')
                        ->orWhereNotNull('published_version_id')
                        ->orWhereNotNull('published_by')
                        ->orWhereNotNull('published_at')
                        ->orWhereNotNull('definition_checksum')
                        ->orWhere('compatibility_status', '<>', 'unversioned')
                        ->orWhereNotNull('compatibility_reason_code')
                        ->orWhereNotNull('compatibility_checked_at');
                })
                ->exists();

            if ($hasRuleEvidence) {
                throw new RuntimeException(
                    'Cannot roll back Ticket Rule versioning after lifecycle or compatibility evidence exists.',
                );
            }
        }

        if (Schema::hasTable(self::FENCE_TABLE)) {
            $hasFenceEvidence = DB::table(self::FENCE_TABLE)
                ->where(function ($query): void {
                    $query
                        ->where('runtime_authority', '<>', 'legacy')
                        ->orWhere('catalog_generation', '>', 0);
                })
                ->exists();

            if ($hasFenceEvidence) {
                throw new RuntimeException(
                    'Cannot roll back Ticket Rule versioning after the authority fence has advanced.',
                );
            }
        }
    }

    private function hasPublishedVersionForeignKey(): bool
    {
        if (! Schema::hasTable('ticket_rules')
            || ! Schema::hasColumn('ticket_rules', 'published_version_id')) {
            return false;
        }

        return collect(Schema::getForeignKeys('ticket_rules'))
            ->contains(fn (array $foreignKey): bool => ($foreignKey['columns'] ?? []) === ['published_version_id']
                && ($foreignKey['foreign_table'] ?? null) === self::VERSION_TABLE
                && ($foreignKey['foreign_columns'] ?? []) === ['id']
            );
    }

    private function createImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared(
                'CREATE TRIGGER '.self::UPDATE_TRIGGER.' BEFORE UPDATE ON '.self::VERSION_TABLE
                ." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket Rule versions are immutable'",
            );
            DB::unprepared(
                'CREATE TRIGGER '.self::DELETE_TRIGGER.' BEFORE DELETE ON '.self::VERSION_TABLE
                ." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ticket Rule versions are immutable'",
            );

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared(
                'CREATE TRIGGER '.self::UPDATE_TRIGGER.' BEFORE UPDATE ON '.self::VERSION_TABLE
                ." BEGIN SELECT RAISE(ABORT, 'Ticket Rule versions are immutable'); END",
            );
            DB::unprepared(
                'CREATE TRIGGER '.self::DELETE_TRIGGER.' BEFORE DELETE ON '.self::VERSION_TABLE
                ." BEGIN SELECT RAISE(ABORT, 'Ticket Rule versions are immutable'); END",
            );

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION ticket_rule_versions_immutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'Ticket Rule versions are immutable';
END;
$$ LANGUAGE plpgsql
SQL);
            DB::unprepared(
                'CREATE TRIGGER '.self::UPDATE_TRIGGER.' BEFORE UPDATE ON '.self::VERSION_TABLE
                .' FOR EACH ROW EXECUTE FUNCTION ticket_rule_versions_immutable()',
            );
            DB::unprepared(
                'CREATE TRIGGER '.self::DELETE_TRIGGER.' BEFORE DELETE ON '.self::VERSION_TABLE
                .' FOR EACH ROW EXECUTE FUNCTION ticket_rule_versions_immutable()',
            );

            return;
        }

        throw new RuntimeException("Ticket Rule version immutability is unsupported for [{$driver}].");
    }

    private function dropImmutabilityGuards(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER);
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::DELETE_TRIGGER);

            return;
        }

        if ($driver === 'sqlite') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER);
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::DELETE_TRIGGER);

            return;
        }

        if ($driver === 'pgsql') {
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::UPDATE_TRIGGER.' ON '.self::VERSION_TABLE);
            DB::unprepared('DROP TRIGGER IF EXISTS '.self::DELETE_TRIGGER.' ON '.self::VERSION_TABLE);
            DB::unprepared('DROP FUNCTION IF EXISTS ticket_rule_versions_immutable()');
        }
    }
};
