<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketRuleExecutionCoordinator;
use App\Modules\Ticket\Services\TicketRuleRuntimeGate;
use App\Modules\Ticket\Support\TicketRuleExecutionResult;
use App\Modules\Ticket\Support\TicketRuleMutationEvent;
use App\Modules\Ticket\Support\TicketRuleTriggerRegistry;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One synchronous entry from authoritative Ticket mutations into Ticket Rules.
 */
final class DispatchTicketRuleMutationEvent
{
    public function __construct(
        private readonly TicketRuleRuntimeGate $runtimeGate,
        private readonly TicketRuleTriggerRegistry $triggers,
    ) {}

    public function handle(
        Ticket $ticket,
        TicketRuleMutationEvent $event,
        ?User $initiator,
    ): ?TicketRuleExecutionResult {
        if ((int) $ticket->id !== $event->ticketId) {
            throw new LogicException('The Ticket mutation event belongs to a different Ticket.');
        }

        if (! $this->runtimeGate->mutationUsesV2() || ! $this->hasEnabledConsumer($event)) {
            return null;
        }

        if (DB::connection()->transactionLevel() < 1) {
            throw new LogicException('Ticket Rule mutation dispatch must remain inside the authoritative Ticket transaction.');
        }

        // Resolve lazily so action providers can depend on authoritative
        // Ticket Actions without creating a coordinator dependency cycle.
        return app(TicketRuleExecutionCoordinator::class)->executeMutation(
            $ticket,
            $event,
            $initiator,
        );
    }

    private function hasEnabledConsumer(TicketRuleMutationEvent $event): bool
    {
        $eventKeys = collect((array) ($event->classification['event_keys'] ?? []))
            ->prepend($event->eventKey)
            ->filter(fn (mixed $key): bool => is_string($key) && str_starts_with($key, 'ticket.'))
            ->unique()
            ->all();

        foreach ($this->triggers->definitions() as $triggerKey => $definition) {
            if (! $this->triggers->enabled((string) $triggerKey)) {
                continue;
            }

            if (array_intersect($eventKeys, (array) ($definition['event_keys'] ?? [])) !== []) {
                return true;
            }
        }

        return false;
    }
}
