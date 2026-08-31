<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketEmailCommunicationContext;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ValidateTicketEmailDraftForSubmission
{
    public function __construct(
        private readonly TicketActionGuard $ticketActions,
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailAccountProviderRuntimeResolver $providerRuntime,
        private readonly TicketEmailCommunicationContext $context,
    ) {}

    /** @param array<string, mixed> $preview */
    public function handle(EmailComposerDraft $draft, User $actor, array $preview): ?TicketEmailOutboundCommunication
    {
        $reference = TicketEmailOutboundCommunication::query()
            ->where('email_composer_draft_id', $draft->id)
            ->whereIn('state', [TicketEmailOutboundCommunication::STATE_DRAFT, TicketEmailOutboundCommunication::STATE_FAILED_PRE_SEND])
            ->latest('id')
            ->first();
        if (! $reference) {
            return null;
        }

        $result = DB::transaction(function () use ($actor, $draft, $preview, $reference): array {
            $communication = TicketEmailOutboundCommunication::query()
                ->with(['ticket.contact', 'relationship.message', 'account', 'sourcePlacement.message'])
                ->whereKey($reference->id)
                ->lockForUpdate()
                ->firstOrFail();
            $ticket = $communication->ticket;
            $relationship = $communication->relationship;
            $placement = $communication->sourcePlacement;

            if (! $ticket || ! $relationship || ! $placement || ! $communication->account
                || (int) $communication->actor_id !== (int) $actor->id
                || (int) $communication->email_account_id !== (int) $draft->email_account_id
                || (int) $communication->source_email_mailbox_placement_id !== (int) $draft->email_mailbox_placement_id
                || $relationship->status !== EmailTicketConversationLink::STATUS_ACTIVE
                || $relationship->audience !== $communication->audience
                || $this->ticketActions->reason($ticket, TicketAction::CUSTOMER_REPLY, $actor)
                || ! $ticket->isPortalVisible()
                || ! $this->mailboxAccess->canAccessAccount($actor, $communication->account, MailboxAccess::VIEW)
                || ! $this->mailboxAccess->canAccessAccount($actor, $communication->account, MailboxAccess::SEND)) {
                $this->markStale($communication, 'AUTHORIZATION_OR_SOURCE_CHANGED');

                return [
                    'communication' => $communication,
                    'error' => 'The Ticket or Mail authorization changed. Start a new reply from the Ticket.',
                ];
            }

            $sender = mb_strtolower(trim((string) $relationship->message?->from_email));
            $sameClientContact = $sender !== '' && ClientUser::query()
                ->whereRaw('LOWER(email) = ?', [$sender])
                ->where('active', true)
                ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
                ->exists();
            $binding = $this->providerRuntime->captureBindingVersion($communication->account);
            $sourceFingerprint = $this->context->sourceFingerprint($ticket, $relationship, $placement, $binding);
            $recipientFingerprint = $this->context->recipientFingerprint($preview['to'], $preview['cc']);
            $threadFingerprint = $this->context->threadFingerprint($preview['threading']);
            $subjectFingerprint = hash('sha256', trim((string) $preview['subject']));

            if (! $sameClientContact
                || $binding !== (int) $communication->provider_binding_version
                || ! hash_equals($communication->source_fingerprint, $sourceFingerprint)
                || ! hash_equals($communication->recipient_fingerprint, $recipientFingerprint)
                || ! hash_equals($communication->thread_fingerprint, $threadFingerprint)
                || ! hash_equals($communication->subject_fingerprint, $subjectFingerprint)) {
                $this->markStale($communication, 'FROZEN_TICKET_MAIL_CONTEXT_CHANGED');

                return [
                    'communication' => $communication,
                    'error' => 'The selected conversation, recipient, thread, subject, or provider binding changed. Start a new reply from the Ticket.',
                ];
            }

            $communication->forceFill([
                'attachment_manifest_hash' => $preview['attachment_manifest_hash'],
                'signature_fingerprint' => hash('sha256', json_encode($preview['signature_evidence'], JSON_THROW_ON_ERROR)),
                'safe_reason_code' => null,
                'version' => (int) $communication->version + 1,
            ])->save();

            return ['communication' => $communication, 'error' => null];
        });

        if ($result['error']) {
            throw ValidationException::withMessages(['draft' => $result['error']]);
        }

        return $result['communication'];
    }

    private function markStale(TicketEmailOutboundCommunication $communication, string $reason): void
    {
        $communication->forceFill([
            'state' => TicketEmailOutboundCommunication::STATE_STALE,
            'safe_reason_code' => $reason,
            'version' => (int) $communication->version + 1,
        ])->save();
    }
}
