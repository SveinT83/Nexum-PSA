<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;

class InvalidateTicketWorkflowReviews
{
    public function handle(Ticket $ticket, string $reason, ?User $actor = null): int
    {
        $reviews = $ticket->workflowReviews()
            ->whereIn('status', ['pending', 'approved'])
            ->whereNull('invalidated_at')
            ->get();

        foreach ($reviews as $review) {
            $review->forceFill([
                'status' => 'invalidated',
                'invalidated_at' => now(),
                'invalidation_reason' => $reason,
            ])->save();
        }

        if ($reviews->isNotEmpty()) {
            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor?->id,
                'type' => 'workflow_reviews_invalidated',
                'message' => $reason,
                'after' => ['review_ids' => $reviews->pluck('id')->all()],
            ]);
        }

        return $reviews->count();
    }
}
