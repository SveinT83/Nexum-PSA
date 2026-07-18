<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Asset\Support\AssetWorkflowFacts;
use App\Modules\Commercial\Support\CommercialWorkflowFacts;
use App\Modules\Economy\Support\EconomyWorkflowFacts;
use App\Modules\Sales\Support\SalesWorkflowFacts;
use App\Modules\Storage\Support\StorageWorkflowFacts;
use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Support\TicketAssignmentWorkflowFacts;
use App\Modules\Ticket\Support\TicketWorkflowFacts;
use RuntimeException;

class TicketWorkflowFactRegistry
{
    /** @var array<int, class-string<WorkflowFactProvider>> */
    private array $providerClasses = [
        TicketWorkflowFacts::class,
        TicketAssignmentWorkflowFacts::class,
        AssetWorkflowFacts::class,
        CommercialWorkflowFacts::class,
        SalesWorkflowFacts::class,
        StorageWorkflowFacts::class,
        EconomyWorkflowFacts::class,
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array
    {
        return collect($this->providers())
            ->flatMap(fn (WorkflowFactProvider $provider) => $provider->catalog())
            ->sortKeys()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array{value: mixed, evidence: array<string, mixed>}
     */
    public function resolve(Ticket $ticket, array $condition): array
    {
        $fact = (string) ($condition['fact'] ?? '');

        foreach ($this->providers() as $provider) {
            if ($provider->supports($fact)) {
                return array_merge(['value' => null, 'evidence' => []], $provider->resolve($ticket, $fact, $condition));
            }
        }

        throw new RuntimeException('Workflow fact provider is unavailable for '.$fact.'.');
    }

    /**
     * @return array<int, WorkflowFactProvider>
     */
    private function providers(): array
    {
        return array_map(fn (string $class) => app($class), $this->providerClasses);
    }
}
