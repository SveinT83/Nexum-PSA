<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Models\TicketEmailOutboundEvent;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProjectTicketEmailOutboundSubmission
{
    public function handle(EmailOutboundSubmission $submission, User $actor): ?TicketEmailOutboundCommunication
    {
        $reference = TicketEmailOutboundCommunication::query()
            ->where('email_composer_draft_id', $submission->email_composer_draft_id)
            ->first();
        if (! $reference) {
            return null;
        }

        return DB::transaction(function () use ($actor, $reference, $submission): TicketEmailOutboundCommunication {
            $communication = TicketEmailOutboundCommunication::query()
                ->with(['ticket', 'draft.attachments'])
                ->whereKey($reference->id)
                ->lockForUpdate()
                ->firstOrFail();
            $submission = EmailOutboundSubmission::query()->whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $state = match ($submission->status) {
                EmailOutboundSubmission::STATUS_ACCEPTED => TicketEmailOutboundCommunication::STATE_ACCEPTED,
                EmailOutboundSubmission::STATUS_SENT_RECONCILED => TicketEmailOutboundCommunication::STATE_RECONCILED,
                EmailOutboundSubmission::STATUS_OUTCOME_UNRESOLVED,
                EmailOutboundSubmission::STATUS_PROVIDER_WRITE_STARTED => TicketEmailOutboundCommunication::STATE_UNRESOLVED,
                EmailOutboundSubmission::STATUS_PROVIDER_NOT_ATTEMPTED => TicketEmailOutboundCommunication::STATE_FAILED_PRE_SEND,
                default => TicketEmailOutboundCommunication::STATE_RESERVED,
            };
            $previousState = $communication->state;
            $communication->forceFill([
                'email_outbound_submission_id' => $submission->id,
                'state' => $state,
                'safe_reason_code' => $submission->reason_code,
                'reserved_at' => $communication->reserved_at ?: $submission->created_at,
                'accepted_at' => in_array($state, [TicketEmailOutboundCommunication::STATE_ACCEPTED, TicketEmailOutboundCommunication::STATE_RECONCILED], true)
                    ? ($communication->accepted_at ?: $submission->accepted_at ?: now())
                    : $communication->accepted_at,
                'reconciled_at' => $state === TicketEmailOutboundCommunication::STATE_RECONCILED ? now() : null,
                'version' => (int) $communication->version + 1,
            ])->save();

            if (in_array($state, [
                TicketEmailOutboundCommunication::STATE_ACCEPTED,
                TicketEmailOutboundCommunication::STATE_UNRESOLVED,
                TicketEmailOutboundCommunication::STATE_RECONCILED,
            ], true) && ! $communication->ticket_message_id) {
                $message = app(AddTicketMessage::class)->handle($communication->ticket, [
                    'type' => 'customer_reply',
                    'visibility' => $communication->audience === 'customer' ? 'public' : 'internal',
                    'body' => $communication->draft->body_text ?: strip_tags((string) $communication->draft->body_html),
                    'metadata' => [
                        'ticket_email_outbound_communication_id' => $communication->id,
                        'email_outbound_submission_id' => $submission->id,
                        'email_delivery_state' => $state,
                    ],
                    'idempotency_key' => 'ticket-email-communication:'.$communication->public_id,
                    'idempotency_fingerprint' => hash('sha256', $communication->public_id.':'.$communication->draft->generation_id),
                    '_suppress_reply_delivery' => true,
                    '_suppress_assignment_claim' => true,
                ], $actor);
                $this->copyAttachments($communication, $message, $actor);
                $communication->forceFill(['ticket_message_id' => $message->id])->save();
            } elseif ($communication->ticket_message_id) {
                $message = TicketMessage::query()->find($communication->ticket_message_id);
                if ($message) {
                    $metadata = $message->metadata ?? [];
                    $metadata['email_delivery_state'] = $state;
                    $metadata['email_outbound_submission_id'] = $submission->id;
                    $message->forceFill(['metadata' => $metadata])->save();
                }
            }

            if ($previousState !== $state) {
                TicketEmailOutboundEvent::query()->create([
                    'ticket_email_outbound_communication_id' => $communication->id,
                    'event_type' => $state,
                    'actor_id' => $actor->id,
                    'safe_reason_code' => $submission->reason_code,
                    'metadata' => [
                        'submission_id' => $submission->id,
                        'ticket_message_id' => $communication->ticket_message_id,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            return $communication->fresh(['ticketMessage', 'submission']);
        });
    }

    private function copyAttachments(TicketEmailOutboundCommunication $communication, TicketMessage $message, User $actor): void
    {
        foreach ($communication->draft->attachments as $attachment) {
            $disk = $attachment->disk ?: 'local';
            if (! $attachment->path || ! Storage::disk($disk)->exists($attachment->path)) {
                continue;
            }
            $content = Storage::disk($disk)->get($attachment->path);
            app(StoreTicketAttachment::class)->fromContent(
                $message,
                $attachment->filename,
                $content,
                $attachment->content_type ?: 'application/octet-stream',
                $actor,
                'email_draft',
            );
        }
    }
}
