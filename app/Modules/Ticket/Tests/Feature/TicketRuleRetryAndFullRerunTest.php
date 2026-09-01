<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\CustomField\Models\CustomFieldDefinition;
use App\Modules\CustomField\Models\CustomFieldValue;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\RetryTicketRuleAction;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRule;
use App\Modules\Ticket\Models\TicketRuleActionResult;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketRuleVersion;
use App\Modules\Ticket\Services\TicketCustomFieldTargetValidator;
use App\Modules\Ticket\Services\TicketRuleActionRetrySelector;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Services\TicketRuleFullRerunBoundary;
use App\Modules\Ticket\Services\TicketRulePublishedDefinitionValidator;
use App\Modules\Ticket\Services\TicketRuleRetryPolicy;
use App\Modules\Ticket\Support\TicketRuleActionProviderRegistry;
use App\Modules\Ticket\Support\TicketRuleDefinitionRegistry;
use App\Modules\Ticket\Support\TicketRuleStableJson;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketRuleRetryAndFullRerunTest extends TestCase
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

        foreach ([
            'ticket.view',
            'ticket.rule_preview',
            'ticket.rule_retry',
            'ticket.rule_execution_view',
            'ticket.rule_full_rerun',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
    }

    #[Test]
    public function failed_idempotent_position_retries_from_the_immutable_version_once(): void
    {
        $targetId = 860201;
        $version = $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $targetId,
        ]);
        $ticket = $this->ticket('TD-2026-860201');
        $run = $this->runCreated($ticket);
        $source = TicketRuleActionResult::query()
            ->where('run_id', $run->id)
            ->where('rule_version_id', $version->id)
            ->firstOrFail();
        $this->assertSame(TicketRuleActionResult::STATUS_FAILED, $source->status);
        $this->assertSame('target_missing', $source->failure_code);

        $this->queueWithId($targetId, 'retry-target');
        $this->assertNull(app(
            \App\Modules\Ticket\Services\TicketRuleCompatibilityTargetValidator::class,
        )->failureCode($source->action_snapshot_json));
        $this->assertSame(
            $source->precondition_fingerprint,
            app(\App\Modules\Ticket\Services\TicketRuleTicketState::class)->fingerprint($ticket),
        );
        $this->assertTrue(app(
            \App\Modules\Ticket\Services\TicketRuleActionRetrySelector::class,
        )->isEligible($source, $ticket));

        $operator = $this->operator([
            'ticket.view',
            'ticket.rule_execution_view',
            'ticket.rule_retry',
        ]);
        $this->actingAs($operator)
            ->get(route('tech.admin.settings.tickets.rules.executions.show', $run))
            ->assertOk()
            ->assertSee('Retry position');
        $attempt = app(RetryTicketRuleAction::class)->handle(
            $run->load('ticket'),
            $source,
            $operator,
        );

        $this->assertSame(TicketRuleActionResult::STATUS_SUCCEEDED, $attempt->status);
        $this->assertSame($source->id, $attempt->retry_of_id);
        $this->assertSame(2, $attempt->attempt_number);
        $this->assertSame($targetId, (int) $ticket->refresh()->queue_id);
        $this->assertSame($operator->id, data_get($attempt->authorization_json, 'retry_operator_id'));
        $this->assertSame(TicketRuleRun::STATUS_FAILED, $run->refresh()->status);
        $this->assertSame(TicketRuleActionResult::STATUS_FAILED, $source->refresh()->status);

        try {
            app(RetryTicketRuleAction::class)->handle($run->load('ticket'), $source, $operator);
            $this->fail('A duplicate submission must not replay the successful retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no longer eligible', $exception->getMessage());
        }

        $this->assertSame(
            2,
            TicketRuleActionResult::query()
                ->where('position_key', $source->position_key)
                ->count(),
        );
    }

    #[Test]
    public function retry_candidates_use_the_latest_sql_bounded_attempt_and_stop_at_the_configured_cap(): void
    {
        config()->set('ticket_rules.retry.max_attempts_per_position', 3);
        $this->assertSame(3, app(TicketRuleRetryPolicy::class)->maxAttemptsPerPosition());

        $targetId = 860212;
        $version = $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $targetId,
        ]);
        $ticket = $this->ticket('TD-2026-860212');
        $run = $this->runCreated($ticket);
        $source = TicketRuleActionResult::query()
            ->where('run_id', $run->id)
            ->where('rule_version_id', $version->id)
            ->firstOrFail();
        $this->queueWithId($targetId, 'retry-cap-target');
        $selector = app(TicketRuleActionRetrySelector::class);

        $attemptTwo = $selector->reserveRetryAttempt($source, $ticket);
        $this->assertNotNull($attemptTwo);
        $this->assertSame(2, $attemptTwo->attempt_number);
        $attemptTwo->forceFill([
            'status' => TicketRuleActionResult::STATUS_FAILED,
            'failure_code' => 'retry_cap_fixture',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'duration_ms' => 1,
        ])->save();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $candidates = $selector->candidates($ticket, (int) $run->id);
        $queryLog = DB::getQueryLog();
        DB::disableQueryLog();
        DB::flushQueryLog();

        $this->assertSame([$attemptTwo->id], $candidates->pluck('id')->all());
        $candidateSql = strtolower(collect($queryLog)->pluck('query')->implode(' '));
        $this->assertStringContainsString('row_number() over', $candidateSql);
        $this->assertStringContainsString('attempt_rank', $candidateSql);
        $this->assertStringContainsString('position_key', $candidateSql);
        $this->assertStringContainsString('limit', $candidateSql);

        $attemptThree = $selector->reserveRetryAttempt($attemptTwo, $ticket);
        $this->assertNotNull($attemptThree);
        $this->assertSame(3, $attemptThree->attempt_number);
        $attemptThree->forceFill([
            'status' => TicketRuleActionResult::STATUS_FAILED,
            'failure_code' => 'retry_cap_fixture',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
            'duration_ms' => 1,
        ])->save();

        $this->assertFalse($selector->isEligible($attemptThree->refresh(), $ticket->refresh()));
        $this->assertSame([], $selector->candidates($ticket->refresh(), (int) $run->id)->all());
        $this->assertNull($selector->reserveRetryAttempt($attemptThree->refresh(), $ticket->refresh()));
        $this->assertSame(
            3,
            TicketRuleActionResult::query()
                ->where('position_key', $source->position_key)
                ->count(),
        );
    }

    #[Test]
    public function retry_revalidates_current_ticket_state_and_the_active_operator_permission(): void
    {
        $stateTargetId = 860202;
        $stateVersion = $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $stateTargetId,
        ]);
        $stateTicket = $this->ticket('TD-2026-860202');
        $stateRun = $this->runCreated($stateTicket);
        $stateSource = TicketRuleActionResult::query()
            ->where('run_id', $stateRun->id)
            ->where('rule_version_id', $stateVersion->id)
            ->firstOrFail();
        $this->queueWithId($stateTargetId, 'retry-state-target');
        DB::table('tickets')->where('id', $stateTicket->id)->update([
            'subject' => 'Ticket changed after failed action',
            'updated_at' => now(),
        ]);
        $operator = $this->operator(['ticket.view', 'ticket.rule_retry']);

        try {
            app(RetryTicketRuleAction::class)->handle(
                $stateRun->load('ticket'),
                $stateSource,
                $operator,
            );
            $this->fail('Ticket state drift must invalidate retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('no longer eligible', $exception->getMessage());
        }

        $permissionTargetId = 860203;
        $permissionVersion = $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $permissionTargetId,
        ]);
        $permissionTicket = $this->ticket('TD-2026-860203');
        $permissionRun = $this->runCreated($permissionTicket);
        $permissionSource = TicketRuleActionResult::query()
            ->where('run_id', $permissionRun->id)
            ->where('rule_version_id', $permissionVersion->id)
            ->firstOrFail();
        $this->queueWithId($permissionTargetId, 'retry-permission-target');
        $operator->revokePermissionTo('ticket.rule_retry');

        try {
            app(RetryTicketRuleAction::class)->handle(
                $permissionRun->load('ticket'),
                $permissionSource,
                $operator,
            );
            $this->fail('Removed operator permission must invalidate retry.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('permissions are required', $exception->getMessage());
        }

        $this->assertFalse(TicketRuleActionResult::query()
            ->where('retry_of_id', $permissionSource->id)
            ->exists());
    }

    #[Test]
    public function full_rerun_is_default_off_preview_bound_and_duplicate_safe(): void
    {
        $operator = $this->operator([
            'ticket.view',
            'ticket.update',
            'ticket.rule_preview',
            'ticket.rule_full_rerun',
        ]);
        $ticket = $this->ticket('TD-2026-860204');
        $source = $this->runCreated($ticket, $operator);
        $boundary = app(TicketRuleFullRerunBoundary::class);

        $this->assertFalse((bool) config('ticket_rules.full_rerun_enabled'));
        try {
            $boundary->preview($source, $operator);
            $this->fail('Full rerun must remain disabled by default.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }

        config()->set('ticket_rules.full_rerun_enabled', true);
        config()->set('ticket_rules.v2_enabled', false);
        try {
            $boundary->preview($source, $operator);
            $this->fail('The full-rerun switch must not bypass v2 runtime authority.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('disabled', $exception->getMessage());
        }
        config()->set('ticket_rules.v2_enabled', true);

        $missingPreviewOperator = $this->operator([
            'ticket.view',
            'ticket.rule_full_rerun',
        ]);
        try {
            $boundary->preview($source, $missingPreviewOperator);
            $this->fail('Full rerun must not bypass the explicit preview permission.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('preview', $exception->getMessage());
        }
        $this->assertFalse($boundary->availableFor($source, $missingPreviewOperator));

        $stalePreview = $boundary->preview($source, $operator);
        $this->assertStringContainsString('external deliveries', $stalePreview['warning']);
        DB::table('tickets')->where('id', $ticket->id)->update([
            'subject' => 'Changed after preview',
            'updated_at' => now(),
        ]);
        try {
            $boundary->execute($source, $operator, $stalePreview['receipt']);
            $this->fail('A changed Ticket must invalidate the signed preview receipt.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('changed after preview', $exception->getMessage());
        }

        $planDriftPreview = $boundary->preview($source->refresh(), $operator);
        config()->set('ticket_rules.limits.max_actions', 2);
        try {
            $boundary->execute($source->refresh(), $operator, $planDriftPreview['receipt']);
            $this->fail('Runtime configuration drift must invalidate the signed preview plan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('plan changed after preview', $exception->getMessage());
        }
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());
        config()->set('ticket_rules.limits.max_actions', 100);

        $fenceDriftPreview = $boundary->preview($source->refresh(), $operator);
        DB::table('ticket_rule_authority_fences')
            ->where('scope', TicketRuleAuthorityFence::SCOPE)
            ->update([
                'catalog_generation' => DB::raw('catalog_generation + 1'),
                'catalog_checksum' => hash('sha256', 'drifted-full-rerun-fence'),
            ]);
        try {
            $boundary->execute($source->refresh(), $operator, $fenceDriftPreview['receipt']);
            $this->fail('Authority-fence drift must invalidate the signed preview plan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authority or no-write plan changed', $exception->getMessage());
        }
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());

        $publishedSetPreview = $boundary->preview($source->refresh(), $operator);
        $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $this->defaults['queue']->id,
        ]);
        try {
            $boundary->execute($source->refresh(), $operator, $publishedSetPreview['receipt']);
            $this->fail('Published-set drift must invalidate the signed preview plan.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('authority or no-write plan changed', $exception->getMessage());
        }
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());

        $preview = $boundary->preview($source->refresh(), $operator);
        $result = $boundary->execute($source->refresh(), $operator, $preview['receipt']);
        $rerun = $result->run->refresh();

        $this->assertNotSame($source->id, $rerun->id);
        $this->assertSame('full_rerun', $rerun->mode);
        $this->assertSame(2, $rerun->attempt_number);
        $this->assertSame($source->id, $rerun->retry_of_run_id);
        $this->assertSame($ticket->id, $rerun->ticket_id);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'ticket_rule_run_id' => $rerun->id,
            'type' => 'automation_run',
        ]);

        DB::table('tickets')->where('id', $ticket->id)->update([
            'subject' => 'Changed after completed rerun',
            'updated_at' => now(),
        ]);
        $this->travel(6)->minutes();
        $duplicate = $boundary->execute($source->refresh(), $operator, $preview['receipt']);
        $this->travelBack();

        $this->assertSame($rerun->id, $duplicate->run->id);
        $this->assertSame(
            1,
            TicketRuleRun::query()
                ->where('mode', 'full_rerun')
                ->where('retry_of_run_id', $source->id)
                ->count(),
        );
        $this->assertSame(TicketRuleRun::STATUS_NO_CHANGE, $source->refresh()->status);
    }

    #[Test]
    public function halted_preview_never_issues_a_full_rerun_receipt_or_execution(): void
    {
        $operator = $this->operator([
            'ticket.view',
            'ticket.rule_preview',
            'ticket.rule_full_rerun',
        ]);
        $ticket = $this->ticket('TD-2026-860205');
        $source = $this->runCreated($ticket, $operator);
        $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $this->defaults['queue']->id,
        ]);
        $this->publishCompatibilityRule([
            'type' => 'set_queue',
            'value' => $this->defaults['queue']->id,
        ]);
        config()->set('ticket_rules.full_rerun_enabled', true);
        config()->set('ticket_rules.limits.max_evaluated_rules', 1);
        $runCount = TicketRuleRun::query()->count();

        try {
            app(TicketRuleFullRerunBoundary::class)->preview($source, $operator);
            $this->fail('A halted or incomplete preview must not authorize full rerun.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('complete executable', $exception->getMessage());
        }

        $this->assertSame($runCount, TicketRuleRun::query()->count());
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());
    }

    #[Test]
    public function schema2_full_rerun_executes_only_after_the_shared_no_write_plan_is_revalidated(): void
    {
        $operator = $this->operator([
            'ticket.view',
            'ticket.update',
            'ticket.rule_preview',
            'ticket.rule_execution_view',
            'ticket.rule_full_rerun',
        ]);
        $privateSubject = 'Private full-rerun preview subject '.Str::uuid();
        $ticket = $this->ticket('TD-2026-860206');
        $ticket->forceFill(['impact' => 1])->save();
        $source = $this->runCreated($ticket->refresh(), $operator);
        $version = $this->publishSchema2SetImpactRule($operator, 4, $privateSubject);
        config()->set('ticket_rules.full_rerun_enabled', true);
        config()->set('ticket_rules.capabilities.triggers', array_replace(
            (array) config('ticket_rules.capabilities.triggers', []),
            [TicketRuleTriggerRegistry::CREATED => true],
        ));
        config()->set('ticket_rules.capabilities.actions.set_ticket_fields', true);
        $boundary = app(TicketRuleFullRerunBoundary::class);
        $runCount = TicketRuleRun::query()->count();
        $executionCount = DB::table('ticket_rule_executions')->count();
        $actionCount = TicketRuleActionResult::query()->count();
        $eventCount = DB::table('ticket_events')->count();

        $capabilityPreview = $boundary->preview($source, $operator);

        $this->assertSame(
            'would_change',
            $capabilityPreview['terminal_status'],
            json_encode($capabilityPreview, JSON_THROW_ON_ERROR),
        );
        $this->assertSame(1, $capabilityPreview['planned_action_count']);
        $this->assertSame(1, (int) $ticket->refresh()->impact);
        $this->assertSame(1, $capabilityPreview['planned_action_displayed_count']);
        $this->assertSame(0, $capabilityPreview['planned_action_omitted_count']);
        $this->assertSame(
            $capabilityPreview['planned_action_row_count'],
            $capabilityPreview['planned_action_displayed_count']
                + $capabilityPreview['planned_action_omitted_count'],
        );
        $publicAction = $capabilityPreview['planned_rules'][0]['actions'][0];
        $this->assertSame(TicketRuleActionProviderRegistry::SET_TICKET_FIELDS, $publicAction['type']);
        $this->assertSame(0, $publicAction['position']);
        $this->assertSame('planned', $publicAction['status']);
        $this->assertStringContainsString('Impact', $publicAction['target']);
        $this->assertStringContainsString('Subject (value redacted)', $publicAction['target']);
        $this->assertStringNotContainsString(
            $privateSubject,
            json_encode($capabilityPreview, JSON_THROW_ON_ERROR),
        );

        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.executions.rerun.preview', $source))
            ->assertRedirect();
        $this->get(route('tech.admin.settings.tickets.rules.executions.show', $source))
            ->assertOk()
            ->assertSee('Planned rules and actions')
            ->assertSee('Set Ticket Fields')
            ->assertSee('Safe target:')
            ->assertSee('Subject (value redacted)')
            ->assertSee('0 collision rows omitted')
            ->assertSee('0 loop-block rows omitted')
            ->assertDontSee($privateSubject);
        $this->assertSame($runCount, TicketRuleRun::query()->count());
        $this->assertSame($executionCount, DB::table('ticket_rule_executions')->count());
        $this->assertSame($actionCount, TicketRuleActionResult::query()->count());
        $this->assertSame($eventCount, DB::table('ticket_events')->count());

        config()->set('ticket_rules.capabilities.actions.set_ticket_fields', false);
        try {
            $boundary->execute($source, $operator, $capabilityPreview['receipt']);
            $this->fail('Action-capability drift must invalidate the signed no-write plan.');
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            $this->assertTrue(
                str_contains($message, 'complete executable')
                    || str_contains($message, 'plan changed after preview'),
                $message,
            );
        }
        $this->assertSame(1, (int) $ticket->refresh()->impact);
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());

        config()->set('ticket_rules.capabilities.actions.set_ticket_fields', true);
        $preview = $boundary->preview($source, $operator);
        $result = $boundary->execute($source, $operator, $preview['receipt']);
        $rerun = $result->run->refresh();

        $this->assertSame(TicketRuleRun::STATUS_SUCCEEDED, $rerun->status);
        $this->assertSame('full_rerun', $rerun->mode);
        $this->assertSame($source->id, $rerun->retry_of_run_id);
        $this->assertSame(4, (int) $ticket->refresh()->impact);
        $this->assertDatabaseHas('ticket_rule_executions', [
            'run_id' => $rerun->id,
            'rule_version_id' => $version->id,
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('ticket_rule_action_results', [
            'run_id' => $rerun->id,
            'rule_version_id' => $version->id,
            'action_type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
            'status' => TicketRuleActionResult::STATUS_SUCCEEDED,
        ]);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $ticket->id,
            'ticket_rule_run_id' => $rerun->id,
            'type' => 'automation_run',
        ]);
        $this->assertSame($runCount + 1, TicketRuleRun::query()->count());
    }

    #[Test]
    public function full_rerun_refuses_receipts_for_opposite_private_custom_field_branches(): void
    {
        $operator = $this->operator([
            'ticket.view',
            'ticket.update',
            'ticket.rule_preview',
            'ticket.rule_full_rerun',
        ]);
        $privateField = $this->customField('private_rerun_decision', [
            'view_permission' => 'ticket.assign',
            'edit_permission' => 'ticket.assign',
        ]);
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($privateField);
        $matchingTicket = $this->ticket('TD-2026-860213');
        $oppositeTicket = $this->ticket('TD-2026-860214');
        $matchingSource = $this->runCreated($matchingTicket, $operator);
        $oppositeSource = $this->runCreated($oppositeTicket, $operator);
        foreach ([
            [$matchingTicket, 'private-match'],
            [$oppositeTicket, 'private-opposite'],
        ] as [$ticket, $value]) {
            CustomFieldValue::query()->create([
                'custom_field_definition_id' => $privateField->id,
                'model_type' => Ticket::class,
                'model_id' => $ticket->id,
                'value_text' => $value,
            ]);
        }

        config()->set('ticket_rules.full_rerun_enabled', true);
        config()->set('ticket_rules.capabilities.triggers', array_replace(
            (array) config('ticket_rules.capabilities.triggers', []),
            [TicketRuleTriggerRegistry::CREATED => true],
        ));
        config()->set('ticket_rules.capabilities.actions.set_ticket_fields', true);
        config()->set('ticket_rules.capabilities.custom_fields.rule_trigger', true);
        $this->publishSchema2Rule($operator, [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => TicketCustomFieldTargetValidator::CURRENT,
                        'target' => $target,
                        'operator' => 'equals',
                        'value' => 'private-match',
                    ]],
                ]],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 4]],
            ]],
            'else_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 2]],
            ]],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Private opposite branch rerun rule');
        $runCount = TicketRuleRun::query()->count();

        foreach ([$matchingSource, $oppositeSource] as $source) {
            $this->actingAs($operator)
                ->post(route('tech.admin.settings.tickets.rules.executions.rerun.preview', $source))
                ->assertRedirect()
                ->assertSessionHasErrors([
                    'full_rerun' => 'Full rerun is unavailable because restricted evidence or action authority prevents the operator from inspecting every decision target and holding every required action permission, including Custom Field edit authority.',
                ])
                ->assertSessionMissing('ticket_rule_full_rerun_preview');
        }

        $this->assertSame($runCount, TicketRuleRun::query()->count());
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());
        $this->assertSame(0, (int) $matchingTicket->refresh()->impact);
        $this->assertSame(0, (int) $oppositeTicket->refresh()->impact);
    }

    #[Test]
    public function full_rerun_refuses_a_receipt_when_action_target_is_viewable_but_not_editable(): void
    {
        $operator = $this->operator([
            'ticket.view',
            'ticket.rule_preview',
            'ticket.rule_full_rerun',
        ]);
        $privateField = $this->customField('private_rerun_action', [
            'view_permission' => 'ticket.rule_full_rerun',
            'edit_permission' => 'ticket.update',
        ]);
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($privateField);
        $ticket = $this->ticket('TD-2026-860215');
        $source = $this->runCreated($ticket, $operator);

        config()->set('ticket_rules.full_rerun_enabled', true);
        config()->set('ticket_rules.capabilities.triggers', array_replace(
            (array) config('ticket_rules.capabilities.triggers', []),
            [TicketRuleTriggerRegistry::CREATED => true],
        ));
        config()->set('ticket_rules.capabilities.actions.set_custom_field', true);
        config()->set('ticket_rules.capabilities.custom_fields.rule_action', true);
        $this->publishSchema2Rule($operator, [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'always',
                'match' => 'ALL',
                'groups' => [],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_CUSTOM_FIELD,
                'input' => [
                    'target' => $target,
                    'value' => 'restricted-action-value',
                ],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Private action authority rerun rule');
        $runCount = TicketRuleRun::query()->count();

        $this->actingAs($operator)
            ->post(route('tech.admin.settings.tickets.rules.executions.rerun.preview', $source))
            ->assertRedirect()
            ->assertSessionHasErrors([
                'full_rerun' => 'Full rerun is unavailable because restricted evidence or action authority prevents the operator from inspecting every decision target and holding every required action permission, including Custom Field edit authority.',
            ])
            ->assertSessionMissing('ticket_rule_full_rerun_preview');

        $this->assertSame($runCount, TicketRuleRun::query()->count());
        $this->assertFalse(TicketRuleRun::query()->where('mode', 'full_rerun')->exists());
        $this->assertDatabaseMissing('custom_field_values', [
            'custom_field_definition_id' => $privateField->id,
            'model_id' => $ticket->id,
        ]);
    }

    #[Test]
    public function completed_receipt_replay_does_not_reveal_outcome_after_custom_field_visibility_is_revoked(): void
    {
        $operator = $this->operator([
            'ticket.view',
            'ticket.update',
            'ticket.rule_preview',
            'ticket.rule_full_rerun',
        ]);
        $privateField = $this->customField('private_rerun_replay', [
            'view_permission' => 'ticket.update',
            'edit_permission' => 'ticket.update',
        ]);
        $target = app(TicketCustomFieldTargetValidator::class)->targetFor($privateField);
        $ticket = $this->ticket('TD-2026-860216');
        $source = $this->runCreated($ticket, $operator);
        CustomFieldValue::query()->create([
            'custom_field_definition_id' => $privateField->id,
            'model_type' => Ticket::class,
            'model_id' => $ticket->id,
            'value_text' => 'private-match',
        ]);

        config()->set('ticket_rules.full_rerun_enabled', true);
        config()->set('ticket_rules.capabilities.triggers', array_replace(
            (array) config('ticket_rules.capabilities.triggers', []),
            [TicketRuleTriggerRegistry::CREATED => true],
        ));
        config()->set('ticket_rules.capabilities.actions.set_ticket_fields', true);
        config()->set('ticket_rules.capabilities.custom_fields.rule_trigger', true);
        $this->publishSchema2Rule($operator, [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'grouped',
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [[
                        'field' => TicketCustomFieldTargetValidator::CURRENT,
                        'target' => $target,
                        'operator' => 'equals',
                        'value' => 'private-match',
                    ]],
                ]],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 4]],
            ]],
            'else_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => ['fields' => ['impact' => 2]],
            ]],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => 10],
        ], 'Private receipt replay rule');
        $boundary = app(TicketRuleFullRerunBoundary::class);
        $preview = $boundary->preview($source, $operator);
        $result = $boundary->execute($source, $operator, $preview['receipt']);

        $this->assertSame(TicketRuleRun::STATUS_SUCCEEDED, $result->run->status);
        $this->assertSame(4, (int) $ticket->refresh()->impact);
        $operator->revokePermissionTo('ticket.update');

        try {
            $boundary->execute($source, $operator->refresh(), $preview['receipt']);
            $this->fail('A completed receipt replay must recheck current evidence visibility.');
        } catch (RuntimeException $exception) {
            $this->assertSame('The linked full-rerun evidence is restricted.', $exception->getMessage());
        }

        $this->assertSame(
            1,
            TicketRuleRun::query()
                ->where('mode', 'full_rerun')
                ->where('retry_of_run_id', $source->id)
                ->count(),
        );
    }

    private function ticket(string $key): Ticket
    {
        return Ticket::factory()->create([
            'ticket_key' => $key,
            'work_context_id' => app(ResolveWorkContext::class)->internal()->id,
            'queue_id' => $this->defaults['queue']->id,
            'priority_id' => $this->defaults['priority']->id,
            'subject' => 'Retry and rerun Ticket',
            'description' => 'Safe fixture description.',
            'channel' => 'manual',
        ]);
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
                    '_source_action' => 'TicketRuleRetryAndFullRerunTest',
                ],
                $initiator,
            );

            return $coordinator->finalizeCreated($ticket->refresh(), $result);
        });
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function publishCompatibilityRule(array $action): TicketRuleVersion
    {
        $weight = ((int) TicketRule::query()->max('weight')) + 10;
        $name = 'Retry rule '.$weight;
        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::SCHEMA_VERSION,
            'trigger' => TicketRuleDefinitionRegistry::TRIGGER_CREATED,
            'conditions' => [
                'match' => 'ALL',
                'groups' => [[
                    'match' => 'ALL',
                    'conditions' => [],
                ]],
            ],
            'then_actions' => [$action],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => $weight],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Retry boundary fixture.',
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
            'description' => 'Retry boundary fixture.',
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

    private function publishSchema2SetImpactRule(
        User $publisher,
        int $impact,
        ?string $privateSubject = null,
    ): TicketRuleVersion {
        $weight = ((int) TicketRule::query()->max('weight')) + 10;
        $name = 'Schema 2 rerun rule '.$weight;
        $fields = ['impact' => $impact];
        if ($privateSubject !== null) {
            $fields['subject'] = $privateSubject;
        }

        $definition = [
            'schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger' => TicketRuleTriggerRegistry::CREATED,
            'trigger_filters' => [],
            'conditions' => [
                'mode' => 'always',
                'match' => 'ALL',
                'groups' => [],
            ],
            'then_actions' => [[
                'type' => TicketRuleActionProviderRegistry::SET_TICKET_FIELDS,
                'input' => [
                    'fields' => $fields,
                ],
            ]],
            'else_actions' => [],
            'flow' => ['stop_processing' => false],
            'order' => ['weight' => $weight],
        ];
        $checksum = TicketRuleStableJson::checksum($definition);
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Schema 2 full-rerun boundary fixture.',
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
            'status' => TicketRuleVersion::STATUS_PUBLISHED,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger_key' => TicketRuleTriggerRegistry::CREATED,
            'weight' => $weight,
            'stop_processing' => false,
            'name' => $name,
            'description' => 'Schema 2 full-rerun boundary fixture.',
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'published_by' => $publisher->id,
            'published_at' => now(),
            'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'published_by' => $publisher->id,
            'published_at' => now(),
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);

        return $version->refresh();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function publishSchema2Rule(
        User $publisher,
        array $definition,
        string $name,
    ): TicketRuleVersion {
        $weight = ((int) TicketRule::query()->max('weight')) + 10;
        data_set($definition, 'order.weight', $weight);
        $validated = app(TicketRulePublishedDefinitionValidator::class)
            ->validateForPublication($definition);
        $this->assertSame(
            TicketRulePublishedDefinitionValidator::STATUS_VALID,
            $validated['status'],
            (string) ($validated['reason_code'] ?? 'definition invalid'),
        );
        $definition = $validated['definition'];
        $checksum = $validated['checksum'];
        $rule = TicketRule::query()->create([
            'name' => $name,
            'description' => 'Custom Field full-rerun authority fixture.',
            'trigger' => TicketRule::TRIGGER_CREATE,
            'weight' => $weight,
            'is_active' => true,
            'stop_processing' => (bool) data_get($definition, 'flow.stop_processing', false),
            'conditions_json' => [],
            'actions_json' => [],
        ]);
        $version = TicketRuleVersion::query()->create([
            'ticket_rule_id' => $rule->id,
            'version_number' => 1,
            'status' => TicketRuleVersion::STATUS_PUBLISHED,
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'trigger_key' => $definition['trigger'],
            'weight' => $weight,
            'stop_processing' => (bool) data_get($definition, 'flow.stop_processing', false),
            'name' => $name,
            'description' => $rule->description,
            'definition_json' => $definition,
            'definition_checksum' => $checksum,
            'source_is_active' => true,
            'source_trigger' => TicketRule::TRIGGER_CREATE,
            'source_hit_count' => 0,
            'published_by' => $publisher->id,
            'published_at' => now(),
            'provenance' => TicketRuleVersion::PROVENANCE_ADMIN_PUBLISH,
            'provenance_batch_uuid' => (string) Str::uuid(),
            'provenance_recorded_at' => now(),
        ]);
        DB::table('ticket_rules')->where('id', $rule->id)->update([
            'lifecycle_status' => TicketRule::LIFECYCLE_PUBLISHED,
            'published_version_id' => $version->id,
            'published_by' => $publisher->id,
            'published_at' => now(),
            'definition_schema_version' => TicketRuleDefinitionRegistry::CURRENT_PUBLICATION_SCHEMA_VERSION,
            'definition_checksum' => $checksum,
            'compatibility_status' => TicketRule::COMPATIBILITY_ELIGIBLE,
            'compatibility_checked_at' => now(),
        ]);

        return $version->refresh();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customField(string $key, array $attributes = []): CustomFieldDefinition
    {
        return CustomFieldDefinition::query()->create(array_merge([
            'model_type' => Ticket::class,
            'key' => $key,
            'label' => str($key)->headline()->toString(),
            'field_type' => CustomFieldDefinition::TYPE_TEXT,
            'visible_in_ui' => true,
            'editable_in_ui' => true,
            'editable_via_api' => true,
            'searchable' => false,
            'unique_per_model' => false,
            'required' => false,
            'admin_only' => false,
            'active' => true,
        ], $attributes));
    }

    private function queueWithId(int $id, string $slug): TicketQueue
    {
        return TicketQueue::query()->forceCreate([
            'id' => $id,
            'name' => str($slug)->headline()->toString(),
            'slug' => $slug,
            'is_default' => false,
            'is_active' => true,
            'sort_order' => 90,
        ]);
    }

    /** @param list<string> $permissions */
    private function operator(array $permissions): User
    {
        $operator = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $operator->givePermissionTo($permissions);

        return $operator->refresh();
    }
}
