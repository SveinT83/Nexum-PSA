<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class CommercialWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'commercial.');
    }

    public function catalog(): array
    {
        return [
            'commercial.valid_contract' => [
                'label' => 'Valid accepted contract exists',
                'operators' => ['is_true', 'is_false'],
                'value_type' => 'none',
            ],
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        if ($fact !== 'commercial.valid_contract' || ! $ticket->client_id) {
            return ['value' => false, 'evidence' => []];
        }

        $contract = Contracts::query()
            ->where('client_id', $ticket->client_id)
            ->whereNotNull('accepted_at')
            ->where(function ($query): void {
                $query->whereNull('start_date')->orWhereDate('start_date', '<=', today());
            })
            ->where(function ($query): void {
                $query->whereNull('end_date')->orWhereDate('end_date', '>=', today());
            })
            ->latest('accepted_at')
            ->first();

        return [
            'value' => $contract !== null,
            'evidence' => $contract ? ['contract_id' => $contract->id, 'accepted_at' => $contract->accepted_at?->toISOString()] : [],
        ];
    }
}
