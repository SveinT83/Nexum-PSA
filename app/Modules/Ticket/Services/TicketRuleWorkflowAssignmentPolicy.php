<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;

/**
 * Applies the existing Workflow state assignment policy once and reports its exact consequence.
 */
final class TicketRuleWorkflowAssignmentPolicy
{
    public function __construct(
        private readonly TicketAssignmentEngine $assignments,
    ) {}

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function plan(Ticket $ticket, array $state): array
    {
        $policy = (array) ($state['assignment_policy'] ?? []);
        $strategy = (string) ($policy['strategy'] ?? 'keep_if_eligible');
        $eligibleIds = $this->eligibleUserIds($policy);
        $beforeOwnerId = $ticket->owner_id === null ? null : (int) $ticket->owner_id;
        $ownerEligible = $this->ownerEligible($beforeOwnerId, $eligibleIds);

        $projectedOwnerId = $beforeOwnerId;
        $outcome = 'owner_retained';
        $decision = false;

        if ($strategy === 'unassigned') {
            $projectedOwnerId = null;
            $outcome = $beforeOwnerId === null ? 'already_unassigned' : 'owner_cleared';
            $decision = true;
        } elseif ($strategy === 'auto') {
            $projectedOwnerId = null;
            $outcome = 'automatic_reassignment';
            $decision = true;
        } elseif ($strategy === 'manual' && ! $ownerEligible) {
            $projectedOwnerId = null;
            $outcome = 'owner_cleared_for_manual_assignment';
            $decision = true;
        } elseif ($strategy === 'keep_if_eligible' && ! $ownerEligible) {
            $projectedOwnerId = null;
            $outcome = 'automatic_reassignment';
            $decision = true;
        }

        return [
            'strategy' => $strategy,
            'before_owner_id' => $beforeOwnerId,
            'projected_owner_id' => $projectedOwnerId,
            'after_owner_id' => $projectedOwnerId,
            'owner_eligible_before' => $ownerEligible,
            'outcome' => $outcome,
            'assignment_decision' => $decision,
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public function apply(Ticket $ticket, array $state): array
    {
        $result = $this->plan($ticket, $state);
        $strategy = $result['strategy'];
        $eligibleIds = $this->eligibleUserIds((array) ($state['assignment_policy'] ?? []));
        $ownerEligible = (bool) $result['owner_eligible_before'];

        if ($strategy === 'unassigned') {
            if ($ticket->owner_id !== null) {
                $ticket->forceFill(['owner_id' => null])->save();
            }
        } elseif ($strategy === 'auto') {
            if ($ticket->owner_id !== null) {
                $ticket->forceFill(['owner_id' => null])->save();
            }
            $this->assignments->assign($ticket->refresh(), force: true, eligibleUserIds: $eligibleIds);
        } elseif ($strategy === 'manual') {
            if (! $ownerEligible && $ticket->owner_id !== null) {
                $ticket->forceFill(['owner_id' => null])->save();
            }
        } elseif (! $ownerEligible) {
            if ($ticket->owner_id !== null) {
                $ticket->forceFill(['owner_id' => null])->save();
            }
            $this->assignments->assign($ticket->refresh(), force: true, eligibleUserIds: $eligibleIds);
        }

        $ticket->refresh();
        $afterOwnerId = $ticket->owner_id === null ? null : (int) $ticket->owner_id;
        if (! $this->ownerEligible($afterOwnerId, $eligibleIds)) {
            $ticket->forceFill(['owner_id' => null])->save();
            $ticket->refresh();
            $afterOwnerId = null;
        }

        return array_replace($result, [
            'after_owner_id' => $afterOwnerId,
            'owner_changed' => $result['before_owner_id'] !== $afterOwnerId,
            'outcome' => $this->actualOutcome($result, $afterOwnerId),
        ]);
    }

    /**
     * @param  array<string, mixed>  $policy
     * @return list<int>|null
     */
    private function eligibleUserIds(array $policy): ?array
    {
        $configured = collect($policy['eligible_user_ids'] ?? [])
            ->map(fn (mixed $id): int => (int) $id)
            ->filter()
            ->unique();
        $requiredPermissions = collect($policy['required_permissions'] ?? [])
            ->filter(fn (mixed $permission): bool => is_string($permission) && $permission !== '')
            ->values();

        if ($configured->isEmpty() && $requiredPermissions->isEmpty()) {
            return null;
        }

        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->where('is_system_actor', false)
            ->when($configured->isNotEmpty(), fn ($query) => $query->whereIn('id', $configured))
            ->get()
            ->filter(fn (User $user): bool => $requiredPermissions
                ->every(fn (string $permission): bool => $user->can($permission)))
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    /** @param list<int>|null $eligibleIds */
    private function ownerEligible(?int $ownerId, ?array $eligibleIds): bool
    {
        if ($ownerId === null) {
            return true;
        }

        $owner = User::query()
            ->whereKey($ownerId)
            ->where('status', User::STATUS_ACTIVE)
            ->where('is_system_actor', false)
            ->first();

        return $owner !== null
            && ($eligibleIds === null || in_array($ownerId, $eligibleIds, true));
    }

    /** @param array<string, mixed> $planned */
    private function actualOutcome(array $planned, ?int $afterOwnerId): string
    {
        if ($planned['before_owner_id'] === $afterOwnerId) {
            return $afterOwnerId === null ? 'already_unassigned' : 'owner_retained';
        }

        if ($afterOwnerId === null) {
            return $planned['strategy'] === 'manual'
                ? 'owner_cleared_for_manual_assignment'
                : 'owner_cleared';
        }

        return 'owner_assigned';
    }
}
