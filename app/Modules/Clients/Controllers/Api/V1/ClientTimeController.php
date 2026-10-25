<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Clients\Actions\BuildClientTimeUsageEntries;
use App\Modules\Clients\Actions\CorrectClientTimeUsage;
use App\Modules\Clients\Actions\ExecuteClientCommand;
use App\Modules\Clients\Support\ClientRevision;
use App\Modules\Commercial\Actions\CalculateClientTimebankBalances;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Integration\Support\DomainApiInput;
use App\Modules\Integration\Support\DomainApiRead;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use Illuminate\Http\Request;

/** Actual Task time, Ticket billing and quick usage retain their distinct source authority. */
class ClientTimeController extends Controller
{
    private function source(Request $request, string $source): void
    {
        $permission = match ($source) {
            'quick' => 'commercial.timebank.view', 'ticket' => 'ticket.view', 'task' => 'task.view', default => abort(404),
        };
        app(\App\Modules\Integration\Support\DomainApiAuthorization::class)->authorize($request, $permission);
    }

    private function record(Request $request, Client $client, string $source, int $id)
    {
        $this->source($request, $source);
        $row = match ($source) {
            'quick' => ClientContractTimeConsumption::query()->where('client_id', $client->id)->findOrFail($id),
            'ticket' => TicketTimeEntry::query()->whereHas('ticket', fn ($q) => $q->where('client_id', $client->id))->findOrFail($id),
            'task' => TaskTimeEntry::query()->whereHas('task', fn ($q) => $q->where(fn ($q) => $q->where('owner_type', $client->getMorphClass())->where('owner_id', $client->id))
                ->orWhereHasMorph('owner', [\App\Modules\Ticket\Models\Ticket::class], fn ($q) => $q->where('client_id', $client->id)))->findOrFail($id),
        };
        if ($source === 'task') {
            app(TaskApiScope::class)->assert($row->task, $request->user());
        }

        return $row;
    }

    private function projection($record, string $source): array
    {
        return $record->only(['id', 'user_id', 'work_date', 'minutes', 'note', 'invoice_text', 'billing_status', 'timebank_status',
            'contract_id', 'contract_item_id', 'time_rate_id', 'rate_name', 'rate_amount_ex_vat', 'rate_currency', 'overused_minutes'])
            + ['source' => $source, 'revision' => ClientRevision::of($record)];
    }

    public function index(Request $request, Client $client)
    {
        $rows = app(BuildClientTimeUsageEntries::class)->handle($client, $request->user())->filter(function ($row) use ($request) {
            $permission = ['quick' => 'commercial.timebank.view', 'ticket' => 'ticket.view', 'task' => 'task.view'][$row['source']];
            if (! $request->user()->can($permission)) {
                return false;
            }
            if ($row['source'] === 'task') {
                $task = TaskTimeEntry::query()->find($row['id'])?->task;

                return $task && app(TaskApiScope::class)->visible(\App\Modules\Task\Models\Task::query(), $request->user())->whereKey($task->id)->exists();
            }

            return true;
        })->map(function ($row) use ($request, $client) {
            $record = $this->record($request, $client, $row['source'], $row['id']);

            return $this->projection($record, $row['source']) + [
                'label' => $row['label'], 'context' => $row['context'], 'can_edit' => $row['can_edit'],
                'rate_options' => $row['rate_options']->values()->all(), 'current_rate_source' => $row['current_rate_source'],
            ];
        })->values();

        return response()->json(DomainApiRead::paginateCollection($rows, $request));
    }

    public function show(Request $request, Client $client, string $source, int $entry)
    {
        return response()->json(['data' => $this->projection($this->record($request, $client, $source, $entry), $source)]);
    }

    public function update(Request $request, Client $client, string $source, int $entry)
    {
        DomainApiInput::validate($request, ['work_date' => 'required|date', 'minutes' => 'required|integer|min:1|max:1440',
            'note' => 'nullable|string|max:2000', 'invoice_text' => 'nullable|string|max:2000', 'time_rate_source' => 'nullable|string|max:100']);
        abort_if($source === 'task' && $request->hasAny(['time_rate_source', 'invoice_text']), 422, 'Task actual time does not store a billing rate or invoice text.');
        $authorize = function () use ($request, $client, $source, $entry) {
            $row = $this->record($request, $client, $source, $entry);
            $higher = ['quick' => 'commercial.timebank.overconsume', 'ticket' => 'ticket.update', 'task' => 'task.update'][$source];
            abort_unless((int) $row->user_id === (int) $request->user()->id || $request->user()->can($higher), 403);
        };
        $result = app(ExecuteClientCommand::class)->handle($request, $authorize, function () use ($request, $client, $source, $entry) {
            return app(\App\Modules\Economy\Support\EconomyMutex::class)->run(function () use ($request, $client, $source, $entry) {
                ClientRevision::check($request, $this->record($request, $client, $source, $entry));
                app(CorrectClientTimeUsage::class)->handle($client, $source, $entry, $request,
                    app(CalculateClientTimebankBalances::class), app(TicketTimeRateOptions::class));

                return ['entry_id' => $entry, 'source' => $source];
            });
        });

        return response()->json($result + ['data' => $this->projection($this->record($request, $client, $source, $entry), $source)]);
    }
}
