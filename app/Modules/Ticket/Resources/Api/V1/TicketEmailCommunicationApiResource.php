<?php

namespace App\Modules\Ticket\Resources\Api\V1;

use App\Modules\Email\Models\EmailComposerDraft;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Draft, provider acceptance and Sent reconciliation remain separate persisted states. */
class TicketEmailCommunicationApiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $shared = $this->draft?->scope === EmailComposerDraft::SCOPE_SHARED;
        $draftRoute = $shared ? 'api.v1.email.mailbox.shared-drafts' : 'api.v1.email.mailbox.drafts';

        return ['id' => $this->id, 'public_id' => $this->public_id, 'ticket_id' => $this->ticket_id, 'relationship_id' => $this->email_ticket_conversation_link_id,
            'email_account_id' => $this->email_account_id, 'email_conversation_id' => $this->email_conversation_id, 'source_placement_id' => $this->source_email_mailbox_placement_id,
            'draft_id' => $this->email_composer_draft_id, 'draft_public_id' => $this->draft?->public_id, 'draft_scope' => $this->draft?->scope, 'draft_version' => $this->draft?->version,
            'submission_id' => $this->email_outbound_submission_id, 'submission_public_id' => $this->submission?->public_id, 'operation_kind' => $this->operation_kind, 'audience' => $this->audience, 'state' => $this->state, 'version' => (int) $this->version,
            'safe_reason_code' => $this->safe_reason_code, 'accepted_at' => $this->accepted_at?->toISOString(), 'reconciled_at' => $this->reconciled_at?->toISOString(),
            'links' => ['self' => route('api.v1.tickets.email-communications.show', ['ticket' => $this->ticket->ticket_key, 'communication' => $this->id]),
                'draft' => $this->draft ? route($draftRoute.'.show', $this->draft->public_id) : null,
                'preview' => $this->draft ? route($draftRoute.'.preview', $this->draft->public_id) : null, 'send' => $this->draft ? route($draftRoute.'.send', $this->draft->public_id) : null,
                'submission' => $this->submission ? route('api.v1.email.mailbox.submissions.show', $this->submission->public_id) : null,
                'sent_reconciliation' => $this->submission ? route('api.v1.email.mailbox.submissions.sent-reconciliation.show', $this->submission->public_id) : null]];
    }
}
