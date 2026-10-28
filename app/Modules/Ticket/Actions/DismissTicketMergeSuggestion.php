<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMergeSuggestionDismissal;
use Illuminate\Support\Facades\DB;

/** A persisted pair dismissal is an ordinary repeatable state update. */
final class DismissTicketMergeSuggestion
{
    public function handle(array $ids, ?User $actor, ?string $reason = null): void
    {
        DB::transaction(function () use ($ids, $actor, $reason) {
            $ids = collect($ids)->map('intval')->unique()->sort()->values();
            $tickets = Ticket::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($tickets->count() === $ids->count(), 404);
            for ($left = 0; $left < $tickets->count(); $left++) {
                for ($right = $left + 1; $right < $tickets->count(); $right++) {
                    TicketMergeSuggestionDismissal::updateOrCreate(TicketMergeSuggestionDismissal::pairIds($tickets[$left], $tickets[$right]),
                        ['dismissed_by' => $actor?->id, 'reason' => $reason ?? 'Dismissed from ticket list.']);
                }
            }
        });
    }
}
