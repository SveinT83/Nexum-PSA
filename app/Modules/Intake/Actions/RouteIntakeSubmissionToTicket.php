<?php

namespace App\Modules\Intake\Actions;

use App\Models\Core\User;
use App\Modules\Intake\Models\IntakeForm;
use App\Modules\Intake\Models\IntakeSubmission;
use App\Modules\Intake\Support\IntakeSubmissionTargetPayload;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Support\Facades\DB;

class RouteIntakeSubmissionToTicket
{
    public function __construct(
        private readonly StoreTicket $storeTicket,
        private readonly IntakeSubmissionTargetPayload $payload,
    ) {}

    public function handle(IntakeSubmission $submission, bool $force = false, ?User $actor = null): ?Ticket
    {
        $submission->loadMissing(['form', 'attachments.field', 'matchedClient', 'matchedSite', 'matchedClientUser']);

        if (! $this->canRoute($submission, $force)) {
            return $this->existingTarget($submission);
        }

        return DB::transaction(function () use ($submission, $actor): Ticket {
            $form = $submission->form;
            $metadata = $this->payload->metadata($submission, IntakeForm::TARGET_TICKET);

            $ticket = $this->storeTicket->handle([
                'channel' => 'intake',
                'client_id' => $submission->matched_client_id,
                'site_id' => $submission->matched_site_id,
                'contact_id' => $submission->matched_client_user_id,
                'owner_id' => $form?->owner_id,
                'subject' => $this->payload->title($submission),
                'description' => $this->payload->description($submission),
            ], $actor);

            $ticket->forceFill([
                'metadata' => array_replace($ticket->metadata ?: [], $metadata),
                'is_unread' => true,
            ])->save();

            $result = $metadata + [
                'action' => 'ticket_created',
                'ticket_id' => $ticket->id,
                'ticket_key' => $ticket->ticket_key,
                'client_id' => $ticket->client_id,
            ];

            $submission->forceFill([
                'status' => IntakeSubmission::STATUS_ROUTED,
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'routing_result' => $result,
            ])->save();

            $submission->events()->create([
                'actor_id' => $actor?->id,
                'type' => 'routed_to_ticket',
                'message' => 'Created ticket '.$ticket->ticket_key.'.',
                'metadata' => $result,
            ]);

            return $ticket->fresh();
        });
    }

    private function canRoute(IntakeSubmission $submission, bool $force): bool
    {
        if ($submission->isClosedForRouting()) {
            return false;
        }

        if ($submission->target_type === Ticket::class && $submission->target_id) {
            return false;
        }

        if ($submission->hasTarget()) {
            return false;
        }

        return $force || $submission->form?->target_type === IntakeForm::TARGET_TICKET;
    }

    private function existingTarget(IntakeSubmission $submission): ?Ticket
    {
        if ($submission->target_type === Ticket::class && $submission->target_id) {
            return Ticket::query()->find($submission->target_id);
        }

        return null;
    }

}
