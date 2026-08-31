<?php

namespace App\Modules\Ticket\Actions;

use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Models\TicketEmailOutboundEvent;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Support\Facades\DB;

class ProjectTicketEmailSentReconciliation
{
    public function handle(int $submissionId): ?TicketEmailOutboundCommunication
    {
        $submission = EmailOutboundSubmission::query()->with('sentReconciliation')->find($submissionId);
        if (! $submission || $submission->status !== EmailOutboundSubmission::STATUS_SENT_RECONCILED) {
            return null;
        }
        $reference = TicketEmailOutboundCommunication::query()
            ->where('email_outbound_submission_id', $submission->id)
            ->first();
        if (! $reference) {
            return null;
        }

        return DB::transaction(function () use ($reference, $submission): TicketEmailOutboundCommunication {
            $communication = TicketEmailOutboundCommunication::query()
                ->with(['relationship', 'ticketMessage'])
                ->whereKey($reference->id)
                ->lockForUpdate()
                ->firstOrFail();
            $reconciliation = $submission->sentReconciliation;
            if (! $reconciliation?->sent_email_message_id || ! $reconciliation->sent_email_mailbox_placement_id) {
                return $communication;
            }
            $changed = $communication->state !== TicketEmailOutboundCommunication::STATE_RECONCILED;
            $communication->forceFill([
                'state' => TicketEmailOutboundCommunication::STATE_RECONCILED,
                'reconciled_sent_email_message_id' => $reconciliation->sent_email_message_id,
                'reconciled_sent_email_mailbox_placement_id' => $reconciliation->sent_email_mailbox_placement_id,
                'safe_reason_code' => null,
                'reconciled_at' => $communication->reconciled_at ?: now(),
                'version' => (int) $communication->version + 1,
            ])->save();

            EmailTicketConversationLink::query()->firstOrCreate([
                'ticket_id' => $communication->ticket_id,
                'email_message_id' => $reconciliation->sent_email_message_id,
                'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            ], [
                'email_mailbox_placement_id' => $reconciliation->sent_email_mailbox_placement_id,
                'account_id' => $communication->email_account_id,
                'email_conversation_id' => $communication->email_conversation_id,
                'linked_by' => $communication->actor_id,
                'conversation_key' => $communication->relationship->conversation_key,
                'relationship_role' => $communication->relationship->relationship_role,
                'audience' => $communication->audience,
                'metadata' => ['ticket_email_outbound_communication_id' => $communication->id],
                'linked_at' => now(),
            ]);
            if ($communication->ticketMessage) {
                $metadata = $communication->ticketMessage->metadata ?? [];
                $metadata['email_delivery_state'] = TicketEmailOutboundCommunication::STATE_RECONCILED;
                $metadata['sent_email_message_id'] = $reconciliation->sent_email_message_id;
                $communication->ticketMessage->forceFill(['metadata' => $metadata])->save();
            }
            if ($changed) {
                TicketEmailOutboundEvent::query()->create([
                    'ticket_email_outbound_communication_id' => $communication->id,
                    'event_type' => 'reconciled',
                    'actor_id' => null,
                    'metadata' => [
                        'submission_id' => $submission->id,
                        'sent_message_id' => $reconciliation->sent_email_message_id,
                        'sent_placement_id' => $reconciliation->sent_email_mailbox_placement_id,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            return $communication->fresh();
        });
    }
}
