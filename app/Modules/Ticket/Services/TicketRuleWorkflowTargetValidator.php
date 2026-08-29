<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflowVersion;
use Illuminate\Validation\ValidationException;

/**
 * Resolves immutable Workflow targets and fails closed on drift or invalid entry state.
 */
final class TicketRuleWorkflowTargetValidator
{
    public function __construct(
        private readonly TicketWorkflowRequirementEvaluator $requirements,
        private readonly TicketWorkflowMigrationService $migrations,
    ) {}

    public function targetVersion(int $versionId): TicketWorkflowVersion
    {
        $version = TicketWorkflowVersion::query()
            ->with('workflow')
            ->whereKey($versionId)
            ->where('status', 'published')
            ->first();

        if (! $version
            || ! $version->workflow
            || ! $version->workflow->is_active
            || $version->workflow->trashed()) {
            throw ValidationException::withMessages([
                'workflow_version_id' => 'Choose an active published Workflow version.',
            ]);
        }

        return $version;
    }

    /**
     * @return array{version: TicketWorkflowVersion, state: array<string, mixed>, status: TicketStatus}
     */
    public function currentPlacement(Ticket $ticket, bool $requireNonTerminal = true): array
    {
        if (! $ticket->workflow_version_id || ! $ticket->workflow_id || blank($ticket->workflow_state_key)) {
            throw ValidationException::withMessages([
                'workflow' => 'The Ticket is not pinned to a complete published Workflow state.',
            ]);
        }

        $version = $this->targetVersion((int) $ticket->workflow_version_id);
        if ((int) $version->ticket_workflow_id !== (int) $ticket->workflow_id) {
            throw ValidationException::withMessages([
                'workflow' => 'The Ticket Workflow pin is inconsistent.',
            ]);
        }

        $state = collect($version->definition['states'] ?? [])
            ->firstWhere('state_key', $ticket->workflow_state_key);
        if (! is_array($state)) {
            throw ValidationException::withMessages([
                'workflow_state_key' => 'The current Workflow state is unavailable in the pinned version.',
            ]);
        }

        $status = $this->activeStatus($state);
        if ((int) $status->id !== (int) $ticket->status_id) {
            throw ValidationException::withMessages([
                'workflow' => 'The Ticket reporting status does not match its pinned Workflow state.',
            ]);
        }

        if ($requireNonTerminal && ((bool) ($state['is_terminal'] ?? false) || $status->is_closed)) {
            throw ValidationException::withMessages([
                'workflow' => 'Ticket Rule Workflow actions cannot move a terminal Ticket.',
            ]);
        }

        return compact('version', 'state', 'status');
    }

    /**
     * @return array{state: array<string, mixed>, status: TicketStatus, strategy: string, reason: string, requirements_result: array<string, mixed>}
     */
    public function initialPlacement(Ticket $ticket, TicketWorkflowVersion $version): array
    {
        $initialStates = collect($version->definition['states'] ?? [])
            ->filter(fn (mixed $state): bool => is_array($state) && (bool) ($state['is_initial'] ?? false))
            ->values();

        if ($initialStates->count() !== 1) {
            throw ValidationException::withMessages([
                'workflow_version_id' => 'The published Workflow version must contain exactly one initial state.',
            ]);
        }

        $validated = $this->validateState($ticket, $version, $initialStates->first());

        return $validated + [
            'strategy' => 'initial_state',
            'reason' => 'The exact published Workflow initial state was selected.',
        ];
    }

    /**
     * @return array{state: array<string, mixed>, status: TicketStatus, strategy: string, reason: string, requirements_result: array<string, mixed>}
     */
    public function switchPlacement(
        Ticket $ticket,
        TicketWorkflowVersion $version,
        string $strategy,
        ?string $stateKey,
    ): array {
        if ($strategy === 'automatic') {
            $placement = $this->migrations->resolveTarget($ticket, $version);
            if (! is_array($placement['target_state'] ?? null)) {
                throw ValidationException::withMessages([
                    'target_state_key' => (string) ($placement['blocked_reason'] ?? 'No safe non-terminal Workflow target state is available.'),
                ]);
            }

            $validated = $this->validateState($ticket, $version, $placement['target_state']);

            return $validated + [
                'strategy' => (string) ($placement['strategy'] ?? 'automatic'),
                'reason' => (string) ($placement['reason'] ?? 'The target state was selected from current Ticket facts.'),
            ];
        }

        if ($strategy !== 'state_key' || blank($stateKey)) {
            throw ValidationException::withMessages([
                'mapping_strategy' => 'Choose automatic placement or one exact target Workflow state.',
            ]);
        }

        $state = collect($version->definition['states'] ?? [])->firstWhere('state_key', $stateKey);
        if (! is_array($state)) {
            throw ValidationException::withMessages([
                'target_state_key' => 'The exact target Workflow state is unavailable in the published version.',
            ]);
        }

        $validated = $this->validateState($ticket, $version, $state);

        return $validated + [
            'strategy' => 'state_key',
            'reason' => 'The configured exact target Workflow state was selected.',
        ];
    }

    /**
     * @return array{state: array<string, mixed>, status: TicketStatus, requirements_result: array<string, mixed>}
     */
    public function validateStateKey(
        Ticket $ticket,
        TicketWorkflowVersion $version,
        string $stateKey,
    ): array {
        $state = collect($version->definition['states'] ?? [])->firstWhere('state_key', $stateKey);
        if (! is_array($state)) {
            throw ValidationException::withMessages([
                'workflow_state_key' => 'The target Workflow state is unavailable in the pinned version.',
            ]);
        }

        return $this->validateState($ticket, $version, $state);
    }

    public function assertRuleMovementAvailable(Ticket $ticket): void
    {
        if ($ticket->getAttribute('rule_workflow_paused_at') !== null) {
            throw ValidationException::withMessages([
                'workflow' => 'Rule-driven Workflow automation is paused for this Ticket.',
            ]);
        }

        $this->currentPlacement($ticket);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{state: array<string, mixed>, status: TicketStatus, requirements_result: array<string, mixed>}
     */
    private function validateState(
        Ticket $ticket,
        TicketWorkflowVersion $version,
        array $state,
    ): array {
        $status = $this->activeStatus($state);
        if ((bool) ($state['is_terminal'] ?? false) || $status->is_closed) {
            throw ValidationException::withMessages([
                'workflow_state_key' => 'Ticket Rule Workflow actions cannot target a terminal state.',
            ]);
        }

        $probe = clone $ticket;
        $probe->forceFill([
            'workflow_id' => $version->ticket_workflow_id,
            'workflow_version_id' => $version->id,
            'workflow_state_key' => $state['state_key'],
            'status_id' => $status->id,
        ]);
        $probe->setRelation('workflow', $version->workflow);
        $probe->setRelation('workflowVersion', $version);
        $probe->setRelation('status', $status);

        $result = $this->requirements->evaluate($probe, $state['requirements'] ?? []);
        if (! ($result['passed'] ?? false)) {
            throw ValidationException::withMessages([
                'workflow_state_key' => (string) data_get(
                    $result,
                    'missing.0.reason',
                    'The target Workflow state requirements are not satisfied.',
                ),
            ]);
        }

        return [
            'state' => $state,
            'status' => $status,
            'requirements_result' => $result,
        ];
    }

    /** @param array<string, mixed> $state */
    private function activeStatus(array $state): TicketStatus
    {
        $statusId = (int) ($state['ticket_status_id'] ?? 0);
        $status = $statusId > 0
            ? TicketStatus::query()->whereKey($statusId)->where('is_active', true)->first()
            : null;

        if (! $status) {
            throw ValidationException::withMessages([
                'workflow_state_key' => 'The target Workflow state reporting status is unavailable.',
            ]);
        }

        return $status;
    }
}
