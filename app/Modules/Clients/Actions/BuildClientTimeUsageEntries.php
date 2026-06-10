<?php

namespace App\Modules\Clients\Actions;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Support\Collection;

class BuildClientTimeUsageEntries
{
    public function __construct(
        private readonly CalculateClientTimebankBalances $balances,
        private readonly TicketTimeRateOptions $ticketTimeRateOptions,
    ) {
    }

    public function handle(Client $client, ?User $actor = null): Collection
    {
        return $this->quickEntries($client, $actor)
            ->concat($this->ticketEntries($client, $actor))
            ->concat($this->taskEntries($client, $actor))
            ->reject(fn (array $entry): bool => $entry['ordered'])
            ->sortByDesc(fn (array $entry): string => $entry['sort_at'])
            ->values();
    }

    private function quickEntries(Client $client, ?User $actor): Collection
    {
        return ClientContractTimeConsumption::query()
            ->with(['user', 'contractItem.timeRates', 'contract'])
            ->where('client_id', $client->id)
            ->latest('work_date')
            ->latest('id')
            ->get()
            ->map(function (ClientContractTimeConsumption $entry) use ($actor): array {
                $ordered = $this->hasOrderLine($entry);

                return [
                    'source' => 'quick',
                    'id' => $entry->id,
                    'label' => 'Quick',
                    'context' => $entry->contractItem?->name ?: 'Contract timebank',
                    'context_url' => route('tech.contracts.show', $entry->contract),
                    'work_date' => $entry->work_date,
                    'minutes' => $entry->minutes,
                    'user' => $entry->user,
                    'note' => $entry->note,
                    'invoice_text' => null,
                    'rate_name' => $entry->rate_name,
                    'rate_amount_ex_vat' => $entry->rate_amount_ex_vat,
                    'overused_minutes' => $entry->overused_minutes,
                    'ordered' => $ordered,
                    'can_edit' => $this->canEditQuick($entry, $actor) && ! $ordered,
                    'rate_options' => $entry->contractItem ? $this->balances->timeRateOptions($entry->contractItem) : collect(),
                    'current_rate_source' => $this->currentRateSource($entry->contract_item_time_rate_id, $entry->time_rate_id),
                    'sort_at' => ($entry->work_date?->toDateString() ?? $entry->created_at?->toDateString() ?? '0000-00-00').' '.$entry->id,
                ];
            });
    }

    private function ticketEntries(Client $client, ?User $actor): Collection
    {
        return TicketTimeEntry::query()
            ->with(['user', 'ticket'])
            ->whereHas('ticket', fn ($query) => $query->where('client_id', $client->id))
            ->latest('work_date')
            ->latest('id')
            ->get()
            ->map(function (TicketTimeEntry $entry) use ($actor): array {
                $ordered = $this->hasOrderLine($entry);

                return [
                    'source' => 'ticket',
                    'id' => $entry->id,
                    'label' => 'Ticket',
                    'context' => trim(($entry->ticket?->ticket_key ? $entry->ticket->ticket_key.' - ' : '').($entry->ticket?->subject ?? 'Ticket')),
                    'context_url' => route('tech.tickets.show', $entry->ticket),
                    'work_date' => $entry->work_date,
                    'minutes' => $entry->minutes,
                    'user' => $entry->user,
                    'note' => $entry->note,
                    'invoice_text' => $entry->invoice_text,
                    'rate_name' => $entry->rate_name,
                    'rate_amount_ex_vat' => $entry->rate_amount_ex_vat,
                    'overused_minutes' => null,
                    'ordered' => $ordered,
                    'can_edit' => $this->canEditTicket($entry, $actor) && ! $ordered,
                    'rate_options' => $entry->ticket ? $this->ticketTimeRateOptions->forTicket($entry->ticket)->map(fn (array $option): array => [
                        'value' => $option['key'],
                        'label' => trim($option['label'].' - '.$option['description']),
                    ]) : collect(),
                    'current_rate_source' => $this->currentTicketRateSource($entry),
                    'sort_at' => ($entry->work_date?->toDateString() ?? $entry->created_at?->toDateString() ?? '0000-00-00').' '.$entry->id,
                ];
            });
    }

    private function taskEntries(Client $client, ?User $actor): Collection
    {
        return TaskTimeEntry::query()
            ->with(['user', 'task.owner'])
            ->whereHas('task', function ($query) use ($client): void {
                $query->where(function ($taskQuery) use ($client): void {
                    $taskQuery->where('owner_type', $client->getMorphClass())
                        ->where('owner_id', $client->id);
                })->orWhereHasMorph('owner', [\App\Modules\Ticket\Models\Ticket::class], fn ($ticketQuery) => $ticketQuery->where('client_id', $client->id));
            })
            ->latest('work_date')
            ->latest('id')
            ->get()
            ->map(fn (TaskTimeEntry $entry): array => [
                'source' => 'task',
                'id' => $entry->id,
                'label' => 'Task',
                'context' => $entry->task?->title ?: 'Task',
                'context_url' => route('tech.tasks.show', $entry->task),
                'work_date' => $entry->work_date,
                'minutes' => $entry->minutes,
                'user' => $entry->user,
                'note' => $entry->note,
                'invoice_text' => null,
                'rate_name' => $entry->billable ? 'Billable task time' : 'Task time',
                'rate_amount_ex_vat' => null,
                'overused_minutes' => null,
                'ordered' => false,
                'can_edit' => $this->canEditTask($entry, $actor),
                'rate_options' => collect(),
                'current_rate_source' => null,
                'sort_at' => ($entry->work_date?->toDateString() ?? $entry->created_at?->toDateString() ?? '0000-00-00').' '.$entry->id,
            ]);
    }

    private function currentRateSource(?int $contractItemTimeRateId, ?int $timeRateId): ?string
    {
        if ($contractItemTimeRateId) {
            return 'contract:'.$contractItemTimeRateId;
        }

        return $timeRateId ? 'global:'.$timeRateId : null;
    }

    private function currentTicketRateSource(TicketTimeEntry $entry): ?string
    {
        if ($entry->contract_item_time_rate_id) {
            return 'contract:'.$entry->contract_item_time_rate_id;
        }

        if ($entry->contract_item_id && $entry->time_rate_id) {
            return 'contract-fallback:'.$entry->contract_item_id.':'.$entry->time_rate_id;
        }

        return $entry->time_rate_id ? 'global:'.$entry->time_rate_id : null;
    }

    private function hasOrderLine(object $entry): bool
    {
        return EconomyOrderLine::query()
            ->where('source_type', method_exists($entry, 'getMorphClass') ? $entry->getMorphClass() : $entry::class)
            ->where('source_id', $entry->id)
            ->exists();
    }

    private function canEditQuick(ClientContractTimeConsumption $entry, ?User $actor): bool
    {
        return $actor && ((int) $entry->user_id === (int) $actor->id || $actor->can('commercial.timebank.overconsume'));
    }

    private function canEditTicket(TicketTimeEntry $entry, ?User $actor): bool
    {
        return $actor && ((int) $entry->user_id === (int) $actor->id || $actor->can('ticket.update'));
    }

    private function canEditTask(TaskTimeEntry $entry, ?User $actor): bool
    {
        return $actor && ((int) $entry->user_id === (int) $actor->id || $actor->can('task.update'));
    }
}
