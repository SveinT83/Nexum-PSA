<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\SelectTicketWorkflowForCreation;
use App\Modules\Ticket\Actions\SetTicketRuleWorkflowAutomationPause;
use App\Modules\Ticket\Actions\SwitchTicketWorkflowByRule;
use App\Modules\Ticket\Actions\TransitionTicketWorkflowByRule;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketMutationResult;
use App\Modules\Ticket\Support\TicketRuleActionFailure;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

/**
 * Schema 2 runtime bridge for the whitelisted Workflow action providers.
 *
 * The returned derived event objects are runtime-only. The coordinator must
 * enqueue them in the current root chain and persist only their sanitized evidence.
 */
final class TicketRuleWorkflowActionExecutor
{
    public const SELECT_WORKFLOW = 'select_workflow';

    public const TRANSITION_WORKFLOW = 'transition_workflow';

    public const SWITCH_WORKFLOW = 'switch_workflow';

    public const PAUSE_WORKFLOW_AUTOMATION = 'pause_workflow_automation';

    public const RESUME_WORKFLOW_AUTOMATION = 'resume_workflow_automation';

    public const PHASE_CREATION = 'creation';

    public const PHASE_MUTATION = 'mutation';

    public function __construct(
        private readonly SelectTicketWorkflowForCreation $selectWorkflow,
        private readonly TransitionTicketWorkflowByRule $transitionWorkflow,
        private readonly SwitchTicketWorkflowByRule $switchWorkflow,
        private readonly SetTicketRuleWorkflowAutomationPause $workflowPause,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(
        Ticket $ticket,
        string $type,
        array $input,
        User $automationActor,
        string $idempotencyKey,
        string $executionPhase,
        bool $apply = true,
    ): array {
        $this->assertExecutionPhase($executionPhase);
        $this->assertIdempotencyKey($idempotencyKey);
        $this->assertProtectedActor($automationActor);

        $permission = $this->permissionFor($type);
        $ticketAction = $this->ticketActionFor($type);
        $this->assertPermission($automationActor, $permission);

        if ($type === self::SELECT_WORKFLOW && $executionPhase !== self::PHASE_CREATION) {
            throw new TicketRuleActionFailure(
                'workflow_action_phase_denied',
                'Workflow selection is available only during the Ticket creation phase.',
            );
        }

        try {
            $result = match ($type) {
                self::SELECT_WORKFLOW => $this->selectWorkflow->handle(
                    $ticket,
                    $this->positiveIntegerInput($input, 'workflow_version_id', ['workflow_version_id']),
                    $automationActor,
                    $idempotencyKey,
                    $apply,
                ),
                self::TRANSITION_WORKFLOW => $this->transitionWorkflow->handle(
                    $ticket,
                    $this->stringInput($input, 'transition_key', ['transition_key'], 190),
                    $automationActor,
                    $idempotencyKey,
                    $apply,
                ),
                self::SWITCH_WORKFLOW => $this->switch($ticket, $input, $automationActor, $idempotencyKey, $apply),
                self::PAUSE_WORKFLOW_AUTOMATION => $this->pause(
                    $ticket,
                    $input,
                    $automationActor,
                    $idempotencyKey,
                    true,
                    $apply,
                ),
                self::RESUME_WORKFLOW_AUTOMATION => $this->pause(
                    $ticket,
                    $input,
                    $automationActor,
                    $idempotencyKey,
                    false,
                    $apply,
                ),
                default => throw new TicketRuleActionFailure(
                    'unsupported_workflow_action',
                    'The Ticket Rule Workflow action type is unsupported.',
                ),
            };
        } catch (ValidationException $exception) {
            throw new TicketRuleActionFailure(
                'workflow_action_denied',
                $this->safeValidationMessage($exception),
            );
        }

        return $this->providerResult(
            $result,
            $permission,
            $ticketAction,
            $executionPhase,
            $apply,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function switch(
        Ticket $ticket,
        array $input,
        User $actor,
        string $idempotencyKey,
        bool $apply,
    ): TicketMutationResult {
        $this->assertOnlyKeys($input, [
            'source_workflow_version_id',
            'target_workflow_version_id',
            'mapping_strategy',
            'target_state_key',
        ]);

        $strategy = $this->stringValue($input['mapping_strategy'] ?? null, 'mapping_strategy', 32);
        if (! in_array($strategy, ['automatic', 'state_key'], true)) {
            throw new TicketRuleActionFailure(
                'invalid_workflow_mapping_strategy',
                'Choose automatic placement or one exact target Workflow state.',
            );
        }

        $targetStateKey = array_key_exists('target_state_key', $input) && $input['target_state_key'] !== null
            ? $this->stringValue($input['target_state_key'], 'target_state_key', 190)
            : null;
        if (($strategy === 'state_key') !== ($targetStateKey !== null)) {
            throw new TicketRuleActionFailure(
                'invalid_workflow_mapping_strategy',
                'An exact target state is required only for state-key Workflow placement.',
            );
        }

        return $this->switchWorkflow->handle(
            $ticket,
            $this->positiveIntegerValue($input['source_workflow_version_id'] ?? null, 'source_workflow_version_id'),
            $this->positiveIntegerValue($input['target_workflow_version_id'] ?? null, 'target_workflow_version_id'),
            $strategy,
            $targetStateKey,
            $actor,
            $idempotencyKey,
            $apply,
        );
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function pause(
        Ticket $ticket,
        array $input,
        User $actor,
        string $idempotencyKey,
        bool $paused,
        bool $apply,
    ): TicketMutationResult {
        $this->assertOnlyKeys($input, ['reason']);

        $reason = null;
        if (array_key_exists('reason', $input) && $input['reason'] !== null) {
            $reason = $this->stringValue($input['reason'], 'reason', 1000, allowEmpty: true);
        }

        return $this->workflowPause->handle(
            $ticket,
            $paused,
            $actor,
            $idempotencyKey,
            $reason,
            $apply,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function providerResult(
        TicketMutationResult $result,
        string $permission,
        string $ticketAction,
        string $executionPhase,
        bool $apply,
    ): array {
        $event = $result->event;
        if (! $event) {
            return [
                'status' => 'no_change',
                'reason_code' => null,
                'changes' => [],
                'authorization' => [
                    'permission' => $permission,
                    'ticket_action' => $ticketAction,
                    'execution_phase' => $executionPhase,
                    'allowed' => true,
                ],
                'after_commit' => null,
                'derived_events' => [],
                'assignment_decision' => false,
                'sla_decision' => false,
            ];
        }

        $changes = collect($event->changedFields)
            ->mapWithKeys(fn (string $field): array => [
                $field => [
                    'before' => $event->before[$field] ?? null,
                    'after' => $event->after[$field] ?? null,
                ],
            ])
            ->all();

        return [
            'status' => $apply ? 'succeeded' : 'planned',
            'reason_code' => null,
            'changes' => $changes,
            'authorization' => [
                'permission' => $permission,
                'ticket_action' => $ticketAction,
                'execution_phase' => $executionPhase,
                'allowed' => true,
            ],
            'after_commit' => null,
            'derived_events' => [$event],
            'assignment_decision' => (bool) ($event->classification['assignment_decision'] ?? false),
            'sla_decision' => false,
            'workflow_result' => [
                'operation' => $event->safeFacts['workflow_operation'] ?? null,
                'action_key' => $event->safeFacts['workflow_action_key'] ?? null,
                'event_keys' => $event->classification['event_keys'] ?? [$event->eventKey],
                'assignment_result' => $event->safeFacts['assignment_result'] ?? [],
                'evidence_invalidation' => $event->safeFacts['evidence_invalidation'] ?? [],
            ],
        ];
    }

    private function permissionFor(string $type): string
    {
        return match ($type) {
            self::SELECT_WORKFLOW,
            self::TRANSITION_WORKFLOW,
            self::PAUSE_WORKFLOW_AUTOMATION,
            self::RESUME_WORKFLOW_AUTOMATION => 'ticket.update',
            self::SWITCH_WORKFLOW => 'ticket.workflow_escalate',
            default => throw new TicketRuleActionFailure(
                'unsupported_workflow_action',
                'The Ticket Rule Workflow action type is unsupported.',
            ),
        };
    }

    private function ticketActionFor(string $type): string
    {
        return match ($type) {
            self::SELECT_WORKFLOW => TicketAction::UPDATE_FIELDS,
            self::TRANSITION_WORKFLOW,
            self::PAUSE_WORKFLOW_AUTOMATION,
            self::RESUME_WORKFLOW_AUTOMATION => TicketAction::CHANGE_STATUS,
            self::SWITCH_WORKFLOW => TicketAction::ESCALATE,
            default => throw new TicketRuleActionFailure(
                'unsupported_workflow_action',
                'The Ticket Rule Workflow action type is unsupported.',
            ),
        };
    }

    private function assertProtectedActor(User $actor): void
    {
        if (! $actor->isSystemActor()) {
            throw new TicketRuleActionFailure(
                'workflow_automation_actor_required',
                'Ticket Rule Workflow actions require the protected automation actor.',
            );
        }
    }

    private function assertPermission(User $actor, string $permission): void
    {
        if (! Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()
            || ! $actor->can($permission)) {
            throw new TicketRuleActionFailure(
                'automation_permission_denied',
                'The Ticket Rule automation actor lacks a required Workflow permission.',
            );
        }
    }

    private function assertExecutionPhase(string $phase): void
    {
        if (! in_array($phase, [self::PHASE_CREATION, self::PHASE_MUTATION], true)) {
            throw new TicketRuleActionFailure(
                'invalid_execution_phase',
                'The Ticket Rule Workflow execution phase is invalid.',
            );
        }
    }

    private function assertIdempotencyKey(string $idempotencyKey): void
    {
        if (trim($idempotencyKey) === '' || mb_strlen($idempotencyKey) > 255) {
            throw new TicketRuleActionFailure(
                'invalid_idempotency_key',
                'A bounded Workflow action idempotency key is required.',
            );
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function assertOnlyKeys(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed) !== []) {
            throw new TicketRuleActionFailure(
                'invalid_workflow_action_input',
                'The Ticket Rule Workflow action input contains unsupported fields.',
            );
        }
    }

    /**
     * @param  list<string>  $allowed
     */
    private function positiveIntegerInput(array $input, string $key, array $allowed): int
    {
        $this->assertOnlyKeys($input, $allowed);

        return $this->positiveIntegerValue($input[$key] ?? null, $key);
    }

    private function positiveIntegerValue(mixed $value, string $key): int
    {
        if (! is_int($value) || $value < 1) {
            throw new TicketRuleActionFailure(
                'invalid_workflow_action_input',
                'The '.$key.' value must be a positive integer.',
            );
        }

        return $value;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function stringInput(array $input, string $key, array $allowed, int $maximumLength): string
    {
        $this->assertOnlyKeys($input, $allowed);

        return $this->stringValue($input[$key] ?? null, $key, $maximumLength);
    }

    private function stringValue(
        mixed $value,
        string $key,
        int $maximumLength,
        bool $allowEmpty = false,
    ): string {
        if (! is_string($value)) {
            throw new TicketRuleActionFailure(
                'invalid_workflow_action_input',
                'The '.$key.' value must be text.',
            );
        }

        $value = trim($value);
        if ((! $allowEmpty && $value === '') || mb_strlen($value) > $maximumLength) {
            throw new TicketRuleActionFailure(
                'invalid_workflow_action_input',
                'The '.$key.' value is empty or exceeds its allowed length.',
            );
        }

        return $value;
    }

    private function safeValidationMessage(ValidationException $exception): string
    {
        $message = collect($exception->errors())
            ->flatten()
            ->first(fn (mixed $value): bool => is_string($value) && $value !== '');

        return is_string($message) && $message !== ''
            ? mb_substr($message, 0, 500)
            : 'The Ticket Rule Workflow action was denied by an authoritative guard.';
    }
}
