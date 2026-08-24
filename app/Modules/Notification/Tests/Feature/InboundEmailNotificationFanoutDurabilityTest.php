<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Email\Actions\StartEmailProviderReconciliation;
use App\Modules\Email\Jobs\EmailRetentionPurgeJob;
use App\Modules\Email\Jobs\ProcessEmailProviderReconciliationAutomation;
use App\Modules\Email\Jobs\ProcessInboundRules;
use App\Modules\Email\Jobs\ReconcileEmailProviderAccount;
use App\Modules\Email\Jobs\ResumeInboundRulesAfterFanoutReady;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailBreakGlassAccess;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailFolderUidNamespace;
use App\Modules\Email\Models\EmailMailboxAccessEvent;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailProviderReconciliationFolder;
use App\Modules\Email\Models\EmailProviderReconciliationItem;
use App\Modules\Email\Models\EmailProviderReconciliationRun;
use App\Modules\Email\Models\EmailRetentionPurgeAttempt;
use App\Modules\Email\Services\EmailProviderReconciliationReadException;
use App\Modules\Email\Services\EmailRetentionEligibilityService;
use App\Modules\Email\Services\ResolveMailboxAccessDecision;
use App\Modules\Notification\Actions\DispatchInboundEmailNotification;
use App\Modules\Notification\Actions\RecordCanonicalNotification;
use App\Modules\Notification\Actions\ResolveInboundEmailNotificationRecipients;
use App\Modules\Notification\Contracts\InboundEmailExternalNotificationDispatcher;
use App\Modules\Notification\Jobs\DeliverInboundEmailExternalNotification;
use App\Modules\Notification\Jobs\DispatchPendingInboundEmailExternalNotifications;
use App\Modules\Notification\Jobs\DispatchPendingInboundEmailNotificationFanouts;
use App\Modules\Notification\Jobs\ProcessInboundEmailNotificationFanout;
use App\Modules\Notification\Models\NotificationInboundEmailFanout;
use App\Modules\Notification\Models\NotificationInboundExternalDelivery;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Notifications\InboundEmailRoutedNotification;
use App\Modules\Notification\Services\InboundEmailNotificationFanoutReadiness;
use App\Modules\Notification\Support\CanonicalNotificationPayloadAttestation;
use App\Modules\Taxonomy\Models\Tag;
use App\Modules\Ticket\Actions\AdvanceInboundEmailTicketMessageRepair;
use App\Modules\Ticket\Actions\LinkInboundEmailToTicket;
use App\Modules\Ticket\Actions\MarkTicketAsNotTicket;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/** Production-facing crash, replay, and rollback contracts for durable inbound fanout. */
class InboundEmailNotificationFanoutDurabilityTest extends TestCase
{
    use RefreshDatabase;

    private int $nextUid = 7000;

    private int $nextAccount = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('email.inbox_view', 'web');
        Permission::findOrCreate('ticket.view', 'web');
    }

    #[Test]
    public function sqlite_guards_are_monotonic_and_a_partial_trigger_pair_is_repaired_on_rerun(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        Queue::fake();

        $account = $this->emailAccount();
        $email = $this->activeEmail($account);
        $setting = $this->notificationSetting($this->activeUser());
        $settingIdentity = $setting->fresh()->getAttributes();

        $this->assertDatabaseRejects(function () use ($setting): void {
            DB::table('notification_settings')
                ->where('id', $setting->id)
                ->update(['notification_type' => 'repurposed_notification_identity']);
        });
        $this->assertSame($settingIdentity, $setting->fresh()->getAttributes());

        $this->assertDatabaseRejects(function () use ($account, $email): void {
            DB::table('notification_inbound_email_fanouts')->insert([
                'email_message_id' => $email->id,
                'source_email_message_id' => $email->id,
                'email_account_id' => $account->id,
                'status' => 'invented',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->assertDatabaseRejects(function (): void {
            DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)->insert([
                'id' => 2,
                'status' => AdvanceInboundEmailTicketMessageRepair::STATUS_PENDING,
                'through_id' => 0,
                'cursor_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
        $this->assertDatabaseRejects(function (): void {
            DB::table('notification_inbound_external_deliveries')->insert([
                'requested_web_push' => true,
                'status' => 'running',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);
        $externalDeliveryId = $this->pendingExternalDelivery();
        $before = $fanout->getAttributes();
        $externalBefore = (array) DB::table('notification_inbound_external_deliveries')
            ->where('id', $externalDeliveryId)
            ->first();
        $this->assertDatabaseRejects(function () use ($fanout): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update(['notification_setting_through_id' => 1]);
        });
        $this->assertDatabaseRejects(function () use ($fanout): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update(['page_attempt_count' => 1]);
        });
        $this->assertDatabaseRejects(function () use ($fanout): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update(['last_attempt_at' => now()]);
        });
        $this->assertDatabaseRejects(function () use ($fanout): void {
            DB::table('notification_inbound_email_fanouts')->where('id', $fanout->id)->delete();
        });
        $this->assertDatabaseRejects(function (): void {
            DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)->where('id', 1)->delete();
        });
        $this->assertDatabaseRejects(function () use ($externalDeliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalDeliveryId)
                ->update(['requested_nextcloud_talk' => true]);
        });
        $this->assertDatabaseRejects(function () use ($externalDeliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalDeliveryId)
                ->delete();
        });
        $this->assertSame($before, $fanout->fresh()->getAttributes());
        $this->assertSame(
            $externalBefore,
            (array) DB::table('notification_inbound_external_deliveries')
                ->where('id', $externalDeliveryId)
                ->first(),
        );

        DB::unprepared('drop trigger if exists `notif_inbound_fanout_contract_ck_update`');
        DB::unprepared('drop trigger if exists `notif_inbound_ticket_repair_ck_update`');
        DB::unprepared('drop trigger if exists `notif_inbound_ext_state_ck_update`');
        $this->assertSame(1, $this->sqliteTriggerCount('notif_inbound_fanout_contract_ck'));
        $this->assertSame(1, $this->sqliteTriggerCount('notif_inbound_ticket_repair_ck'));
        $this->assertSame(1, $this->sqliteTriggerCount('notif_inbound_ext_state_ck'));

        $this->fanoutMigration()->up();

        $this->assertSame(2, $this->sqliteTriggerCount('notif_inbound_fanout_contract_ck'));
        $this->assertSame(2, $this->sqliteTriggerCount('notif_inbound_ticket_repair_ck'));
        $this->assertSame(2, $this->sqliteTriggerCount('notif_inbound_ext_state_ck'));
        $this->assertIndex('notification_settings', 'notification_settings_type_cursor_ix');
        $this->assertIndex('ticket_messages', 'ticket_messages_source_inbound_email_uq', true);
        $this->assertIndex('ticket_messages', 'ticket_messages_ticket_source_inbound_ix');
        $this->assertIndex('notification_inbound_email_fanouts', 'notif_inbound_fanout_email_uq', true);
        $this->assertIndex('notification_inbound_email_fanouts', 'notif_inbound_fanout_source_email_uq', true);
        $this->assertIndex('notification_inbound_email_fanouts', 'notif_inbound_fanout_due_ix');
        $this->assertIndex('notification_inbound_email_fanouts', 'notif_inbound_fanout_status_cursor_ix');
        $this->assertIndex(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_status_cursor_ix',
        );
        $this->assertIndex(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_due_ix',
        );
        $this->assertIndex(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_fanout_status_ix',
        );
        $this->assertIndex('email_remote_operations', 'email_remote_ops_unresolved_placement_ix');
        $this->assertIndex('email_remote_operations', 'email_remote_ops_placement_status_cursor_ix');
        $this->assertIndex('email_remote_operations', 'email_remote_ops_unresolved_folder_ix');

        $this->assertSqlitePlanUsesIndex(
            'select id from notification_inbound_email_fanouts where status = ? order by id limit 50',
            [NotificationInboundEmailFanout::STATUS_PENDING],
            'notif_inbound_fanout_status_cursor_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from notification_inbound_email_fanouts '
                .'where status = ? and last_attempt_at <= ? order by last_attempt_at, id limit 50',
            [NotificationInboundEmailFanout::STATUS_RUNNING, now()->subMinutes(2)],
            'notif_inbound_fanout_due_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from notification_settings '
                .'where notification_type = ? and id > ? and id <= ? order by id limit 100',
            [ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED, 0, 100],
            'notification_settings_type_cursor_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from ticket_messages '
                .'where ticket_id = ? and source_inbound_email_message_id is not null limit 1',
            [1],
            'ticket_messages_ticket_source_inbound_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from notification_inbound_external_deliveries where status = ? order by id limit 50',
            ['pending'],
            'notif_inbound_ext_status_cursor_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from notification_inbound_external_deliveries '
                .'where status = ? and last_attempt_at <= ? order by last_attempt_at, id limit 25',
            [NotificationInboundExternalDelivery::STATUS_RUNNING, now()->subMinutes(2)],
            'notif_inbound_ext_due_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from notification_inbound_external_deliveries '
                .'where inbound_notification_fanout_id = ? and status in (?, ?) limit 1',
            [1, NotificationInboundExternalDelivery::STATUS_PENDING, NotificationInboundExternalDelivery::STATUS_RUNNING],
            'notif_inbound_ext_fanout_status_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from email_remote_operations '
                .'where email_mailbox_placement_id = ? and status = ? order by id limit 1',
            [1, 'pending'],
            'email_remote_ops_placement_status_cursor_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from email_remote_operations where email_mailbox_placement_id = ? '
                .'and status = ? and reconciled_at is null and failure_classification = ? '
                .'order by id limit 1',
            [1, 'failed', 'ambiguous'],
            'email_remote_ops_unresolved_placement_ix',
        );
        $this->assertSqlitePlanUsesIndex(
            'select id from email_remote_operations where email_folder_id = ? '
                .'and status = ? and reconciled_at is null and failure_classification = ? '
                .'order by id limit 1',
            [1, 'failed', 'ambiguous'],
            'email_remote_ops_unresolved_folder_ix',
        );
    }

    #[Test]
    public function sealed_schema_refuses_to_recreate_missing_repair_evidence(): void
    {
        $seal = $this->fanoutMigrationSeal();
        Schema::drop(AdvanceInboundEmailTicketMessageRepair::TABLE);

        try {
            $this->fanoutMigration()->up();
            $this->fail('A sealed schema must not recreate missing durable repair evidence.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Sealed inbound Ticket-message repair evidence is missing.',
                $exception->getMessage(),
            );
        }

        $this->assertFalse(Schema::hasTable(AdvanceInboundEmailTicketMessageRepair::TABLE));
        $this->assertSame($seal, $this->fanoutMigrationSeal());
        $this->assertTrue(Schema::hasTable('notification_inbound_email_fanouts'));
    }

    #[Test]
    public function sqlite_rerun_replaces_permissive_same_named_fanout_and_repair_guards(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        Queue::fake();
        $ticket = Ticket::factory()->create();
        $this->ticketMessage($ticket);
        $this->rebuildRepairState();
        $fanout = app(DispatchInboundEmailNotification::class)->handle(
            $this->activeEmail($this->emailAccount()),
        );
        $this->assertNotNull($fanout);

        foreach ([
            ['notif_inbound_fanout_contract_ck_insert', 'before insert on `notification_inbound_email_fanouts`'],
            ['notif_inbound_fanout_contract_ck_update', 'before update on `notification_inbound_email_fanouts`'],
            ['notif_inbound_fanout_initial', 'before insert on `notification_inbound_email_fanouts`'],
            ['notif_inbound_fanout_monotonic', 'before update on `notification_inbound_email_fanouts`'],
            ['notif_inbound_ticket_repair_ck_insert', 'before insert on `notification_inbound_ticket_message_repairs`'],
            ['notif_inbound_ticket_repair_ck_update', 'before update on `notification_inbound_ticket_message_repairs`'],
            ['notif_inbound_ticket_repair_monotonic', 'before update on `notification_inbound_ticket_message_repairs`'],
        ] as [$name, $timing]) {
            $this->replaceSqliteTriggerWithNoOp($name, $timing);
        }

        $this->fanoutMigration()->up();

        foreach ([
            'notif_inbound_fanout_contract_ck_insert',
            'notif_inbound_fanout_contract_ck_update',
            'notif_inbound_fanout_initial',
            'notif_inbound_fanout_monotonic',
            'notif_inbound_ticket_repair_ck_insert',
            'notif_inbound_ticket_repair_ck_update',
            'notif_inbound_ticket_repair_monotonic',
        ] as $name) {
            $sql = (string) DB::table('sqlite_master')
                ->where('type', 'trigger')
                ->where('name', $name)
                ->value('sql');
            $this->assertNotSame('', $sql, $name);
            $this->assertStringNotContainsString('select 1', strtolower($sql), $name);
        }

        $fanoutSnapshot = $fanout->fresh()->getAttributes();
        $this->assertDatabaseRejects(function () use ($fanout): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update([
                    'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
                    'claim_token' => hash('sha256', 'witness-less-permissive-rerun'),
                    'page_attempt_count' => 1,
                    'last_attempt_at' => now(),
                    'updated_at' => now(),
                ]);
        });
        $this->assertSame($fanoutSnapshot, $fanout->fresh()->getAttributes());

        $repair = (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
            ->where('id', 1)
            ->first();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_PENDING, $repair['status']);
        $this->assertDatabaseRejects(function () use ($repair): void {
            DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                ->where('id', 1)
                ->update([
                    'status' => AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED,
                    'cursor_id' => $repair['through_id'],
                    'page_count' => 1,
                    'completed_at' => now(),
                    'updated_at' => now(),
                ]);
        });
        $this->assertSame(
            $repair,
            (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                ->where('id', 1)
                ->first(),
        );
    }

    #[Test]
    public function reconciliation_intent_cannot_attach_after_a_fanout_page_is_claimed(): void
    {
        Queue::fake();

        $fanout = app(DispatchInboundEmailNotification::class)->handle(
            $this->activeEmail($this->emailAccount()),
        );
        $this->assertNotNull($fanout);

        $claimedAt = now();
        DB::table('notification_inbound_email_fanouts')
            ->where('id', $fanout->id)
            ->update([
                'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
                'claim_token' => hash('sha256', 'fanout-running-before-reconciliation-attach'),
                ...$this->frozenFanoutPageWitness($fanout),
                'page_attempt_count' => 1,
                'last_attempt_at' => $claimedAt,
                'updated_at' => $claimedAt,
            ]);
        $running = $fanout->fresh()->getAttributes();

        try {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update([
                    'email_provider_reconciliation_item_id' => 1,
                    'automation_claim_token' => hash(
                        'sha256',
                        'late-reconciliation-automation-claim',
                    ),
                    'updated_at' => now(),
                ]);
            $this->fail('A reconciliation intent attached after the fanout page was claimed.');
        } catch (QueryException $exception) {
            // Assert the state-machine guard, rather than accepting a later
            // foreign-key rejection for this intentionally synthetic item ID.
            $this->assertStringContainsString(
                'notification_fanout_is_monotonic',
                $exception->getMessage(),
            );
        }

        $this->assertSame($running, $fanout->fresh()->getAttributes());
    }

    #[Test]
    public function frozen_page_witness_bounds_cursor_and_forbids_early_completion(): void
    {
        Queue::fake();
        $account = $this->emailAccount();
        $this->genericInboxRecipients($account, DispatchInboundEmailNotification::PAGE_SIZE + 1);
        $fanout = app(DispatchInboundEmailNotification::class)->handle($this->activeEmail($account));
        $this->assertNotNull($fanout);
        $witness = $this->frozenFanoutPageWitness($fanout);
        $pageThroughId = (int) $witness['page_setting_through_id'];

        $this->assertLessThan((int) $fanout->notification_setting_through_id, $pageThroughId);
        $this->assertSame(DispatchInboundEmailNotification::PAGE_SIZE, $witness['page_setting_row_count']);
        $this->assertTrue($witness['page_owner_pending']);
        $this->assertFalse($witness['page_owner_candidate_included']);

        $claimedAt = now();
        DB::table('notification_inbound_email_fanouts')
            ->where('id', $fanout->id)
            ->update([
                'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
                'claim_token' => hash('sha256', 'fanout-bounded-page-witness'),
                ...$witness,
                'page_attempt_count' => 1,
                'last_attempt_at' => $claimedAt,
                'updated_at' => $claimedAt,
            ]);
        $running = $fanout->fresh()->getAttributes();
        $commit = [
            'claim_token' => null,
            'page_setting_through_id' => null,
            'page_setting_row_count' => null,
            'page_owner_pending' => null,
            'page_owner_candidate_included' => null,
            'page_attempt_count' => 0,
            'page_count' => 1,
            'owner_candidate_processed' => true,
            'updated_at' => now()->addSecond(),
        ];

        $this->assertDatabaseRejects(function () use ($commit, $fanout, $pageThroughId): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update($commit + [
                    'status' => NotificationInboundEmailFanout::STATUS_PENDING,
                    'notification_setting_cursor_id' => $pageThroughId + 1,
                ]);
        });
        $this->assertSame($running, $fanout->fresh()->getAttributes());

        $this->assertDatabaseRejects(function () use ($commit, $fanout, $pageThroughId): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update($commit + [
                    'status' => NotificationInboundEmailFanout::STATUS_COMPLETED,
                    'notification_setting_cursor_id' => $pageThroughId,
                    'completed_at' => now()->addSecond(),
                ]);
        });
        $this->assertSame($running, $fanout->fresh()->getAttributes());

        DB::table('notification_inbound_email_fanouts')
            ->where('id', $fanout->id)
            ->update($commit + [
                'status' => NotificationInboundEmailFanout::STATUS_PENDING,
                'notification_setting_cursor_id' => $pageThroughId,
            ]);

        $pending = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_PENDING, $pending->status);
        $this->assertSame($pageThroughId, $pending->notification_setting_cursor_id);
        $this->assertSame(1, $pending->page_count);
        $this->assertNull($pending->page_setting_through_id);
        $this->assertNull($pending->page_setting_row_count);
        $this->assertNull($pending->page_owner_pending);
        $this->assertNull($pending->page_owner_candidate_included);
    }

    #[Test]
    public function deleted_setting_after_claim_releases_without_checkpoint_then_recomputes(): void
    {
        Queue::fake();
        $account = $this->emailAccount();
        $this->genericInboxRecipients($account, 2);
        $settings = NotificationSetting::query()
            ->where('notification_type', ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $settings);
        $fanout = app(DispatchInboundEmailNotification::class)->handle($this->activeEmail($account));
        $this->assertNotNull($fanout);
        $witness = $this->frozenFanoutPageWitness($fanout);
        $this->claimFanoutPageForRecovery($fanout, $witness, 'deleted-setting-drift');
        $deleted = $settings->first();
        $remaining = $settings->last();
        $deleted->delete();
        Queue::fake();

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $released = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_PENDING, $released->status);
        $this->assertSame(2, $released->page_attempt_count);
        $this->assertSame(0, $released->notification_setting_cursor_id);
        $this->assertSame(0, $released->page_count);
        $this->assertNull($released->claim_token);
        $this->assertNull($released->page_setting_through_id);
        $this->assertNull($released->page_setting_row_count);
        $this->assertNull($released->page_owner_pending);
        $this->assertNull($released->page_owner_candidate_included);
        $this->assertSame(0, DatabaseNotification::query()->count());
        $this->assertSame(0, NotificationInboundExternalDelivery::query()->count());

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $completed = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $completed->status);
        $this->assertSame(0, $completed->page_attempt_count);
        $this->assertSame((int) $completed->notification_setting_through_id, $completed->notification_setting_cursor_id);
        $this->assertSame(1, $completed->page_count);
        $this->assertSame(1, DatabaseNotification::query()->count());
        $this->assertSame(
            0,
            DatabaseNotification::query()->where('notifiable_id', $deleted->user_id)->count(),
        );
        $this->assertSame(
            1,
            DatabaseNotification::query()->where('notifiable_id', $remaining->user_id)->count(),
        );
    }

    #[Test]
    public function inserted_setting_inside_a_claimed_gap_releases_then_recomputes_exactly_once(): void
    {
        Queue::fake();
        $account = $this->emailAccount();
        $this->genericInboxRecipients($account, 3);
        $settings = NotificationSetting::query()
            ->where('notification_type', ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED)
            ->orderBy('id')
            ->get();
        $this->assertCount(3, $settings);
        $gap = $settings->get(1);
        $gapAttributes = $gap->getAttributes();
        $gap->delete();
        $fanout = app(DispatchInboundEmailNotification::class)->handle($this->activeEmail($account));
        $this->assertNotNull($fanout);
        $witness = $this->frozenFanoutPageWitness($fanout);
        $this->assertSame(2, $witness['page_setting_row_count']);
        $this->claimFanoutPageForRecovery($fanout, $witness, 'inserted-setting-drift');
        DB::table('notification_settings')->insert($gapAttributes);
        Queue::fake();

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $released = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_PENDING, $released->status);
        $this->assertSame(2, $released->page_attempt_count);
        $this->assertSame(0, $released->notification_setting_cursor_id);
        $this->assertSame(0, $released->page_count);
        $this->assertNull($released->page_setting_through_id);
        $this->assertSame(0, DatabaseNotification::query()->count());

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $completed = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $completed->status);
        $this->assertSame(0, $completed->page_attempt_count);
        $this->assertSame((int) $completed->notification_setting_through_id, $completed->notification_setting_cursor_id);
        $this->assertSame(1, $completed->page_count);
        $this->assertSame(3, DatabaseNotification::query()->count());
        $this->assertSame(
            1,
            DatabaseNotification::query()->where('notifiable_id', $gap->user_id)->count(),
        );
    }

    #[Test]
    public function frozen_source_survives_fk_detach_and_fanout_rollback_refuses_before_ddl(): void
    {
        Queue::fake();

        $account = $this->emailAccount();
        $email = $this->activeEmail($account);
        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);

        $this->assertDatabaseRejects(function () use ($fanout): void {
            DB::table('notification_inbound_email_fanouts')
                ->where('id', $fanout->id)
                ->update(['email_account_id' => (int) $fanout->email_account_id + 1]);
        });

        $beforeDetach = $fanout->fresh()->getAttributes();
        $email->forceDelete();
        $detached = $fanout->fresh();

        $this->assertNull($detached->email_message_id);
        $this->assertSame((int) $email->id, $detached->source_email_message_id);
        foreach (array_keys($beforeDetach) as $attribute) {
            if ($attribute !== 'email_message_id') {
                $this->assertSame($beforeDetach[$attribute], $detached->getRawOriginal($attribute));
            }
        }

        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertSame(NotificationInboundEmailFanout::STATUS_FAILED, $fanout->fresh()->status);
        $this->assertSame(NotificationInboundEmailFanout::ERROR_SOURCE_MISSING, $fanout->fresh()->error_code);
        $this->assertSame((int) $email->id, $fanout->fresh()->source_email_message_id);

        $migration = $this->fanoutMigration();
        try {
            $migration->down();
            $this->fail('Fanout evidence must block rollback before any DDL.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Inbound notification fanout evidence must be preserved before schema rollback.',
                $exception->getMessage(),
            );
        }
        $this->assertTrue(Schema::hasTable('notification_inbound_email_fanouts'));
        $this->assertTrue(Schema::hasColumn('ticket_messages', 'inbound_email_message_id'));
        $this->assertIndex('notification_settings', 'notification_settings_type_cursor_ix');
    }

    #[Test]
    public function repaired_ticket_message_pointer_refuses_rollback_before_any_ddl(): void
    {
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        $linkedEmail = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
        $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $linkedEmail->id,
            'inbound_email_message_id' => $linkedEmail->id,
            'metadata' => ['email_message_id' => $linkedEmail->id],
        ]);

        $migration = $this->fanoutMigration();
        try {
            $migration->down();
            $this->fail('A repaired Ticket-message pointer must block rollback before any DDL.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Inbound Ticket-message links must be repaired before schema rollback.',
                $exception->getMessage(),
            );
        }
        $this->assertTrue(Schema::hasTable('notification_inbound_email_fanouts'));
        $this->assertTrue(Schema::hasTable(AdvanceInboundEmailTicketMessageRepair::TABLE));
        $this->assertTrue(Schema::hasColumn('ticket_messages', 'source_inbound_email_message_id'));
        $this->assertTrue(Schema::hasColumn('ticket_messages', 'inbound_email_message_id'));
        $this->assertIndex('ticket_messages', 'ticket_messages_source_inbound_email_uq', true);
    }

    #[Test]
    public function ticket_message_pointer_insert_is_strict_and_only_the_live_fk_may_detach(): void
    {
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        $linkedEmail = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
        $otherEmail = $this->bareEmail($account, ['ticket_id' => $ticket->id]);

        $unlinked = $this->ticketMessage($ticket);
        $this->assertNull($unlinked->source_inbound_email_message_id);
        $this->assertNull($unlinked->inbound_email_message_id);
        $unlinkedSnapshot = $unlinked->fresh()->getAttributes();
        $this->assertDatabaseRejects(function () use ($linkedEmail, $unlinked): void {
            DB::table('ticket_messages')
                ->where('id', $unlinked->id)
                ->update(['source_inbound_email_message_id' => $linkedEmail->id]);
        });
        $this->assertSame($unlinkedSnapshot, $unlinked->fresh()->getAttributes());

        $this->assertDatabaseRejects(fn (): TicketMessage => $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $linkedEmail->id,
        ]));
        $this->assertDatabaseRejects(fn (): TicketMessage => $this->ticketMessage($ticket, [
            'inbound_email_message_id' => $linkedEmail->id,
        ]));
        $this->assertDatabaseRejects(fn (): TicketMessage => $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $linkedEmail->id,
            'inbound_email_message_id' => $otherEmail->id,
        ]));

        $linked = $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $linkedEmail->id,
            'inbound_email_message_id' => $linkedEmail->id,
        ]);
        $this->assertSame((int) $linkedEmail->id, $linked->source_inbound_email_message_id);
        $this->assertSame((int) $linkedEmail->id, $linked->inbound_email_message_id);

        $linkedEmail->forceDelete();
        $detached = $linked->fresh();
        $this->assertSame((int) $linkedEmail->id, $detached->source_inbound_email_message_id);
        $this->assertNull($detached->inbound_email_message_id);

        $this->assertDatabaseRejects(function () use ($detached): void {
            DB::table('ticket_messages')
                ->where('id', $detached->id)
                ->update(['source_inbound_email_message_id' => null]);
        });
    }

    #[Test]
    public function frozen_repair_high_water_rejects_new_legacy_metadata_and_post_page_rewrites(): void
    {
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        $lateEmail = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
        $passed = $this->ticketMessage($ticket);
        $unrelated = $this->ticketMessage($ticket);
        $throughId = (int) $unrelated->id;
        $this->rebuildRepairState();

        $completed = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED, $completed['status']);
        $this->assertSame($throughId, $completed['through_id']);
        $this->assertSame($throughId, $completed['cursor_id']);
        $this->assertNull($passed->fresh()->source_inbound_email_message_id);
        $this->assertNull($unrelated->fresh()->source_inbound_email_message_id);
        $this->assertSame($throughId, (int) DB::table('ticket_messages')->max('id'));

        $repairSnapshot = (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
            ->where('id', 1)
            ->first();
        $messageSnapshot = DB::table('ticket_messages')
            ->orderBy('id')
            ->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $this->assertDatabaseRejects(fn (): TicketMessage => $this->ticketMessage($ticket, [
            'metadata' => ['email_message_id' => (int) $lateEmail->id],
        ]));
        $this->assertSame(
            $repairSnapshot,
            (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                ->where('id', 1)
                ->first(),
        );
        $this->assertSame(
            $messageSnapshot,
            DB::table('ticket_messages')
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        );

        $passedSnapshot = $passed->fresh()->getAttributes();
        $this->assertDatabaseRejects(function () use ($lateEmail, $passed): void {
            DB::table('ticket_messages')
                ->where('id', $passed->id)
                ->update([
                    'metadata' => json_encode([
                        'email_message_id' => (int) $lateEmail->id,
                    ], JSON_THROW_ON_ERROR),
                ]);
        });
        $this->assertSame($passedSnapshot, $passed->fresh()->getAttributes());

        $unrelated->forceFill([
            'subject' => 'Unrelated update after repair',
            'metadata' => ['email_message_id' => null],
        ])->save();
        $this->assertSame('Unrelated update after repair', $unrelated->fresh()->subject);
        $this->assertNull($unrelated->fresh()->metadata['email_message_id']);
        $this->assertNull($unrelated->fresh()->source_inbound_email_message_id);

        $exactEmail = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
        $exact = $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $exactEmail->id,
            'inbound_email_message_id' => $exactEmail->id,
            'metadata' => ['email_message_id' => (int) $exactEmail->id],
        ]);
        $this->assertGreaterThan($throughId, (int) $exact->id);
        $this->assertSame((int) $exactEmail->id, $exact->source_inbound_email_message_id);
        $this->assertSame((int) $exactEmail->id, $exact->inbound_email_message_id);
        $this->assertSame((int) $exactEmail->id, $exact->metadata['email_message_id']);
        $this->assertSame(
            $repairSnapshot,
            (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                ->where('id', 1)
                ->first(),
        );
    }

    #[Test]
    public function frozen_ticket_message_evidence_cannot_be_hard_deleted_or_parent_cascaded(): void
    {
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        $email = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
        $linked = $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $email->id,
            'inbound_email_message_id' => $email->id,
        ]);
        $linkedSnapshot = $linked->fresh()->getAttributes();

        $this->assertDatabaseRejects(fn (): ?bool => $linked->forceDelete());
        $this->assertSame(
            $linkedSnapshot,
            TicketMessage::query()->withTrashed()->findOrFail($linked->id)->getAttributes(),
        );
        $this->assertDatabaseRejects(fn (): ?bool => $ticket->forceDelete());
        $this->assertNotNull(Ticket::query()->withTrashed()->find($ticket->id));

        $linked = TicketMessage::query()->withTrashed()->findOrFail($linked->id);
        $ticket = Ticket::query()->withTrashed()->findOrFail($ticket->id);
        $linked->delete();
        $softDeleted = TicketMessage::query()->withTrashed()->findOrFail($linked->id);
        $this->assertTrue($softDeleted->trashed());
        $this->assertSame((int) $email->id, $softDeleted->source_inbound_email_message_id);
        try {
            app(LinkInboundEmailToTicket::class)->handle($email->fresh(), $ticket->fresh());
            $this->fail('A soft-deleted frozen link was duplicated.');
        } catch (RuntimeException $exception) {
            $this->assertSame('inbound_ticket_message_pointer_deleted', $exception->getMessage());
        }
        $this->assertSame(
            1,
            TicketMessage::query()->withTrashed()
                ->where('source_inbound_email_message_id', $email->id)
                ->count(),
        );

        $unrelatedTicket = Ticket::factory()->create();
        $unrelatedMessage = $this->ticketMessage($unrelatedTicket);
        $this->assertTrue($unrelatedMessage->forceDelete());
        $this->assertNull(TicketMessage::query()->withTrashed()->find($unrelatedMessage->id));
        $this->assertTrue($unrelatedTicket->forceDelete());
        $this->assertNull(Ticket::query()->withTrashed()->find($unrelatedTicket->id));
    }

    #[Test]
    public function external_delivery_evidence_refuses_rollback_before_any_ddl(): void
    {
        DB::unprepared('drop trigger if exists `notif_inbound_ext_initial`');
        try {
            $this->pendingExternalDelivery([
                'inbound_notification_fanout_id' => null,
                'canonical_payload_hash' => null,
            ]);
        } finally {
            // This is the exact nullable legacy shape that an interrupted
            // additive migration may leave behind. Restore the strict INSERT
            // boundary before proving that down() sees the legacy evidence.
            $this->fanoutMigration()->up();
        }

        try {
            $this->fanoutMigration()->down();
            $this->fail('External-delivery evidence must block rollback before any DDL.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Inbound notification external-delivery evidence must be preserved before schema rollback.',
                $exception->getMessage(),
            );
        }

        $this->assertTrue(Schema::hasTable('notification_inbound_external_deliveries'));
        $this->assertTrue(Schema::hasTable('notification_inbound_email_fanouts'));
        $this->assertTrue(Schema::hasColumn('ticket_messages', 'inbound_email_message_id'));
        $this->assertIndex(
            'notification_inbound_external_deliveries',
            'notif_inbound_ext_status_cursor_ix',
        );
    }

    #[Test]
    public function external_delivery_state_is_monotonic_but_fk_detach_remains_recoverable(): void
    {
        $deliveryId = $this->pendingExternalDelivery();
        $pending = (array) DB::table('notification_inbound_external_deliveries')
            ->where('id', $deliveryId)
            ->first();

        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->update([
                    'status' => 'completed',
                    'attempt_count' => 1,
                    'last_attempt_at' => now(),
                    'completed_at' => now(),
                ]);
        });
        $this->assertSame(
            $pending,
            (array) DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->first(),
        );
        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->update(['id' => $deliveryId + 1000]);
        });
        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->update(['inbound_notification_fanout_id' => $deliveryId + 1000]);
        });
        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->update(['canonical_payload_hash' => hash('sha256', 'rewritten-attestation')]);
        });

        $token = hash('sha256', 'external-delivery-claim');
        $attemptedAt = now();
        DB::table('notification_inbound_external_deliveries')
            ->where('id', $deliveryId)
            ->update([
                'status' => 'running',
                'claim_token' => $token,
                'attempt_count' => 1,
                'last_attempt_at' => $attemptedAt,
            ]);
        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->update([
                    'status' => 'pending',
                    'claim_token' => null,
                ]);
        });

        $completedAt = now()->addSecond();
        DB::table('notification_inbound_external_deliveries')
            ->where('id', $deliveryId)
            ->update([
                'status' => 'completed',
                'claim_token' => null,
                'completed_at' => $completedAt,
            ]);
        $terminal = (array) DB::table('notification_inbound_external_deliveries')
            ->where('id', $deliveryId)
            ->first();
        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->update(['completed_at' => now()->addMinutes(2)]);
        });
        $this->assertDatabaseRejects(function () use ($deliveryId): void {
            DB::table('notification_inbound_external_deliveries')->where('id', $deliveryId)->delete();
        });
        $this->assertSame(
            $terminal,
            (array) DB::table('notification_inbound_external_deliveries')
                ->where('id', $deliveryId)
                ->first(),
        );

        $user = $this->activeUser();
        $notificationId = (string) Str::uuid();
        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => InboundEmailRoutedNotification::class,
            'delivery_identity' => 'fanout-detach-'.$notificationId,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => '{}',
            'read_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $detachingId = $this->pendingExternalDelivery([
            'notification_id' => $notificationId,
            'user_id' => $user->id,
        ]);
        $beforeDetach = (array) DB::table('notification_inbound_external_deliveries')
            ->where('id', $detachingId)
            ->first();

        DB::table('notifications')->where('id', $notificationId)->delete();
        DB::table((new User)->getTable())->where('id', $user->id)->delete();
        $detached = (array) DB::table('notification_inbound_external_deliveries')
            ->where('id', $detachingId)
            ->first();

        $this->assertNull($detached['notification_id']);
        $this->assertNull($detached['user_id']);
        unset(
            $beforeDetach['notification_id'],
            $beforeDetach['user_id'],
            $detached['notification_id'],
            $detached['user_id'],
        );
        $this->assertSame($beforeDetach, $detached);
    }

    #[Test]
    public function external_worker_attests_canonical_bytes_but_allows_read_state_changes(): void
    {
        Queue::fake();
        $user = $this->activeUser(['email.inbox_view']);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $user);
        $setting = $this->notificationSetting($user);
        $setting->forceFill(['web_push_enabled' => true])->save();
        $dispatcher = new DurabilityRecordingInboundEmailExternalDispatcher;
        $this->app->instance(InboundEmailExternalNotificationDispatcher::class, $dispatcher);

        foreach (['data', 'delivery_identity'] as $target) {
            [, $delivery, $canonical] = $this->externalFanoutFixture($account);
            if ($target === 'data') {
                $payload = $canonical->data;
                $payload['title'] = 'Tampered after canonical storage';
                DB::table('notifications')->where('id', $canonical->id)->update([
                    'data' => json_encode($payload, JSON_THROW_ON_ERROR),
                ]);
            } else {
                DB::table('notifications')->where('id', $canonical->id)->update([
                    'delivery_identity' => 'tampered-'.$canonical->id,
                ]);
            }

            app()->call([new DeliverInboundEmailExternalNotification((int) $delivery->id), 'handle']);

            $this->assertSame(
                NotificationInboundExternalDelivery::STATUS_SUPPRESSED,
                $delivery->fresh()->status,
            );
            $this->assertSame(
                'inbound_notification_payload_attestation_failed',
                $delivery->fresh()->error_code,
            );
        }

        [, $readStateDelivery, $readStateCanonical] = $this->externalFanoutFixture($account);
        DB::table('notifications')->where('id', $readStateCanonical->id)->update([
            'read_at' => now(),
        ]);
        app()->call([
            new DeliverInboundEmailExternalNotification((int) $readStateDelivery->id),
            'handle',
        ]);

        $this->assertSame(
            NotificationInboundExternalDelivery::STATUS_COMPLETED,
            $readStateDelivery->fresh()->status,
        );
        $this->assertSame(1, $dispatcher->calls);
    }

    #[Test]
    public function terminal_fanout_remains_retention_protected_until_its_external_outbox_is_terminal(): void
    {
        Queue::fake();
        $user = $this->activeUser(['email.inbox_view']);
        $account = $this->emailAccount();
        $this->grantMailbox($account, $user);
        $setting = $this->notificationSetting($user);
        $setting->forceFill(['web_push_enabled' => true])->save();
        $dispatcher = new DurabilityRecordingInboundEmailExternalDispatcher;
        $this->app->instance(InboundEmailExternalNotificationDispatcher::class, $dispatcher);

        [$fanout, $delivery, , $email] = $this->externalFanoutFixture($account, [
            'received_at' => now()->subYears(3),
        ]);
        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $fanout->fresh()->status);
        EmailMailboxPlacement::query()->where('email_message_id', $email->id)->delete();

        $eligibility = app(EmailRetentionEligibilityService::class);
        $cutoff = now()->subYear();
        $protected = $eligibility->assess($email->fresh(), $cutoff);
        $this->assertFalse($protected['eligible']);
        $this->assertSame(
            [EmailRetentionEligibilityService::REASON_NOTIFICATION_FANOUT],
            $protected['reasons'],
        );

        app()->call([new DeliverInboundEmailExternalNotification((int) $delivery->id), 'handle']);
        $this->assertSame(NotificationInboundExternalDelivery::STATUS_SUPPRESSED, $delivery->fresh()->status);
        $this->assertTrue($delivery->fresh()->terminal());
        $released = $eligibility->assess($email->fresh(), $cutoff);
        $this->assertTrue($released['eligible'], json_encode($released['reasons']));
        $this->assertSame([], $released['reasons']);
    }

    #[Test]
    public function recovery_sweepers_bound_pending_pages_without_starving_abandoned_rows(): void
    {
        Queue::fake();
        $account = $this->emailAccount();
        $fanouts = collect();
        $deliveryIds = collect();

        for ($position = 0; $position < 52; $position++) {
            $email = $this->bareEmail($account);
            $fanout = NotificationInboundEmailFanout::query()->create([
                'email_message_id' => $email->id,
                'source_email_message_id' => $email->id,
                'email_account_id' => $account->id,
                'notification_setting_through_id' => 0,
                'status' => NotificationInboundEmailFanout::STATUS_PENDING,
            ]);
            $fanouts->push($fanout);
            $deliveryIds->push($this->pendingExternalDelivery([
                'inbound_notification_fanout_id' => $fanout->id,
            ]));
        }

        $abandonedAt = now()->subMinutes(3);
        $abandonedFanout = $fanouts->last();
        $abandonedFanout->forceFill([
            'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
            'claim_token' => hash('sha256', 'abandoned-fanout-recovery-fairness'),
            ...$this->frozenFanoutPageWitness($abandonedFanout),
            'page_attempt_count' => 1,
            'last_attempt_at' => $abandonedAt,
        ])->save();
        $abandonedDeliveryId = (int) $deliveryIds->last();
        DB::table('notification_inbound_external_deliveries')
            ->where('id', $abandonedDeliveryId)
            ->update([
                'status' => NotificationInboundExternalDelivery::STATUS_RUNNING,
                'claim_token' => hash('sha256', 'abandoned-external-recovery-fairness'),
                'attempt_count' => 1,
                'last_attempt_at' => $abandonedAt,
            ]);

        app()->call([new DispatchPendingInboundEmailNotificationFanouts, 'handle']);
        app()->call([new DispatchPendingInboundEmailExternalNotifications, 'handle']);

        Queue::assertPushed(ProcessInboundEmailNotificationFanout::class, 51);
        Queue::assertPushed(
            ProcessInboundEmailNotificationFanout::class,
            fn (ProcessInboundEmailNotificationFanout $job): bool => $job->fanoutId
                === (int) $abandonedFanout->id,
        );
        Queue::assertPushed(DeliverInboundEmailExternalNotification::class, 51);
        Queue::assertPushed(
            DeliverInboundEmailExternalNotification::class,
            fn (DeliverInboundEmailExternalNotification $job): bool => $job->deliveryId
                === $abandonedDeliveryId,
        );
    }

    #[Test]
    public function fanout_and_external_sweepers_are_safe_before_their_schema_is_deployed(): void
    {
        Queue::fake();
        Schema::drop('notification_inbound_external_deliveries');
        Schema::drop('notification_inbound_email_fanouts');
        Schema::drop(AdvanceInboundEmailTicketMessageRepair::TABLE);

        app()->call([new DispatchPendingInboundEmailNotificationFanouts, 'handle']);
        app()->call([new DispatchPendingInboundEmailExternalNotifications, 'handle']);
        $repair = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();

        Queue::assertNothingPushed();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_FAILED, $repair['status']);
        $this->assertSame('repair_schema_unavailable', $repair['error_code']);
    }

    #[Test]
    public function migration_seal_defers_ordinary_rules_and_resumes_without_provider_authority(): void
    {
        Bus::fake();
        $email = $this->activeEmail($this->emailAccount());
        $emailSnapshot = $email->fresh()->getAttributes();
        $sideEffects = collect([
            'email_rules',
            'email_rule_logs',
            'email_rule_execution_attempts',
            'tickets',
            'signals',
            'email_remote_operations',
            'notification_inbound_email_fanouts',
            'notifications',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->count(),
        ])->all();
        $this->assertSame(array_fill_keys(array_keys($sideEffects), 0), $sideEffects);

        $seal = $this->removeFanoutMigrationSeal();
        try {
            $readiness = new InboundEmailNotificationFanoutReadiness;
            $this->app->instance(InboundEmailNotificationFanoutReadiness::class, $readiness);
            $queued = (new ProcessInboundRules(
                (int) $email->id,
                allowProviderMutation: true,
            ))->withFakeQueueInteractions();

            app()->call([$queued, 'handle']);

            Bus::assertDispatched(
                ResumeInboundRulesAfterFanoutReady::class,
                fn (ResumeInboundRulesAfterFanoutReady $job): bool => $job->emailMessageId
                    === (int) $email->id,
            );
            Bus::assertDispatchedTimes(ResumeInboundRulesAfterFanoutReady::class, 1);
            Bus::assertNotDispatched(ProcessInboundRules::class);
            Bus::assertNotDispatched(ProcessEmailProviderReconciliationAutomation::class);
            $this->assertSame(
                $sideEffects,
                collect(array_keys($sideEffects))->mapWithKeys(fn (string $table): array => [
                    $table => DB::table($table)->count(),
                ])->all(),
            );
            $this->assertSame($emailSnapshot, $email->fresh()->getAttributes());
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }

        Bus::fake();
        (new ResumeInboundRulesAfterFanoutReady((int) $email->id))->handle(
            new InboundEmailNotificationFanoutReadiness,
        );

        Bus::assertDispatched(
            ProcessInboundRules::class,
            fn (ProcessInboundRules $job): bool => $job->emailMessageId === (int) $email->id
                && $job->allowProviderMutation === false
                && $job->deferInboundNotification === false,
        );
        Bus::assertDispatchedTimes(ProcessInboundRules::class, 1);
        Bus::assertNotDispatched(ResumeInboundRulesAfterFanoutReady::class);
    }

    #[Test]
    public function readiness_false_is_a_zero_side_effect_boundary_for_reconciliation_automation(): void
    {
        $account = $this->emailAccount();
        $email = $this->activeEmail($account);
        $folder = EmailFolder::query()
            ->where('account_id', $account->id)
            ->where('path', 'INBOX')
            ->sole();
        $namespace = EmailFolderUidNamespace::query()->create([
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'generation' => 1,
            'uid_validity' => 1,
            'uid_next_at_establishment' => 9003,
            'live_start_uid' => 9002,
            'status' => EmailFolderUidNamespace::STATUS_ACTIVE,
            'provenance_code' => 'fanout_readiness_test',
            'established_at' => now(),
        ]);
        $run = EmailProviderReconciliationRun::query()->create([
            'account_id' => $account->id,
            'provider' => 'imap',
            'trigger' => EmailProviderReconciliationRun::TRIGGER_MANUAL,
            'status' => EmailProviderReconciliationRun::STATUS_QUEUED,
            'phase' => EmailProviderReconciliationRun::PHASE_DISCOVER_START,
            'active_slot' => 1,
            'idempotency_key' => hash('sha256', 'fanout-readiness-run-'.$account->id),
            'provider_binding_version' => 1,
            'max_folders' => 10,
            'uid_batch_size' => 10,
            'provider_time_cap_seconds' => 10,
            'normal_interval_seconds' => 300,
            'queued_at' => now(),
        ]);
        $folderRun = EmailProviderReconciliationFolder::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'uid_namespace_id' => $namespace->id,
            'folder_path' => $folder->path,
            'folder_name' => $folder->name,
            'delimiter' => '/',
            'discovery_state' => EmailProviderReconciliationFolder::DISCOVERY_EXISTING,
            'status' => EmailProviderReconciliationFolder::STATUS_PENDING,
            'import_policy' => EmailProviderReconciliationFolder::IMPORT_LIVE,
        ]);
        $terminal = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9001,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'result_placement_id' => $email->placements()->sole()->id,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
            'automation_completed_at' => now(),
            'automation_error_code' => 'fanout_readiness_terminal_fixture',
            'completed_at' => now(),
        ]);
        $deleted = EmailProviderReconciliationItem::query()->create([
            'email_provider_reconciliation_run_id' => $run->id,
            'email_provider_reconciliation_folder_id' => $folderRun->id,
            'uid_namespace_id' => $namespace->id,
            'imap_uid' => 9002,
            'kind' => EmailProviderReconciliationItem::KIND_IMPORT,
            'status' => EmailProviderReconciliationItem::STATUS_PROJECTED,
            'automation_required' => true,
            'automation_status' => EmailProviderReconciliationItem::AUTOMATION_FAILED,
            'automation_completed_at' => now(),
            'automation_error_code' => 'fanout_readiness_deleted_fixture',
            'completed_at' => now(),
        ]);
        $deletedId = (int) $deleted->id;
        $deleted->delete();
        $this->assertTrue($terminal->terminal());
        $this->assertTrue($terminal->automationTerminal());

        $terminalSnapshot = $terminal->fresh()->getAttributes();
        $runSnapshot = $run->fresh()->getAttributes();
        $folderSnapshot = $folderRun->fresh()->getAttributes();
        $seal = $this->removeFanoutMigrationSeal();
        Bus::fake();
        try {
            $this->app->instance(
                InboundEmailNotificationFanoutReadiness::class,
                new InboundEmailNotificationFanoutReadiness,
            );
            (new ProcessEmailProviderReconciliationAutomation((int) $terminal->id))->handle();
            (new ProcessEmailProviderReconciliationAutomation($deletedId))->handle();

            Bus::assertNothingDispatched();
            $this->assertSame($terminalSnapshot, $terminal->fresh()->getAttributes());
            $this->assertNull(EmailProviderReconciliationItem::query()->find($deletedId));
            $this->assertSame($runSnapshot, $run->fresh()->getAttributes());
            $this->assertSame($folderSnapshot, $folderRun->fresh()->getAttributes());
            $this->assertSame(0, NotificationInboundEmailFanout::query()->count());
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }
    }

    #[Test]
    public function provider_reconciliation_start_rejects_an_unsealed_schema_before_run_or_job_work(): void
    {
        $account = $this->emailAccount();
        $accountSnapshot = $account->fresh()->getAttributes();
        $runCount = EmailProviderReconciliationRun::query()->count();
        $seal = $this->removeFanoutMigrationSeal();
        Bus::fake();
        try {
            $this->app->instance(
                InboundEmailNotificationFanoutReadiness::class,
                new InboundEmailNotificationFanoutReadiness,
            );
            try {
                app(StartEmailProviderReconciliation::class)->handle(
                    $account,
                    EmailProviderReconciliationRun::TRIGGER_MANUAL,
                );
                $this->fail('Provider reconciliation started without the exact fanout migration seal.');
            } catch (EmailProviderReconciliationReadException $exception) {
                $this->assertSame('inbound_notification_fanout_schema_not_ready', $exception->safeCode);
                $this->assertNull($exception->getPrevious());
                $this->assertStringNotContainsString($account->address, (string) $exception);
            }

            Bus::assertNothingDispatched();
            Bus::assertNotDispatched(ReconcileEmailProviderAccount::class);
            $this->assertSame($runCount, EmailProviderReconciliationRun::query()->count());
            $this->assertSame($accountSnapshot, $account->fresh()->getAttributes());
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }
    }

    #[Test]
    public function readiness_probe_is_bounded_and_cached_per_service_scope(): void
    {
        $seal = $this->fanoutMigrationSeal();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $readiness = new InboundEmailNotificationFanoutReadiness;

        $this->assertTrue($readiness->ready());
        $firstProbeCount = count($queries);
        $this->assertGreaterThanOrEqual(1, $firstProbeCount);
        $this->assertLessThanOrEqual(5, $firstProbeCount, implode("\n", $queries));
        $this->assertTrue($readiness->ready());
        $this->assertCount($firstProbeCount, $queries);

        DB::table($this->migrationRepository())
            ->where('migration', InboundEmailNotificationFanoutReadiness::MIGRATION)
            ->delete();
        try {
            $queries = [];
            $this->assertFalse((new InboundEmailNotificationFanoutReadiness)->ready());
            $this->assertCount(1, $queries, implode("\n", $queries));
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }
    }

    #[Test]
    public function unsealed_readiness_protects_retention_without_legacy_ticket_json_queries(): void
    {
        CommonSetting::query()->updateOrCreate(
            ['type' => 'emailhub', 'name' => 'retention_months'],
            ['value' => '12'],
        );
        $email = $this->bareEmail($this->emailAccount(), [
            'received_at' => now()->subYears(3),
        ]);
        $emailSnapshot = $email->fresh()->getAttributes();
        $seal = $this->removeFanoutMigrationSeal();
        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = (string) $query->sql;
        });

        try {
            $this->app->instance(
                InboundEmailNotificationFanoutReadiness::class,
                new InboundEmailNotificationFanoutReadiness,
            );

            app()->call([new EmailRetentionPurgeJob(12), 'handle']);

            $attempt = EmailRetentionPurgeAttempt::query()->sole();
            $this->assertSame(EmailRetentionPurgeAttempt::STATUS_PROTECTED, $attempt->status);
            $this->assertContains(
                EmailRetentionEligibilityService::REASON_TICKET_EVIDENCE,
                $attempt->reasons_json,
            );
            $this->assertSame($emailSnapshot, $email->fresh()->getAttributes());
            $this->assertSame(
                [],
                collect($queries)
                    ->filter(function (string $sql): bool {
                        $sql = strtolower($sql);

                        return str_contains($sql, 'ticket_messages')
                            && (str_contains($sql, 'metadata') || str_contains($sql, 'json_'));
                    })
                    ->values()
                    ->all(),
                implode("\n", $queries),
            );
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }
    }

    #[Test]
    public function unsealed_readiness_blocks_ticket_link_and_return_with_byte_exact_domain_state(): void
    {
        $account = $this->emailAccount();
        $linkTicket = Ticket::factory()->create();
        $linkEmail = $this->bareEmail($account);
        $returnTicket = Ticket::factory()->create();
        $returnEmail = $this->bareEmail($account, [
            'ticket_id' => $returnTicket->id,
            'state' => 'linked',
        ]);
        $this->ticketMessage($returnTicket, [
            'source_inbound_email_message_id' => $returnEmail->id,
            'inbound_email_message_id' => $returnEmail->id,
            'metadata' => ['email_message_id' => (int) $returnEmail->id],
        ]);
        $this->assertSame(
            AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED,
            DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)->where('id', 1)->value('status'),
        );
        $emailSnapshot = EmailMessage::query()
            ->whereIn('id', [$linkEmail->id, $returnEmail->id])
            ->orderBy('id')
            ->get()
            ->map(fn (EmailMessage $message): array => $message->getAttributes())
            ->all();
        $ticketSnapshot = Ticket::query()
            ->whereIn('id', [$linkTicket->id, $returnTicket->id])
            ->orderBy('id')
            ->get()
            ->map(fn (Ticket $ticket): array => $ticket->getAttributes())
            ->all();
        $messageSnapshot = DB::table('ticket_messages')->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $eventSnapshot = DB::table('ticket_events')->orderBy('id')->get()
            ->map(fn (object $row): array => (array) $row)
            ->all();
        $repairSnapshot = (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
            ->where('id', 1)
            ->first();
        $seal = $this->removeFanoutMigrationSeal();

        try {
            $this->app->instance(
                InboundEmailNotificationFanoutReadiness::class,
                new InboundEmailNotificationFanoutReadiness,
            );
            try {
                app(LinkInboundEmailToTicket::class)->handle($linkEmail, $linkTicket);
                $this->fail('Linking proceeded without the exact fanout migration seal.');
            } catch (RuntimeException $exception) {
                $this->assertSame('inbound_ticket_message_pointer_repair_pending', $exception->getMessage());
            }
            try {
                app(MarkTicketAsNotTicket::class)->handle($returnTicket);
                $this->fail('Returning mail to Inbox proceeded without the exact fanout migration seal.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame(
                    'Inbound Ticket-message pointer repair must complete before returning mail to Inbox.',
                    $exception->getMessage(),
                );
            }

            $this->assertSame(
                $emailSnapshot,
                EmailMessage::query()
                    ->whereIn('id', [$linkEmail->id, $returnEmail->id])
                    ->orderBy('id')
                    ->get()
                    ->map(fn (EmailMessage $message): array => $message->getAttributes())
                    ->all(),
            );
            $this->assertSame(
                $ticketSnapshot,
                Ticket::query()
                    ->whereIn('id', [$linkTicket->id, $returnTicket->id])
                    ->orderBy('id')
                    ->get()
                    ->map(fn (Ticket $ticket): array => $ticket->getAttributes())
                    ->all(),
            );
            $this->assertSame(
                $messageSnapshot,
                DB::table('ticket_messages')->orderBy('id')->get()
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
            );
            $this->assertSame(
                $eventSnapshot,
                DB::table('ticket_events')->orderBy('id')->get()
                    ->map(fn (object $row): array => (array) $row)
                    ->all(),
            );
            $this->assertSame(
                $repairSnapshot,
                (array) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)
                    ->where('id', 1)
                    ->first(),
            );
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }
    }

    #[Test]
    public function more_than_one_page_recovers_from_a_failed_page_and_deadline_without_skipping_or_duplicates(): void
    {
        Queue::fake();
        config(['notification.inbound_email_fanout_page_time_budget_ms' => 1]);

        $account = $this->emailAccount();
        $email = $this->activeEmail($account);
        $recipients = $this->genericInboxRecipients($account, 105);
        $settingIds = NotificationSetting::query()
            ->whereIn('user_id', $recipients->modelKeys())
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);
        $this->assertSame(max($settingIds), $fanout->notification_setting_through_id);

        $this->app->instance(RecordCanonicalNotification::class, new AlwaysFailingCanonicalNotificationRecorder);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $afterFailure = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_PENDING, $afterFailure->status);
        $this->assertSame(1, $afterFailure->page_attempt_count);
        $this->assertSame(0, $afterFailure->notification_setting_cursor_id);
        $this->assertSame(0, $afterFailure->page_count);
        $this->assertSame(0, DatabaseNotification::query()->count());

        $this->app->forgetInstance(RecordCanonicalNotification::class);
        $this->app->instance(RecordCanonicalNotification::class, new SlowCanonicalNotificationRecorder);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $afterDeadline = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_PENDING, $afterDeadline->status);
        $this->assertSame(1, $afterDeadline->page_count);
        $this->assertSame(0, $afterDeadline->page_attempt_count);
        $this->assertGreaterThan(0, $afterDeadline->notification_setting_cursor_id);
        $this->assertLessThan($afterDeadline->notification_setting_through_id, $afterDeadline->notification_setting_cursor_id);
        $this->assertLessThanOrEqual(100, DatabaseNotification::query()->count());

        $this->app->forgetInstance(RecordCanonicalNotification::class);
        config(['notification.inbound_email_fanout_page_time_budget_ms' => 8000]);
        for ($page = 0; $page < 5 && ! $fanout->fresh()->terminal(); $page++) {
            app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        }

        $completed = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $completed->status);
        $this->assertSame($completed->notification_setting_through_id, $completed->notification_setting_cursor_id);
        $this->assertSame(105, DatabaseNotification::query()->count());
        $this->assertSame(
            $settingIds,
            DatabaseNotification::query()
                ->get()
                ->map(fn (DatabaseNotification $notification): int => (int) $notification->data['notification_setting_id'])
                ->sort()
                ->values()
                ->all(),
        );
        $this->assertSame(
            $recipients->modelKeys(),
            DatabaseNotification::query()
                ->pluck('notifiable_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->sort()
                ->values()
                ->all(),
        );

        $terminalSnapshot = $completed->getAttributes();
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertSame($terminalSnapshot, $fanout->fresh()->getAttributes());
        $this->assertSame(105, DatabaseNotification::query()->count());
    }

    #[Test]
    public function content_only_break_glass_never_becomes_notification_recipient_authority(): void
    {
        Queue::fake();
        $owner = $this->activeUser(['email.inbox_view']);
        $operator = $this->activeUser(['email.break_glass_activate']);
        $account = $this->emailAccount([
            'account_kind' => EmailAccount::KIND_PERSONAL,
            'owner_id' => $owner->id,
        ]);
        $email = $this->activeEmail($account);
        $setting = $this->notificationSetting($operator);
        EmailBreakGlassAccess::query()->create([
            'email_account_id' => $account->id,
            'actor_id' => $operator->id,
            'can_view_content' => true,
            'can_search' => false,
            'can_download_attachments' => false,
            'can_view_raw_source' => false,
            'reason' => 'Test content-only emergency access.',
            'starts_at' => now()->subMinute(),
            'expires_at' => now()->addHour(),
        ]);
        $decision = app(ResolveMailboxAccessDecision::class)->resolve(
            $operator,
            $account,
            ResolveMailboxAccessDecision::CONTENT_VIEW,
        );
        $this->assertTrue($decision->allowed);

        $settingSnapshot = $setting->fresh()->getAttributes();
        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $fanout->fresh()->status);
        $this->assertSame(0, $operator->notifications()->count());
        $this->assertSame(0, NotificationInboundExternalDelivery::query()->count());
        $this->assertSame($settingSnapshot, $setting->fresh()->getAttributes());
        $this->assertSame(0, EmailMailboxAccessEvent::query()->count());
    }

    #[Test]
    public function owner_external_delivery_uses_the_exact_effective_setting_and_talk_target(): void
    {
        Queue::fake();
        $owner = $this->activeUser(['ticket.view']);
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        $email = $this->activeEmail($account, [
            'ticket_id' => $ticket->id,
            'state' => 'linked',
        ]);
        $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $email->id,
            'inbound_email_message_id' => $email->id,
        ]);
        $talkTarget = 'https://talk.example.test/ocs/v2.php/apps/spreed/api/v1/chat/abc';
        $ownerSetting = NotificationSetting::query()->create([
            'user_id' => $owner->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => true,
            'nextcloud_talk_webhook_url' => $talkTarget,
        ]);
        $dispatcher = new DurabilityRecordingInboundEmailExternalDispatcher;
        $this->app->instance(InboundEmailExternalNotificationDispatcher::class, $dispatcher);

        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $canonical = $owner->notifications()->sole();
        $this->assertSame((int) $ownerSetting->id, (int) $canonical->data['notification_setting_id']);
        $delivery = NotificationInboundExternalDelivery::query()
            ->where('inbound_notification_fanout_id', $fanout->id)
            ->sole();

        app()->call([new DeliverInboundEmailExternalNotification((int) $delivery->id), 'handle']);

        $this->assertSame(NotificationInboundExternalDelivery::STATUS_COMPLETED, $delivery->fresh()->status);
        $this->assertSame(1, $dispatcher->calls);
        $this->assertSame($talkTarget, $dispatcher->requested['nextcloud_talk_webhook_url'] ?? null);
        $this->assertTrue($dispatcher->requested['nextcloud_talk'] ?? false);
    }

    #[Test]
    public function linked_target_is_reauthorized_between_fanout_pages(): void
    {
        Queue::fake();
        config(['notification.inbound_email_fanout_page_time_budget_ms' => 1]);
        $owner = $this->activeUser(['ticket.view']);
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        $email = $this->activeEmail($account, [
            'ticket_id' => $ticket->id,
            'state' => 'linked',
        ]);
        $this->ticketMessage($ticket, [
            'source_inbound_email_message_id' => $email->id,
            'inbound_email_message_id' => $email->id,
        ]);
        NotificationSetting::query()->create([
            'user_id' => $owner->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
            'nextcloud_talk_webhook_url' => null,
        ]);
        foreach (range(1, 2) as $position) {
            $recipient = $this->activeUser(['ticket.view']);
            $this->notificationSetting($recipient);
        }
        $this->app->instance(RecordCanonicalNotification::class, new SlowCanonicalNotificationRecorder);

        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $this->assertSame(NotificationInboundEmailFanout::STATUS_PENDING, $fanout->fresh()->status);
        $this->assertSame(1, DatabaseNotification::query()->count());

        $email->forceFill(['ticket_id' => null])->save();
        $this->app->forgetInstance(RecordCanonicalNotification::class);
        config(['notification.inbound_email_fanout_page_time_budget_ms' => 8000]);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $fanout->fresh()->status);
        $this->assertSame(1, DatabaseNotification::query()->count());
        $this->assertSame(0, NotificationInboundExternalDelivery::query()->count());
    }

    #[Test]
    public function owner_defaults_do_not_write_settings_and_owner_generic_dedupe_is_aba_safe(): void
    {
        Queue::fake();

        $account = $this->emailAccount();
        $firstOwner = $this->activeUser(['ticket.view']);
        $firstTicket = Ticket::factory()->create(['owner_id' => $firstOwner->id]);
        $firstEmail = $this->activeEmail($account, [
            'ticket_id' => $firstTicket->id,
            'state' => 'linked',
        ]);
        $firstGeneric = $this->notificationSetting($firstOwner);

        $firstFanout = app(DispatchInboundEmailNotification::class)->handle($firstEmail);
        $this->assertNotNull($firstFanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $firstFanout->id);

        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $firstFanout->fresh()->status);
        $this->assertSame(1, $firstOwner->notifications()->count());
        $this->assertSame(
            ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
            $firstOwner->notifications()->sole()->data['type'],
        );
        $this->assertSame(0, NotificationSetting::query()->where(
            'notification_type',
            ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
        )->count());

        $firstGeneric->delete();
        $secondOwner = $this->activeUser(['ticket.view']);
        $secondTicket = Ticket::factory()->create(['owner_id' => $secondOwner->id]);
        $secondEmail = $this->activeEmail($account, [
            'ticket_id' => $secondTicket->id,
            'state' => 'linked',
        ]);
        $staleSetting = $this->notificationSetting($secondOwner);
        $secondFanout = app(DispatchInboundEmailNotification::class)->handle($secondEmail);
        $this->assertNotNull($secondFanout);
        $this->assertSame((int) $staleSetting->id, $secondFanout->notification_setting_through_id);

        $staleSetting->delete();
        $replacement = $this->notificationSetting($secondOwner);
        $this->assertGreaterThan($secondFanout->notification_setting_through_id, $replacement->id);
        app(DispatchInboundEmailNotification::class)->advance((int) $secondFanout->id);

        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $secondFanout->fresh()->status);
        $this->assertSame(1, $secondOwner->notifications()->count());
        $this->assertNull($secondOwner->notifications()->sole()->data['notification_setting_id']);
        $this->assertSame(1, NotificationSetting::query()->count());
        $this->assertSame(0, NotificationSetting::query()->where(
            'notification_type',
            ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
        )->count());
    }

    #[Test]
    public function disabled_owner_channels_reserve_priority_and_cannot_fall_through_to_generic_delivery(): void
    {
        Queue::fake();
        $account = $this->emailAccount();
        $owner = $this->activeUser(['ticket.view']);
        $ticket = Ticket::factory()->create(['owner_id' => $owner->id]);
        $email = $this->activeEmail($account, [
            'ticket_id' => $ticket->id,
            'state' => 'linked',
        ]);
        NotificationSetting::query()->create([
            'user_id' => $owner->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_TICKET_CUSTOMER_REPLY_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => false,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $this->notificationSetting($owner);

        $fanout = app(DispatchInboundEmailNotification::class)->handle($email);
        $this->assertNotNull($fanout);
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);

        $fanout = $fanout->fresh();
        $this->assertSame(NotificationInboundEmailFanout::STATUS_COMPLETED, $fanout->status);
        $this->assertTrue($fanout->owner_candidate_processed);
        $this->assertTrue($fanout->owner_priority_reserved);
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertSame(2, NotificationSetting::query()->where('user_id', $owner->id)->count());
    }

    #[Test]
    public function legacy_ticket_message_repair_is_bounded_redeliverable_and_includes_soft_deleted_rows(): void
    {
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        [$emails, $messages] = $this->seedLegacyTicketMessages(function () use ($account, $ticket): array {
            $messages = collect();
            $emails = collect();

            for ($position = 0; $position < 101; $position++) {
                $email = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
                $emails->push($email);
                $messages->push($this->ticketMessage($ticket, [
                    'metadata' => ['email_message_id' => $email->id],
                ]));
            }

            // This is deliberately legacy evidence: the strict metadata
            // guard must be restored only after the soft-delete is durable.
            $messages->get(1)->delete();

            return [$emails, $messages];
        });
        $emails->get(0)->delete();
        $throughId = (int) $messages->last()->id;
        $this->rebuildRepairState();
        $this->assertSame(
            $throughId,
            (int) DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)->where('id', 1)->value('through_id'),
        );

        $first = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_PENDING, $first['status']);
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::BATCH_SIZE, $first['processed']);
        $this->assertSame((int) $messages->get(99)->id, $first['cursor_id']);
        $this->assertSame(100, TicketMessage::query()->withTrashed()->whereNotNull(
            'inbound_email_message_id',
        )->count());
        $this->assertSame(100, TicketMessage::query()->withTrashed()->whereNotNull(
            'source_inbound_email_message_id',
        )->count());
        $this->assertSame(
            (int) $emails->get(0)->id,
            (int) TicketMessage::query()->withTrashed()->findOrFail($messages->get(0)->id)->inbound_email_message_id,
        );
        $this->assertSame(
            (int) $emails->get(0)->id,
            (int) TicketMessage::query()->withTrashed()->findOrFail($messages->get(0)->id)->source_inbound_email_message_id,
        );
        $this->assertSame(
            (int) $emails->get(1)->id,
            (int) TicketMessage::query()->withTrashed()->findOrFail($messages->get(1)->id)->inbound_email_message_id,
        );

        $second = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED, $second['status']);
        $this->assertSame(1, $second['processed']);
        $this->assertSame($throughId, $second['cursor_id']);
        $this->assertSame(101, TicketMessage::query()->withTrashed()->whereNotNull(
            'inbound_email_message_id',
        )->count());
        $this->assertSame(101, TicketMessage::query()->withTrashed()->whereNotNull(
            'source_inbound_email_message_id',
        )->count());

        $redelivery = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED, $redelivery['status']);
        $this->assertSame(0, $redelivery['processed']);
        $this->assertSame($throughId, $redelivery['cursor_id']);
    }

    #[Test]
    public function legacy_not_ticket_detachment_repairs_capture_without_relinking_email(): void
    {
        [$ticket, $emails, $messages] = $this->legacyNotTicketDetachment(2, true, true);
        $softDeletedMessage = $messages->first();
        $this->assertTrue($softDeletedMessage->trashed());
        $softDeletedAt = $softDeletedMessage->deleted_at?->toISOString();
        $evidenceSnapshot = $this->legacyNotTicketEvidenceSnapshot($ticket, $emails);
        $this->rebuildRepairState();

        $result = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();

        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED, $result['status']);
        $this->assertSame(2, $result['processed']);
        foreach ($messages as $position => $message) {
            $message->refresh();
            $email = $emails->get($position);
            $this->assertSame((int) $email->id, $message->source_inbound_email_message_id);
            $this->assertSame((int) $email->id, $message->inbound_email_message_id);
            $this->assertNull($email->fresh()->ticket_id);
            $this->assertSame('untriaged', $email->fresh()->state);
        }
        $this->assertTrue($softDeletedMessage->fresh()->trashed());
        $this->assertSame($softDeletedAt, $softDeletedMessage->fresh()->deleted_at?->toISOString());
        $this->assertSoftDeleted('tickets', ['id' => $ticket->id]);

        foreach ($emails as $email) {
            $this->assertNull(app(DispatchInboundEmailNotification::class)->handle($email->fresh()));
        }
        $this->assertDatabaseCount('notification_inbound_email_fanouts', 0);
        $this->assertSame(
            $evidenceSnapshot,
            $this->legacyNotTicketEvidenceSnapshot($ticket, $emails),
        );
    }

    #[Test]
    #[DataProvider('legacyNotTicketEvidenceBreaks')]
    public function legacy_not_ticket_detachment_with_broken_evidence_fails_closed(
        string $proofBreak,
    ): void {
        [$ticket, $emails, $messages] = $this->legacyNotTicketDetachment(1);
        $email = $emails->sole();
        $event = TicketEvent::query()
            ->where('ticket_id', $ticket->id)
            ->where('type', 'marked_not_ticket')
            ->sole();

        match ($proofBreak) {
            'email_wrong_state' => $email->forceFill(['state' => 'archived'])->save(),
            'email_other_ticket' => $email->forceFill([
                'ticket_id' => Ticket::factory()->create()->id,
            ])->save(),
            'ticket_restored' => $ticket->restore(),
            'ticket_merged' => $ticket->forceFill([
                'merged_into_ticket_id' => Ticket::factory()->create()->id,
            ])->save(),
            'ticket_metadata_candidate_missing' => $this->replaceNotTicketMetadataEmailIds(
                $ticket,
                [(int) $email->id + 100000],
            ),
            'ticket_metadata_duplicate' => $this->replaceNotTicketMetadataEmailIds(
                $ticket,
                [(int) $email->id, (int) $email->id],
            ),
            'ticket_metadata_string_id' => $this->replaceNotTicketMetadataEmailIds(
                $ticket,
                [(string) $email->id],
            ),
            'event_missing' => $event->delete(),
            'event_duplicate' => $event->replicate()->save(),
            'event_wrong_tag' => $this->replaceMarkedNotTicketEventAfter(
                $event,
                ['email_message_ids' => [(int) $email->id], 'tag' => 'noise'],
            ),
            'event_mismatched_ids' => $this->replaceMarkedNotTicketEventAfter(
                $event,
                ['email_message_ids' => [(int) $email->id + 100000], 'tag' => 'not-ticket'],
            ),
            'tag_missing' => $email->tags()->detach(),
            'tag_wrong_module' => $email->tags()->updateExistingPivot(
                (int) $email->tags()->where('tags.slug', 'not-ticket')->value('tags.id'),
                ['module' => 'ticket'],
            ),
            default => throw new RuntimeException('Unknown legacy not-ticket proof break.'),
        };

        $evidenceSnapshot = $this->legacyNotTicketEvidenceSnapshot($ticket, $emails);
        $messageSnapshot = (array) DB::table('ticket_messages')
            ->where('id', $messages->sole()->id)
            ->first();
        $this->rebuildRepairState();

        $result = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();

        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_FAILED, $result['status']);
        $this->assertSame('repair_email_ticket_conflict', $result['error_code']);
        $this->assertSame(0, $result['cursor_id']);
        $this->assertNull($messages->sole()->fresh()->source_inbound_email_message_id);
        $this->assertNull($messages->sole()->fresh()->inbound_email_message_id);
        $this->assertSame(
            $messageSnapshot,
            (array) DB::table('ticket_messages')->where('id', $messages->sole()->id)->first(),
        );
        $this->assertSame(
            $evidenceSnapshot,
            $this->legacyNotTicketEvidenceSnapshot($ticket, $emails),
        );
    }

    /** @return array<string, array{string}> */
    public static function legacyNotTicketEvidenceBreaks(): array
    {
        return [
            'Email state is not the detached workflow state' => ['email_wrong_state'],
            'Email points at another Ticket' => ['email_other_ticket'],
            'source Ticket is restored' => ['ticket_restored'],
            'source Ticket is a merge source' => ['ticket_merged'],
            'Ticket metadata omits the candidate' => ['ticket_metadata_candidate_missing'],
            'Ticket metadata repeats an ID' => ['ticket_metadata_duplicate'],
            'Ticket metadata stores a string ID' => ['ticket_metadata_string_id'],
            'marked-not-ticket event is missing' => ['event_missing'],
            'marked-not-ticket event is duplicated' => ['event_duplicate'],
            'event has the wrong tag' => ['event_wrong_tag'],
            'event and Ticket metadata disagree' => ['event_mismatched_ids'],
            'Email tag evidence is missing' => ['tag_missing'],
            'Email tag pivot has the wrong module' => ['tag_wrong_module'],
        ];
    }

    #[Test]
    public function semantic_repair_conflict_rolls_back_the_whole_page_and_fails_safely(): void
    {
        $account = $this->emailAccount();
        $firstTicket = Ticket::factory()->create();
        $secondTicket = Ticket::factory()->create();
        $validEmail = $this->bareEmail($account, ['ticket_id' => $firstTicket->id]);
        $conflictingEmail = $this->bareEmail($account, ['ticket_id' => $secondTicket->id]);
        [$valid, $conflict] = $this->seedLegacyTicketMessages(fn (): array => [
            $this->ticketMessage($firstTicket, [
                'metadata' => ['email_message_id' => $validEmail->id],
            ]),
            $this->ticketMessage($firstTicket, [
                'metadata' => ['email_message_id' => $conflictingEmail->id],
            ]),
        ]);
        $this->rebuildRepairState();

        $result = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();

        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_FAILED, $result['status']);
        $this->assertSame('repair_email_ticket_conflict', $result['error_code']);
        $this->assertSame(0, $result['cursor_id']);
        $this->assertNull($valid->fresh()->inbound_email_message_id);
        $this->assertNull($valid->fresh()->source_inbound_email_message_id);
        $this->assertNull($conflict->fresh()->inbound_email_message_id);
        $this->assertNull($conflict->fresh()->source_inbound_email_message_id);
        $state = DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)->where('id', 1)->first();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_FAILED, $state->status);
        $this->assertSame('repair_email_ticket_conflict', $state->error_code);
        $this->assertNotNull($state->completed_at);
    }

    #[Test]
    public function present_but_malformed_legacy_metadata_fails_closed_without_cursor_advance(): void
    {
        $ticket = Ticket::factory()->create();
        foreach ([false, true, 1.0, '1', ' 1 ', 0, 'not-an-id'] as $value) {
            $invalidMetadata = $this->seedLegacyTicketMessages(function () use ($ticket, $value): TicketMessage {
                $message = $this->ticketMessage($ticket, [
                    'metadata' => ['email_message_id' => $value],
                ]);

                if (is_float($value)) {
                    // Eloquent's JSON cast canonicalizes 1.0 to 1. Preserve
                    // the legacy REAL token so strict-int repair is exercised.
                    DB::table('ticket_messages')->where('id', $message->id)->update([
                        'metadata' => '{"email_message_id":1.0}',
                        'updated_at' => now(),
                    ]);
                    $message->refresh();
                }

                return $message;
            });
            $this->rebuildRepairState();

            $invalid = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
            $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_FAILED, $invalid['status']);
            $this->assertSame('repair_pointer_metadata_invalid', $invalid['error_code']);
            $this->assertSame(0, $invalid['cursor_id']);
            $this->assertNull($invalidMetadata->fresh()->inbound_email_message_id);
            $this->assertNull($invalidMetadata->fresh()->source_inbound_email_message_id);

            // Keep earlier matrix rows neutral before refreezing the next exact
            // migration high-water; each iteration must fail on its own value.
            $invalidMetadata->forceFill(['metadata' => []])->save();
        }

        try {
            $this->fanoutMigration()->down();
            $this->fail('Failed repair evidence must block rollback before any DDL.');
        } catch (RuntimeException $exception) {
            $this->assertSame(
                'Failed inbound Ticket-message repair evidence must be resolved before schema rollback.',
                $exception->getMessage(),
            );
        }
        $this->assertTrue(Schema::hasTable(AdvanceInboundEmailTicketMessageRepair::TABLE));
        $this->assertTrue(Schema::hasColumn('ticket_messages', 'inbound_email_message_id'));
    }

    #[Test]
    public function transient_repair_failure_preserves_cursor_and_linking_waits_for_completed_repair(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        $email = $this->bareEmail($account, ['ticket_id' => $ticket->id]);
        $message = $this->seedLegacyTicketMessages(fn (): TicketMessage => $this->ticketMessage($ticket, [
            'metadata' => ['email_message_id' => $email->id],
        ]));
        $this->rebuildRepairState();

        try {
            app(LinkInboundEmailToTicket::class)->handle($email, $ticket);
            $this->fail('New linking must wait for the durable legacy-pointer repair.');
        } catch (RuntimeException $exception) {
            $this->assertSame('inbound_ticket_message_pointer_repair_pending', $exception->getMessage());
        }
        $this->assertNull($message->fresh()->inbound_email_message_id);
        $this->assertNull($message->fresh()->source_inbound_email_message_id);

        DB::unprepared(
            'create trigger `test_inbound_ticket_repair_transient` '
            .'before update of `inbound_email_message_id` on `ticket_messages` '
            .'when NEW.id = '.(int) $message->id.' begin '
            ."select raise(abort, 'simulated_transient_repair_failure'); end",
        );
        try {
            $failedPage = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
        } finally {
            DB::unprepared('drop trigger if exists `test_inbound_ticket_repair_transient`');
        }

        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_PENDING, $failedPage['status']);
        $this->assertSame('repair_page_failed', $failedPage['error_code']);
        $this->assertSame(0, $failedPage['processed']);
        $this->assertSame(0, $failedPage['cursor_id']);
        $this->assertNull($message->fresh()->inbound_email_message_id);
        $this->assertNull($message->fresh()->source_inbound_email_message_id);
        $stateAfterFailure = DB::table(AdvanceInboundEmailTicketMessageRepair::TABLE)->where('id', 1)->first();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_PENDING, $stateAfterFailure->status);
        $this->assertSame(0, (int) $stateAfterFailure->cursor_id);
        $this->assertNull($stateAfterFailure->error_code);

        $recovered = app(AdvanceInboundEmailTicketMessageRepair::class)->handle();
        $this->assertSame(AdvanceInboundEmailTicketMessageRepair::STATUS_COMPLETED, $recovered['status']);
        $this->assertSame((int) $message->id, $recovered['cursor_id']);
        $this->assertSame((int) $email->id, (int) $message->fresh()->inbound_email_message_id);
        $this->assertSame(
            (int) $email->id,
            (int) $message->fresh()->source_inbound_email_message_id,
        );
        $this->assertTrue(app(LinkInboundEmailToTicket::class)->handle($email->fresh(), $ticket->fresh())->is($message));
    }

    /** @return Collection<int, User> */
    private function genericInboxRecipients(EmailAccount $account, int $count): Collection
    {
        $users = User::factory()->count($count)->create(['status' => User::STATUS_ACTIVE]);
        $permission = Permission::findOrCreate('email.inbox_view', 'web');
        $permissionRows = [];
        $grantRows = [];
        $settingRows = [];
        $at = now();

        foreach ($users as $user) {
            $permissionRows[] = [
                'permission_id' => $permission->id,
                'model_type' => User::class,
                'model_id' => $user->id,
            ];
            $grantRows[] = [
                'email_account_id' => $account->id,
                'user_id' => $user->id,
                'can_view' => true,
                'can_organize' => false,
                'can_send' => false,
                'granted_by' => null,
                'granted_at' => $at,
                'created_at' => $at,
                'updated_at' => $at,
            ];
            $settingRows[] = [
                'user_id' => $user->id,
                'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
                'mail_enabled' => false,
                'database_enabled' => true,
                'web_push_enabled' => false,
                'web_push_preview_enabled' => false,
                'nextcloud_talk_enabled' => false,
                'nextcloud_talk_webhook_url' => null,
                'created_at' => $at,
                'updated_at' => $at,
            ];
        }

        DB::table(config('permission.table_names.model_has_permissions'))->insert($permissionRows);
        EmailAccountUserGrant::query()->insert($grantRows);
        NotificationSetting::query()->insert($settingRows);

        return $users;
    }

    /** @param list<string> $permissions */
    private function activeUser(array $permissions = []): User
    {
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }

    private function grantMailbox(EmailAccount $account, User $user): EmailAccountUserGrant
    {
        return EmailAccountUserGrant::query()->create([
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'can_view' => true,
            'can_organize' => false,
            'can_send' => false,
            'granted_by' => null,
            'granted_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function emailAccount(array $overrides = []): EmailAccount
    {
        $sequence = $this->nextAccount++;

        return EmailAccount::query()->create(array_merge([
            'address' => "fanout-{$sequence}@example.test",
            'description' => 'Fanout durability',
            'from_name' => 'Support',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => true,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => "fanout-{$sequence}@example.test",
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => "fanout-{$sequence}@example.test",
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function bareEmail(EmailAccount $account, array $overrides = []): EmailMessage
    {
        $uid = $this->nextUid++;

        return EmailMessage::query()->create(array_merge([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'imap_uid_validity' => 1,
            'message_id' => "<fanout-{$uid}@example.test>",
            'subject' => "Fanout {$uid}",
            'from_name' => 'Customer',
            'from_email' => 'customer@example.test',
            'to_json' => [['email' => $account->address]],
            'cc_json' => [],
            'headers_json' => [],
            'received_at' => Carbon::parse('2026-08-16 08:00:00', 'Europe/Oslo')->utc(),
            'size_bytes' => 512,
            'is_oversize' => false,
            'state' => 'untriaged',
            'labels_json' => [],
            'body_text' => 'Durable fanout test.',
            'attachments_count' => 0,
            'checksum_sha1' => sha1("fanout-{$uid}"),
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function activeEmail(EmailAccount $account, array $overrides = []): EmailMessage
    {
        $email = $this->bareEmail($account, $overrides);
        $folder = EmailFolder::query()->firstOrCreate(
            ['account_id' => $account->id, 'path' => 'INBOX'],
            [
                'name' => 'INBOX',
                'role' => EmailFolder::ROLE_INBOX,
                'is_selectable' => true,
                'sync_enabled' => true,
                'uid_validity' => 1,
            ],
        );
        EmailMailboxPlacement::query()->create([
            'email_message_id' => $email->id,
            'account_id' => $account->id,
            'email_folder_id' => $folder->id,
            'provider' => 'imap',
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 1,
            'imap_uid' => $email->imap_uid,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
            'sync_status' => EmailMailboxPlacement::SYNC_SYNCED,
            'sync_version' => 1,
            'provider_missing_at' => null,
        ]);

        return $email;
    }

    private function notificationSetting(User $user): NotificationSetting
    {
        return NotificationSetting::query()->create([
            'user_id' => $user->id,
            'notification_type' => ResolveInboundEmailNotificationRecipients::TYPE_INBOUND_EMAIL_RECEIVED,
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
            'nextcloud_talk_webhook_url' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $emailOverrides
     * @return array{NotificationInboundEmailFanout,NotificationInboundExternalDelivery,DatabaseNotification,EmailMessage}
     */
    private function externalFanoutFixture(
        EmailAccount $account,
        array $emailOverrides = [],
    ): array {
        $email = $this->activeEmail($account, $emailOverrides);
        $fanout = app(DispatchInboundEmailNotification::class)->handle($email)
            ?? throw new RuntimeException('The test fanout was suppressed unexpectedly.');
        app(DispatchInboundEmailNotification::class)->advance((int) $fanout->id);
        $delivery = NotificationInboundExternalDelivery::query()
            ->where('inbound_notification_fanout_id', $fanout->id)
            ->sole();
        $canonical = DatabaseNotification::query()
            ->whereKey($delivery->notification_id)
            ->firstOrFail();

        return [$fanout->fresh(), $delivery, $canonical, $email];
    }

    /** @param array<string, mixed> $overrides */
    private function pendingExternalDelivery(array $overrides = []): int
    {
        if (! array_key_exists('notification_id', $overrides)
            && ! array_key_exists('user_id', $overrides)) {
            $user = $this->activeUser();
            $notificationId = (string) Str::uuid();
            DB::table('notifications')->insert([
                'id' => $notificationId,
                'type' => InboundEmailRoutedNotification::class,
                'delivery_identity' => 'fanout-external-'.$notificationId,
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => '{}',
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $overrides = [
                'notification_id' => $notificationId,
                'user_id' => $user->id,
            ] + $overrides;
        }

        if (! array_key_exists('inbound_notification_fanout_id', $overrides)) {
            $account = $this->emailAccount();
            $email = $this->bareEmail($account);
            $fanout = NotificationInboundEmailFanout::query()->create([
                'email_message_id' => $email->id,
                'source_email_message_id' => $email->id,
                'email_account_id' => $account->id,
                'notification_setting_through_id' => 0,
                'status' => NotificationInboundEmailFanout::STATUS_PENDING,
            ]);
            $overrides['inbound_notification_fanout_id'] = $fanout->id;
        }

        if (! array_key_exists('canonical_payload_hash', $overrides)) {
            $canonical = DB::table('notifications')
                ->where('id', $overrides['notification_id'])
                ->first();
            if (! $canonical) {
                throw new RuntimeException('The external-delivery fixture lacks its canonical row.');
            }
            $overrides['canonical_payload_hash'] = CanonicalNotificationPayloadAttestation::hash(
                (string) $canonical->id,
                (string) $canonical->type,
                (string) $canonical->delivery_identity,
                (string) $canonical->notifiable_type,
                (int) $canonical->notifiable_id,
                (string) $canonical->data,
            );
        }

        return (int) DB::table('notification_inbound_external_deliveries')->insertGetId(array_merge([
            'requested_mail' => false,
            'requested_web_push' => true,
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
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function ticketMessage(Ticket $ticket, array $overrides = []): TicketMessage
    {
        return TicketMessage::query()->create(array_merge([
            'ticket_id' => $ticket->id,
            'author_id' => null,
            'author_type' => 'contact',
            'type' => 'customer_reply',
            'visibility' => 'public',
            'subject' => 'Inbound reply',
            'body' => 'Inbound reply body.',
            'metadata' => [],
        ], $overrides));
    }

    /**
     * @return array{0:Ticket,1:Collection<int, EmailMessage>,2:Collection<int, TicketMessage>}
     */
    private function legacyNotTicketDetachment(
        int $emailCount,
        bool $attachTagEvidence = true,
        bool $softDeleteFirstMessage = false,
    ): array {
        $account = $this->emailAccount();
        $ticket = Ticket::factory()->create();
        $emails = collect();
        for ($position = 0; $position < $emailCount; $position++) {
            $emails->push($this->bareEmail($account));
        }

        if ($attachTagEvidence) {
            $tag = Tag::query()->firstOrCreate(
                ['slug' => 'not-ticket'],
                ['name' => 'not-ticket', 'color' => '#6c757d', 'active' => true],
            );
            foreach ($emails as $email) {
                $email->tags()->attach($tag->id, ['module' => 'email']);
            }
        }

        $emailIds = $emails
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
        $messages = $this->seedLegacyTicketMessages(function () use (
            $ticket,
            $emails,
            $emailIds,
            $softDeleteFirstMessage,
        ): Collection {
            $messages = $emails->map(fn (EmailMessage $email): TicketMessage => $this->ticketMessage(
                $ticket,
                ['metadata' => ['email_message_id' => (int) $email->id]],
            ));
            if ($softDeleteFirstMessage) {
                $messages->first()?->delete();
            }
            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'type' => 'marked_not_ticket',
                'message' => 'Ticket returned to Inbox as not ticket.',
                'after' => [
                    'email_message_ids' => $emailIds,
                    'tag' => 'not-ticket',
                ],
            ]);
            $ticket->forceFill([
                'metadata' => [
                    'not_ticket' => [
                        'by_user_id' => null,
                        'at' => '2026-05-29T10:00:00+02:00',
                        'email_message_ids' => $emailIds,
                    ],
                ],
            ])->save();
            $ticket->delete();

            return $messages;
        });

        return [$ticket, $emails, $messages];
    }

    /**
     * Snapshot every legacy not-ticket fact which the repair may inspect but
     * must never mutate while it adds TicketMessage capture pointers.
     *
     * @param  Collection<int, EmailMessage>  $emails
     * @return array{
     *     emails:array<int, array<string, mixed>>,
     *     ticket:array<string, mixed>,
     *     events:array<int, array<string, mixed>>,
     *     taggables:array<int, array<string, mixed>>
     * }
     */
    private function legacyNotTicketEvidenceSnapshot(Ticket $ticket, Collection $emails): array
    {
        $emailIds = $emails->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $morphType = $emails->first()?->getMorphClass() ?? EmailMessage::class;

        return [
            'emails' => EmailMessage::query()
                ->withTrashed()
                ->whereIn('id', $emailIds)
                ->orderBy('id')
                ->get()
                ->map(fn (EmailMessage $email): array => $email->getAttributes())
                ->all(),
            'ticket' => Ticket::query()
                ->withTrashed()
                ->findOrFail($ticket->id)
                ->getAttributes(),
            'events' => TicketEvent::query()
                ->where('ticket_id', $ticket->id)
                ->orderBy('id')
                ->get()
                ->map(fn (TicketEvent $event): array => $event->getAttributes())
                ->all(),
            'taggables' => DB::table('taggables')
                ->where('taggable_type', $morphType)
                ->whereIn('taggable_id', $emailIds)
                ->orderBy('id')
                ->get()
                ->map(fn (object $taggable): array => (array) $taggable)
                ->all(),
        ];
    }

    /** @param array<int, mixed> $emailIds */
    private function replaceNotTicketMetadataEmailIds(Ticket $ticket, array $emailIds): bool
    {
        $metadata = is_array($ticket->metadata) ? $ticket->metadata : [];
        $notTicket = is_array($metadata['not_ticket'] ?? null)
            ? $metadata['not_ticket']
            : [];
        $notTicket['email_message_ids'] = $emailIds;
        $metadata['not_ticket'] = $notTicket;

        return $ticket->forceFill(['metadata' => $metadata])->save();
    }

    /** @param array<string, mixed> $after */
    private function replaceMarkedNotTicketEventAfter(TicketEvent $event, array $after): bool
    {
        return $event->forceFill(['after' => $after])->save();
    }

    /**
     * @return array{
     *     page_setting_through_id:int,
     *     page_setting_row_count:int,
     *     page_owner_pending:bool,
     *     page_owner_candidate_included:bool
     * }
     */
    private function frozenFanoutPageWitness(NotificationInboundEmailFanout $fanout): array
    {
        $witness = app(ResolveInboundEmailNotificationRecipients::class)->pageWitness(
            $fanout->fresh(),
            DispatchInboundEmailNotification::PAGE_SIZE,
        );

        return [
            'page_setting_through_id' => $witness['setting_through_id'],
            'page_setting_row_count' => $witness['setting_row_count'],
            'page_owner_pending' => $witness['owner_pending'],
            'page_owner_candidate_included' => $witness['owner_candidate_included'],
        ];
    }

    /** @param array<string, int|bool> $witness */
    private function claimFanoutPageForRecovery(
        NotificationInboundEmailFanout $fanout,
        array $witness,
        string $suffix,
    ): void {
        $claimedAt = now()->subSeconds(DispatchInboundEmailNotification::ABANDONED_CLAIM_SECONDS + 5);
        $updated = DB::table('notification_inbound_email_fanouts')
            ->where('id', $fanout->id)
            ->update([
                'status' => NotificationInboundEmailFanout::STATUS_RUNNING,
                'claim_token' => hash('sha256', 'fanout-page-recovery-'.$suffix),
                ...$witness,
                'page_attempt_count' => 1,
                'last_attempt_at' => $claimedAt,
                'updated_at' => $claimedAt,
            ]);
        $this->assertSame(1, $updated);
    }

    private function replaceSqliteTriggerWithNoOp(string $name, string $timing): void
    {
        DB::unprepared("drop trigger if exists `{$name}`");
        DB::unprepared("create trigger `{$name}` {$timing} begin select 1; end");
    }

    /**
     * Seed only the pre-migration legacy shape, then restore every production
     * guard before the repair singleton freezes or scans that evidence.
     */
    private function seedLegacyTicketMessages(callable $seed): mixed
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        DB::unprepared('drop trigger if exists `ticket_messages_inbound_pointer_initial`');
        DB::unprepared('drop trigger if exists `ticket_messages_inbound_metadata_consistent`');

        try {
            return $seed();
        } finally {
            $this->fanoutMigration()->up();
        }
    }

    /** Re-run the schema initializer against exact pre-migration legacy evidence. */
    private function rebuildRepairState(): void
    {
        $seal = $this->removeFanoutMigrationSeal();
        try {
            Schema::drop(AdvanceInboundEmailTicketMessageRepair::TABLE);
            $this->fanoutMigration()->up();
        } finally {
            $this->restoreFanoutMigrationSeal($seal);
        }
    }

    /** @return array<string, mixed> */
    private function fanoutMigrationSeal(): array
    {
        $seal = DB::table($this->migrationRepository())
            ->where('migration', InboundEmailNotificationFanoutReadiness::MIGRATION)
            ->first();
        if (! $seal) {
            throw new RuntimeException('The fanout migration seal fixture is missing.');
        }

        return (array) $seal;
    }

    /** @return array<string, mixed> */
    private function removeFanoutMigrationSeal(): array
    {
        $seal = $this->fanoutMigrationSeal();
        $deleted = DB::table($this->migrationRepository())
            ->where('migration', InboundEmailNotificationFanoutReadiness::MIGRATION)
            ->delete();
        $this->assertSame(1, $deleted);

        return $seal;
    }

    /** @param array<string, mixed> $seal */
    private function restoreFanoutMigrationSeal(array $seal): void
    {
        DB::table($this->migrationRepository())->insert($seal);
    }

    private function migrationRepository(): string
    {
        $repository = (string) config('database.migrations.table', 'migrations');
        if ($repository === '') {
            throw new RuntimeException('The migration repository name is missing.');
        }

        return $repository;
    }

    private function fanoutMigration(): object
    {
        return require database_path(
            'migrations/2026_08_16_118500_add_durable_inbound_notification_fanout.php',
        );
    }

    private function sqliteTriggerCount(string $prefix): int
    {
        return DB::table('sqlite_master')
            ->where('type', 'trigger')
            ->whereIn('name', ["{$prefix}_insert", "{$prefix}_update"])
            ->count();
    }

    private function assertIndex(string $table, string $name, ?bool $unique = null): void
    {
        $index = collect(Schema::getIndexes($table))
            ->first(fn (array $candidate): bool => ($candidate['name'] ?? null) === $name);
        $this->assertNotNull($index, "Missing index {$table}.{$name}.");
        if ($unique !== null) {
            $this->assertSame($unique, (bool) ($index['unique'] ?? false));
        }
    }

    /** @param array<int, mixed> $bindings */
    private function assertSqlitePlanUsesIndex(string $sql, array $bindings, string $index): void
    {
        $plan = collect(DB::select('explain query plan '.$sql, $bindings))
            ->pluck('detail')
            ->implode(' ');

        $this->assertStringContainsString($index, $plan, $plan);
    }

    private function assertDatabaseRejects(callable $write): void
    {
        try {
            $write();
            $this->fail('The database accepted an invalid durable-state mutation.');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }
}

/** Force the exact page transaction to roll back before its cursor can advance. */
final class AlwaysFailingCanonicalNotificationRecorder extends RecordCanonicalNotification
{
    public function handleWithStatus(
        User $user,
        string $notificationClass,
        string $deliveryIdentity,
        array $data,
        bool $unread = true,
        bool $externalDeliveryRequired = false,
        ?array $externalChannelRequest = null,
        ?array $externalMailSnapshot = null,
    ): array {
        throw new RuntimeException('simulated_fanout_page_loss');
    }
}

/** Ensure the one-millisecond test budget deterministically stops after one candidate. */
final class SlowCanonicalNotificationRecorder extends RecordCanonicalNotification
{
    public function handleWithStatus(
        User $user,
        string $notificationClass,
        string $deliveryIdentity,
        array $data,
        bool $unread = true,
        bool $externalDeliveryRequired = false,
        ?array $externalChannelRequest = null,
        ?array $externalMailSnapshot = null,
    ): array {
        usleep(2000);

        return parent::handleWithStatus(
            $user,
            $notificationClass,
            $deliveryIdentity,
            $data,
            $unread,
            $externalDeliveryRequired,
            $externalChannelRequest,
            $externalMailSnapshot,
        );
    }
}

/** Capture exact externally authorized channels without contacting a provider. */
final class DurabilityRecordingInboundEmailExternalDispatcher implements InboundEmailExternalNotificationDispatcher
{
    public int $calls = 0;

    /** @var null|array{mail:bool,web_push:bool,nextcloud_talk:bool,nextcloud_talk_webhook_url?:?string} */
    public ?array $requested = null;

    public function deliver(
        User $user,
        InboundEmailRoutedNotification $notification,
        array $requested,
    ): array {
        $this->calls++;
        $this->requested = $requested;

        return [
            'status' => NotificationInboundExternalDelivery::STATUS_COMPLETED,
            'reason_code' => null,
        ];
    }
}
