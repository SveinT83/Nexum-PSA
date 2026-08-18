<?php

namespace App\Modules\Email\Tests\Integration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PDO;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

/**
 * Opt-in, randomly isolated MariaDB contract for the order-seven DB guards.
 */
class EmailProviderReconciliationMariaDbGuardContractTest extends TestCase
{
    private ?PDO $server = null;

    private ?string $databaseName = null;

    private string $originalDefaultConnection;

    #[Test]
    public function actual_mariadb_server_through_mysql_driver_adds_and_drops_every_reconciliation_guard(): void
    {
        if (getenv('TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped('Set TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.');
        }

        try {
            $this->connectIsolatedDatabase();
            $this->createGuardTables();
            $reconciliation = require database_path(
                'migrations/2026_08_16_118000_add_email_provider_reconciliation.php',
            );
            $outbox = require database_path(
                'migrations/2026_08_16_118200_add_inbound_notification_external_outbox.php',
            );
            $targetIdentity = require database_path(
                'migrations/2026_08_16_118300_add_authoritative_target_identity_to_email_remote_operations.php',
            );

            $this->invoke($reconciliation, 'addPositiveBindingGuard');
            $this->invoke($reconciliation, 'addActiveSlotGuard');
            $this->invoke($reconciliation, 'addAutomationScopeGuard');
            $this->invoke($reconciliation, 'addFinalSummaryGuard');
            $this->invoke($reconciliation, 'addPlacementObservedVersionGuard');
            $this->invoke($reconciliation, 'addPlacementObservedIdentityGuard');
            $this->invoke($reconciliation, 'addLocalFolderSnapshotGuard');
            $this->invoke($reconciliation, 'addMetadataVerificationGuard');
            $this->invoke($reconciliation, 'addFolderItemSummaryGuard');
            $this->invoke($reconciliation, 'addPlacementSnapshotGuard');
            $this->invoke($reconciliation, 'addHistoricalBaselineGuard');
            $this->invoke($reconciliation, 'addAutomationGuard');
            $this->invoke($reconciliation, 'addSummaryWriteBarriers');
            $this->invoke($outbox, 'addStatusGuard');
            $this->invoke($outbox, 'addDeliveryContractGuard');
            $this->invoke($targetIdentity, 'addTargetIdentityGuards');

            DB::table('email_provider_reconciliation_runs')->insert([
                'provider_binding_version' => 1,
                'active_slot' => 1,
            ]);
            DB::table('notification_inbound_external_deliveries')->insert([
                'requested_web_push' => true,
                'status' => 'pending',
            ]);
            DB::table('email_provider_reconciliation_items')->insert([
                'email_provider_reconciliation_run_id' => 1,
                'email_provider_reconciliation_folder_id' => 1,
                'kind' => 'import',
                'status' => 'projected',
                'historical_baseline_required' => false,
                'historical_baseline_max_id' => 0,
                'historical_baseline_cursor_id' => 0,
                'historical_baseline_attempt_count' => 0,
            ]);
            DB::table('email_provider_reconciliation_folders')->insert([
                'email_provider_reconciliation_run_id' => 1,
                'status' => 'pending',
                'placement_snapshot_through_id' => 0,
            ]);
            DB::table('email_remote_operations')->insert([
                'acknowledged_target_uid_validity' => null,
                'acknowledged_target_uid' => null,
            ]);
            DB::table('email_mailbox_placements')->insert([
                'last_provider_observed_sync_version' => null,
                'last_provider_observed_identity_hash' => null,
            ]);
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update(['provider_binding_version' => 0]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update(['active_slot' => 2]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'automation_scope_unsafe' => true,
                    'automation_scope_error_code' => null,
                    'automation_scope_unsafe_at' => now(),
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'automation_scope_unsafe' => true,
                    'automation_scope_error_code' => 'provider_reconciliation_automation_scope_unsafe',
                    'automation_scope_unsafe_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'automation_scope_unsafe' => false,
                    'automation_scope_error_code' => null,
                    'automation_scope_unsafe_at' => null,
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update(['automation_scope_unsafe' => null]));
            $this->assertRejects(fn () => DB::table('email_mailbox_placements')
                ->where('id', 1)->update(['last_provider_observed_sync_version' => 0]));
            $this->assertRejects(fn () => DB::table('email_mailbox_placements')
                ->where('id', 1)->update(['last_provider_observed_identity_hash' => 'short']));
            $this->assertRejects(fn () => DB::table('email_mailbox_placements')
                ->where('id', 1)->update([
                    'last_provider_observed_identity_hash' => str_repeat('A', 64),
                ]));
            $this->assertSame(1, DB::table('email_mailbox_placements')
                ->where('id', 1)->update([
                    'last_provider_observed_sync_version' => 1,
                    'last_provider_observed_identity_hash' => str_repeat('a', 64),
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'local_folder_snapshot_status' => 'completed',
                    'local_folder_snapshot_through_id' => 5,
                    'local_folder_snapshot_cursor_id' => 5,
                    'local_folder_snapshot_count' => 1,
                    'local_folder_snapshot_hash' => hash('sha256', 'local'),
                    'local_folder_snapshot_batch_count' => 1,
                    'local_folder_snapshot_started_at' => now(),
                    'local_folder_snapshot_completed_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update(['local_folder_snapshot_status' => null]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'metadata_verification_status' => 'completed',
                    'metadata_verification_next_uid' => 6,
                    'metadata_verification_count' => 1,
                    'metadata_verification_hash' => hash('sha256', 'metadata'),
                    'metadata_verification_batch_count' => 1,
                    'metadata_verification_started_at' => now(),
                    'metadata_verification_completed_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update(['metadata_verification_status' => null]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update(['supports_modseq' => true]));
            $this->assertRejects(fn () => DB::table('notification_inbound_external_deliveries')
                ->where('id', 1)->update(['status' => 'invented']));
            $this->assertRejects(fn () => DB::table('notification_inbound_external_deliveries')->insert([
                'requested_mail' => true,
                'mail_scope' => 'sales',
                'mail_account_id' => 1,
                'mail_provider_binding_version' => 1,
                'status' => 'pending',
            ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'historical_baseline_required' => true,
                    'historical_baseline_status' => 'running',
                    'historical_baseline_max_id' => 5,
                    'historical_baseline_frozen_at' => now(),
                    'historical_baseline_last_attempt_at' => now(),
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'status' => 'waiting_for_baseline',
                    'historical_baseline_required' => true,
                    'historical_baseline_status' => 'pending',
                    'historical_baseline_max_id' => 5,
                    'historical_baseline_frozen_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update(['historical_baseline_status' => null]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'status' => 'projected',
                    'historical_baseline_required' => false,
                    'historical_baseline_status' => null,
                    'historical_baseline_max_id' => 0,
                    'historical_baseline_cursor_id' => 0,
                    'historical_baseline_frozen_at' => null,
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_required' => true,
                    'automation_status' => 'awaiting_correlation',
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update(['automation_status' => null]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_status' => 'pending',
                    'automation_attempt_count' => 1,
                    'automation_last_attempt_at' => now(),
                    'automation_rule_attempt_floor_id' => 0,
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_status' => 'pending',
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_status' => 'running',
                    'automation_claim_token' => hash('sha256', 'mariadb-running'),
                    'automation_attempt_count' => 1,
                    'automation_last_attempt_at' => now(),
                    'automation_rule_attempt_floor_id' => null,
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_status' => 'running',
                    'automation_claim_token' => hash('sha256', 'mariadb-running'),
                    'automation_attempt_count' => 1,
                    'automation_last_attempt_at' => now(),
                    'automation_rule_attempt_floor_id' => 0,
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_status' => 'completed',
                    'automation_claim_token' => null,
                    'automation_completed_at' => null,
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'automation_status' => 'completed',
                    'automation_claim_token' => null,
                    'automation_completed_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update(['automation_last_attempt_at' => null]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'placement_snapshot_purpose' => 'baseline',
                    'placement_snapshot_status' => 'completed',
                    'placement_snapshot_through_id' => 5,
                    'placement_snapshot_cursor_id' => 5,
                    'placement_snapshot_count' => 1,
                    'placement_snapshot_hash' => hash('sha256', 'placement'),
                    'placement_snapshot_batch_count' => 1,
                    'placement_snapshot_started_at' => now(),
                    'placement_snapshot_completed_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update(['placement_snapshot_purpose' => null]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update(['placement_snapshot_status' => null]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'placement_snapshot_purpose' => 'baseline',
                    'placement_snapshot_status' => 'completed',
                    'placement_snapshot_through_id' => 5,
                    'placement_snapshot_cursor_id' => 6,
                    'placement_snapshot_hash' => hash('sha256', ''),
                    'placement_snapshot_started_at' => now(),
                    'placement_snapshot_completed_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('email_remote_operations')
                ->where('id', 1)->update(['acknowledged_target_uid' => 8801]));
            $this->assertSame(1, DB::table('email_remote_operations')
                ->where('id', 1)->update([
                    'acknowledged_target_uid_validity' => 88,
                    'acknowledged_target_uid' => 8801,
                ]));
            $this->assertRejects(fn () => DB::table('email_remote_operations')
                ->where('id', 1)->update([
                    'acknowledged_target_uid_validity' => 89,
                    'acknowledged_target_uid' => 8901,
                ]));

            $summaryAt = now();
            $this->assertSame(1, DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'item_summary_status' => 'sealed',
                    'item_summary_through_id' => 1,
                    'item_summary_cursor_id' => 1,
                    'item_summary_started_at' => $summaryAt,
                    'item_summary_completed_at' => $summaryAt,
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update(['status' => 'conflict']));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')->insert([
                'email_provider_reconciliation_run_id' => 1,
                'email_provider_reconciliation_folder_id' => 1,
                'kind' => 'observation',
                'status' => 'projected',
            ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'status' => 'complete',
                    'missing_count' => 1,
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'status' => 'complete',
                    'item_summary_nonterminal' => true,
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'status' => 'complete',
                    'missing_count' => 0,
                    'conflict_count' => 0,
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'phase' => 'summary',
                    'final_summary_status' => 'sealed',
                    'final_summary_folder_through_id' => 1,
                    'final_summary_folder_cursor_id' => 1,
                    'final_summary_item_through_id' => 1,
                    'final_summary_item_cursor_id' => 1,
                    'final_summary_complete_folder_count' => 1,
                    'final_summary_started_at' => $summaryAt,
                    'final_summary_completed_at' => $summaryAt,
                ]));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update(['automation_error_code' => 'late']));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update(['status' => 'stale']));
            $this->assertRejects(fn () => DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'status' => 'completed',
                    'active_slot' => null,
                    'final_summary_conflict_count' => 1,
                    'complete_folder_count' => 1,
                    'conflict_count' => 1,
                    'finished_at' => now(),
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'status' => 'completed',
                    'active_slot' => null,
                    'complete_folder_count' => 1,
                    'finished_at' => now(),
                ]));

            $this->invoke($targetIdentity, 'dropTargetIdentityGuards');
            $this->invoke($outbox, 'dropDeliveryContractGuard');
            $this->invoke($outbox, 'dropStatusGuard');
            $this->invoke($reconciliation, 'dropSummaryWriteBarriers');
            $this->invoke($reconciliation, 'dropAutomationGuard');
            $this->invoke($reconciliation, 'dropHistoricalBaselineGuard');
            $this->invoke($reconciliation, 'dropPlacementSnapshotGuard');
            $this->invoke($reconciliation, 'dropMetadataVerificationGuard');
            $this->invoke($reconciliation, 'dropFolderItemSummaryGuard');
            $this->invoke($reconciliation, 'dropLocalFolderSnapshotGuard');
            $this->invoke($reconciliation, 'dropPlacementObservedIdentityGuard');
            $this->invoke($reconciliation, 'dropPlacementObservedVersionGuard');
            $this->invoke($reconciliation, 'dropAutomationScopeGuard');
            $this->invoke($reconciliation, 'dropFinalSummaryGuard');
            $this->invoke($reconciliation, 'dropActiveSlotGuard');
            $this->invoke($reconciliation, 'dropPositiveBindingGuard');

            $this->assertSame(1, DB::table('email_provider_reconciliation_runs')
                ->where('id', 1)->update([
                    'provider_binding_version' => 0,
                    'active_slot' => 2,
                    'local_folder_snapshot_status' => null,
                    'automation_scope_unsafe' => false,
                    'automation_scope_error_code' => null,
                    'automation_scope_unsafe_at' => null,
                ]));
            $this->assertSame(1, DB::table('notification_inbound_external_deliveries')
                ->where('id', 1)->update(['status' => 'invented']));
            $this->assertSame(1, DB::table('email_provider_reconciliation_items')
                ->where('id', 1)->update([
                    'historical_baseline_required' => true,
                    'historical_baseline_status' => 'running',
                    'automation_status' => null,
                ]));
            $this->assertSame(1, DB::table('email_mailbox_placements')
                ->where('id', 1)->update([
                    'last_provider_observed_sync_version' => 0,
                    'last_provider_observed_identity_hash' => str_repeat('A', 64),
                ]));
            $this->assertSame(1, DB::table('email_provider_reconciliation_folders')
                ->where('id', 1)->update([
                    'metadata_verification_status' => null,
                    'placement_snapshot_purpose' => 'baseline',
                    'placement_snapshot_status' => 'completed',
                    'placement_snapshot_through_id' => 5,
                    'placement_snapshot_cursor_id' => 6,
                ]));
            $this->assertSame(1, DB::table('email_remote_operations')
                ->where('id', 1)->update([
                    'acknowledged_target_uid_validity' => 89,
                    'acknowledged_target_uid' => 8901,
                ]));

            // The rollback preflight must treat the observation timestamp as
            // durable evidence even when every older pointer field is null.
            DB::table('email_provider_reconciliation_items')->delete();
            DB::table('email_provider_reconciliation_folders')->delete();
            DB::table('email_provider_reconciliation_runs')->delete();
            DB::table('email_mailbox_placements')->where('id', 1)->update([
                'last_provider_reconciliation_run_id' => null,
                'last_provider_observed_sync_version' => null,
                'last_provider_observed_identity_hash' => null,
                'last_provider_observed_at' => now(),
            ]);
            try {
                $reconciliation->down();
                $this->fail('Timestamp-only provider evidence must block MariaDB schema rollback.');
            } catch (RuntimeException $exception) {
                $this->assertSame(
                    'Provider reconciliation evidence must be preserved before schema rollback.',
                    $exception->getMessage(),
                );
            }
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    #[Test]
    public function actual_mariadb_server_through_mysql_driver_enforces_durable_fanout_guards_and_bounded_indexes(): void
    {
        if (getenv('TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped('Set TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.');
        }

        try {
            $this->connectIsolatedDatabase();
            $this->createFanoutContractTables();
            $migration = require database_path(
                'migrations/2026_08_16_118500_add_durable_inbound_notification_fanout.php',
            );

            // Every additive DDL step is independently discoverable. A full
            // rerun after MySQL/MariaDB autocommit must therefore be harmless.
            $migration->up();
            foreach ([
                'dropTicketMessagePointerMonotonicGuard',
                'dropTicketMessagePointerInitialGuard',
                'dropTicketMessagePointerGuard',
                'dropRepairMonotonicGuard',
                'dropRepairGuard',
                'dropFanoutMonotonicGuard',
                'dropFanoutInitialGuard',
                'dropFanoutGuard',
                'dropExternalDeliveryMonotonicGuard',
                'dropExternalDeliveryInitialGuard',
                'dropExternalDeliveryGuard',
            ] as $dropGuard) {
                $this->invoke($migration, $dropGuard);
            }
            DB::statement(
                'alter table `ticket_messages`'
                .' add constraint `ticket_messages_inbound_pointer_ck` check (1 = 1)',
            );
            DB::unprepared(
                'create trigger `ticket_messages_inbound_pointer_initial`'
                .' before insert on `ticket_messages`'
                .' for each row begin set NEW.id = NEW.id; end',
            );
            DB::unprepared(
                'create trigger `ticket_messages_inbound_pointer_monotonic`'
                .' before update on `ticket_messages`'
                .' for each row begin set NEW.id = NEW.id; end',
            );
            DB::statement(
                'alter table `notification_inbound_ticket_message_repairs`'
                .' add constraint `notif_inbound_ticket_repair_ck` check (1 = 1)',
            );
            DB::unprepared(
                'create trigger `notif_inbound_ticket_repair_ck_insert`'
                .' before insert on `notification_inbound_ticket_message_repairs`'
                .' for each row begin set NEW.id = NEW.id; end',
            );
            DB::unprepared(
                'create trigger `notif_inbound_ticket_repair_monotonic`'
                .' before update on `notification_inbound_ticket_message_repairs`'
                .' for each row begin set NEW.updated_at = NEW.updated_at; end',
            );
            DB::statement(
                'alter table `notification_inbound_email_fanouts`'
                .' add constraint `notif_inbound_fanout_contract_ck` check (1 = 1)',
            );
            DB::unprepared(
                'create trigger `notif_inbound_fanout_initial`'
                .' before insert on `notification_inbound_email_fanouts`'
                .' for each row begin set NEW.updated_at = NEW.updated_at; end',
            );
            DB::unprepared(
                'create trigger `notif_inbound_fanout_monotonic`'
                .' before update on `notification_inbound_email_fanouts`'
                .' for each row begin set NEW.updated_at = NEW.updated_at; end',
            );
            DB::statement(
                'alter table `notification_inbound_external_deliveries`'
                .' add constraint `notif_inbound_ext_state_ck` check (1 = 1)',
            );
            DB::unprepared(
                'create trigger `notif_inbound_ext_initial`'
                .' before insert on `notification_inbound_external_deliveries`'
                .' for each row begin set NEW.updated_at = NEW.updated_at; end',
            );
            DB::unprepared(
                'create trigger `notif_inbound_ext_monotonic`'
                .' before update on `notification_inbound_external_deliveries`'
                .' for each row begin set NEW.updated_at = NEW.updated_at; end',
            );
            // A retry must replace old same-named definitions rather than
            // treating their names as proof of the final state machine.
            $migration->up();
            $this->sealFanoutMigration();

            foreach ([
                ['ticket_messages', 'ticket_messages_source_inbound_email_uq'],
                ['ticket_messages', 'ticket_messages_ticket_source_inbound_ix'],
                ['notification_settings', 'notification_settings_type_cursor_ix'],
                ['email_remote_operations', 'email_remote_ops_unresolved_placement_ix'],
                ['email_remote_operations', 'email_remote_ops_placement_status_cursor_ix'],
                ['email_remote_operations', 'email_remote_ops_unresolved_folder_ix'],
                ['email_provider_reconciliation_items', 'em_recon_items_import_recovery_due_ix'],
                ['email_provider_reconciliation_items', 'em_recon_items_automation_recovery_due_ix'],
                ['email_provider_reconciliation_items', 'em_recon_items_baseline_recovery_due_ix'],
                ['notification_inbound_external_deliveries', 'notif_inbound_ext_status_cursor_ix'],
                ['notification_inbound_external_deliveries', 'notif_inbound_ext_fanout_status_ix'],
                ['notification_inbound_email_fanouts', 'notif_inbound_fanout_due_ix'],
                ['notification_inbound_email_fanouts', 'notif_inbound_fanout_status_cursor_ix'],
            ] as [$table, $index]) {
                $this->assertTrue(Schema::hasIndex($table, $index), "Missing MariaDB index {$index}.");
            }

            $setting = DB::table('notification_settings')->where('id', 1)->first();
            $this->assertNotNull($setting);
            $this->assertRejects(fn () => DB::table('notification_settings')
                ->where('id', 1)
                ->update(['notification_type' => 'repurposed']));

            $this->assertRejects(fn () => DB::table('ticket_messages')
                ->where('id', 1)
                ->update(['source_inbound_email_message_id' => 1]));
            $this->assertSame(1, DB::table('ticket_messages')->where('id', 1)->update([
                'source_inbound_email_message_id' => 1,
                'inbound_email_message_id' => 1,
            ]));
            $this->assertRejects(fn () => DB::table('ticket_messages')->where('id', 1)->delete());
            $this->assertRejects(fn () => DB::table('tickets')->where('id', 1)->delete());
            $this->assertSame(1, DB::table('ticket_messages')->where('id', 1)->update([
                'deleted_at' => now(),
            ]));

            DB::table('tickets')->insert(['id' => 2]);
            $this->assertRejects(fn () => DB::table('ticket_messages')->insert([
                'id' => 2,
                'ticket_id' => 2,
                'source_inbound_email_message_id' => 2,
                'inbound_email_message_id' => null,
                'metadata' => json_encode(['email_message_id' => 2], JSON_THROW_ON_ERROR),
                'deleted_at' => null,
            ]));
            DB::table('ticket_messages')->insert([
                'id' => 2,
                'ticket_id' => 2,
                'source_inbound_email_message_id' => 2,
                'inbound_email_message_id' => 2,
                'metadata' => json_encode(['email_message_id' => 2], JSON_THROW_ON_ERROR),
                'deleted_at' => null,
            ]);
            $this->assertRejects(fn () => DB::table('ticket_messages')->where('id', 2)->delete());

            $this->assertRejects(fn () => DB::table('ticket_messages')->insert([
                'id' => 4,
                'ticket_id' => 2,
                'metadata' => json_encode(['email_message_id' => 2], JSON_THROW_ON_ERROR),
                'deleted_at' => null,
            ]));

            DB::table('tickets')->insert(['id' => 3]);
            DB::table('ticket_messages')->insert([
                'id' => 3,
                'ticket_id' => 3,
                'metadata' => null,
                'deleted_at' => null,
            ]);
            $this->assertRejects(fn () => DB::table('ticket_messages')->where('id', 3)->update([
                'metadata' => json_encode(['email_message_id' => 1], JSON_THROW_ON_ERROR),
            ]));
            $this->assertSame(1, DB::table('ticket_messages')->where('id', 3)->delete());
            $this->assertSame(1, DB::table('tickets')->where('id', 3)->delete());

            $repair = DB::table('notification_inbound_ticket_message_repairs')->where('id', 1)->first();
            $this->assertSame('pending', $repair?->status);
            $this->assertRejects(fn () => DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)
                ->update([
                    'status' => 'completed',
                    'cursor_id' => $repair->through_id,
                    'completed_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)
                ->update([
                    'status' => 'failed',
                    'last_attempt_at' => now(),
                    'completed_at' => now(),
                    'error_code' => null,
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_ticket_message_repairs')->insert([
                'id' => 2,
                'status' => 'pending',
                'through_id' => 0,
                'cursor_id' => 0,
                'page_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)->delete());

            $this->assertRejects(fn () => DB::table('notification_inbound_email_fanouts')->insert([
                'email_message_id' => 2,
                'source_email_message_id' => 2,
                'email_account_id' => 1,
                'notification_setting_through_id' => 105,
                'notification_setting_cursor_id' => 0,
                'owner_candidate_processed' => false,
                'owner_priority_reserved' => false,
                'status' => 'pending',
                'page_attempt_count' => 100,
                'page_count' => 0,
                'last_attempt_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $fanoutId = (int) DB::table('notification_inbound_email_fanouts')->insertGetId([
                'email_message_id' => 2,
                'source_email_message_id' => 2,
                'email_account_id' => 1,
                'notification_setting_through_id' => 105,
                'notification_setting_cursor_id' => 0,
                'owner_candidate_processed' => false,
                'owner_priority_reserved' => false,
                'status' => 'pending',
                'page_attempt_count' => 0,
                'page_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $fanoutToken = hash('sha256', 'mariadb-fanout-claim');
            $this->assertRejects(fn () => DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)
                ->update([
                    'status' => 'running',
                    'claim_token' => $fanoutToken,
                    'page_setting_through_id' => 105,
                    'page_setting_row_count' => 101,
                    'page_owner_pending' => true,
                    'page_owner_candidate_included' => false,
                    'page_attempt_count' => 1,
                    'last_attempt_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)
                ->update([
                    'id' => $fanoutId + 100,
                    'status' => 'running',
                    'claim_token' => $fanoutToken,
                    'page_setting_through_id' => 100,
                    'page_setting_row_count' => 100,
                    'page_owner_pending' => true,
                    'page_owner_candidate_included' => false,
                    'page_attempt_count' => 1,
                    'last_attempt_at' => now(),
                ]));
            $this->assertSame(1, DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)
                ->update([
                    'status' => 'running',
                    'claim_token' => $fanoutToken,
                    'page_setting_through_id' => 100,
                    'page_setting_row_count' => 100,
                    'page_owner_pending' => true,
                    'page_owner_candidate_included' => false,
                    'page_attempt_count' => 1,
                    'last_attempt_at' => now(),
                    'updated_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)
                ->update([
                    'notification_setting_cursor_id' => 105,
                    'owner_candidate_processed' => true,
                    'status' => 'completed',
                    'claim_token' => null,
                    'page_setting_through_id' => null,
                    'page_setting_row_count' => null,
                    'page_owner_pending' => null,
                    'page_owner_candidate_included' => null,
                    'page_attempt_count' => 0,
                    'page_count' => 1,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]));
            $this->assertSame(1, DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)
                ->update([
                    'notification_setting_cursor_id' => 100,
                    'owner_candidate_processed' => true,
                    'status' => 'pending',
                    'claim_token' => null,
                    'page_setting_through_id' => null,
                    'page_setting_row_count' => null,
                    'page_owner_pending' => null,
                    'page_owner_candidate_included' => null,
                    'page_attempt_count' => 0,
                    'page_count' => 1,
                    'completed_at' => null,
                    'updated_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)->delete());

            $invalidNotificationId = (string) Str::uuid();
            DB::table('notifications')->insert(['id' => $invalidNotificationId]);
            $this->assertRejects(fn () => DB::table('notification_inbound_external_deliveries')->insert([
                'notification_id' => $invalidNotificationId,
                'user_id' => 1,
                'inbound_notification_fanout_id' => $fanoutId,
                'canonical_payload_hash' => hash('sha256', 'mariadb-null-mail-scope'),
                'requested_mail' => true,
                'requested_web_push' => false,
                'requested_nextcloud_talk' => false,
                'mail_scope' => null,
                'mail_account_id' => 1,
                'mail_provider_binding_version' => 1,
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $notificationId = (string) Str::uuid();
            DB::table('notifications')->insert(['id' => $notificationId]);
            $externalId = (int) DB::table('notification_inbound_external_deliveries')->insertGetId([
                'notification_id' => $notificationId,
                'user_id' => 1,
                'inbound_notification_fanout_id' => $fanoutId,
                'canonical_payload_hash' => hash('sha256', 'mariadb-canonical-payload'),
                'requested_mail' => false,
                'requested_web_push' => true,
                'requested_nextcloud_talk' => false,
                'status' => 'pending',
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $externalToken = hash('sha256', 'mariadb-external-claim');
            $this->assertRejects(fn () => DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)
                ->update([
                    'status' => 'running',
                    'claim_token' => 'not-a-valid-token',
                    'attempt_count' => 1,
                    'last_attempt_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)
                ->update([
                    'id' => $externalId + 100,
                    'status' => 'running',
                    'claim_token' => $externalToken,
                    'attempt_count' => 1,
                    'last_attempt_at' => now(),
                ]));
            $this->assertSame(1, DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)
                ->update([
                    'status' => 'running',
                    'claim_token' => $externalToken,
                    'attempt_count' => 1,
                    'last_attempt_at' => now(),
                    'updated_at' => now(),
                ]));
            // All three live SET NULL authorities must detach without changing
            // their immutable source/evidence snapshots. The final guarded
            // rerun proves that detached base shapes remain valid, while the
            // worker can still terminalize its already-claimed delivery.
            $ticketBeforeDetach = (array) DB::table('ticket_messages')->where('id', 1)->first();
            $fanoutBeforeDetach = (array) DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)->first();
            $externalBeforeDetach = (array) DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)->first();
            $this->assertSame(1, DB::table('email_messages')->where('id', 1)->delete());
            $this->assertSame(1, DB::table('email_messages')->where('id', 2)->delete());
            $this->assertSame(1, DB::table('notifications')->where('id', $notificationId)->delete());
            $this->assertSame(1, DB::table('user_management')->where('id', 1)->delete());
            $ticketAfterDetach = (array) DB::table('ticket_messages')->where('id', 1)->first();
            $fanoutAfterDetach = (array) DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)->first();
            $externalAfterDetach = (array) DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)->first();
            $ticketBeforeDetach['inbound_email_message_id'] = null;
            $fanoutBeforeDetach['email_message_id'] = null;
            $externalBeforeDetach['notification_id'] = null;
            $externalBeforeDetach['user_id'] = null;
            $this->assertSame($ticketBeforeDetach, $ticketAfterDetach);
            $this->assertSame($fanoutBeforeDetach, $fanoutAfterDetach);
            $this->assertSame($externalBeforeDetach, $externalAfterDetach);
            $this->assertSame(1, DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)
                ->update([
                    'status' => 'completed',
                    'claim_token' => null,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]));
            $this->assertRejects(fn () => DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalId)->delete());
            $migration->up();

            $this->assertMariaExplainUsesIndex(
                "select id from email_remote_operations force index (`email_remote_ops_placement_status_cursor_ix`) where email_mailbox_placement_id = 1 and status = 'pending' order by id limit 1",
                'email_remote_ops_placement_status_cursor_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from email_remote_operations force index (`email_remote_ops_unresolved_placement_ix`) where email_mailbox_placement_id = 1 and status = 'failed' and reconciled_at is null and failure_classification = 'ambiguous' order by id limit 1",
                'email_remote_ops_unresolved_placement_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from email_remote_operations force index (`email_remote_ops_unresolved_folder_ix`) where email_folder_id = 1 and status = 'failed' and reconciled_at is null and failure_classification = 'transient' limit 1",
                'email_remote_ops_unresolved_folder_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from email_provider_reconciliation_items force index (`em_recon_items_import_recovery_due_ix`) where email_provider_reconciliation_run_id = 1 and kind = 'import' and status = 'running' and last_attempt_at <= '2026-08-16 00:00:00' order by last_attempt_at, id limit 25",
                'em_recon_items_import_recovery_due_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from email_provider_reconciliation_items force index (`em_recon_items_automation_recovery_due_ix`) where email_provider_reconciliation_run_id = 1 and kind = 'import' and automation_required = 1 and automation_status = 'awaiting_notification_fanout' order by automation_last_attempt_at, id limit 25",
                'em_recon_items_automation_recovery_due_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from email_provider_reconciliation_items force index (`em_recon_items_baseline_recovery_due_ix`) where email_provider_reconciliation_run_id = 1 and kind = 'import' and historical_baseline_required = 1 and historical_baseline_status = 'running' and historical_baseline_last_attempt_at <= '2026-08-16 00:00:00' order by historical_baseline_last_attempt_at, id limit 25",
                'em_recon_items_baseline_recovery_due_ix',
            );
            $this->assertMariaExplainUsesIndex(
                'select id from ticket_messages force index (`ticket_messages_ticket_source_inbound_ix`) where ticket_id = 1 and source_inbound_email_message_id is not null limit 1',
                'ticket_messages_ticket_source_inbound_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from notification_inbound_email_fanouts force index (`notif_inbound_fanout_status_cursor_ix`) where status = 'pending' order by id limit 50",
                'notif_inbound_fanout_status_cursor_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from notification_inbound_email_fanouts force index (`notif_inbound_fanout_due_ix`) where status = 'running' and last_attempt_at <= '2026-08-16 00:00:00' order by last_attempt_at, id limit 25",
                'notif_inbound_fanout_due_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from notification_inbound_external_deliveries force index (`notif_inbound_ext_status_cursor_ix`) where status = 'pending' order by id limit 50",
                'notif_inbound_ext_status_cursor_ix',
            );
            $this->assertMariaExplainUsesIndex(
                "select id from notification_inbound_external_deliveries force index (`notif_inbound_ext_due_ix`) where status = 'running' and last_attempt_at <= '2026-08-16 00:00:00' order by last_attempt_at, id limit 25",
                'notif_inbound_ext_due_ix',
            );
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    #[Test]
    public function actual_mariadb_server_rejects_unsealed_partial_fanout_evidence_and_resumes_after_repair(): void
    {
        if (getenv('TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT') !== '1') {
            $this->markTestSkipped('Set TDPSA_EMAIL_PROVIDER_MARIADB_CONTRACT=1 to run the isolated MariaDB contract.');
        }

        try {
            $this->connectIsolatedDatabase();
            $this->createFanoutContractTables();
            $migration = require database_path(
                'migrations/2026_08_16_118500_add_durable_inbound_notification_fanout.php',
            );
            $migration->up();

            $notificationId = (string) Str::uuid();
            DB::table('notifications')->insert(['id' => $notificationId]);
            foreach ([
                'dropExternalDeliveryDeleteGuard',
                'dropExternalDeliveryMonotonicGuard',
                'dropExternalDeliveryInitialGuard',
                'dropExternalDeliveryGuard',
            ] as $dropGuard) {
                $this->invoke($migration, $dropGuard);
            }
            $deliveryId = (int) DB::table('notification_inbound_external_deliveries')->insertGetId([
                'notification_id' => $notificationId,
                'user_id' => 1,
                'inbound_notification_fanout_id' => null,
                'canonical_payload_hash' => null,
                'requested_mail' => true,
                'requested_web_push' => false,
                'requested_nextcloud_talk' => false,
                'mail_scope' => null,
                'mail_account_id' => null,
                'mail_provider_binding_version' => null,
                'mail_snapshot_failure_code' => null,
                'status' => 'pending',
                'claim_token' => null,
                'attempt_count' => 0,
                'last_attempt_at' => null,
                'completed_at' => null,
                'error_code' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound notification external-delivery evidence is malformed.',
            );
            $this->assertFalse($this->fanoutMigrationIsSealed());
            DB::table('notification_inbound_external_deliveries')->where('id', $deliveryId)->update([
                'requested_mail' => false,
                'requested_web_push' => true,
            ]);
            $migration->up();

            foreach ([
                'dropExternalDeliveryDeleteGuard',
                'dropExternalDeliveryMonotonicGuard',
                'dropExternalDeliveryInitialGuard',
                'dropExternalDeliveryGuard',
            ] as $dropGuard) {
                $this->invoke($migration, $dropGuard);
            }
            DB::table('notification_inbound_external_deliveries')->where('id', $deliveryId)->update([
                'canonical_payload_hash' => hash('sha256', 'unsealed-external-linkage'),
            ]);
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound notification external linkage exists before the fanout schema seal.',
            );
            DB::table('notification_inbound_external_deliveries')->where('id', $deliveryId)->update([
                'canonical_payload_hash' => null,
            ]);
            $migration->up();

            $this->assertSame(1, DB::table('ticket_messages')->where('id', 1)->update([
                'source_inbound_email_message_id' => 1,
                'inbound_email_message_id' => 1,
            ]));
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound Ticket-message pointer evidence exists before the fanout schema seal.',
            );
            foreach ([
                'dropTicketMessageMetadataGuard',
                'dropTicketMessagePointerMonotonicGuard',
                'dropTicketMessagePointerInitialGuard',
                'dropTicketMessagePointerGuard',
            ] as $dropGuard) {
                $this->invoke($migration, $dropGuard);
            }
            DB::table('ticket_messages')->where('id', 1)->update([
                'source_inbound_email_message_id' => null,
                'inbound_email_message_id' => null,
            ]);
            $migration->up();

            $fanoutId = (int) DB::table('notification_inbound_email_fanouts')->insertGetId([
                'email_message_id' => 2,
                'source_email_message_id' => 2,
                'email_account_id' => 1,
                'notification_setting_through_id' => 105,
                'notification_setting_cursor_id' => 0,
                'owner_candidate_processed' => false,
                'owner_priority_reserved' => false,
                'status' => 'pending',
                'page_attempt_count' => 0,
                'page_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound notification fanout evidence exists before the fanout schema seal.',
            );
            foreach ([
                'dropFanoutDeleteGuard',
                'dropFanoutMonotonicGuard',
                'dropFanoutInitialGuard',
                'dropFanoutGuard',
            ] as $dropGuard) {
                $this->invoke($migration, $dropGuard);
            }
            $this->assertSame(1, DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanoutId)->delete());
            $migration->up();

            $repairToken = hash('sha256', 'unsealed-repair-progress');
            $this->assertSame(1, DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)->update([
                    'status' => 'running',
                    'claim_token' => $repairToken,
                    'page_through_id' => 1,
                    'page_row_count' => 1,
                    'last_attempt_at' => now(),
                    'updated_at' => now(),
                ]));
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound Ticket-message repair progressed before the fanout schema seal.',
            );
            foreach ([
                'dropRepairMonotonicGuard',
                'dropRepairGuard',
            ] as $dropGuard) {
                $this->invoke($migration, $dropGuard);
            }
            DB::table('notification_inbound_ticket_message_repairs')->where('id', 1)->update([
                'status' => 'pending',
                'claim_token' => null,
                'page_through_id' => null,
                'page_row_count' => null,
                'last_attempt_at' => null,
            ]);
            $migration->up();

            $this->invoke($migration, 'dropRepairDeleteGuard');
            $this->invoke($migration, 'dropRepairMonotonicGuard');
            $this->invoke($migration, 'dropRepairGuard');
            DB::table('notification_inbound_ticket_message_repairs')->insert([
                'id' => 2,
                'status' => 'pending',
                'through_id' => 0,
                'cursor_id' => 0,
                'page_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound Ticket-message repair progressed before the fanout schema seal.',
            );
            DB::table('notification_inbound_ticket_message_repairs')->where('id', 2)->delete();
            $migration->up();

            DB::table('tickets')->insert(['id' => 2]);
            DB::table('ticket_messages')->insert([
                'id' => 2,
                'ticket_id' => 2,
                'metadata' => null,
                'deleted_at' => null,
            ]);
            // An unrelated comment above the frozen cursor is legal.
            $migration->up();
            DB::table('ticket_messages')->insert([
                'id' => 3,
                'ticket_id' => 2,
                'metadata' => json_encode(['email_message_id' => null], JSON_THROW_ON_ERROR),
                'deleted_at' => null,
            ]);
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Inbound Ticket-message repair scope changed before the fanout schema seal.',
            );
            $this->assertSame(1, DB::table('ticket_messages')->where('id', 3)->delete());
            $migration->up();

            $this->invoke($migration, 'dropRepairDeleteGuard');
            $this->assertSame(1, DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)->delete());
            $this->assertRejects(fn () => DB::table('notification_inbound_ticket_message_repairs')->insert([
                'id' => 1,
                'status' => 'completed',
                'through_id' => 1,
                'cursor_id' => 1,
                'page_count' => 0,
                'completed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]));
            $migration->up();

            $repair = DB::table('notification_inbound_ticket_message_repairs')->where('id', 1)->first();
            $this->assertSame(2, (int) $repair?->through_id);
            $terminalToken = hash('sha256', 'sealed-repair-terminal');
            $this->assertSame(1, DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)->update([
                    'status' => 'running',
                    'claim_token' => $terminalToken,
                    'page_through_id' => 2,
                    'page_row_count' => 2,
                    'last_attempt_at' => now(),
                    'updated_at' => now(),
                ]));
            $this->assertSame(1, DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)->update([
                    'status' => 'completed',
                    'cursor_id' => 2,
                    'claim_token' => null,
                    'page_through_id' => null,
                    'page_row_count' => null,
                    'page_count' => 1,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]));
            $this->sealFanoutMigration();
            $migration->up();

            $this->invoke($migration, 'dropRepairDeleteGuard');
            $this->assertSame(1, DB::table('notification_inbound_ticket_message_repairs')
                ->where('id', 1)->delete());
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Sealed inbound Ticket-message repair evidence is missing.',
            );
            Schema::drop('notification_inbound_ticket_message_repairs');
            $this->assertMigrationRuntimeFailure(
                fn () => $migration->up(),
                'Sealed inbound Ticket-message repair evidence is missing.',
            );
            $this->assertTrue($this->fanoutMigrationIsSealed());
        } finally {
            $this->disconnectAndDropIsolatedDatabase();
        }
    }

    private function connectIsolatedDatabase(): void
    {
        $mysql = (array) config('database.connections.mysql');
        $host = (string) ($mysql['host'] ?? '127.0.0.1');
        $port = (int) ($mysql['port'] ?? 3306);
        $username = (string) ($mysql['username'] ?? '');
        $password = (string) ($mysql['password'] ?? '');
        $socket = trim((string) getenv('TDPSA_EMAIL_PROVIDER_MARIADB_SOCKET'));
        $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
        if ($socket !== '') {
            $dsn = "mysql:unix_socket={$socket};charset=utf8mb4";
            $username = 'root';
            $password = '';
            $mysql['host'] = 'localhost';
            $mysql['port'] = null;
            $mysql['unix_socket'] = $socket;
            $mysql['username'] = $username;
            $mysql['password'] = $password;
            $mysql['options'] = [];
        }
        $this->databaseName = 'tdpsa_reconciliation_contract_'.strtolower(Str::random(12));
        if (preg_match('/^tdpsa_reconciliation_contract_[a-z0-9]{12}$/', $this->databaseName) !== 1) {
            throw new RuntimeException('The isolated MariaDB contract name failed validation.');
        }

        $this->server = new PDO(
            $dsn,
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->server->exec(
            'CREATE DATABASE `'.$this->databaseName.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );

        $this->originalDefaultConnection = (string) config('database.default');
        config()->set('database.connections.reconciliation_mariadb_contract', [
            ...$mysql,
            // Plesk exposes MariaDB through Laravel's ordinary mysql driver.
            // This must exercise that production branch, not only the explicit
            // mariadb connector alias.
            'driver' => 'mysql',
            'database' => $this->databaseName,
        ]);
        config()->set('database.default', 'reconciliation_mariadb_contract');
        DB::purge('reconciliation_mariadb_contract');
        DB::connection('reconciliation_mariadb_contract')->getPdo();
        $this->assertSame('mysql', DB::getDriverName());
    }

    private function createGuardTables(): void
    {
        Schema::create('email_provider_reconciliation_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('provider_binding_version');
            $table->string('status', 40)->default('running');
            $table->string('phase', 32)->default('discover_end');
            $table->unsignedTinyInteger('active_slot')->nullable();
            $table->string('local_folder_snapshot_status', 16)->nullable();
            $table->unsignedBigInteger('local_folder_snapshot_through_id')->default(0);
            $table->unsignedBigInteger('local_folder_snapshot_cursor_id')->default(0);
            $table->unsignedBigInteger('local_folder_snapshot_count')->default(0);
            $table->char('local_folder_snapshot_hash', 64)->nullable();
            $table->unsignedInteger('local_folder_snapshot_batch_count')->default(0);
            $table->dateTime('local_folder_snapshot_started_at')->nullable();
            $table->dateTime('local_folder_snapshot_completed_at')->nullable();
            $table->boolean('automation_scope_unsafe')->default(false);
            $table->string('automation_scope_error_code', 80)->nullable();
            $table->dateTime('automation_scope_unsafe_at')->nullable();
            $table->string('final_summary_status', 16)->nullable();
            $table->unsignedBigInteger('final_summary_folder_through_id')->default(0);
            $table->unsignedBigInteger('final_summary_folder_cursor_id')->default(0);
            $table->unsignedBigInteger('final_summary_item_through_id')->default(0);
            $table->unsignedBigInteger('final_summary_item_cursor_id')->default(0);
            $table->unsignedInteger('final_summary_complete_folder_count')->default(0);
            $table->unsignedBigInteger('final_summary_missing_count')->default(0);
            $table->unsignedBigInteger('final_summary_move_count')->default(0);
            $table->unsignedBigInteger('final_summary_conflict_count')->default(0);
            $table->unsignedBigInteger('final_summary_error_count')->default(0);
            $table->boolean('final_summary_blocked')->default(false);
            $table->boolean('final_summary_failed')->default(false);
            $table->boolean('final_summary_stale')->default(false);
            $table->boolean('final_summary_automation_failed')->default(false);
            $table->unsignedInteger('final_summary_batch_count')->default(0);
            $table->dateTime('final_summary_started_at')->nullable();
            $table->dateTime('final_summary_completed_at')->nullable();
            $table->unsignedInteger('complete_folder_count')->default(0);
            $table->unsignedBigInteger('missing_count')->default(0);
            $table->unsignedBigInteger('move_count')->default(0);
            $table->unsignedBigInteger('conflict_count')->default(0);
            $table->unsignedBigInteger('error_count')->default(0);
            $table->dateTime('finished_at')->nullable();
        });
        Schema::create('notification_inbound_external_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->boolean('requested_mail')->default(false);
            $table->boolean('requested_web_push')->default(false);
            $table->boolean('requested_nextcloud_talk')->default(false);
            $table->string('mail_scope', 24)->nullable();
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->unsignedInteger('mail_provider_binding_version')->nullable();
            $table->string('mail_snapshot_failure_code', 80)->nullable();
            $table->string('status', 24)->default('pending');
        });
        Schema::create('email_provider_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_provider_reconciliation_run_id')->nullable();
            $table->unsignedBigInteger('email_provider_reconciliation_folder_id')->nullable();
            $table->string('kind', 32)->default('import');
            $table->string('status', 32)->default('projected');
            $table->boolean('historical_baseline_required')->default(false);
            $table->string('historical_baseline_status', 32)->nullable();
            $table->unsignedBigInteger('historical_baseline_max_id')->default(0);
            $table->unsignedBigInteger('historical_baseline_cursor_id')->default(0);
            $table->char('historical_baseline_claim_token', 64)->nullable();
            $table->unsignedInteger('historical_baseline_attempt_count')->default(0);
            $table->dateTime('historical_baseline_frozen_at')->nullable();
            $table->dateTime('historical_baseline_first_attempt_at')->nullable();
            $table->dateTime('historical_baseline_last_attempt_at')->nullable();
            $table->dateTime('historical_baseline_completed_at')->nullable();
            $table->string('historical_baseline_error_code', 80)->nullable();
            $table->boolean('automation_required')->default(false);
            $table->string('automation_status', 32)->nullable();
            $table->char('automation_claim_token', 64)->nullable();
            $table->unsignedSmallInteger('automation_attempt_count')->default(0);
            $table->dateTime('automation_last_attempt_at')->nullable();
            $table->dateTime('automation_completed_at')->nullable();
            $table->string('automation_error_code', 80)->nullable();
            $table->unsignedBigInteger('automation_rule_attempt_floor_id')->nullable();
        });
        Schema::create('email_provider_reconciliation_folders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_provider_reconciliation_run_id')->nullable();
            $table->string('status', 40)->default('pending');
            $table->boolean('supports_modseq')->default(false);
            $table->unsignedBigInteger('scan_through_uid')->default(5);
            $table->string('metadata_verification_status', 16)->nullable();
            $table->unsignedBigInteger('metadata_verification_next_uid')->default(1);
            $table->unsignedBigInteger('metadata_verification_count')->default(0);
            $table->char('metadata_verification_hash', 64)->nullable();
            $table->unsignedInteger('metadata_verification_batch_count')->default(0);
            $table->dateTime('metadata_verification_started_at')->nullable();
            $table->dateTime('metadata_verification_completed_at')->nullable();
            $table->string('item_summary_status', 16)->nullable();
            $table->unsignedBigInteger('item_summary_through_id')->default(0);
            $table->unsignedBigInteger('item_summary_cursor_id')->default(0);
            $table->unsignedBigInteger('item_summary_missing_count')->default(0);
            $table->unsignedBigInteger('item_summary_move_count')->default(0);
            $table->unsignedBigInteger('item_summary_conflict_count')->default(0);
            $table->boolean('item_summary_nonterminal')->default(false);
            $table->unsignedInteger('item_summary_batch_count')->default(0);
            $table->dateTime('item_summary_started_at')->nullable();
            $table->dateTime('item_summary_completed_at')->nullable();
            $table->unsignedBigInteger('missing_count')->default(0);
            $table->unsignedBigInteger('conflict_count')->default(0);
            $table->string('placement_snapshot_purpose', 40)->nullable();
            $table->string('placement_snapshot_status', 16)->nullable();
            $table->unsignedBigInteger('placement_snapshot_through_id')->default(0);
            $table->unsignedBigInteger('placement_snapshot_cursor_id')->default(0);
            $table->unsignedBigInteger('placement_snapshot_count')->default(0);
            $table->char('placement_snapshot_hash', 64)->nullable();
            $table->unsignedInteger('placement_snapshot_batch_count')->default(0);
            $table->dateTime('placement_snapshot_started_at')->nullable();
            $table->dateTime('placement_snapshot_completed_at')->nullable();
        });
        Schema::create('email_remote_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('acknowledged_target_uid_validity')->nullable();
            $table->unsignedBigInteger('acknowledged_target_uid')->nullable();
        });
        Schema::create('email_mailbox_placements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('last_provider_reconciliation_run_id')->nullable();
            $table->unsignedInteger('last_provider_observed_sync_version')->nullable();
            $table->char('last_provider_observed_identity_hash', 64)->nullable();
            $table->dateTime('last_provider_observed_at')->nullable();
        });
    }

    /** Build only the pre-118500 columns needed by the additive fanout migration. */
    private function createFanoutContractTables(): void
    {
        Schema::create('email_messages', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('email_messages')->insert([['id' => 1], ['id' => 2]]);

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
        });
        Schema::create('user_management', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('user_management')->insert(['id' => 1]);

        Schema::create('tickets', function (Blueprint $table): void {
            $table->id();
        });
        DB::table('tickets')->insert(['id' => 1]);

        Schema::create('ticket_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('tickets')->cascadeOnDelete();
            $table->json('metadata')->nullable();
            $table->dateTime('deleted_at')->nullable();
        });
        DB::table('ticket_messages')->insert([
            'id' => 1,
            'ticket_id' => 1,
            'metadata' => json_encode(['email_message_id' => 1], JSON_THROW_ON_ERROR),
            'deleted_at' => null,
        ]);

        Schema::create('notification_settings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('notification_type', 100);
        });
        DB::table('notification_settings')->insert([
            'id' => 1,
            'user_id' => 1,
            'notification_type' => 'inbound_email_received',
        ]);
        DB::table('notification_settings')->insert(array_map(
            static fn (int $id): array => [
                'id' => $id,
                'user_id' => $id,
                'notification_type' => 'inbound_email_received',
            ],
            range(2, 105),
        ));

        Schema::create('email_remote_operations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_mailbox_placement_id')->nullable();
            $table->unsignedBigInteger('email_folder_id')->nullable();
            $table->string('status', 24);
            $table->dateTime('reconciled_at')->nullable();
            $table->string('failure_classification', 24)->nullable();
        });

        Schema::create('email_provider_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('email_provider_reconciliation_run_id');
            $table->string('kind', 32);
            $table->string('status', 32);
            $table->dateTime('last_attempt_at')->nullable();
            $table->boolean('automation_required')->default(false);
            $table->string('automation_status', 40)->nullable();
            $table->dateTime('automation_last_attempt_at')->nullable();
            $table->boolean('historical_baseline_required')->default(false);
            $table->string('historical_baseline_status', 40)->nullable();
            $table->dateTime('historical_baseline_last_attempt_at')->nullable();
        });

        Schema::create('notification_inbound_external_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('notification_id')->nullable()->unique();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->boolean('requested_mail')->default(false);
            $table->boolean('requested_web_push')->default(false);
            $table->boolean('requested_nextcloud_talk')->default(false);
            $table->string('mail_scope', 24)->nullable();
            $table->unsignedBigInteger('mail_account_id')->nullable();
            $table->unsignedInteger('mail_provider_binding_version')->nullable();
            $table->string('mail_snapshot_failure_code', 80)->nullable();
            $table->string('status', 24)->default('pending');
            $table->char('claim_token', 64)->nullable();
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->dateTime('last_attempt_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->foreign('notification_id', 'notif_inbound_ext_notification_fk')
                ->references('id')->on('notifications')->nullOnDelete();
            $table->foreign('user_id', 'notif_inbound_ext_user_fk')
                ->references('id')->on('user_management')->nullOnDelete();
            $table->index(['status', 'last_attempt_at', 'id'], 'notif_inbound_ext_due_ix');
        });
    }

    private function assertMariaExplainUsesIndex(string $query, string $expectedIndex): void
    {
        $row = (array) DB::selectOne('explain '.$query);
        $this->assertSame($expectedIndex, $row['key'] ?? null);
        $extra = (string) ($row['Extra'] ?? $row['extra'] ?? '');
        $this->assertStringNotContainsString('Using filesort', $extra);
        $this->assertStringNotContainsString('Using temporary', $extra);
    }

    private function sealFanoutMigration(): void
    {
        if (! Schema::hasTable('migrations')) {
            Schema::create('migrations', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('migration');
                $table->integer('batch');
            });
        }

        DB::table('migrations')->insertOrIgnore([
            'migration' => '2026_08_16_118500_add_durable_inbound_notification_fanout',
            'batch' => 1,
        ]);
    }

    private function fanoutMigrationIsSealed(): bool
    {
        return Schema::hasTable('migrations')
            && DB::table('migrations')
                ->where('migration', '2026_08_16_118500_add_durable_inbound_notification_fanout')
                ->exists();
    }

    private function assertMigrationRuntimeFailure(callable $callback, string $message): void
    {
        try {
            $callback();
            $this->fail('The partial fanout migration should fail before its repository seal.');
        } catch (RuntimeException $exception) {
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function invoke(Migration $migration, string $method): void
    {
        (new ReflectionMethod($migration, $method))->invoke($migration);
    }

    private function assertRejects(callable $callback): void
    {
        try {
            $callback();
            $this->fail('MariaDB must enforce the order-seven database guard.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    private function disconnectAndDropIsolatedDatabase(): void
    {
        if (isset($this->originalDefaultConnection)) {
            DB::disconnect('reconciliation_mariadb_contract');
            config()->set('database.default', $this->originalDefaultConnection);
            DB::purge('reconciliation_mariadb_contract');
        }

        if ($this->server && $this->databaseName) {
            if (preg_match('/^tdpsa_reconciliation_contract_[a-z0-9]{12}$/', $this->databaseName) !== 1) {
                throw new RuntimeException('Refusing to drop an unvalidated MariaDB contract database.');
            }
            $this->server->exec('DROP DATABASE IF EXISTS `'.$this->databaseName.'`');
        }

        $this->server = null;
        $this->databaseName = null;
    }
}
