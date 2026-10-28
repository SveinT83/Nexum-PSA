<?php

namespace App\Modules\System\Tests\Feature;

use App\Modules\System\Jobs\CompleteRuntimeQueueProbe;
use App\Modules\System\Queries\RuntimeHealthQuery;
use App\Modules\System\Services\RuntimeHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\TestCase;

class RuntimeHealthTest extends TestCase
{
    use RefreshDatabase;

    private const QUEUE = 'runtime-health-test';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-27T12:00:00Z'));
        config()->set('runtime_health', [
            'enabled' => true, 'runtime_key' => 'test',
            'connection' => 'database', 'required_queues' => [self::QUEUE],
            'probe_queues' => [self::QUEUE], 'blocked_queues' => [],
            'scheduler_stale_seconds' => 180, 'worker_stale_seconds' => 300,
            'probe_interval_seconds' => 60, 'retention_days' => 30,
        ]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    #[Test]
    public function disabled_defaults_do_not_create_evidence_or_publish_jobs(): void
    {
        Queue::fake();
        config()->set('runtime_health.enabled', false);
        $this->assertSame('blocked', app(RuntimeHealthQuery::class)->get()['status']);
        $this->assertSame('runtime_health_disabled', app(RuntimeHeartbeat::class)->tick()['reason_code']);
        $this->assertSame(0, DB::table(RuntimeHeartbeat::TABLE)->count());
        Queue::assertNothingPushed();
        $this->artisan('system:runtime-health')->assertExitCode(1);
    }

    #[Test]
    public function missing_schema_and_unobserved_components_are_unknown_without_writes(): void
    {
        $this->assertSame('unknown', app(RuntimeHealthQuery::class)->get()['status']);
        $this->assertSame(0, DB::table(RuntimeHeartbeat::TABLE)->count());
        Schema::drop(RuntimeHeartbeat::TABLE);
        $result = app(RuntimeHealthQuery::class)->get();
        $this->assertSame('runtime_evidence_unavailable', $result['reason_code']);
        $this->artisan('system:runtime-health')->assertExitCode(2);
        $this->assertFalse(Schema::hasTable(RuntimeHeartbeat::TABLE));
    }

    #[Test]
    public function repeated_ticks_bound_an_unconsumed_probe_and_do_not_claim_worker_health(): void
    {
        Queue::fake();
        $heartbeat = app(RuntimeHeartbeat::class);
        $this->assertSame(1, $heartbeat->tick()['probes_dispatched']);
        $probe = $this->queueRow()->pending_probe_id;
        $this->assertSame(0, $heartbeat->tick()['probes_dispatched']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $this->assertSame(0, $heartbeat->tick()['probes_dispatched']);
        $this->assertSame($probe, $this->queueRow()->pending_probe_id);
        $this->assertSame(2, DB::table(RuntimeHeartbeat::TABLE)->count());
        Queue::assertPushed(CompleteRuntimeQueueProbe::class, 1);
        $health = app(RuntimeHealthQuery::class)->get();
        $this->assertSame('healthy', $health['components'][0]['status']);
        $this->assertSame('stale', $health['components'][1]['status']);
        $this->assertFalse($health['business_delivery_verified']);
    }

    #[Test]
    public function only_exact_current_probe_completes_and_old_delivery_cannot_refresh_it(): void
    {
        Queue::fake();
        $heartbeat = app(RuntimeHeartbeat::class);
        $heartbeat->tick();
        $first = $this->queueRow();
        $this->assertFalse($heartbeat->complete('wrong-runtime', $first->component_key, $first->pending_probe_id));
        $this->assertFalse($heartbeat->complete('test', $first->component_key, (string) Str::uuid()));
        $this->assertTrue($heartbeat->complete('test', $first->component_key, $first->pending_probe_id));
        $completed = $this->queueRow()->completed_at;
        $this->assertSame('healthy', app(RuntimeHealthQuery::class)->get()['status']);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $this->assertSame(1, $heartbeat->tick()['probes_dispatched']);
        $second = $this->queueRow();
        $this->assertNotSame($first->pending_probe_id, $second->pending_probe_id);
        $this->assertFalse($heartbeat->complete('test', $first->component_key, $first->pending_probe_id));
        $this->assertSame($second->pending_probe_id, $this->queueRow()->pending_probe_id);
        $this->assertSame($completed, $this->queueRow()->completed_at);
        (new CompleteRuntimeQueueProbe('test', $second->component_key, $second->pending_probe_id))->handle($heartbeat);
        $this->assertNull($this->queueRow()->pending_probe_id);
        $this->assertSame($second->pending_probe_id, $this->queueRow()->completed_probe_id);
    }

    #[Test]
    public function freshness_thresholds_and_future_evidence_never_report_false_health(): void
    {
        Queue::fake();
        $heartbeat = app(RuntimeHeartbeat::class);
        $heartbeat->tick();
        $row = $this->queueRow();
        $heartbeat->complete('test', $row->component_key, $row->pending_probe_id);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(180));
        $this->assertSame('healthy', app(RuntimeHealthQuery::class)->get()['status']);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSecond());
        $this->assertSame('stale', app(RuntimeHealthQuery::class)->get()['status']);
        DB::table(RuntimeHeartbeat::TABLE)->where('kind', 'scheduler')
            ->update(['observed_at' => CarbonImmutable::now()->addHour()]);
        $this->assertSame('unknown', app(RuntimeHealthQuery::class)->get()['status']);
        $this->assertSame('evidence_in_future', app(RuntimeHealthQuery::class)->get()['components'][0]['reason_code']);
    }

    #[Test]
    public function unapproved_and_blocked_queues_do_not_receive_probes_even_after_prior_success(): void
    {
        Queue::fake();
        $heartbeat = app(RuntimeHeartbeat::class);
        $heartbeat->tick();
        $row = $this->queueRow();
        $heartbeat->complete('test', $row->component_key, $row->pending_probe_id);
        config()->set('runtime_health.blocked_queues', [self::QUEUE]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $this->assertSame(0, $heartbeat->tick()['probes_dispatched']);
        $this->assertSame('blocked', app(RuntimeHealthQuery::class)->get()['status']);
        $this->assertSame('operator_review_required', app(RuntimeHealthQuery::class)->get()['components'][1]['reason_code']);
        config()->set('runtime_health.blocked_queues', []);
        config()->set('runtime_health.probe_queues', []);
        $this->assertSame('queue_probe_not_approved', app(RuntimeHealthQuery::class)->get()['components'][1]['reason_code']);
        Queue::assertPushed(CompleteRuntimeQueueProbe::class, 1);
    }

    #[Test]
    public function sync_or_unknown_backends_cannot_simulate_worker_completion(): void
    {
        Queue::fake();
        foreach (['sync', 'null', 'private-unsupported-driver'] as $driver) {
            config()->set('queue.connections.database.driver', $driver);
            app(RuntimeHeartbeat::class)->tick();
            $result = app(RuntimeHealthQuery::class)->get();
            $this->assertSame('unknown', $result['status']);
            $this->assertSame('asynchronous_backend_required', $result['components'][1]['reason_code']);
            $this->assertStringNotContainsString('private-unsupported-driver', json_encode($result));
        }
        Queue::assertNothingPushed();
    }

    #[Test]
    public function ambiguous_dispatch_preserves_one_identity_and_never_exposes_transport_error(): void
    {
        Queue::shouldReceive('connection')->once()->with('database')->andReturnSelf();
        Queue::shouldReceive('push')->once()->andThrow(new RuntimeException('private-password-and-payload-canary'));
        $heartbeat = app(RuntimeHeartbeat::class);
        $heartbeat->tick();
        $row = $this->queueRow();
        $this->assertNotNull($row->pending_probe_id);
        $this->assertSame('probe_dispatch_unknown', $row->blocked_reason);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
        $heartbeat->tick();
        $this->assertSame($row->pending_probe_id, $this->queueRow()->pending_probe_id);
        $result = app(RuntimeHealthQuery::class)->get();
        $this->assertSame('blocked', $result['status']);
        $this->assertStringNotContainsString('private-password', json_encode($result));
    }

    #[Test]
    public function incomplete_dispatch_and_corrupt_pending_evidence_stay_unknown(): void
    {
        Queue::fake();
        app(RuntimeHeartbeat::class)->tick();
        DB::table(RuntimeHeartbeat::TABLE)->where('kind', 'queue')->update(['enqueued_at' => null]);
        $this->assertSame('probe_dispatch_unconfirmed', app(RuntimeHealthQuery::class)->get()['components'][1]['reason_code']);
        DB::table(RuntimeHeartbeat::TABLE)->where('kind', 'queue')->update([
            'enqueued_at' => CarbonImmutable::now(), 'pending_since' => null,
            'completed_at' => CarbonImmutable::now(),
        ]);
        $this->assertSame('unknown', app(RuntimeHealthQuery::class)->get()['status']);
    }

    #[Test]
    public function reading_health_is_observational_and_disabled_completion_cannot_write(): void
    {
        Queue::fake();
        app(RuntimeHeartbeat::class)->tick();
        $row = $this->queueRow();
        $before = DB::table(RuntimeHeartbeat::TABLE)->get()->toJson();
        app(RuntimeHealthQuery::class)->get();
        app(RuntimeHealthQuery::class)->get();
        $this->assertSame($before, DB::table(RuntimeHeartbeat::TABLE)->get()->toJson());
        config()->set('runtime_health.enabled', false);
        $this->assertFalse(app(RuntimeHeartbeat::class)->complete('test', $row->component_key, $row->pending_probe_id));
        $this->assertSame($before, DB::table(RuntimeHeartbeat::TABLE)->get()->toJson());
    }

    #[Test]
    public function invalid_or_empty_scope_fails_closed_and_does_not_dump_configuration(): void
    {
        foreach ([
            ['runtime_health.runtime_key' => 'private / credential'],
            ['runtime_health.required_queues' => array_fill(0, 33, self::QUEUE)],
            ['runtime_health.probe_queues' => ['outside-approval']],
        ] as $config) {
            $before = config('runtime_health');
            config()->set($config);
            $result = app(RuntimeHealthQuery::class)->get();
            $this->assertSame('unknown', $result['status']);
            $this->assertSame('runtime_scope_invalid', $result['reason_code']);
            $this->assertStringNotContainsString('credential', json_encode($result));
            config()->set('runtime_health', $before);
        }
        config()->set('runtime_health.required_queues', []);
        config()->set('runtime_health.probe_queues', []);
        $this->assertSame('required_queues_not_configured', app(RuntimeHealthQuery::class)->get()['reason_code']);
    }

    #[Test]
    public function schedule_registration_does_not_need_schema_or_enable_runtime(): void
    {
        Schema::drop(RuntimeHeartbeat::TABLE);
        config()->set('runtime_health.enabled', false);
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event) => $event->description === 'system.runtime.heartbeat');
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertFalse(Schema::hasTable(RuntimeHeartbeat::TABLE));
    }

    #[Test]
    public function retention_only_removes_obsolete_completed_health_rows(): void
    {
        Queue::fake();
        $old = CarbonImmutable::now()->subDays(31);
        foreach (['completed', 'unresolved'] as $key) {
            DB::table(RuntimeHeartbeat::TABLE)->insert([
                'component_key' => hash('sha256', $key), 'runtime_key' => 'obsolete',
                'kind' => 'queue', 'observed_at' => $old,
                'pending_probe_id' => $key === 'unresolved' ? (string) Str::uuid() : null,
            ]);
        }
        $jobId = DB::table('jobs')->insertGetId([
            'queue' => 'unrelated', 'payload' => '{}', 'attempts' => 0,
            'reserved_at' => null, 'available_at' => time(), 'created_at' => time(),
        ]);
        app(RuntimeHeartbeat::class)->tick();
        $this->assertDatabaseMissing(RuntimeHeartbeat::TABLE, ['component_key' => hash('sha256', 'completed')]);
        $this->assertDatabaseHas(RuntimeHeartbeat::TABLE, ['component_key' => hash('sha256', 'unresolved')]);
        $this->assertDatabaseHas('jobs', ['id' => $jobId]);
    }

    #[Test]
    public function per_queue_deadlines_and_explicit_probe_reconciliation_remain_narrow(): void
    {
        Queue::fake();
        $heartbeat = app(RuntimeHeartbeat::class);
        $heartbeat->tick();
        $row = $this->queueRow();
        $this->assertSame($row->pending_probe_id, app(RuntimeHealthQuery::class)->get()['components'][1]['pending_probe_id']);
        $this->artisan('system:runtime-probe-reconcile', ['queue' => self::QUEUE, 'probe' => $row->pending_probe_id])
            ->assertExitCode(1);
        $this->assertSame($row->pending_probe_id, $this->queueRow()->pending_probe_id);
        $this->assertFalse($heartbeat->reconcile('outside-scope', $row->pending_probe_id));
        $this->assertFalse($heartbeat->reconcile(self::QUEUE, (string) Str::uuid()));
        $this->artisan('system:runtime-probe-reconcile', [
            'queue' => self::QUEUE, 'probe' => $row->pending_probe_id, '--confirm-not-delivered' => true,
        ])->assertExitCode(0);
        $this->assertSame($row->pending_probe_id, $this->queueRow()->last_reconciled_probe_id);
        $this->assertNull($this->queueRow()->completed_at);
        $this->assertFalse($heartbeat->complete('test', $row->component_key, $row->pending_probe_id));
        Queue::assertPushed(CompleteRuntimeQueueProbe::class, 1);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
        $heartbeat->tick();
        $next = $this->queueRow();
        $this->assertNotSame($row->pending_probe_id, $next->pending_probe_id);
        $this->assertTrue($heartbeat->complete('test', $next->component_key, $next->pending_probe_id));
        config()->set('runtime_health.scheduler_stale_seconds', 3600);
        config()->set('runtime_health.queue_stale_seconds', [self::QUEUE => 60]);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(61));
        $this->assertSame('stale', app(RuntimeHealthQuery::class)->get()['status']);
        $this->assertSame(60, app(RuntimeHealthQuery::class)->get()['components'][1]['threshold_seconds']);
    }

    #[Test]
    public function delayed_canary_completion_cannot_hide_excessive_queue_latency(): void
    {
        Queue::fake();
        $heartbeat = app(RuntimeHeartbeat::class);
        $heartbeat->tick();
        $row = $this->queueRow();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(301));
        $heartbeat->tick();
        $this->assertTrue($heartbeat->complete('test', $row->component_key, $row->pending_probe_id));
        $health = app(RuntimeHealthQuery::class)->get();
        $this->assertSame('healthy', $health['components'][0]['status']);
        $this->assertSame('stale', $health['components'][1]['status']);
        $this->assertSame('queue_latency_exceeded', $health['components'][1]['reason_code']);
        $this->assertSame(301, $health['components'][1]['queue_latency_seconds']);
        $this->assertSame('stale', $health['status']);
    }

    private function queueRow(): object
    {
        return DB::table(RuntimeHeartbeat::TABLE)->where('runtime_key', 'test')->where('kind', 'queue')->firstOrFail();
    }
}
