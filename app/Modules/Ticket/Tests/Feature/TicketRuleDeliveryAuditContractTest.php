<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Signal\Models\Signal;
use App\Modules\Ticket\Actions\DispatchTicketRuleAfterCommit;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketPriority;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleAfterCommitResult;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleExecution;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Services\TicketRuleActionRetrySelector;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleDeliveryAuditContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $defaults;

    protected function setUp(): void
    {
        parent::setUp();

        $this->defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
    }

    #[Test]
    public function managed_actor_is_non_login_roleless_and_exact_permission_drift_fails_closed(): void
    {
        $actor = app(TicketRuleRuntimeGate::class)->requireExistingActor();

        $this->assertTrue($actor->isSystemActor());
        $this->assertFalse($actor->isActive());
        $this->assertFalse($actor->roles()->exists());
        $this->assertSame(
            collect(TicketRuleAutomationActor::PERMISSIONS)->sort()->values()->all(),
            $actor->getDirectPermissions()->pluck('name')->sort()->values()->all(),
        );

        $unexpectedPermission = Permission::query()
            ->where('guard_name', 'web')
            ->whereNotIn('name', TicketRuleAutomationActor::PERMISSIONS)
            ->orderBy('name')
            ->firstOrFail();
        $this->assertNotContains($unexpectedPermission->name, TicketRuleAutomationActor::PERMISSIONS);
        $actor->givePermissionTo($unexpectedPermission);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('permission set has drifted');
        app(TicketRuleRuntimeGate::class)->requireExistingActor();
    }

    #[Test]
    public function target_domain_guard_denial_is_audited_and_initiator_authority_cannot_override_it(): void
    {
        $alternateQueue = $this->queue('guard-denied-target');
        $version = $this->publishRule([[
            'type' => 'set_queue',
            'value' => $alternateQueue->id,
        ]]);
        $closed = TicketStatus::query()->where('slug', 'closed')->firstOrFail();
        $ticket = $this->ticket(['status_id' => $closed->id, 'closed_at' => now()]);
        $initiator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $initiator->givePermissionTo(Permission::findOrCreate('ticket.update', 'web'));

        $run = $this->runCreated($ticket, $initiator);
        $execution = TicketRuleExecution::query()
            ->where('run_id', $run->id)
            ->where('rule_version_id', $version->id)
            ->firstOrFail();
        $action = $execution->actionResults()->firstOrFail();

        $this->assertSame(TicketRuleRun::STATUS_FAILED, $run->status);
        $this->assertSame(TicketRuleExecution::STATUS_FAILED, $execution->status);
        $this->assertSame('action_guard_denied', $action->failure_code);
        $this->assertSame($initiator->id, $run->initiator_id);
        $this->assertNotSame($initiator->id, $run->automation_actor_id);
        $this->assertSame($this->defaults['queue']->id, $ticket->refresh()->queue_id);
    }

    #[Test]
    public function completed_evidence_rejects_model_delete_and_raw_database_mutation(): void
    {
        $alternateQueue = $this->queue('immutable-evidence-target');
        $this->publishRule([[
            'type' => 'set_queue',
            'value' => $alternateQueue->id,
        ]]);
        $initiator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $run = $this->runCreated($this->ticket(), $initiator);

        try {
            $run->forceFill(['status' => TicketRuleRun::STATUS_FAILED])->save();
            $this->fail('Completed run mutation should have been refused.');
        } catch (LogicException $exception) {
            $this->assertSame('Completed Ticket Rule evidence is immutable.', $exception->getMessage());
        }

        try {
            $run->delete();
            $this->fail('Evidence deletion should have been refused.');
        } catch (LogicException $exception) {
            $this->assertSame('Ticket Rule evidence cannot be deleted.', $exception->getMessage());
        }

        $initiatorId = $initiator->id;
        $initiator->delete();
        $this->assertSame($initiatorId, $run->refresh()->initiator_id);

        $this->expectException(QueryException::class);
        DB::table('ticket_rule_runs')
            ->where('id', $run->id)
            ->update(['termination_reason' => 'rewritten']);
    }

    #[Test]
    public function signal_delivery_is_after_commit_shaped_idempotent_and_privacy_safe(): void
    {
        $action = [
            'type' => 'emit_signal',
            'signal_type' => 'security_alert',
            'severity' => 'warning',
            'confidence' => 88,
            'payload_note' => 'Secret token abc-123',
        ];
        $version = $this->publishRule([$action]);
        $dispatchPayload = $action + [
            'ticket_rule_id' => $version->ticket_rule_id,
            'ticket_rule_name' => $version->name,
            'ticket_rule_action_index' => 0,
        ];
        $ticket = $this->ticket();
        $queuedStatus = null;
        $run = DB::transaction(function () use ($ticket, &$queuedStatus): TicketRuleRun {
            $run = $this->runCreated($ticket);
            $queuedStatus = $run->afterCommitResults()->firstOrFail()->status;

            return $run;
        });
        $delivery = $run->afterCommitResults()->firstOrFail();

        $this->assertSame(TicketRuleAfterCommitResult::STATUS_QUEUED, $queuedStatus);
        $this->assertSame(TicketRuleAfterCommitResult::STATUS_SUCCEEDED, $delivery->status);
        $this->assertStringNotContainsString(
            'abc-123',
            json_encode($delivery->safe_payload_json, JSON_THROW_ON_ERROR),
        );

        app(DispatchTicketRuleAfterCommit::class)->signal($delivery->id, $dispatchPayload);

        $delivery->refresh();
        $this->assertSame(TicketRuleAfterCommitResult::STATUS_SUCCEEDED, $delivery->status);
        $this->assertSame(1, $delivery->attempt_count);
        $this->assertNotNull($delivery->completed_at);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            (string) $delivery->external_reference_fingerprint,
        );
        $signals = Signal::query()
            ->where('source_domain', 'ticket')
            ->where('source_id', $ticket->id)
            ->where('payload->ticket_rule_delivery_key', $delivery->delivery_key)
            ->get();
        $this->assertCount(1, $signals);
        $signal = $signals->firstOrFail();
        $this->assertSame('Ticket rule signal: security alert', $signal->summary);
        $this->assertSame($version->ticket_rule_id, $signal->payload['ticket_rule_id']);
        $this->assertSame($version->name, $signal->payload['ticket_rule_name']);
        $this->assertSame(0, $signal->payload['ticket_rule_action_index']);
    }

    #[Test]
    public function unknown_worker_outcome_requires_reconciliation_and_retry_is_lineaged_idempotently(): void
    {
        $payload = [
            'type' => 'emit_signal',
            'signal_type' => 'delivery_retry_contract',
            'severity' => 'info',
            'confidence' => 100,
            'summary' => null,
            'payload_note' => null,
        ];
        $this->publishRule([array_diff_key($payload, ['type' => null]) + ['type' => 'emit_signal']]);
        DB::beginTransaction();

        try {
            $run = $this->runCreated($this->ticket());
            $delivery = $run->afterCommitResults()->firstOrFail();
            $delivery->forceFill([
                'status' => TicketRuleAfterCommitResult::STATUS_RUNNING,
                'attempt_count' => 1,
                'started_at' => now()->subMinutes(5),
            ])->save();
            $dispatcher = app(DispatchTicketRuleAfterCommit::class);

            $this->assertTrue($dispatcher->markStaleRunningUnresolved(
                $delivery->id,
                now()->subMinute(),
            ));
            $this->assertSame(
                TicketRuleAfterCommitResult::STATUS_UNRESOLVED,
                $delivery->refresh()->status,
            );

            try {
                $dispatcher->retryUnresolved($delivery->id, '');
                $this->fail('Unresolved delivery retry must require reconciliation evidence.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('reconciliation evidence', $exception->getMessage());
            }

            $reference = 'provider-audit: confirmed not delivered';
            $retry = $dispatcher->retryUnresolved($delivery->id, $reference);
            $duplicate = $dispatcher->retryUnresolved($delivery->id, $reference);

            try {
                $dispatcher->retryUnresolved($delivery->id, 'different provider outcome');
                $this->fail('A repeated retry must not accept conflicting reconciliation evidence.');
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString('does not match', $exception->getMessage());
            }

            $this->assertNotNull($retry);
            $this->assertSame($retry->id, $duplicate?->id);
            $this->assertSame($delivery->id, $retry->retry_of_id);
            $this->assertSame(2, $retry->attempt_number);
            $this->assertSame(TicketRuleAfterCommitResult::STATUS_QUEUED, $retry->status);
            $this->assertSame($delivery->delivery_key, $retry->delivery_key);
            $this->assertSame($delivery->precondition_fingerprint, $retry->precondition_fingerprint);
            $this->assertSame(
                hash('sha256', 'confirmed-not-delivered:'.$reference),
                $retry->reconciliation_fingerprint,
            );
            $this->assertNull($retry->external_reference_fingerprint);
            $this->assertStringNotContainsString($reference, json_encode($retry->getAttributes(), JSON_THROW_ON_ERROR));
        } finally {
            DB::rollBack();
        }
    }

    #[Test]
    public function synchronous_failed_and_not_run_positions_are_reserved_idempotently_and_fail_closed_on_drift(): void
    {
        $alternateQueue = $this->queue('retry-selector-queue');
        $alternatePriority = $this->priority('retry-selector-priority', 2);
        $version = $this->publishRule([
            ['type' => 'set_queue', 'value' => $alternateQueue->id],
            ['type' => 'set_priority', 'value' => $alternatePriority->id],
            ['type' => 'emit_signal', 'signal_type' => 'non_idempotent_retry'],
        ]);
        $closed = TicketStatus::query()->where('slug', 'closed')->firstOrFail();
        $ticket = $this->ticket(['status_id' => $closed->id, 'closed_at' => now()]);
        $run = $this->runCreated($ticket);
        $execution = TicketRuleExecution::query()
            ->where('run_id', $run->id)
            ->where('rule_version_id', $version->id)
            ->firstOrFail();
        $results = $execution->actionResults()->orderBy('position')->get();
        $failed = $results[0];
        $notRun = $results[1];
        $nonIdempotent = $results[2];
        $selector = app(TicketRuleActionRetrySelector::class);

        $this->assertSame([
            TicketRuleActionResult::STATUS_FAILED,
            TicketRuleActionResult::STATUS_NOT_RUN,
            TicketRuleActionResult::STATUS_NOT_RUN,
        ], $results->pluck('status')->all());
        $this->assertSame('action_guard_denied', $failed->failure_code);
        $this->assertSame(
            [$failed->id, $notRun->id],
            $selector->candidates($ticket, $run->id)->pluck('id')->all(),
        );
        $this->assertTrue($selector->isEligible($failed, $ticket));
        $this->assertTrue($selector->isEligible($notRun, $ticket));
        $this->assertFalse($selector->isEligible($nonIdempotent, $ticket));

        $retry = $selector->reserveRetryAttempt($failed, $ticket);
        $duplicate = $selector->reserveRetryAttempt($failed, $ticket);

        $this->assertNotNull($retry);
        $this->assertSame($retry->id, $duplicate?->id);
        $this->assertSame(TicketRuleActionResult::STATUS_PLANNED, $retry->status);
        $this->assertSame(2, $retry->attempt_number);
        $this->assertSame($failed->id, $retry->retry_of_id);
        $this->assertSame($failed->position_key, $retry->position_key);
        $this->assertSame($failed->precondition_fingerprint, $retry->precondition_fingerprint);
        $this->assertSame($failed->action_snapshot_json, $retry->action_snapshot_json);
        $this->assertFalse($selector->isEligible($failed, $ticket));
        $this->assertFalse($selector->isEligible($retry, $ticket));
        $this->assertSame(
            [$notRun->id],
            $selector->candidates($ticket, $run->id)->pluck('id')->all(),
        );

        $alternatePriority->update(['is_active' => false]);
        $this->assertFalse($selector->isEligible($notRun, $ticket));
        $this->assertSame([], $selector->candidates($ticket, $run->id)->pluck('id')->all());

        $alternatePriority->update(['is_active' => true]);
        $this->assertTrue($selector->isEligible($notRun, $ticket));
        $ticket->forceFill(['subject' => 'Ticket state changed after the failed run.'])->save();
        $this->assertFalse($selector->isEligible($notRun, $ticket));
        $this->assertNull($selector->reserveRetryAttempt($notRun, $ticket));
    }

    #[Test]
    public function completed_and_rolled_back_positions_are_never_synchronous_retry_candidates(): void
    {
        $alternateQueue = $this->queue('retry-status-queue');
        $alternatePriority = $this->priority('retry-status-priority', 1);
        $completedVersion = $this->publishRule([
            ['type' => 'set_queue', 'value' => $this->defaults['queue']->id],
            ['type' => 'set_priority', 'value' => $alternatePriority->id],
            ['type' => 'emit_signal', 'signal_type' => 'queued_status_must_not_retry'],
        ]);
        $failedVersion = $this->publishRule([
            ['type' => 'set_queue', 'value' => $alternateQueue->id],
            ['type' => 'set_priority', 'value' => 999999],
        ]);
        $ticket = $this->ticket();
        $run = $this->runCreated($ticket);
        $completedResults = TicketRuleExecution::query()
            ->where('run_id', $run->id)
            ->where('rule_version_id', $completedVersion->id)
            ->firstOrFail()
            ->actionResults()
            ->orderBy('position')
            ->get();
        $failedResults = TicketRuleExecution::query()
            ->where('run_id', $run->id)
            ->where('rule_version_id', $failedVersion->id)
            ->firstOrFail()
            ->actionResults()
            ->orderBy('position')
            ->get();
        $selector = app(TicketRuleActionRetrySelector::class);

        $this->assertSame([
            TicketRuleActionResult::STATUS_NO_CHANGE,
            TicketRuleActionResult::STATUS_SUCCEEDED,
            TicketRuleActionResult::STATUS_QUEUED,
        ], $completedResults->pluck('status')->all());
        $this->assertSame([
            TicketRuleActionResult::STATUS_ROLLED_BACK,
            TicketRuleActionResult::STATUS_FAILED,
        ], $failedResults->pluck('status')->all());
        foreach ($completedResults->concat($failedResults) as $result) {
            $this->assertFalse($selector->isEligible($result, $ticket));
        }
        $this->assertSame([], $selector->candidates($ticket, $run->id)->pluck('id')->all());
    }

    #[Test]
    public function reconciled_delivery_retry_dispatch_preserves_separate_fingerprints_and_ignores_safe_payload(): void
    {
        $action = [
            'type' => 'emit_signal',
            'signal_type' => 'immutable_retry_signal',
            'severity' => 'warning',
            'confidence' => 73,
            'summary' => 'Immutable retry summary',
            'payload_note' => 'Published action evidence note.',
        ];
        $version = $this->publishRule([$action]);
        $ticket = $this->ticket();
        $reference = 'Provider case 8173 confirms not delivered: free-form reconciliation note.';
        $reconciliationFingerprint = hash('sha256', 'confirmed-not-delivered:'.$reference);
        $committed = false;
        $retry = null;
        DB::beginTransaction();

        try {
            $run = $this->runCreated($ticket);
            $delivery = $run->afterCommitResults()->firstOrFail();
            $delivery->forceFill([
                'status' => TicketRuleAfterCommitResult::STATUS_RUNNING,
                'attempt_count' => 1,
                'started_at' => now()->subMinutes(5),
                'safe_payload_json' => [
                    'signal_type' => 'poisoned_safe_payload',
                    'summary' => 'Safe payload must never be replayed.',
                ],
            ])->save();
            $dispatcher = app(DispatchTicketRuleAfterCommit::class);
            $this->assertTrue($dispatcher->markStaleRunningUnresolved(
                $delivery->id,
                now()->subMinute(),
            ));

            $retry = $dispatcher->retryUnresolved($delivery->id, $reference);
            $this->assertNotNull($retry);
            $this->assertSame(TicketRuleAfterCommitResult::STATUS_QUEUED, $retry->status);
            $this->assertSame($reconciliationFingerprint, $retry->reconciliation_fingerprint);
            $this->assertNull($retry->external_reference_fingerprint);
            $this->assertStringNotContainsString(
                $reference,
                json_encode($retry->getAttributes(), JSON_THROW_ON_ERROR),
            );

            DB::commit();
            $committed = true;
        } finally {
            if (! $committed && DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }

        $retry?->refresh();
        $this->assertNotNull($retry);
        $this->assertSame(TicketRuleAfterCommitResult::STATUS_SUCCEEDED, $retry->status);
        $this->assertSame($reconciliationFingerprint, $retry->reconciliation_fingerprint);
        $this->assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/',
            (string) $retry->external_reference_fingerprint,
        );
        $this->assertNotSame($retry->reconciliation_fingerprint, $retry->external_reference_fingerprint);
        $this->assertSame('poisoned_safe_payload', $retry->safe_payload_json['signal_type']);

        $signal = Signal::query()
            ->where('source_domain', 'ticket')
            ->where('source_id', $ticket->id)
            ->where('payload->ticket_rule_delivery_key', $retry->delivery_key)
            ->sole();
        $this->assertSame('immutable_retry_signal', $signal->signal_type);
        $this->assertSame('Immutable retry summary', $signal->summary);
        $this->assertSame($version->ticket_rule_id, $signal->payload['ticket_rule_id']);
        $this->assertSame($version->name, $signal->payload['ticket_rule_name']);
        $this->assertSame(0, $signal->payload['ticket_rule_action_index']);
        $storedDeliveries = DB::table('ticket_rule_after_commit_results')
            ->whereIn('id', [$delivery->id, $retry->id])
            ->get();
        $this->assertStringNotContainsString(
            $reference,
            json_encode($storedDeliveries, JSON_THROW_ON_ERROR),
        );
    }

    /** @param array<string, mixed> $overrides */
    private function ticket(array $overrides = []): Ticket
    {
        return Ticket::factory()->create(array_replace([
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'channel' => 'manual',
            'subject' => 'Slice 2 delivery and audit contract',
            'description' => 'Privacy-safe evidence source.',
        ], $overrides));
    }

    private function runCreated(Ticket $ticket, ?User $initiator = null): TicketRuleRun
    {
        return DB::transaction(function () use ($ticket, $initiator): TicketRuleRun {
            $coordinator = app(TicketRuleExecutionCoordinator::class);
            $result = $coordinator->executeCreated(
                $ticket,
                [
                    'channel' => $ticket->channel,
                    'subject' => $ticket->subject,
                    'description' => $ticket->description,
                    '_source_action' => 'TicketRuleDeliveryAuditContractTest',
                ],
                $initiator,
            );

            return $coordinator->finalizeCreated($ticket->refresh(), $result);
        });
    }

    /**
     * @param  list<array<string, mixed>>  $actions
     */
    private function publishRule(array $actions): TicketRuleVersion
    {
        $weight = ((int) TicketRule::query()->max('weight')) + 10;
        $name = 'Delivery audit rule '.$weight;
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => ['match' => 'ALL', 'groups' => []],
            'then_actions' => $actions,
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => $weight],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Slice 2 delivery and audit contract.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $weight,
            'is_active' => true,
            'stop_processing' => false,
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_COMPATIBILITY,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger_key' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'weight' => $weight,
            'stop_processing' => false,
            'name' => $name,
            'description' => 'Slice 2 delivery and audit contract.',
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'provenance' => TicketRuleVersion::PROVENANCE_LEGACY_BACKFILL,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'definition_schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);

        return $version->refresh();
    }

    private function queue(string $slug): TicketQueue
    {
        return TicketQueue::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
    }

    private function priority(string $slug, int $level): TicketPriority
    {
        return TicketPriority::query()->create([
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'level' => $level,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
    }
}
