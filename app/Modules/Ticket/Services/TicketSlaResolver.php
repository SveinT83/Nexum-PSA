<?php

namespace App\Modules\Ticket\Services;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Commercial\Models\Sla\Sla;
use App\Modules\Ticket\Models\TicketPriority;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class TicketSlaResolver
{
    /**
     * Resolve the SLA policy and due dates for a ticket creation context.
     *
     * SLA selection is intentionally kept in Ticket because the resulting timers are ticket-owned,
     * even though SLA policies and contract bindings are maintained by Commercial.
     *
     * @return array{sla_id: int|null, sla_source: string|null, sla_source_id: int|null, sla_snapshot: array|null, first_response_due_at: Carbon|null, resolve_due_at: Carbon|null}
     */
    public function resolve(array $context, TicketPriority $priority, ?Carbon $createdAt = null): array
    {
        $createdAt ??= now();
        $resolution = $this->policyFromRule($context)
            ?? $this->policyFromContract($context)
            ?? $this->defaultPolicy();

        if (! $resolution) {
            return $this->emptyResolution();
        }

        $sla = $resolution['sla'];
        $priorityBand = $this->priorityBand($priority);
        $snapshot = $this->snapshot($sla, $priorityBand);

        return [
            'sla_id' => $sla->id,
            'sla_source' => $resolution['source'],
            'sla_source_id' => $resolution['source_id'],
            'sla_snapshot' => $snapshot,
            'first_response_due_at' => $this->addInterval($createdAt, $snapshot['first_response_value'], $snapshot['first_response_unit']),
            'resolve_due_at' => $this->addInterval($createdAt, $snapshot['resolve_value'], $snapshot['resolve_unit']),
        ];
    }

    /**
     * @return array{sla: Sla, source: string, source_id: int|null}|null
     */
    private function policyFromRule(array $context): ?array
    {
        $slaId = data_get($context, 'sla_id');

        if (! is_numeric($slaId)) {
            return null;
        }

        $sla = Sla::query()->find((int) $slaId);

        return $sla ? ['sla' => $sla, 'source' => 'ticket_rule', 'source_id' => (int) $slaId] : null;
    }

    /**
     * @return array{sla: Sla, source: string, source_id: int|null}|null
     */
    private function policyFromContract(array $context): ?array
    {
        if (! Schema::hasColumn('contracts', 'sla_id')) {
            return null;
        }

        $clientId = data_get($context, 'client_id');

        if (! is_numeric($clientId)) {
            return null;
        }

        $contract = Contracts::query()
            ->where('client_id', (int) $clientId)
            ->whereNotNull('sla_id')
            ->whereIn('approval_status', ['approved', 'sent_contract', 'won'])
            ->whereDate('start_date', '<=', now()->toDateString())
            ->where(function ($query) {
                $query->whereNull('end_date')
                    ->orWhereDate('end_date', '>=', now()->toDateString());
            })
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();

        return $contract?->sla
            ? ['sla' => $contract->sla, 'source' => 'contract', 'source_id' => $contract->id]
            : null;
    }

    /**
     * @return array{sla: Sla, source: string, source_id: int|null}|null
     */
    private function defaultPolicy(): ?array
    {
        $query = Sla::query();

        $sla = Schema::hasColumn('sla', 'is_default')
            ? (clone $query)->where('is_default', true)->orderBy('id')->first()
            : null;

        $sla ??= (clone $query)->where('name', 'Default')->orderBy('id')->first()
            ?? Sla::query()->orderBy('id')->first();

        return $sla ? ['sla' => $sla, 'source' => 'default', 'source_id' => $sla->id] : null;
    }

    private function priorityBand(TicketPriority $priority): string
    {
        $slug = (string) $priority->slug;

        if (in_array($slug, ['critical', 'high'], true) || $priority->level <= 2) {
            return 'high';
        }

        if (in_array($slug, ['low'], true) || $priority->level >= 4) {
            return 'low';
        }

        return 'medium';
    }

    /**
     * @return array{name: string, priority_band: string, first_response_value: int, first_response_unit: string, resolve_value: int, resolve_unit: string}
     */
    private function snapshot(Sla $sla, string $priorityBand): array
    {
        return [
            'name' => $sla->name,
            'priority_band' => $priorityBand,
            'first_response_value' => (int) $sla->{$priorityBand.'_firstResponse'},
            'first_response_unit' => (string) $sla->{$priorityBand.'_firstResponse_type'},
            // The current SLA model stores the second target as onsite. Ticket uses it as resolve target for v1.
            'resolve_value' => (int) $sla->{$priorityBand.'_onsite'},
            'resolve_unit' => (string) $sla->{$priorityBand.'_onsite_type'},
        ];
    }

    private function addInterval(Carbon $from, int $value, string $unit): Carbon
    {
        $base = CarbonImmutable::instance($from);

        return Carbon::instance(match ($unit) {
            'minute', 'minutes' => $base->addMinutes($value),
            'day', 'days' => $base->addDays($value),
            'week', 'weeks' => $base->addWeeks($value),
            default => $base->addHours($value),
        });
    }

    /**
     * @return array{sla_id: null, sla_source: null, sla_source_id: null, sla_snapshot: null, first_response_due_at: null, resolve_due_at: null}
     */
    private function emptyResolution(): array
    {
        return [
            'sla_id' => null,
            'sla_source' => null,
            'sla_source_id' => null,
            'sla_snapshot' => null,
            'first_response_due_at' => null,
            'resolve_due_at' => null,
        ];
    }
}
