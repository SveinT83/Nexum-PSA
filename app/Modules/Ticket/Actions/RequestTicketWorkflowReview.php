<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketWorkflowEvidenceFingerprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RequestTicketWorkflowReview
{
    public function __construct(
        private readonly TicketActionGuard $guard,
        private readonly TicketWorkflowEvidenceFingerprint $fingerprint,
    ) {}

    public function handle(Ticket $ticket, array $data, User $actor): TicketWorkflowReview
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::REQUEST_SENIOR_REVIEW, $actor)) {
            throw ValidationException::withMessages(['review' => $reason]);
        }

        $review = DB::transaction(function () use ($ticket, $data, $actor): TicketWorkflowReview {
            $gateKey = (string) ($data['gate_key'] ?? 'senior-review');
            $fingerprint = $this->fingerprint->forTicket($ticket);

            $ticket->workflowReviews()
                ->where('gate_key', $gateKey)
                ->where('status', 'pending')
                ->whereNull('invalidated_at')
                ->update([
                    'status' => 'invalidated',
                    'invalidated_at' => now(),
                    'invalidation_reason' => 'A newer review request replaced this request.',
                    'updated_at' => now(),
                ]);

            if (! empty($data['assigned_reviewer_id'])) {
                $reviewer = User::query()->where('status', User::STATUS_ACTIVE)->findOrFail((int) $data['assigned_reviewer_id']);

                if (! $reviewer->can('ticket.review_senior')) {
                    throw ValidationException::withMessages(['assigned_reviewer_id' => 'The selected user is not eligible for senior review.']);
                }

                if ((int) $reviewer->id === (int) $actor->id) {
                    throw ValidationException::withMessages(['assigned_reviewer_id' => 'The requestor cannot review their own checkpoint.']);
                }
            }

            $review = $ticket->workflowReviews()->create([
                'workflow_version_id' => $ticket->workflow_version_id,
                'state_key' => $ticket->workflow_state_key ?: 'legacy',
                'gate_key' => $gateKey,
                'status' => 'pending',
                'evidence_fingerprint' => $fingerprint,
                'requirements_snapshot' => $data['requirements_snapshot'] ?? null,
                'requested_by' => $actor->id,
                'assigned_reviewer_id' => $data['assigned_reviewer_id'] ?? null,
                'comment' => $data['comment'] ?? null,
                'metadata' => [
                    'separation_of_duties' => (bool) ($data['separation_of_duties'] ?? true),
                ],
            ]);

            TicketEvent::query()->create([
                'ticket_id' => $ticket->id,
                'actor_id' => $actor->id,
                'type' => 'senior_review_requested',
                'message' => 'Senior review requested for '.$gateKey.'.',
                'after' => ['review_id' => $review->id, 'gate_key' => $gateKey],
            ]);

            return $review;
        });

        app(ApplyTicketWorkflowActionTrigger::class)->handle($ticket->refresh(), TicketAction::REQUEST_SENIOR_REVIEW, $actor);

        return $review;
    }
}
