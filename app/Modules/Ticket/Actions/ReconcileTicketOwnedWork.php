<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Clients\ClientSite;
use App\Models\Core\User;
use App\Modules\Storage\Models\Reservation;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskActivity;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Queries\TicketTimeRateOptions;
use App\Modules\WorkContext\Actions\ResolveWorkContext;

/** Reconcile mutable ownership while preserving booked financial evidence and actual-time history. */
final class ReconcileTicketOwnedWork
{
    public function handle(int $sourceTicketId, Ticket $target, ?User $actor, bool $customerChanged): void
    {
        $contextId = $target->work_context_id ?: app(ResolveWorkContext::class)->fromClientId($target->client_id)->id;
        if (! $target->work_context_id) {
            $target->forceFill(['work_context_id' => $contextId])->save();
        }
        $tasks = Task::query()->where('owner_type', $target->getMorphClass())->where('owner_id', $sourceTicketId)->orderBy('id')->lockForUpdate()->get();
        $rates = null;
        foreach ($tasks as $task) {
            $before = $task->only(['owner_id', 'client_id', 'work_context_id', 'site_id']);
            $siteId = $task->site_id;
            if ($siteId && (! $target->client_id || ! ClientSite::query()->whereKey($siteId)->where('client_id', $target->client_id)->exists())) {
                $siteId = $target->site_id;
            }
            $metadata = $task->metadata ?? [];
            if ($customerChanged && isset($metadata['ticket_rate_key'])) {
                $rates ??= app(TicketTimeRateOptions::class)->forTicket($target);
                if (! collect($rates)->contains('key', $metadata['ticket_rate_key'])) {
                    unset($metadata['ticket_rate_key'], $metadata['ticket_rate_label']);
                }
            }
            $task->forceFill(['owner_id' => $target->id, 'client_id' => $target->client_id, 'work_context_id' => $contextId, 'site_id' => $siteId, 'metadata' => $metadata])->save();
            $after = $task->only(['owner_id', 'client_id', 'work_context_id', 'site_id']);
            if ($before !== $after) {
                TaskActivity::create(['task_id' => $task->id, 'user_id' => $actor?->id, 'type' => 'owner_context_changed', 'visibility' => Task::VISIBILITY_INTERNAL,
                    'body' => 'Ticket ownership context changed.', 'changes' => ['before' => $before, 'after' => $after]]);
            }
        }
        $reservationIds = TicketCostEntry::query()->where('ticket_id', $sourceTicketId)->whereNotNull('storage_reservation_id')->pluck('storage_reservation_id');
        Reservation::query()->whereIn('id', $reservationIds)->where('source_type', 'ticket')->where('source_id', (string) $sourceTicketId)
            ->where('status', 'active')->update(['source_id' => (string) $target->id]);
        if (! $customerChanged) {
            return;
        }
        $entries = TicketTimeEntry::query()->where('ticket_id', $sourceTicketId)->where('billing_status', 'pending')->whereDoesntHave('allocation')
            ->where(fn ($q) => $q->whereNotNull('contract_id')->orWhereNotNull('contract_item_id')->orWhereNotNull('contract_item_time_rate_id'))->orderBy('id')->lockForUpdate()->get();
        foreach ($entries as $entry) {
            if ($entry->contract_id && $target->client_id && (int) $entry->contract?->client_id === (int) $target->client_id) {
                continue;
            }
            $before = $entry->only(['contract_id', 'contract_item_id', 'contract_item_time_rate_id', 'billing_basis']);
            $entry->forceFill(['contract_id' => null, 'contract_item_id' => null, 'contract_item_time_rate_id' => null, 'billing_basis' => 'without_contract'])->save();
            TicketEvent::create(['ticket_id' => $target->id, 'actor_id' => $actor?->id, 'type' => 'billing_context_reconciled',
                'message' => 'Pending time retained its recorded rate; previous-customer contract references were detached.',
                'before' => $before, 'after' => $entry->only(['contract_id', 'contract_item_id', 'contract_item_time_rate_id', 'billing_basis']),
                'metadata' => ['time_entry_id' => $entry->id, 'source_ticket_id' => $sourceTicketId]]);
        }
    }
}
