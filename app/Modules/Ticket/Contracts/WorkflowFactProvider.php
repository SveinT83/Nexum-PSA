<?php

namespace App\Modules\Ticket\Contracts;

use App\Modules\Ticket\Models\Ticket;

interface WorkflowFactProvider
{
    public function supports(string $fact): bool;

    /**
     * @return array<string, array<string, mixed>>
     */
    public function catalog(): array;

    /**
     * @param  array<string, mixed>  $condition
     * @return array{value: mixed, evidence?: array<string, mixed>}
     */
    public function resolve(Ticket $ticket, string $fact, array $condition = []): array;
}
