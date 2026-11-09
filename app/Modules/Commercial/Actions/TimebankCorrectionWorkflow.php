<?php

namespace App\Modules\Commercial\Actions;

use App\Models\Clients\Client;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\TimebankCorrection;
use App\Modules\Commercial\Support\TimebankLedger;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Support\EconomyMutex;
use App\Modules\Integration\Support\DomainApiAuthorization;
use App\Modules\Task\Services\TaskApiScope;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Services\TicketMutationScopeGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Shared browser/API correction boundary; previews are pure and work records are never edited. */
class TimebankCorrectionWorkflow
{
    private function financeAllowed(Request $request, bool $write): bool
    {
        return app(DomainApiAuthorization::class)->permits($request, 'economy.order_manage')
            && (! $request->is('api/*') || $request->user()->tokenCan('economy.update')
                || (! $write && $request->user()->tokenCan('economy.read')));
    }

    public function authorize(Request $request, string $kind = 'read'): void
    {
        abort_unless(config('commercial.timebank_corrections_enabled') && TimebankLedger::installed(), 503,
            'Timebank corrections are not enabled.');
        foreach (['client.view', 'commercial.timebank.view'] as $permission) {
            app(DomainApiAuthorization::class)->authorize($request, $permission);
        }
        if ($kind !== 'read') {
            app(DomainApiAuthorization::class)->authorize($request, 'commercial.timebank.'.($kind === 'adjustment' ? 'adjust' : 'reverse'));
        }
    }

    public function input(Request $request, string $kind): array
    {
        $rules = ['reason' => 'required|string|max:2000'];
        $rules += $kind === 'adjustment'
            ? ['contract_item_id' => 'required|integer|min:1', 'effective_date' => 'required|date_format:Y-m-d|before_or_equal:today',
                'mode' => ['required', Rule::in(['restore', 'zero', 'custom'])],
                'target_minutes' => 'required_if:mode,custom|nullable|integer|min:0|max:52560000']
            : ['source_type' => ['required', Rule::in(['quick', 'ticket', 'task_billing'])], 'source_id' => 'required|integer|min:1'];
        $allowed = array_merge(array_keys($rules), ['preview_token']);
        if (! $request->is('api/*')) {
            $allowed[] = '_token';
        }
        abort_if(array_diff(array_keys($request->all()), $allowed) !== [], 422, 'Unknown correction input.');
        $data = $request->validate($rules);
        $data['reason'] = trim($data['reason']);
        abort_if($data['reason'] === '', 422, 'A correction reason is required.');

        return $data;
    }

    /** Canonical source checks are reused by previews, execution, replay and history. */
    public function source(Request $request, Client $client, string $type, int $id): object
    {
        if ($type === 'quick') {
            $row = ClientContractTimeConsumption::query()->where('client_id', $client->id)->findOrFail($id);
            abort_unless((int) $row->contract?->client_id === (int) $client->id, 409, 'Financial source ownership is inconsistent.');

            $recordedOwner = \Illuminate\Support\Facades\Schema::hasTable('economy_source_owners')
                    ? DB::table('economy_source_owners')->where('source_type', $row->getMorphClass())
                        ->where('source_id', $row->id)->value('client_id') : null;
            abort_if($recordedOwner !== null && (int) $recordedOwner !== (int) $client->id, 409,
                'Recorded financial ownership differs from the current customer.');

            return $row;
        }
        abort_unless(in_array($type, ['ticket', 'task_billing'], true), 404);
        app(DomainApiAuthorization::class)->authorize($request, 'ticket.view');
        $row = TicketTimeEntry::query()->with(['ticket', 'task', 'allocation'])->findOrFail($id);
        abort_unless((int) $row->ticket?->client_id === (int) $client->id, 404);
        app(TicketMutationScopeGuard::class)->assertReadable($row->ticket);
        if ($type === 'task_billing') {
            abort_unless($row->task_id, 404);
        }
        if ($row->task_id) {
            app(DomainApiAuthorization::class)->authorize($request, 'task.view');
            abort_unless($row->task, 404);
            app(TaskApiScope::class)->assert($row->task, $request->user());
        }
        $recordedOwner = \Illuminate\Support\Facades\Schema::hasTable('economy_source_owners')
            ? DB::table('economy_source_owners')->where('source_type', $row->getMorphClass())
                ->where('source_id', $row->id)->value('client_id') : null;
        abort_if($recordedOwner !== null && (int) $recordedOwner !== (int) $client->id, 409,
            'Recorded financial ownership differs from the current customer.');
        if ($row->allocation) {
            abort_unless((int) $row->allocation->client_id === (int) $client->id, 409, 'Recorded financial ownership differs from the current customer.');
        }

        return $row;
    }

    public function preview(Request $request, Client $client, string $kind, ?array $data = null): array
    {
        $this->authorize($request, $kind);
        $data ??= $this->input($request, $kind);
        $state = $kind === 'adjustment' ? $this->adjustment($request, $client, $data) : $this->reversal($request, $client, $data);
        $revision = hash('sha256', json_encode([$state, TimebankLedger::sequence(), EconomyMutex::revision()], JSON_THROW_ON_ERROR));
        $allowed = $state['blockers'] === [];
        $token = $allowed ? Crypt::encryptString(json_encode([
            'purpose' => 'timebank_'.$kind, 'actor' => $request->user()->id, 'client' => $client->id,
            'input' => $data, 'revision' => $revision, 'expires_at' => now()->timestamp + 600,
        ], JSON_THROW_ON_ERROR)) : null;

        return ['kind' => $kind, 'client_id' => $client->id, 'allowed' => $allowed,
            'preserved_employee_time' => true, 'revision' => $revision, 'preview_token' => $token,
            'expires_in_seconds' => 600] + $state;
    }

    private function adjustment(Request $request, Client $client, array $data): array
    {
        $item = ContractItem::with(['contract', 'service'])->findOrFail($data['contract_item_id']);
        abort_unless((int) $item->contract?->client_id === (int) $client->id, 404);
        abort_unless($item->service?->timebank_enabled, 422, 'Select a timebank contract line.');
        $date = Carbon::parse($data['effective_date']);
        abort_unless($date->gte(Carbon::parse($item->contract->start_date))
            && (! $item->contract->end_date || $date->lte(Carbon::parse($item->contract->end_date))), 422, 'Effective date must be within the contract.');
        abort_unless(in_array($item->contract->approval_status, ['approved', 'won'], true), 409, 'The contract must be approved.');
        $balance = app(CalculateClientTimebankBalances::class)->forContractItem($client, $item->contract, $item, $date, true);
        $target = match ($data['mode']) {
            'restore' => $balance['included_minutes'], 'zero' => 0, default => (int) $data['target_minutes']
        };
        $start = $balance['period_start']->toDateString();
        $end = $balance['period_end']->toDateString();
        $blockers = [];
        // Existing processed economics are never silently re-priced by a backdated adjustment.
        $affected = \App\Modules\Ticket\Models\TicketTimeEntryAllocation::query()
            ->where('contract_item_id', $item->id)->whereDate('period_start', $start)
            ->whereDate('period_end', $end)->whereHas('timeEntry', fn ($q) => $q->whereDate('work_date', '>', $data['effective_date']))->exists();
        $quick = TimebankLedger::excludeReversed(ClientContractTimeConsumption::query(), 'quick')
            ->where('contract_item_id', $item->id)->whereDate('period_start', $start)->whereDate('period_end', $end)
            ->whereDate('work_date', '>', $data['effective_date'])->exists();
        if ($affected || $quick) {
            $blockers[] = 'allocation_reconciliation_required';
        }
        $signed = $balance['signed_balance_minutes'] ?? ($balance['included_minutes'] - $balance['used_minutes']);
        abort_if(abs($target - $signed) > 2147483647, 422, 'The correction exceeds the supported minute range.');

        return ['contract_item_id' => $item->id, 'period_start' => $start, 'period_end' => $end,
            'effective_date' => $data['effective_date'], 'included_minutes' => $balance['included_minutes'],
            'before_signed_minutes' => $signed, 'after_signed_minutes' => $target,
            'adjustment_minutes' => $target - $signed, 'blockers' => $blockers,
            'dependency' => hash('sha256', json_encode([$item->toArray(), $item->service->toArray(), $balance['used_minutes']], JSON_THROW_ON_ERROR))];
    }

    private function reversal(Request $request, Client $client, array $data): array
    {
        $row = $this->source($request, $client, $data['source_type'], (int) $data['source_id']);
        $type = $data['source_type'] === 'quick' ? 'quick' : 'ticket';
        $blockers = TimebankLedger::reversal($type, $row->id) ? ['already_reversed'] : [];
        $lines = EconomyOrderLine::with('order')->where('source_type', $row->getMorphClass())->where('source_id', $row->id)->get();
        foreach ($lines as $line) {
            if ((int) $line->client_id !== (int) $client->id || $line->order?->status !== 'draft'
                || $line->status !== 'active') {
                $blockers[] = 'financial_correction_required';
            } elseif (! app(DomainApiAuthorization::class)->permits($request, 'economy.order_manage')) {
                $blockers[] = 'economy_correction_permission_required';
            }
        }
        $allocation = $type === 'ticket' ? $row->allocation : null;
        if ($lines->isNotEmpty() && $request->is('api/*') && ! $request->user()->tokenCan('economy.update')) {
            $blockers[] = 'economy_correction_scope_required';
        }
        if ($allocation && ((int) $allocation->covered_minutes + (int) $allocation->billable_minutes !== (int) $row->minutes
            || (int) $allocation->contract_item_id !== (int) $row->contract_item_id)) {
            $blockers[] = 'allocation_reconciliation_required';
        }
        if ($type === 'ticket' && $row->billing_status === 'covered' && ! $allocation) {
            $blockers[] = 'financial_correction_required';
        }
        if ($type === 'quick' && ($row->overused_minutes < 0 || $row->overused_minutes > $row->minutes)) {
            $blockers[] = 'allocation_reconciliation_required';
        }
        if ($type === 'ticket' && ! in_array($row->billing_status, ['pending', 'covered', 'queued'], true)) {
            $blockers[] = 'financial_correction_required';
        }
        if ($type === 'ticket' && ! $row->billable) {
            $blockers[] = 'not_a_customer_charge';
        }
        if ($type === 'ticket' && in_array($row->billing_status, ['queued', 'exported', 'invoiced'], true) && $lines->isEmpty()) {
            $blockers[] = 'financial_correction_required';
        }
        $isBank = $type === 'quick' || ($row->contract_item_id
            && ($row->contractItem?->service?->timebank_enabled || ($allocation?->included_minutes ?? 0) > 0));
        $usage = $isBank ? (int) $row->minutes : 0;
        $covered = $type === 'quick' ? max(0, $row->minutes - $row->overused_minutes) : (int) $allocation?->covered_minutes;
        $billable = $type === 'quick' ? (int) $row->overused_minutes : (int) ($allocation?->billable_minutes ?? $row->minutes);
        $start = $type === 'quick' ? $row->period_start?->toDateString() : $allocation?->period_start?->toDateString();
        $end = $type === 'quick' ? $row->period_end?->toDateString() : $allocation?->period_end?->toDateString();
        if ($usage && ! $start) {
            $item = $row->contractItem;
            abort_unless($item && (int) $item->contract?->client_id === (int) $client->id, 409, 'Timebank source ownership is inconsistent.');
            [$pStart, $pEnd] = app(CalculateClientTimebankBalances::class)->timebankPeriod($row->work_date, $item->contract, $item);
            $start = $pStart->toDateString();
            $end = $pEnd->toDateString();
        }

        return ['source_type' => $type, 'source_id' => $row->id, 'contract_item_id' => $row->contract_item_id,
            'period_start' => $start, 'period_end' => $end, 'effective_date' => $row->work_date->toDateString(),
            'reversed_usage_minutes' => $usage, 'reversed_covered_minutes' => $covered,
            'reversed_billable_minutes' => $billable, 'draft_line_ids' => $this->financeAllowed($request, true) ? $lines->modelKeys() : [],
            'blockers' => array_values(array_unique($blockers)),
            'dependency' => hash('sha256', json_encode([$row->toArray(), $lines->toArray()], JSON_THROW_ON_ERROR))];
    }

    public function apply(Request $request, Client $client, string $kind): array
    {
        $this->authorize($request, $kind);
        $data = $this->input($request, $kind);
        abort_unless($request->header('If-Match') && $request->has('preview_token'), 428, 'Supply If-Match and the reviewed preview_token.');
        $request->validate(['preview_token' => 'required|string|max:20000']);

        // Global Economy -> actor/Commercial receipt -> source/period. Never invert this order.
        return app(EconomyMutex::class)->run(function () use ($request, $client, $kind, $data) {
            return app(ExecuteCommercialCommand::class)->handle($request, function () use ($request, $client, $kind, $data) {
                $actor = \App\Models\Core\User::findOrFail($request->user()->id);
                $actor->withAccessToken($request->user()->currentAccessToken());
                $request->setUserResolver(fn () => $actor);
                $this->authorize($request, $kind);
                if ($kind === 'reversal') {
                    $source = $this->source($request, $client, $data['source_type'], (int) $data['source_id']);
                    if (EconomyOrderLine::where('source_type', $source->getMorphClass())->where('source_id', $source->id)->exists()) {
                        abort_unless($this->financeAllowed($request, true), 403, 'Current Economy correction authority is required, including command replay.');
                    }
                }
            }, function () use ($request, $client, $kind, $data) {
                try {
                    $token = json_decode(Crypt::decryptString($request->input('preview_token')), true, flags: JSON_THROW_ON_ERROR);
                } catch (\Illuminate\Contracts\Encryption\DecryptException|\JsonException $e) {
                    abort(409, 'Invalid correction preview.');
                }
                abort_unless(($token['purpose'] ?? null) === 'timebank_'.$kind
                    && ($token['actor'] ?? null) === $request->user()->id
                    && ($token['client'] ?? null) === $client->id
                    && ($token['input'] ?? null) === $data
                    && ($token['expires_at'] ?? 0) >= now()->timestamp, 409, 'Expired or mismatched correction preview.');
                $preview = $this->preview($request, $client, $kind, $data);
                abort_unless(hash_equals($preview['revision'], (string) ($token['revision'] ?? ''))
                    && hash_equals($preview['revision'], trim($request->header('If-Match'), '"')), 409, 'Correction dependencies changed; preview again.');
                abort_unless($preview['allowed'], 409, implode(', ', $preview['blockers']));
                $evidence = [];
                if ($kind === 'reversal') {
                    $source = $this->source($request, $client, $data['source_type'], (int) $data['source_id']);
                    $evidence['source'] = $source->attributesToArray();
                    if ($source instanceof TicketTimeEntry) {
                        $evidence['allocation'] = $source->allocation?->attributesToArray();
                    }
                    $evidence['order_lines'] = EconomyOrderLine::with('order')->whereIn('id', $preview['draft_line_ids'])->get()->toArray();
                }
                unset($preview['preview_token'], $preview['dependency']);
                $correction = TimebankCorrection::create([
                    'client_id' => $client->id, 'actor_id' => $request->user()->id, 'kind' => $kind,
                    'source_type' => $kind === 'reversal' ? $preview['source_type'] : null,
                    'source_id' => $kind === 'reversal' ? $preview['source_id'] : null,
                    'quick_consumption_id' => $kind === 'reversal' && $preview['source_type'] === 'quick' ? $preview['source_id'] : null,
                    'ticket_time_entry_id' => $kind === 'reversal' && $preview['source_type'] === 'ticket' ? $preview['source_id'] : null,
                    'reversal_key' => $kind === 'reversal' ? $preview['source_type'].':'.$preview['source_id'] : null,
                    'reason' => $data['reason'], 'effective_date' => $preview['effective_date'],
                    'snapshot' => ['result' => $preview, 'evidence' => $evidence], 'created_at' => now(),
                ]);
                $correction->effects()->create([
                    'contract_item_id' => $preview['contract_item_id'], 'period_start' => $preview['period_start'],
                    'period_end' => $preview['period_end'], 'adjustment_minutes' => $preview['adjustment_minutes'] ?? 0,
                    'reversed_usage_minutes' => $preview['reversed_usage_minutes'] ?? 0,
                    'reversed_covered_minutes' => $preview['reversed_covered_minutes'] ?? 0,
                    'reversed_billable_minutes' => $preview['reversed_billable_minutes'] ?? 0,
                ]);
                if ($kind === 'reversal') {
                    app(\App\Modules\Economy\Actions\ReverseCustomerTimeCharge::class)->handle($correction);
                }

                return ['data' => $this->read($request, $client, $correction)];
            });
        });
    }

    public function read(Request $request, Client $client, TimebankCorrection $correction): array
    {
        $this->authorize($request);
        abort_unless((int) $correction->client_id === (int) $client->id, 404);
        if ($correction->kind === 'reversal') {
            $this->source($request, $client, $correction->source_type, (int) $correction->source_id);
        }

        $reviewedResult = $correction->snapshot['result'];
        if (isset($reviewedResult['draft_line_ids']) && ! $this->financeAllowed($request, false)) {
            $reviewedResult['draft_line_ids'] = [];
        }

        return $correction->only(['id', 'client_id', 'actor_id', 'kind', 'source_type', 'source_id', 'reason',
            'effective_date', 'created_at']) + ['preserved_employee_time' => true,
                'effects' => $correction->effects()->get()->toArray(),
                'result' => $reviewedResult,
                'financial_outcome' => $correction->kind === 'reversal' ? 'local_customer_charge_reversed' : 'balance_adjusted'];
    }

    /** Only authorized source rows contribute to history totals/pagination. */
    public function history(Request $request, Client $client): \Illuminate\Support\Collection
    {
        $this->authorize($request);
        $filters = $request->validate(['date_from' => 'sometimes|date_format:Y-m-d',
            'date_to' => 'sometimes|date_format:Y-m-d|after_or_equal:date_from',
            'source_type' => 'sometimes|in:quick,ticket,adjustment,reversal',
            'contract_item_id' => 'sometimes|integer|min:1']);
        $rows = collect();
        foreach (['quick' => ClientContractTimeConsumption::class, 'ticket' => TicketTimeEntry::class] as $type => $class) {
            if ($type === 'ticket' && ! app(DomainApiAuthorization::class)->permits($request, 'ticket.view')) {
                continue;
            }
            $query = $type === 'quick' ? $class::where('client_id', $client->id)
                : $class::whereHas('ticket', fn ($q) => $q->where('client_id', $client->id));
            $query->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('work_date', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('work_date', '<=', $date))
                ->when($filters['contract_item_id'] ?? null, fn ($q, $id) => $q->where('contract_item_id', $id));
            $sources = $query->limit(10001)->get();
            abort_if($sources->count() > 10000, 422, 'Select a narrower history period.');
            foreach ($sources as $row) {
                try {
                    $this->source($request, $client, $type, $row->id);
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                    if (in_array($e->getStatusCode(), [403, 404], true)) {
                        continue;
                    }
                    throw $e;
                }
                $reversed = \App\Modules\Commercial\Support\TimebankLedger::reversal($type, $row->id);
                $rows->push(['kind' => 'charge', 'source_type' => $type, 'source_id' => $row->id,
                    'contract_item_id' => $row->contract_item_id, 'effective_date' => $row->work_date->toDateString(),
                    'recorded_at' => $row->created_at?->toIso8601String(), 'minutes' => (int) $row->minutes,
                    'reversed' => (bool) $reversed, 'correction_id' => $reversed?->id]);
            }
        }
        $corrections = TimebankCorrection::where('client_id', $client->id)->with('effects')
            ->when($filters['date_from'] ?? null, fn ($q, $date) => $q->whereDate('effective_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($q, $date) => $q->whereDate('effective_date', '<=', $date))
            ->limit(10001)->orderBy('id')->get();
        abort_if($corrections->count() > 10000, 422, 'Select a narrower history period.');
        foreach ($corrections as $correction) {
            try {
                $detail = $this->read($request, $client, $correction);
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if (in_array($e->getStatusCode(), [403, 404], true)) {
                    continue;
                }
                throw $e;
            }
            $rows->push(['kind' => $correction->kind, 'source_type' => $correction->source_type,
                'source_id' => $correction->source_id, 'contract_item_id' => $correction->effects->first()?->contract_item_id,
                'effective_date' => $correction->effective_date->toDateString(),
                'recorded_at' => $correction->created_at->toIso8601String(), 'correction_id' => $correction->id,
                'reason' => $correction->reason, 'detail' => $detail]);
        }

        return $rows->filter(fn ($r) => (! isset($filters['date_from']) || $r['effective_date'] >= $filters['date_from'])
            && (! isset($filters['date_to']) || $r['effective_date'] <= $filters['date_to'])
            && (! isset($filters['source_type']) || $r['source_type'] === $filters['source_type'] || $r['kind'] === $filters['source_type'])
            && (! isset($filters['contract_item_id']) || (int) $r['contract_item_id'] === (int) $filters['contract_item_id']))
            ->sortBy([['effective_date', 'desc'], ['recorded_at', 'desc'], ['source_id', 'desc'], ['source_type', 'asc'], ['kind', 'asc'], ['correction_id', 'desc']])->values();
    }
}
