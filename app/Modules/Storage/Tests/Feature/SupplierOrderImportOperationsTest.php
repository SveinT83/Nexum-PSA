<?php

namespace App\Modules\Storage\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Storage\Actions\CreatePurchaseOrderImport;
use App\Modules\Storage\Actions\DispatchDueSupplierOrderImports;
use App\Modules\Storage\Actions\GetCurrentPurchaseOrderAutomationPolicy;
use App\Modules\Storage\Actions\ProcessPurchaseOrderImport;
use App\Modules\Storage\Actions\PublishSupplierOrderImportOperationalAlert;
use App\Modules\Storage\Actions\PurgeSupplierOrderImportTroubleshootingData;
use App\Modules\Storage\Actions\RunSupplierOrderImportOperationsMaintenance;
use App\Modules\Storage\Actions\SendSupplierOrderImportDailyDigest;
use App\Modules\Storage\Actions\UpdateSupplierOrderImportOperationalState;
use App\Modules\Storage\Jobs\ProcessScheduledSupplierOrderImport;
use App\Modules\Storage\Jobs\ProcessSupplierOrderImport;
use App\Modules\Storage\Jobs\RecordSupplierOrderImportQueueHeartbeat;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicyRevision;
use App\Modules\Storage\Models\PurchaseOrderImport;
use App\Modules\Storage\Models\PurchaseOrderImportProfile;
use App\Modules\Storage\Models\PurchaseOrderImportRepair;
use App\Modules\Storage\Notifications\SupplierOrderImportDailyDigestNotification;
use App\Modules\Storage\Notifications\SupplierOrderImportExceptionNotification;
use App\Modules\Storage\Queries\PurchaseOrderImportOperationalHealthQuery;
use App\Modules\Storage\Support\StableJson;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Bus\Dispatcher as BusDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Console\Command\Command as ConsoleCommand;
use Tests\TestCase;

class SupplierOrderImportOperationsTest extends TestCase
{
    use RefreshDatabase;

    private User $operationsAdmin;

    private PurchaseOrderAutomationPolicy $policy;

    private PurchaseOrderAutomationPolicyRevision $policyRevision;

    private int $sourceSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::findOrCreate('storage.purchase_import_policy_manage', 'web');
        Permission::findOrCreate('storage.purchase_manage', 'web');
        $this->operationsAdmin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->operationsAdmin->givePermissionTo('storage.purchase_import_policy_manage');
        ['policy' => $this->policy, 'revision' => $this->policyRevision] = app(
            GetCurrentPurchaseOrderAutomationPolicy::class,
        )->handle();
    }

    #[Test]
    public function scheduler_claims_due_pending_and_retry_imports_once_and_records_heartbeat(): void
    {
        Queue::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $pending = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);
        $retry = $this->import(PurchaseOrderImport::STATUS_RETRY_SCHEDULED, [
            'created_at' => $at->subMinutes(20),
            'next_retry_at' => $at->subMinute(),
        ]);
        $fresh = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        $dispatcher = app(DispatchDueSupplierOrderImports::class);
        $this->assertSame(2, $dispatcher->handle(100, $at));
        $this->assertSame(0, $dispatcher->handle(100, $at));

        Queue::assertPushed(ProcessScheduledSupplierOrderImport::class, 2);
        Queue::assertPushed(fn (ProcessScheduledSupplierOrderImport $job): bool => $job->importId === $pending->id);
        Queue::assertPushed(fn (ProcessScheduledSupplierOrderImport $job): bool => $job->importId === $retry->id);
        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $pending->fresh()->status);
        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $retry->fresh()->status);
        $this->assertSame(PurchaseOrderImport::STATUS_PENDING, $fresh->fresh()->status);
        $this->assertDatabaseCount('storage_purchase_order_import_dispatches', 2);
        $this->assertDatabaseHas('storage_purchase_order_import_operations', [
            'operation_key' => UpdateSupplierOrderImportOperationalState::OPERATION_KEY,
            'last_dispatched_import_count' => 0,
        ]);
    }

    #[Test]
    public function source_snapshot_checksum_and_auth_projection_are_required_at_creation(): void
    {
        $auth = ['authentication_passed' => true, 'aligned' => true];
        $snapshot = [
            'subject' => 'Synthetic integrity test',
            'body_text' => 'Synthetic source',
            'trusted_auth' => $auth,
        ];
        $payload = [
            'source_domain' => 'email',
            'source_type' => 'email_message',
            'source_id' => 'integrity-create',
            'signal_action_key' => 'integrity-create',
            'source_fingerprint' => str_repeat('0', 64),
            'safe_source_snapshot' => $snapshot,
            'trusted_auth_snapshot' => $auth,
            'policy_revision_id' => $this->policyRevision->id,
            'status' => PurchaseOrderImport::STATUS_PENDING,
            'stage' => PurchaseOrderImport::STAGE_DETECT,
        ];

        try {
            app(CreatePurchaseOrderImport::class)->handle($payload);
            $this->fail('A mismatched source checksum must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'source_snapshot_fingerprint_mismatch',
                $exception->errors()['source_integrity'],
            );
        }

        $payload['source_id'] = 'integrity-auth';
        $payload['signal_action_key'] = 'integrity-auth';
        $payload['source_fingerprint'] = StableJson::checksum($snapshot);
        $payload['trusted_auth_snapshot'] = [
            'authentication_passed' => false,
            'aligned' => false,
        ];
        try {
            app(CreatePurchaseOrderImport::class)->handle($payload);
            $this->fail('A mismatched trusted-auth projection must be rejected.');
        } catch (ValidationException $exception) {
            $this->assertContains(
                'trusted_auth_snapshot_mismatch',
                $exception->errors()['source_integrity'],
            );
        }

        $this->assertDatabaseCount('storage_purchase_order_imports', 0);
    }

    #[Test]
    public function persisted_source_evidence_is_model_immutable(): void
    {
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING);

        $this->expectException(\DomainException::class);
        $import->forceFill([
            'safe_source_snapshot' => ['body_text' => 'Changed after creation'],
        ])->save();
    }

    #[Test]
    public function processing_fails_closed_when_persisted_source_evidence_was_tampered_out_of_band(): void
    {
        $this->policy->forceFill([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
        ])->save();
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING);
        DB::table('storage_purchase_order_imports')
            ->where('id', $import->id)
            ->update([
                'safe_source_snapshot' => json_encode([
                    'body_text' => 'Tampered source',
                    'trusted_auth' => $import->trusted_auth_snapshot,
                ], JSON_THROW_ON_ERROR),
            ]);

        $processed = app(ProcessPurchaseOrderImport::class)->handle($import->fresh());

        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $processed->status);
        $this->assertSame('source_integrity_failed', $processed->reason_code);
        $this->assertContains(
            'source_snapshot_fingerprint_mismatch',
            data_get($processed->reason_context, 'errors', []),
        );
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function dispatcher_does_not_overwrite_a_worker_state_that_advanced_during_dispatch(): void
    {
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);
        $realBus = app(BusDispatcher::class);
        $racingBus = \Mockery::mock(BusDispatcher::class);
        $racingBus->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($import, $at): string {
                DB::table('storage_purchase_order_import_dispatches')
                    ->where('import_id', $import->id)
                    ->update([
                        'status' => 'running',
                        'worker_started_at' => $at,
                        'updated_at' => $at,
                    ]);

                return 'synthetic-job-id';
            });
        $this->app->instance(BusDispatcher::class, $racingBus);

        try {
            $this->assertSame(1, app(DispatchDueSupplierOrderImports::class)->handle(100, $at));
        } finally {
            $this->app->instance(BusDispatcher::class, $realBus);
        }

        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'status' => 'running',
        ]);
    }

    #[Test]
    public function late_transport_failure_cannot_release_a_claim_already_started_by_the_worker(): void
    {
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);
        $realBus = app(BusDispatcher::class);
        $racingBus = \Mockery::mock(BusDispatcher::class);
        $racingBus->shouldReceive('dispatch')
            ->once()
            ->andReturnUsing(function () use ($import, $at): never {
                DB::table('storage_purchase_order_import_dispatches')
                    ->where('import_id', $import->id)
                    ->update([
                        'status' => 'running',
                        'worker_started_at' => $at,
                        'updated_at' => $at,
                    ]);

                throw new RuntimeException('Transport reported failure after worker acceptance.');
            });
        $this->app->instance(BusDispatcher::class, $racingBus);

        try {
            $this->assertSame(1, app(DispatchDueSupplierOrderImports::class)->handle(100, $at));
        } finally {
            $this->app->instance(BusDispatcher::class, $realBus);
        }

        $claimed = $import->fresh();
        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $claimed->status);
        $this->assertSame('scheduled_dispatch_claimed', $claimed->reason_code);
        $this->assertNotNull(data_get($claimed->reason_context, 'claim_token'));
        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'status' => 'running',
        ]);
        $this->assertDatabaseCount('storage_purchase_order_import_operational_alerts', 0);
    }

    #[Test]
    public function late_job_failure_cannot_overwrite_completed_or_newer_claim_state(): void
    {
        Queue::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);
        $this->assertSame(1, app(DispatchDueSupplierOrderImports::class)->handle(100, $at));
        $job = null;
        Queue::assertPushed(ProcessScheduledSupplierOrderImport::class, function ($queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertNotNull($job);

        DB::table('storage_purchase_order_import_dispatches')
            ->where('import_id', $import->id)
            ->where('claim_token', $job->claimToken)
            ->update([
                'status' => 'completed',
                'worker_completed_at' => $at,
                'last_outcome' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
                'updated_at' => $at,
            ]);
        $import->forceFill([
            'status' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
            'reason_code' => 'profile_or_extraction_unresolved',
            'reason_context' => [],
            'locked_at' => null,
        ])->save();

        $job->failed(new RuntimeException('Late queue callback.'));
        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'claim_token' => $job->claimToken,
            'status' => 'completed',
            'last_outcome' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
        ]);
        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $import->fresh()->status);

        $newToken = '11111111-1111-4111-8111-111111111111';
        DB::table('storage_purchase_order_import_dispatches')
            ->where('import_id', $import->id)
            ->update([
                'claim_token' => $newToken,
                'dispatch_count' => 2,
                'status' => 'running',
                'claimed_at' => $at->addMinute(),
                'worker_started_at' => $at->addMinute(),
                'worker_completed_at' => null,
                'last_outcome' => null,
                'updated_at' => $at->addMinute(),
            ]);
        $import->forceFill([
            'status' => PurchaseOrderImport::STATUS_PROCESSING,
            'reason_code' => 'scheduled_dispatch_claimed',
            'reason_context' => ['claim_token' => $newToken],
            'locked_at' => $at->addMinute(),
        ])->save();

        $job->failed(new RuntimeException('Superseded queue callback.'));
        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'claim_token' => $newToken,
            'status' => 'running',
            'dispatch_count' => 2,
        ]);
        $this->assertSame($newToken, data_get($import->fresh()->reason_context, 'claim_token'));
    }

    #[Test]
    public function duplicate_job_delivery_does_not_complete_a_claim_already_running(): void
    {
        Queue::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);

        $this->assertSame(1, app(DispatchDueSupplierOrderImports::class)->handle(100, $at));
        $job = null;
        Queue::assertPushed(ProcessScheduledSupplierOrderImport::class, function ($queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertNotNull($job);

        DB::table('storage_purchase_order_import_dispatches')
            ->where('import_id', $import->id)
            ->where('claim_token', $job->claimToken)
            ->update([
                'status' => 'running',
                'worker_started_at' => $at,
                'updated_at' => $at,
            ]);
        $import->refresh()->forceFill([
            'status' => PurchaseOrderImport::STATUS_PROCESSING,
            'reason_code' => null,
            'reason_context' => null,
            'attempt_count' => 1,
            'locked_at' => $at,
        ])->save();

        $job->handle(
            app(ProcessPurchaseOrderImport::class),
            app(UpdateSupplierOrderImportOperationalState::class),
        );

        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'claim_token' => $job->claimToken,
            'status' => 'running',
            'worker_completed_at' => null,
        ]);
        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $import->fresh()->status);
        $this->assertNull($import->fresh()->reason_code);
    }

    #[Test]
    public function scheduled_worker_accepts_only_the_current_claim_token_and_completes_processing(): void
    {
        Queue::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);

        $this->assertSame(1, app(DispatchDueSupplierOrderImports::class)->handle(100, $at));
        $job = null;
        Queue::assertPushed(ProcessScheduledSupplierOrderImport::class, function ($queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertNotNull($job);
        $process = app(ProcessPurchaseOrderImport::class);

        $supersededJob = new ProcessScheduledSupplierOrderImport(
            $import->id,
            '00000000-0000-4000-8000-000000000000',
        );
        $supersededJob->handle($process, app(UpdateSupplierOrderImportOperationalState::class));
        $withoutToken = $process->handle($import->fresh());
        $wrongToken = $process->handle($import->fresh(), '00000000-0000-4000-8000-000000000000');
        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $withoutToken->status);
        $this->assertSame(PurchaseOrderImport::STATUS_PROCESSING, $wrongToken->status);
        $this->assertSame(0, $import->fresh()->attempt_count);
        $this->assertSame('scheduled_dispatch_claimed', $import->fresh()->reason_code);
        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'claim_token' => $job->claimToken,
            'status' => 'dispatched',
        ]);

        $job->handle($process, app(UpdateSupplierOrderImportOperationalState::class));

        $processed = $import->fresh();
        $this->assertSame(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, $processed->status);
        $this->assertSame('profile_or_extraction_unresolved', $processed->reason_code);
        $this->assertSame(1, $processed->attempt_count);
        $this->assertNull($processed->locked_at);
        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'claim_token' => $job->claimToken,
            'status' => 'completed',
            'last_outcome' => PurchaseOrderImport::STATUS_NEEDS_ATTENTION,
        ]);
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function queue_dispatch_failure_is_alerted_then_resolved_and_job_failure_is_bounded(): void
    {
        Notification::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        NotificationSetting::query()->create([
            'user_id' => $this->operationsAdmin->id,
            'notification_type' => 'storage_purchase_import_exception',
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $import = $this->import(PurchaseOrderImport::STATUS_PENDING, [
            'created_at' => $at->subMinutes(10),
            'updated_at' => $at->subMinutes(10),
        ]);
        $realBus = app(BusDispatcher::class);
        $failingBus = \Mockery::mock(BusDispatcher::class);
        $failingBus->shouldReceive('dispatch')
            ->once()
            ->andThrow(new RuntimeException('Queue transport unavailable.'));
        $this->app->instance(BusDispatcher::class, $failingBus);

        $dispatcher = app(DispatchDueSupplierOrderImports::class);
        $this->assertSame(0, $dispatcher->handle(100, $at));
        $this->assertSame(PurchaseOrderImport::STATUS_PENDING, $import->fresh()->status);
        $this->assertSame('queue_dispatch_failed', $import->fresh()->reason_code);
        Notification::assertSentToTimes(
            $this->operationsAdmin,
            SupplierOrderImportExceptionNotification::class,
            1,
        );
        $this->assertNull(DB::table('storage_purchase_order_import_operational_alerts')
            ->where('alert_type', 'queue_dispatch_failed')->value('resolved_at'));

        $this->app->instance(BusDispatcher::class, $realBus);
        Queue::fake();
        $this->assertSame(1, $dispatcher->handle(100, $at->addMinute()));
        $job = null;
        Queue::assertPushed(ProcessScheduledSupplierOrderImport::class, function ($queued) use (&$job): bool {
            $job = $queued;

            return true;
        });
        $this->assertNotNull($job);
        $this->assertNotNull(DB::table('storage_purchase_order_import_operational_alerts')
            ->where('alert_type', 'queue_dispatch_failed')->value('resolved_at'));

        $job->failed(new RuntimeException('Worker bootstrap failed.'));
        $this->assertSame(PurchaseOrderImport::STATUS_RETRY_SCHEDULED, $import->fresh()->status);
        $this->assertDatabaseHas('storage_purchase_order_import_dispatches', [
            'import_id' => $import->id,
            'status' => 'failed',
            'last_outcome' => RuntimeException::class,
        ]);
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function independent_scheduler_and_worker_heartbeats_are_exposed_by_the_health_contract(): void
    {
        Notification::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        app(UpdateSupplierOrderImportOperationalState::class)->handle(['scheduler_heartbeat_at' => $at]);
        app(RecordSupplierOrderImportQueueHeartbeat::class, [
            'scheduledAt' => $at->subSeconds(12)->toIso8601String(),
        ])->handle(app(UpdateSupplierOrderImportOperationalState::class));
        $this->import(PurchaseOrderImport::STATUS_RETRY_SCHEDULED, [
            'created_at' => $at->subDays(30),
            'next_retry_at' => $at->subMinute(),
        ]);

        $healthy = app(PurchaseOrderImportOperationalHealthQuery::class)->get($at);
        $this->assertSame('healthy', $healthy['state']);
        $this->assertTrue($healthy['scheduler']['healthy']);
        $this->assertTrue($healthy['queue_worker']['healthy']);
        $this->assertSame(12, $healthy['queue_worker']['queue_latency_seconds']);
        $this->assertSame(60, $healthy['imports']['backlog_age_seconds']);

        $alertId = DB::table('storage_purchase_order_import_operational_alerts')->insertGetId([
            'dedupe_key' => hash('sha256', 'delivery-failure-test'),
            'alert_type' => 'delivery_test',
            'severity' => 'info',
            'occurrence' => 1,
            'title' => 'Delivery test',
            'summary' => 'Delivery test',
            'first_detected_at' => $at,
            'last_detected_at' => $at,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        DB::table('storage_purchase_order_import_alert_deliveries')->insert([
            'alert_id' => $alertId,
            'occurrence' => 1,
            'user_id' => $this->operationsAdmin->id,
            'status' => 'failed',
            'failed_at' => $at->subMinutes(20),
            'failure_class' => 'RuntimeException',
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $deliveryWarning = app(PurchaseOrderImportOperationalHealthQuery::class)->get($at);
        $this->assertSame('warning', $deliveryWarning['state']);
        $this->assertSame(1, $deliveryWarning['notifications']['failed_delivery_count']);

        $this->assertSame(1, app(PublishSupplierOrderImportOperationalAlert::class)->retryFailedDeliveries());
        Notification::assertSentToTimes(
            $this->operationsAdmin,
            SupplierOrderImportExceptionNotification::class,
            1,
        );
        $this->assertDatabaseHas('storage_purchase_order_import_alert_deliveries', [
            'id' => DB::table('storage_purchase_order_import_alert_deliveries')->value('id'),
            'status' => 'delivered',
        ]);

        $stale = app(PurchaseOrderImportOperationalHealthQuery::class)->get($at->addMinutes(10));
        $this->assertSame('critical', $stale['state']);
        $this->assertFalse($stale['scheduler']['healthy']);
        $this->assertFalse($stale['queue_worker']['healthy']);
    }

    #[Test]
    public function stale_processing_is_recovered_once_and_notified_only_to_active_policy_managers(): void
    {
        Notification::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW])->save();
        $otherUser = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        NotificationSetting::query()->create([
            'user_id' => $this->operationsAdmin->id,
            'notification_type' => 'storage_purchase_import_exception',
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        app(UpdateSupplierOrderImportOperationalState::class)->handle([
            'scheduler_heartbeat_at' => $at,
            'worker_heartbeat_at' => $at,
            'worker_sample_scheduled_at' => $at,
            'worker_queue_latency_seconds' => 0,
        ]);
        $import = $this->import(PurchaseOrderImport::STATUS_PROCESSING, [
            'created_at' => $at,
            'locked_at' => $at->subMinutes(30),
            'stage' => PurchaseOrderImport::STAGE_AI_EXTRACT,
        ]);

        $maintenance = app(RunSupplierOrderImportOperationsMaintenance::class);
        $first = $maintenance->handle($at);
        $second = $maintenance->handle($at);

        $this->assertSame(1, $first['recovered_count']);
        $this->assertSame(0, $second['recovered_count']);
        $this->assertSame(PurchaseOrderImport::STATUS_RETRY_SCHEDULED, $import->fresh()->status);
        $this->assertNull($import->fresh()->locked_at);
        Notification::assertSentToTimes(
            $this->operationsAdmin,
            SupplierOrderImportExceptionNotification::class,
            1,
        );
        Notification::assertNotSentTo($otherUser, SupplierOrderImportExceptionNotification::class);
        $delivery = DB::table('storage_purchase_order_import_alert_deliveries')->first();
        $this->assertSame(['database'], json_decode($delivery->channels, true));
        $this->assertDatabaseHas('storage_purchase_order_import_operational_alerts', [
            'alert_type' => 'stale_processing',
            'occurrence' => 1,
        ]);
        $this->assertNotNull(
            DB::table('storage_purchase_order_import_operational_alerts')
                ->where('alert_type', 'stale_processing')
                ->value('resolved_at'),
        );
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function maintenance_detects_queue_actor_profile_and_ai_policy_exceptions_without_repeat_notifications(): void
    {
        Notification::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_AUTO_DETERMINISTIC,
            'automation_user_id' => null,
        ])->save();
        app(UpdateSupplierOrderImportOperationalState::class)->handle([
            'scheduler_heartbeat_at' => $at,
            'worker_heartbeat_at' => $at->subMinutes(10),
            'worker_sample_scheduled_at' => $at->subMinutes(20),
            'worker_queue_latency_seconds' => 600,
        ]);
        PurchaseOrderImportProfile::query()->create([
            'name' => 'Drifted supplier',
            'slug' => 'drifted-supplier',
            'lifecycle_state' => PurchaseOrderImportProfile::STATE_PAUSED,
            'health_state' => 'paused',
            'consecutive_failures' => 5,
            'pause_reason' => 'circuit_breaker:canonical_validation_failed',
        ]);
        $this->import(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, [
            'reason_code' => 'workload_agent_inactive',
            'reason_context' => ['ai' => ['status' => 'denied', 'reason_code' => 'workload_agent_inactive']],
        ]);

        $maintenance = app(RunSupplierOrderImportOperationsMaintenance::class);
        $maintenance->handle($at);
        $maintenance->handle($at);

        Notification::assertSentToTimes(
            $this->operationsAdmin,
            SupplierOrderImportExceptionNotification::class,
            4,
        );
        $this->assertSame(
            ['ai_policy_denial', 'automation_actor_disabled', 'circuit_breaker', 'queue_worker_unhealthy'],
            DB::table('storage_purchase_order_import_operational_alerts')
                ->orderBy('alert_type')
                ->pluck('alert_type')
                ->all(),
        );
        $this->assertDatabaseCount('storage_purchase_order_import_alert_deliveries', 4);
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function ordinary_success_is_silent_when_the_digest_is_disabled(): void
    {
        Notification::fake();
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->travelTo($at);
        $this->policy->forceFill([
            'runtime_mode' => PurchaseOrderAutomationPolicy::MODE_REVIEW,
            'daily_digest_enabled' => false,
        ])->save();
        app(UpdateSupplierOrderImportOperationalState::class)->handle([
            'scheduler_heartbeat_at' => $at,
            'worker_heartbeat_at' => $at,
            'worker_sample_scheduled_at' => $at,
            'worker_queue_latency_seconds' => 0,
        ]);
        $this->import(PurchaseOrderImport::STATUS_IMPORTED, ['processed_at' => $at]);

        app(RunSupplierOrderImportOperationsMaintenance::class)->handle($at);

        Notification::assertNothingSent();
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function retention_preserves_append_only_attempts_and_source_audit_evidence(): void
    {
        $at = CarbonImmutable::parse('2026-08-05 10:00:00');
        $this->policy->forceFill(['retention_days' => 30])->save();
        $document = [
            'external_order_number' => 'PO-RETAIN-1',
            'lines' => [[
                'supplier_sku' => 'SKU-1',
                'quantity' => 1,
                'evidence' => ['anchor' => 'body.line.1'],
            ]],
        ];
        $import = $this->import(PurchaseOrderImport::STATUS_IMPORTED, [
            'created_at' => $at->subDays(31),
            'updated_at' => $at->subDays(31),
            'safe_source_snapshot' => [
                'subject' => 'Order confirmation',
                'body_text' => 'Durable sanitized source body',
                'diagnostics' => ['parser_trace' => 'temporary'],
                'attachments' => [[
                    'name' => 'order.pdf',
                    'checksum' => hash('sha256', 'attachment'),
                    'parser_debug' => ['duration_ms' => 12],
                ]],
            ],
            'normalized_document' => $document,
        ]);
        $attempt = $import->attempts()->create([
            'attempt_number' => 1,
            'stage' => PurchaseOrderImport::STAGE_VALIDATE,
            'status' => PurchaseOrderImport::STATUS_IMPORTED,
            'input_fingerprint' => $import->source_fingerprint,
            'output_fingerprint' => hash('sha256', json_encode($document)),
            'metadata' => ['temporary_payload' => 'remove me', 'duration_ms' => 12],
            'service_identity' => 'storage.supplier-order-import',
            'started_at' => $at->subDays(31),
            'completed_at' => $at->subDays(31),
        ]);
        $repair = PurchaseOrderImportRepair::query()->create([
            'import_id' => $import->id,
            'sequence' => 1,
            'status' => 'validated',
            'original_document_checksum' => hash('sha256', 'original'),
            'corrected_document' => $document,
            'corrected_document_checksum' => hash('sha256', 'corrected'),
            'validation_results' => ['valid' => true],
            'decision_summary' => ['outcome' => 'review'],
            'actor_id' => $this->operationsAdmin->id,
        ]);
        $fingerprint = $import->source_fingerprint;
        $originalSnapshot = $import->safe_source_snapshot;
        $revisionCount = DB::table('storage_purchase_order_automation_policy_revisions')->count();

        $result = app(PurgeSupplierOrderImportTroubleshootingData::class)->handle($at);

        $this->assertSame(0, $result['attempt_metadata_cleared']);
        $this->assertSame(0, $result['snapshot_fields_removed']);
        $import->refresh();
        $this->assertSame($fingerprint, $import->source_fingerprint);
        $this->assertSame($originalSnapshot, $import->safe_source_snapshot);
        $this->assertSame($document, $import->normalized_document);
        $this->assertSame('Durable sanitized source body', data_get($import->safe_source_snapshot, 'body_text'));
        $this->assertSame(['temporary_payload' => 'remove me', 'duration_ms' => 12], $attempt->fresh()->metadata);
        $this->assertDatabaseHas('storage_purchase_order_import_attempts', ['id' => $attempt->id]);
        $this->assertDatabaseHas('storage_purchase_order_import_repairs', ['id' => $repair->id]);
        $this->assertSame($revisionCount, DB::table('storage_purchase_order_automation_policy_revisions')->count());
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function enabled_daily_digest_is_deduplicated_per_recipient_and_day(): void
    {
        Notification::fake();
        $at = CarbonImmutable::parse('2026-08-05 07:00:00');
        $this->travelTo($at);
        $this->policy->forceFill(['daily_digest_enabled' => true])->save();
        NotificationSetting::query()->create([
            'user_id' => $this->operationsAdmin->id,
            'notification_type' => 'storage_purchase_import_digest',
            'mail_enabled' => false,
            'database_enabled' => true,
            'web_push_enabled' => false,
            'web_push_preview_enabled' => false,
            'nextcloud_talk_enabled' => false,
        ]);
        $this->import(PurchaseOrderImport::STATUS_IMPORTED, [
            'created_at' => CarbonImmutable::parse('2026-08-04 12:00:00'),
            'processed_at' => CarbonImmutable::parse('2026-08-04 12:01:00'),
        ]);
        $this->import(PurchaseOrderImport::STATUS_NEEDS_ATTENTION, [
            'created_at' => CarbonImmutable::parse('2026-08-04 13:00:00'),
            'reason_code' => 'supplier_requires_review',
        ]);
        $this->import(PurchaseOrderImport::STATUS_IMPORTED, [
            'created_at' => CarbonImmutable::parse('2026-08-05 00:00:00'),
            'processed_at' => CarbonImmutable::parse('2026-08-05 00:01:00'),
        ]);

        $digest = app(SendSupplierOrderImportDailyDigest::class);
        $firstId = $digest->handle($at);
        $secondId = $digest->handle($at);

        $this->assertNotNull($firstId);
        $this->assertSame($firstId, $secondId);
        Notification::assertSentToTimes(
            $this->operationsAdmin,
            SupplierOrderImportDailyDigestNotification::class,
            1,
        );
        Notification::assertSentTo(
            $this->operationsAdmin,
            fn (SupplierOrderImportDailyDigestNotification $notification): bool => $notification->total === 2,
        );
        $this->assertDatabaseCount('storage_purchase_order_import_alert_deliveries', 1);
        $this->assertDatabaseHas('storage_purchase_order_import_operational_alerts', [
            'id' => $firstId,
            'alert_type' => 'daily_digest',
            'occurrence' => 1,
        ]);
        $this->assertNoInventoryPostingSideEffects();
    }

    #[Test]
    public function supplier_order_runtime_jobs_use_the_dedicated_queue(): void
    {
        $this->assertSame('supplier-orders', (new ProcessSupplierOrderImport(1))->queue);
        $this->assertSame(
            'supplier-orders',
            (new ProcessScheduledSupplierOrderImport(1, '00000000-0000-4000-8000-000000000000'))->queue,
        );
        $this->assertSame('supplier-orders', (new RecordSupplierOrderImportQueueHeartbeat)->queue);
    }

    #[Test]
    public function isolated_runtime_command_routes_the_five_owned_operations(): void
    {
        Queue::fake();

        $dispatch = \Mockery::mock(DispatchDueSupplierOrderImports::class);
        $dispatch->shouldReceive('handle')->once()->andReturn(3);
        $this->app->instance(DispatchDueSupplierOrderImports::class, $dispatch);

        $health = \Mockery::mock(RunSupplierOrderImportOperationsMaintenance::class);
        $health->shouldReceive('handle')->once()->andReturn([
            'health' => ['state' => 'healthy'],
            'recovered_count' => 1,
            'active_alert_count' => 2,
        ]);
        $this->app->instance(RunSupplierOrderImportOperationsMaintenance::class, $health);

        $retention = \Mockery::mock(PurgeSupplierOrderImportTroubleshootingData::class);
        $retention->shouldReceive('handle')->once()->andReturn([
            'retention_days' => 730,
            'attempt_metadata_cleared' => 0,
            'snapshot_fields_removed' => 0,
        ]);
        $this->app->instance(PurgeSupplierOrderImportTroubleshootingData::class, $retention);

        $digest = \Mockery::mock(SendSupplierOrderImportDailyDigest::class);
        $digest->shouldReceive('handle')->once()->andReturn(42);
        $this->app->instance(SendSupplierOrderImportDailyDigest::class, $digest);

        $this->assertSame(
            ConsoleCommand::SUCCESS,
            Artisan::call('storage:supplier-orders', ['operation' => 'dispatch']),
        );
        $this->assertStringContainsString('3 import(s) queued', Artisan::output());

        $this->assertSame(
            ConsoleCommand::SUCCESS,
            Artisan::call('storage:supplier-orders', ['operation' => 'heartbeat']),
        );
        $this->assertStringContainsString('worker heartbeat queued', Artisan::output());
        Queue::assertPushed(
            RecordSupplierOrderImportQueueHeartbeat::class,
            fn (RecordSupplierOrderImportQueueHeartbeat $job): bool => $job->queue === 'supplier-orders',
        );

        $this->assertSame(
            ConsoleCommand::SUCCESS,
            Artisan::call('storage:supplier-orders', ['operation' => 'health']),
        );
        $this->assertStringContainsString(
            'healthy; 1 recovered; 2 active alert(s)',
            Artisan::output(),
        );

        $this->assertSame(
            ConsoleCommand::SUCCESS,
            Artisan::call('storage:supplier-orders', ['operation' => 'retention']),
        );
        $this->assertStringContainsString('730 day(s)', Artisan::output());

        $this->assertSame(
            ConsoleCommand::SUCCESS,
            Artisan::call('storage:supplier-orders', ['operation' => 'digest']),
        );
        $this->assertStringContainsString('digest delivered', Artisan::output());
    }

    #[Test]
    public function isolated_runtime_command_rejects_an_unknown_operation(): void
    {
        $this->assertSame(
            ConsoleCommand::INVALID,
            Artisan::call('storage:supplier-orders', ['operation' => 'unknown']),
        );
        $output = Artisan::output();
        $this->assertStringContainsString(
            'Invalid supplier-order operation [unknown]',
            $output,
        );
        $this->assertStringContainsString(
            'Allowed operations: dispatch, heartbeat, health, retention, digest',
            $output,
        );
    }

    #[Test]
    public function operational_schedules_and_notification_preferences_are_registered(): void
    {
        $this->assertArrayHasKey('storage_purchase_import_exception', NotificationSetting::TYPES);
        $this->assertArrayHasKey('storage_purchase_import_digest', NotificationSetting::TYPES);

        Artisan::call('schedule:list');
        $output = Artisan::output();
        $this->assertStringContainsString('storage.supplier_order_imports.dispatch_due', $output);
        $this->assertStringContainsString('storage.supplier_order_imports.queue_heartbeat', $output);
        $this->assertStringContainsString('storage.supplier_order_imports.health', $output);
        $this->assertStringContainsString('storage.supplier_order_imports.retention', $output);
        $this->assertStringContainsString('storage.supplier_order_imports.digest', $output);
    }

    /** @param array<string, mixed> $attributes */
    private function import(string $status, array $attributes = []): PurchaseOrderImport
    {
        $this->sourceSequence++;
        $sourceId = 'operations-test-'.$this->sourceSequence;
        $trustedAuth = [
            'authentication_passed' => true,
            'aligned' => true,
        ];
        $trustedAuth = $attributes['trusted_auth_snapshot'] ?? $trustedAuth;
        $snapshot = $attributes['safe_source_snapshot'] ?? [
            'from' => ['address' => 'orders@example.invalid'],
            'subject' => 'Order '.$this->sourceSequence,
            'received_at' => now()->toIso8601String(),
            'body_text' => 'Sanitized source',
        ];
        $snapshot['trusted_auth'] = $trustedAuth;
        $result = app(CreatePurchaseOrderImport::class)->handle([
            'source_domain' => 'email',
            'source_type' => 'email_message',
            'source_id' => $sourceId,
            'signal_action_key' => 'test-action-'.$this->sourceSequence,
            'source_fingerprint' => StableJson::checksum($snapshot),
            'safe_source_snapshot' => $snapshot,
            'trusted_auth_snapshot' => $trustedAuth,
            'policy_revision_id' => $this->policyRevision->id,
            'status' => $status,
            'stage' => $attributes['stage'] ?? PurchaseOrderImport::STAGE_DETECT,
        ]);
        $import = $result['import'];
        unset($attributes['safe_source_snapshot']);
        unset($attributes['trusted_auth_snapshot']);
        unset($attributes['source_fingerprint']);
        $import->forceFill($attributes)->save();

        return $import->fresh();
    }

    private function assertNoInventoryPostingSideEffects(): void
    {
        $this->assertDatabaseCount('storage_purchase_receipts', 0);
        $this->assertDatabaseCount('storage_purchase_receipt_lines', 0);
        $this->assertDatabaseCount('storage_stock_units', 0);
        $this->assertDatabaseCount('storage_movements', 0);
    }
}
