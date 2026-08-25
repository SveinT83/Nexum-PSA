<?php

namespace App\Modules\Email\Tests\Feature;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailProviderReconciliationPlacementSchemaRepairTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_repairs_a_recorded_reconciliation_migration_with_missing_placement_ddl(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('The standard repair regression rebuilds the isolated SQLite test schema.');
        }

        $migration = $this->migration();
        $this->removePlacementObservationContract();

        $this->assertFalse(Schema::hasColumn(
            'email_mailbox_placements',
            'last_provider_reconciliation_run_id',
        ));
        $this->assertTrue(DB::table('migrations')
            ->where('migration', '2026_08_16_118000_add_email_provider_reconciliation')
            ->exists());

        $migration->up();
        $first = $this->contract();
        $migration->up();

        $this->assertSame($first, $this->contract());
        $this->assertSame(
            [
                'last_provider_reconciliation_run_id',
                'last_provider_observed_sync_version',
                'last_provider_observed_identity_hash',
                'last_provider_observed_at',
            ],
            $first['columns'],
        );
        $this->assertSame(
            ['em_place_provider_observed_ix', 'em_place_recon_identity_ix'],
            $first['indexes'],
        );
        $this->assertSame(['last_provider_reconciliation_run_id'], $first['foreign_columns']);
        $this->assertSame(
            ['em_place_observed_identity_ck_insert', 'em_place_observed_identity_ck_update',
                'em_place_observed_version_positive_ck_insert', 'em_place_observed_version_positive_ck_update'],
            $first['triggers'],
        );

        $migration->down();
        $this->assertSame($first, $this->contract());
    }

    private function removePlacementObservationContract(): void
    {
        foreach ([
            'em_place_observed_version_positive_ck_insert',
            'em_place_observed_version_positive_ck_update',
            'em_place_observed_identity_ck_insert',
            'em_place_observed_identity_ck_update',
        ] as $trigger) {
            DB::unprepared("drop trigger if exists `{$trigger}`");
        }

        Schema::table('email_mailbox_placements', function (Blueprint $table): void {
            $table->dropForeign(['last_provider_reconciliation_run_id']);
            $table->dropIndex('em_place_provider_observed_ix');
            $table->dropIndex('em_place_recon_identity_ix');
        });
        Schema::table('email_mailbox_placements', function (Blueprint $table): void {
            $table->dropColumn([
                'last_provider_reconciliation_run_id',
                'last_provider_observed_sync_version',
                'last_provider_observed_identity_hash',
                'last_provider_observed_at',
            ]);
        });
    }

    /**
     * @return array{
     *     columns:list<string>,
     *     indexes:list<string>,
     *     foreign_columns:list<string>,
     *     triggers:list<string>
     * }
     */
    private function contract(): array
    {
        $expectedColumns = [
            'last_provider_reconciliation_run_id',
            'last_provider_observed_sync_version',
            'last_provider_observed_identity_hash',
            'last_provider_observed_at',
        ];
        $expectedIndexes = ['em_place_provider_observed_ix', 'em_place_recon_identity_ix'];

        return [
            'columns' => array_values(array_intersect(
                $expectedColumns,
                Schema::getColumnListing('email_mailbox_placements'),
            )),
            'indexes' => collect(Schema::getIndexes('email_mailbox_placements'))
                ->pluck('name')->intersect($expectedIndexes)->sort()->values()->all(),
            'foreign_columns' => collect(Schema::getForeignKeys('email_mailbox_placements'))
                ->filter(fn (array $foreign): bool => ($foreign['foreign_table'] ?? null)
                    === 'email_provider_reconciliation_runs')
                ->flatMap(fn (array $foreign): array => $foreign['columns'] ?? [])
                ->values()->all(),
            'triggers' => DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('tbl_name', 'email_mailbox_placements')
                ->where('name', 'like', 'em_place_observed_%')
                ->orderBy('name')
                ->pluck('name')->all(),
        ];
    }

    private function migration(): Migration
    {
        return require database_path(
            'migrations/2026_08_25_100000_repair_email_provider_reconciliation_placement_schema.php',
        );
    }
}
