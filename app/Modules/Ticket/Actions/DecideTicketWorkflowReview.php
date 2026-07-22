<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketWorkflowEvidenceFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DecideTicketWorkflowReview
{
    public function __construct(
        private readonly TicketWorkflowEvidenceFingerprint $fingerprint,
        private readonly TicketActionGuard $guard,
    ) {}

    public function handle(TicketWorkflowReview $review, string $decision, ?string $comment, User $actor): TicketWorkflowReview
    {
        if (! $actor->can('ticket.review_senior')) {
            throw ValidationException::withMessages(['review' => 'Senior review permission is required.']);
        }

        $review->loadMissing('ticket');
        if ($reason = $this->guard->reason($review->ticket, TicketAction::SENIOR_REVIEW, $actor)) {
            throw ValidationException::withMessages(['review' => $reason]);
        }

        if (! in_array($decision, ['approved', 'sent_back'], true)) {
            throw ValidationException::withMessages(['decision' => 'Review decision must be approved or sent_back.']);
        }

        if ($decision === 'sent_back' && blank($comment)) {
            throw ValidationException::withMessages(['comment' => 'A comment is required when sending work back.']);
        }

        $decided = DB::transaction(function () use ($review, $decision, $comment, $actor): TicketWorkflowReview {
            $locked = TicketWorkflowReview::query()->lockForUpdate()->with('ticket')->findOrFail($review->id);

            if ($locked->status !== 'pending' || $locked->invalidated_at) {
                throw ValidationException::withMessages(['review' => 'This review request is no longer pending.']);
            }

            if ($locked->assigned_reviewer_id && (int) $locked->assigned_reviewer_id !== (int) $actor->id) {
                throw ValidationException::withMessages(['review' => 'This review request is assigned to another senior technician.']);
            }

            if (($locked->metadata['separation_of_duties'] ?? true)
                && (in_array((int) $actor->id, [(int) $locked->requested_by, (int) $locked->ticket->owner_id], true))) {
                throw ValidationException::withMessages(['review' => 'Separation of duties prevents reviewing your own or your currently owned work.']);
            }

            $currentFingerprint = $this->fingerprint->forTicket($locked->ticket);
            if (! hash_equals($locked->evidence_fingerprint, $currentFingerprint)) {
                $locked->forceFill([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'Material Ticket evidence changed after the review request.',
                ])->save();

                throw ValidationException::withMessages(['review' => 'Ticket evidence changed. Request a new senior review.']);
            }

            $locked->forceFill([
                'status' => $decision,
                'reviewed_by' => $actor->id,
                'comment' => $comment,
                'decided_at' => now(),
            ])->save();

            TicketEvent::query()->create([
                'ticket_id' => $locked->ticket_id,
                'actor_id' => $actor->id,
                'type' => $decision === 'approved' ? 'senior_review_approved' : 'senior_review_sent_back',
                'message' => $decision === 'approved' ? 'Senior review approved.' : 'Senior review sent back.',
                'after' => ['review_id' => $locked->id, 'gate_key' => $locked->gate_key, 'comment' => $comment],
            ]);

            return $locked->refresh();
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($decided->ticket->refresh(), TicketAction::SENIOR_REVIEW, $actor);

        return $decided;
    }
}
