<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\PublishTicketRuleDraft;
use App\Modules\Ticket\Actions\SaveTicketRuleDraft;
use App\Modules\Ticket\Actions\SetPublishedTicketRuleEnabled;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Actions\TicketRuleAutomationActor;
use App\Modules\Ticket\Models\TicketQueue;
use App\Modules\Ticket\Models\TicketRuleAuthorityFence;
use App\Modules\Ticket\Models\TicketRuleRun;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowHistory;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Services\TicketWorkflowDefinitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Exercises the minimal Issue 285 cutover using synthetic data and the real
 * publication, enablement and canonical Ticket creation boundaries.
 */
class TicketRuleMinimalWorkflowAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    #[DataProvider('creationChannels')]
    public function only_the_matching_queue_selects_the_published_workflow_and_replay_is_inert(string $channel): void
    {
        Notification::fake();
        $defaults = app(EnsureTicketDefaults::class)->handle();
        app(TicketRuleAutomationActor::class)->resolve();
        $manager = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['ticket.manage_rules', 'ticket.rule_publish', 'ticket.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $manager->givePermissionTo($permission);
        }

        // Enable exactly the proposed minimal scope inside this isolated test.
        config()->set('ticket_rules.v2_enabled', true);
        config()->set('ticket_rules.allow_sqlite_mutations_for_tests', true);
        config()->set('ticket_rules.full_rerun_enabled', false);
        $triggers = array_fill_keys(array_keys(config('ticket_rules.capabilities.triggers')), false);
        $triggers['ticket.created'] = true;
        config()->set('ticket_rules.capabilities.triggers', $triggers);
        $actions = array_fill_keys(array_keys(config('ticket_rules.capabilities.actions')), false);
        $actions['select_workflow'] = true;
        config()->set('ticket_rules.capabilities.actions', $actions);

        $queue = TicketQueue::query()->create([
            'name' => 'Implementering acceptance', 'slug' => 'implementering-acceptance',
            'is_active' => true, 'is_default' => false, 'sort_order' => 100,
        ]);
        $workflow = TicketWorkflow::query()->create([
            'name' => 'Implementering acceptance', 'slug' => 'implementering-acceptance',
            'is_active' => true, 'is_default' => false, 'sort_order' => 100,
        ]);
        $definitions = app(TicketWorkflowDefinitionService::class);
        $state = [
            'state_key' => 'intake', 'ticket_status_id' => $defaults['status']->id,
            'name' => 'Intake', 'is_initial' => true, 'is_terminal' => false,
            'requirements' => ['match' => 'all', 'groups' => []],
            'action_policy' => [], 'assignment_policy' => [
                'strategy' => 'keep_if_eligible', 'eligible_user_ids' => [],
                'required_permissions' => [],
            ],
            'commercial_policy' => [], 'sort_order' => 10,
        ];
        $definitions->saveDraft($workflow, [
            'states' => [$state], 'transitions' => [], 'escalation_paths' => [],
        ]);
        $workflowVersion = $definitions->publish($workflow, $manager);
        $rule = app(SaveTicketRuleDraft::class)->handle(null, [
            'name' => 'Implementering from queue acceptance',
            'description' => 'Synthetic minimal cutover acceptance.',
            'definition' => [
                'schema_version' => 2, 'trigger' => 'ticket.created', 'trigger_filters' => [],
                'conditions' => [
                    'mode' => 'grouped', 'match' => 'ALL',
                    'groups' => [['match' => 'ALL', 'conditions' => [[
                        'field' => 'queue_id', 'operator' => 'equals', 'value' => $queue->id,
                    ]]]],
                ],
                'then_actions' => [[
                    'type' => 'select_workflow',
                    'input' => ['workflow_version_id' => $workflowVersion->id],
                ]],
                'else_actions' => [], 'flow' => ['stop_processing' => false],
                'order' => ['weight' => 100],
            ],
        ], $manager, creationToken: (string) Str::uuid());
        $version = app(PublishTicketRuleDraft::class)->handle(
            $rule, $manager, (string) $rule->draft_checksum,
        );
        $this->assertFalse($rule->fresh()->is_active, 'Publication must not activate the rule.');

        TicketRuleAuthorityFence::query()->whereKey(TicketRuleAuthorityFence::SCOPE)
            ->update(['runtime_authority' => TicketRuleAuthorityFence::AUTHORITY_V2]);
        app(SetPublishedTicketRuleEnabled::class)->handle(
            $rule->fresh(), $manager, $version->id, $version->definition_checksum, false,
        );

        $context = [
            'subject' => 'Synthetic Issue 285 acceptance', 'channel' => $channel,
            '_source_action' => 'Issue285Acceptance:'.$channel,
            'suppress_notifications' => true, '_skip_initial_description_note' => true,
        ];
        $matching = app(StoreTicket::class)->handle($context + ['queue_id' => $queue->id], $manager);
        $other = app(StoreTicket::class)->handle($context + ['queue_id' => $defaults['queue']->id], $manager);

        $this->assertSame($workflowVersion->id, $matching->fresh()->workflow_version_id);
        $this->assertSame($workflow->id, $matching->workflow_id);
        $this->assertSame('intake', $matching->workflow_state_key);
        $this->assertSame($defaults['status']->id, $matching->status_id);
        $this->assertNotSame($workflowVersion->id, $other->fresh()->workflow_version_id);
        $this->assertNotSame($workflow->id, $other->workflow_id);
        $run = TicketRuleRun::query()->where('ticket_id', $matching->id)->firstOrFail();
        $this->assertSame(TicketRuleRun::STATUS_SUCCEEDED, $run->status);
        $this->assertSame($channel, $run->source_channel);
        $this->assertSame(1, TicketWorkflowHistory::query()
            ->where('ticket_id', $matching->id)->where('event_type', 'rule_workflow_selected')->count());

        // Re-delivery uses the canonical created identity after the Ticket changed.
        $coordinator = app(TicketRuleExecutionCoordinator::class);
        DB::transaction(function () use ($coordinator, $matching, $context, $manager): void {
            $result = $coordinator->executeCreated($matching->fresh(), $context, $manager);
            $coordinator->finalizeCreated($matching->fresh(), $result);
        });
        $this->assertSame(1, TicketRuleRun::query()->where('ticket_id', $matching->id)->count());
        $this->assertSame(1, TicketWorkflowHistory::query()
            ->where('ticket_id', $matching->id)->where('event_type', 'rule_workflow_selected')->count());

        // Later Workflow publication never repins an existing Ticket or Rule.
        $state['state_key'] = 'later-intake';
        $definitions->saveDraft($workflow->fresh(), [
            'states' => [$state], 'transitions' => [], 'escalation_paths' => [],
        ]);
        $nextVersion = $definitions->publish($workflow->fresh(), $manager);
        $this->assertNotSame($workflowVersion->id, $nextVersion->id);
        $this->assertSame($workflowVersion->id, $matching->fresh()->workflow_version_id);
        $this->assertSame('intake', $matching->workflow_state_key);
        $this->assertSame($workflowVersion->id, data_get(
            $version->fresh()->definition_json, 'then_actions.0.input.workflow_version_id',
        ));

        $this->assertSame(['ticket.created'], array_keys(array_filter(config('ticket_rules.capabilities.triggers'))));
        $this->assertSame(['select_workflow'], array_keys(array_filter(config('ticket_rules.capabilities.actions'))));
        $this->assertFalse(config('ticket_rules.full_rerun_enabled'));
        Notification::assertNothingSent();
    }

    /** These channels exercise their common domain boundary, not live transport. */
    public static function creationChannels(): array
    {
        return ['UI' => ['manual'], 'Email' => ['email'], 'API' => ['api'], 'Integration' => ['integration']];
    }
}
