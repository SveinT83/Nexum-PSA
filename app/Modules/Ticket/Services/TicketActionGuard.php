<?php

namespace App\Modules\Ticket\Services;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketAction;
use Spatie\Permission\Models\Permission;

class TicketActionGuard
{
    public function __construct(
        private readonly TicketWorkflowRuntime $workflow,
        private readonly TicketWorkflowRequirementEvaluator $requirements,
    ) {}

    public function allowed(Ticket $ticket, string $action, ?User $actor = null): bool
    {
        return $this->decision($ticket, $action, $actor)['allowed'];
    }

    public function reason(Ticket $ticket, string $action, ?User $actor = null): ?string
    {
        return $this->decision($ticket, $action, $actor)['reason'];
    }

    /** @return array<string, mixed> */
    public function decision(Ticket $ticket, string $action, ?User $actor = null): array
    {
        $definitions = TicketAction::definitions();

        if (! isset($definitions[$action])) {
            return $this->blocked($action, 'Unknown ticket action.', false);
        }

        $definition = $definitions[$action];

        if (! $actor || $actor->status !== User::STATUS_ACTIVE) {
            return $this->blocked($action, 'An active user is required for this ticket action.', false);
        }

        if ($permission = ($definition['permission'] ?? null)) {
            if (Permission::query()->where('name', $permission)->where('guard_name', 'web')->exists()
                && ! $actor->can($permission)) {
                return $this->blocked($action, 'Missing permission: '.$permission.'.', false);
            }
        }

        $ticket->loadMissing('status', 'contact');
        $coreReason = $this->coreReason($ticket, $action);
        if ($coreReason) {
            return $this->blocked($action, $coreReason, true);
        }

        $state = $this->workflow->currentState($ticket);
        $policy = data_get($state, 'action_policy.'.$action, ['mode' => 'inherit']);
        $mode = (string) ($policy['mode'] ?? 'inherit');

        if ($mode === 'hidden') {
            return $this->blocked($action, $policy['reason'] ?? 'This action is hidden in the current workflow state.', false, $mode);
        }

        if ($mode === 'blocked') {
            return $this->blocked($action, $policy['reason'] ?? 'This action is disabled in the current workflow state.', true, $mode);
        }

        if ($mode === 'conditional') {
            $result = $this->requirements->evaluate($ticket, $policy['requirements'] ?? []);
            if (! $result['passed']) {
                return $this->blocked(
                    $action,
                    $result['missing'][0]['reason'] ?? 'Workflow requirements are not satisfied.',
                    true,
                    $mode,
                    $result,
                );
            }
        }

        foreach ($this->workflow->escalationDecisions($ticket) as $escalation) {
            $protected = $escalation['protected_actions'] ?? [
                TicketAction::ADD_ACTUAL_COST,
                TicketAction::ASSIGN_OTHER,
                TicketAction::CLOSE,
            ];

            if (($escalation['mode'] ?? 'optional') === 'required'
                && $escalation['allowed']
                && in_array($action, $protected, true)) {
                return $this->blocked(
                    $action,
                    'Escalate this Ticket to the required workflow before using this action.',
                    true,
                    'required_escalation',
                    $escalation['requirements_result'] ?? null,
                );
            }
        }

        return [
            'action' => $action,
            'label' => $definition['label'],
            'visible' => true,
            'allowed' => true,
            'reason' => null,
            'mode' => $mode,
            'requirements' => null,
        ];
    }

    /** @return array<string, bool> */
    public function map(Ticket $ticket, ?User $actor = null): array
    {
        return collect(array_keys(TicketAction::definitions()))
            ->mapWithKeys(fn (string $action) => [$action => $this->allowed($ticket, $action, $actor)])
            ->all();
    }

    /** @return array<string, array<string, mixed>> */
    public function decisionMap(Ticket $ticket, ?User $actor = null): array
    {
        return collect(array_keys(TicketAction::definitions()))
            ->mapWithKeys(fn (string $action) => [$action => $this->decision($ticket, $action, $actor)])
            ->all();
    }

    private function coreReason(Ticket $ticket, string $action): ?string
    {
        if ($action === TicketAction::CUSTOMER_REPLY && ! $this->hasReplyContact($ticket)) {
            return 'A customer reply requires a Ticket contact with an email address.';
        }

        if ($this->isClosed($ticket) && in_array($action, [
            TicketAction::UPDATE_FIELDS,
            TicketAction::ASSIGN_OWNER,
            TicketAction::ASSIGN_SELF,
            TicketAction::ASSIGN_OTHER,
            TicketAction::CUSTOMER_REPLY,
            TicketAction::APPLY_SLA,
            TicketAction::START_TIMER,
            TicketAction::REGISTER_TIME,
            TicketAction::ADD_PLANNED_COST,
            TicketAction::ADD_ACTUAL_COST,
            TicketAction::CREATE_QUOTE,
            TicketAction::EDIT_QUOTE,
            TicketAction::SEND_QUOTE,
            TicketAction::ESCALATE,
        ], true)) {
            return 'This action is blocked because the Ticket is closed.';
        }

        if ($action === TicketAction::CLOSE && $this->isClosed($ticket)) {
            return 'The Ticket is already closed.';
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function blocked(string $action, string $reason, bool $visible, string $mode = 'blocked', ?array $requirements = null): array
    {
        return [
            'action' => $action,
            'label' => TicketAction::definitions()[$action]['label'] ?? $action,
            'visible' => $visible,
            'allowed' => false,
            'reason' => $reason,
            'mode' => $mode,
            'requirements' => $requirements,
        ];
    }

    private function isClosed(Ticket $ticket): bool
    {
        return (bool) ($ticket->status?->is_closed || $ticket->closed_at);
    }

    private function hasReplyContact(Ticket $ticket): bool
    {
        if (filled($ticket->contact?->email)) {
            return true;
        }

        return $ticket->client_id && ClientUser::query()
            ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
            ->where('active', true)
            ->whereNotNull('email')
            ->exists();
    }
}
