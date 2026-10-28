<?php

namespace App\Modules\Notification\Tests\Feature;

use App\Modules\Notification\Support\SystemWebPushQueueEvidence;
use App\Modules\System\Queries\RuntimeHealthQuery;
use App\Modules\System\Services\RuntimeHeartbeat;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/** Consume the actual System contract without granting queue scope or confusing cold cohorts with the default worker. */
class SystemWebPushQueueEvidenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-28T12:00:00Z'));
        config(['queue.default' => 'database', 'runtime_health.enabled' => true,
            'runtime_health.runtime_key' => 'webpush-integration-fixture',
            'runtime_health.connection' => 'database',
            'runtime_health.required_queues' => ['default', 'notifications'],
            'runtime_health.probe_queues' => ['default'],
            'runtime_health.blocked_queues' => ['notifications'],
            'runtime_health.queue_stale_seconds' => [],
            'runtime_health.scheduler_stale_seconds' => 180,
            'runtime_health.worker_stale_seconds' => 300,
            'runtime_health.probe_interval_seconds' => 60]);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function completeDefaultProbe(): void
    {
        $heartbeat = app(RuntimeHeartbeat::class);
        $this->assertSame(1, $heartbeat->tick()['probes_dispatched']);
        $row = DB::table(RuntimeHeartbeat::TABLE)->where('queue_name', 'default')->first();
        $this->assertTrue($heartbeat->complete('webpush-integration-fixture', $row->component_key, $row->pending_probe_id));
    }

    #[Test]
    public function exact_default_evidence_is_read_only_while_unrelated_cold_queues_stay_blocked(): void
    {
        $this->completeDefaultProbe();
        $this->assertSame('blocked', app(RuntimeHealthQuery::class)->get()['status']);
        $before = DB::table(RuntimeHeartbeat::TABLE)->orderBy('component_key')->get()->toJson();
        $evidence = app(SystemWebPushQueueEvidence::class)->get();
        $this->assertSame('healthy', $evidence['state']);
        $this->assertSame('default', $evidence['queue']);
        $this->assertSame('database', $evidence['connection']);
        $this->assertNotNull($evidence['last_completed_at']);
        $this->assertSame($before, DB::table(RuntimeHeartbeat::TABLE)->orderBy('component_key')->get()->toJson());
        $this->assertNull(DB::table(RuntimeHeartbeat::TABLE)->where('queue_name', 'notifications')->value('pending_probe_id'));
        Queue::assertPushed(\App\Modules\System\Jobs\CompleteRuntimeQueueProbe::class, 1);
    }

    #[Test]
    public function stale_unapproved_disabled_and_different_connection_evidence_never_becomes_ready(): void
    {
        $this->completeDefaultProbe();
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(301));
        $this->assertSame('stale', app(SystemWebPushQueueEvidence::class)->get()['state']);
        config(['runtime_health.probe_queues' => []]);
        $this->assertSame('default_queue_not_approved', app(SystemWebPushQueueEvidence::class)->get()['reason_code']);
        config(['runtime_health.probe_queues' => ['default'], 'queue.default' => 'redis']);
        $this->assertSame('default_queue_not_approved', app(SystemWebPushQueueEvidence::class)->get()['reason_code']);
        config(['runtime_health.enabled' => false]);
        $this->assertSame('runtime_health_not_enabled', app(SystemWebPushQueueEvidence::class)->get()['reason_code']);
        Queue::assertPushed(\App\Modules\System\Jobs\CompleteRuntimeQueueProbe::class, 1);
    }
}
