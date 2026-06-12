<?php

namespace App\Modules\Clients\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\ContractItemTimeRate;
use App\Modules\Commercial\Models\TimeRate;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class ClientTimeUsageController extends Controller
{
    public function update(Client $client, string $source, int $entry, Request $request, CalculateClientTimebankBalances $balances, TicketTimeRateOptions $ticketRates): RedirectResponse
    {
        $data = $request->validate([
            'work_date' => ['required', 'date'],
            'minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'time_rate_source' => ['nullable', 'string', 'max:100'],
            'invoice_text' => ['nullable', 'string', 'max:2000'],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        match ($source) {
            'quick' => $this->updateQuick($client, $entry, $data, $request, $balances),
            'ticket' => $this->updateTicket($client, $entry, $data, $request, $ticketRates),
            'task' => $this->updateTask($client, $entry, $data, $request),
            default => abort(404),
        };

        return redirect()
            ->route('tech.clients.show', ['client' => $client->id, 'tab' => 'time-usage'])
            ->with('status', 'Time usage updated.');
    }

    private function updateQuick(Client $client, int $entryId, array $data, Request $request, CalculateClientTimebankBalances $balances): void
    {
        $entry = ClientContractTimeConsumption::query()->with(['contract', 'contractItem.service'])->findOrFail($entryId);
        abort_unless((int) $entry->client_id === (int) $client->id, 404);
        $this->authorizeOwnerOrPermission($entry->user_id, $request, 'commercial.timebank.overconsume');
        $this->abortIfOrdered($entry);

        $workDate = Carbon::parse($data['work_date'])->startOfDay();
        $contractItem = ContractItem::query()->with(['contract', 'service'])->findOrFail($entry->contract_item_id);
        $period = $balances->forContractItem($client, $contractItem->contract, $contractItem, $workDate);
        $usedWithoutEntry = max(0, $period['used_minutes'] - $entry->minutes);
        $overusedBefore = max(0, $usedWithoutEntry - $period['included_minutes']);
        $overusedAfter = max(0, ($usedWithoutEntry + (int) $data['minutes']) - $period['included_minutes']);
        $rate = filled($data['time_rate_source'] ?? null)
            ? $this->resolveQuickRate($contractItem, $data['time_rate_source'])
            : null;

        $payload = [
            'work_date' => $data['work_date'],
            'minutes' => $data['minutes'],
            'note' => $data['note'] ?? null,
            'period_start' => $period['period_start']->toDateString(),
            'period_end' => $period['period_end']->toDateString(),
            'included_minutes_snapshot' => $period['included_minutes'],
            'used_before_minutes_snapshot' => $usedWithoutEntry,
            'overused_minutes' => max(0, $overusedAfter - $overusedBefore),
        ];

        if ($rate) {
            $payload = array_merge($payload, $rate);
        }

        $entry->forceFill($payload)->save();
    }

    private function updateTicket(Client $client, int $entryId, array $data, Request $request, TicketTimeRateOptions $ticketRates): void
    {
        $entry = TicketTimeEntry::query()->with(['ticket', 'allocation'])->findOrFail($entryId);
        abort_unless((int) $entry->ticket?->client_id === (int) $client->id, 404);
        $this->authorizeOwnerOrPermission($entry->user_id, $request, 'ticket.update');
        $this->abortIfOrdered($entry);

        $rate = filled($data['time_rate_source'] ?? null)
            ? $ticketRates->findForTicket($entry->ticket, $data['time_rate_source'])
            : null;

        if (filled($data['time_rate_source'] ?? null) && ! $rate) {
            throw ValidationException::withMessages([
                'time_rate_source' => 'The selected time rate is not available for this ticket.',
            ]);
        }

        $entry->allocation?->delete();
        $payload = [
            'work_date' => $data['work_date'],
            'minutes' => $data['minutes'],
            'invoice_text' => $data['invoice_text'] ?? $entry->invoice_text,
            'note' => $data['note'] ?? null,
            'billing_status' => 'pending',
            'timebank_status' => 'pending',
        ];

        if ($rate) {
            $payload = array_merge($payload, [
                'cost_account' => $rate['rate_code'] ?? null,
                'billing_basis' => $rate['billing_basis'] ?? null,
                'contract_id' => $rate['contract_id'] ?? null,
                'contract_item_id' => $rate['contract_item_id'] ?? null,
                'contract_item_time_rate_id' => $rate['contract_item_time_rate_id'] ?? null,
                'time_rate_id' => $rate['time_rate_id'] ?? null,
                'rate_name' => $rate['rate_name'] ?? null,
                'rate_code' => $rate['rate_code'] ?? null,
                'rate_type' => $rate['rate_type'] ?? null,
                'rate_unit' => $rate['rate_unit'] ?? null,
                'rate_amount_ex_vat' => $rate['rate_amount_ex_vat'] ?? null,
                'rate_currency' => $rate['rate_currency'] ?? 'NOK',
            ]);
        }

        $entry->forceFill($payload)->save();
    }

    private function updateTask(Client $client, int $entryId, array $data, Request $request): void
    {
        $entry = TaskTimeEntry::query()->with('task.owner')->findOrFail($entryId);
        $belongsToClient = $entry->task?->owner_type === $client->getMorphClass() && (int) $entry->task?->owner_id === (int) $client->id;
        $belongsToClientTicket = $entry->task?->owner instanceof \App\Modules\Ticket\Models\Ticket
            && (int) $entry->task->owner->client_id === (int) $client->id;

        abort_unless($belongsToClient || $belongsToClientTicket, 404);
        $this->authorizeOwnerOrPermission($entry->user_id, $request, 'task.update');

        $entry->forceFill([
            'work_date' => $data['work_date'],
            'minutes' => $data['minutes'],
            'note' => $data['note'] ?? null,
        ])->save();
    }

    private function authorizeOwnerOrPermission(?int $ownerId, Request $request, string $permission): void
    {
        $actor = $request->user();

        if (! $actor || ((int) $ownerId !== (int) $actor->id && ! $actor->can($permission))) {
            throw ValidationException::withMessages([
                'time_usage' => 'You do not have permission to edit this time entry.',
            ]);
        }
    }

    private function abortIfOrdered(object $entry): void
    {
        $hasOrderLine = EconomyOrderLine::query()
            ->where('source_type', method_exists($entry, 'getMorphClass') ? $entry->getMorphClass() : $entry::class)
            ->where('source_id', $entry->id)
            ->exists();

        if ($hasOrderLine) {
            throw ValidationException::withMessages([
                'time_usage' => 'This time entry is already included on an Economy order line.',
            ]);
        }
    }

    private function resolveQuickRate(ContractItem $item, string $source): array
    {
        if (! str_contains($source, ':')) {
            throw ValidationException::withMessages([
                'time_rate_source' => 'Select a valid time rate.',
            ]);
        }

        [$type, $id] = explode(':', $source, 2);

        if ($type === 'contract') {
            $rate = ContractItemTimeRate::query()
                ->where('contract_item_id', $item->id)
                ->where('is_active', true)
                ->where('amount_ex_vat', '>', 0)
                ->find((int) $id);

            if (! $rate) {
                throw ValidationException::withMessages([
                    'time_rate_source' => 'The selected contract time rate is not available.',
                ]);
            }

            return [
                'contract_item_time_rate_id' => $rate->id,
                'time_rate_id' => $rate->time_rate_id,
                'rate_name' => $rate->name,
                'rate_code' => $rate->code,
                'rate_type' => $rate->rate_type,
                'rate_unit' => $rate->unit,
                'rate_amount_ex_vat' => (float) $rate->amount_ex_vat,
                'rate_currency' => $rate->currency ?: 'NOK',
            ];
        }

        if ($type === 'global') {
            $rate = TimeRate::query()
                ->where('is_active', true)
                ->where('applies_with_contract', true)
                ->where('amount_ex_vat', '>', 0)
                ->find((int) $id);

            if (! $rate) {
                throw ValidationException::withMessages([
                    'time_rate_source' => 'The selected time rate is not available.',
                ]);
            }

            return [
                'contract_item_time_rate_id' => null,
                'time_rate_id' => $rate->id,
                'rate_name' => $rate->name,
                'rate_code' => $rate->code,
                'rate_type' => $rate->rate_type,
                'rate_unit' => $rate->unit,
                'rate_amount_ex_vat' => (float) $rate->amount_ex_vat,
                'rate_currency' => $rate->currency ?: 'NOK',
            ];
        }

        throw ValidationException::withMessages([
            'time_rate_source' => 'Select a valid time rate.',
        ]);
    }
}
