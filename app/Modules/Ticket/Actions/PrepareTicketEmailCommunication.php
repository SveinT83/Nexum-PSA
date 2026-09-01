<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailAccountProviderRuntimeResolver;
use App\Modules\Email\Services\EmailComposerDraftService;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\MailboxAccess;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Models\TicketEmailOutboundEvent;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Services\TicketEmailCommunicationContext;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PrepareTicketEmailCommunication
{
    public function __construct(
        private readonly TicketActionGuard $ticketActions,
        private readonly MailboxAccess $mailboxAccess,
        private readonly EmailConversationProjector $conversations,
        private readonly EmailAccountProviderRuntimeResolver $providerRuntime,
        private readonly EmailComposerDraftService $drafts,
        private readonly SendEmailComposerMessage $composer,
        private readonly TicketEmailCommunicationContext $context,
    ) {}

    public function handle(
        Ticket $ticket,
        EmailTicketConversationLink $relationship,
        User $actor,
        string $mode,
    ): TicketEmailOutboundCommunication {
        if (! in_array($mode, [SendEmailComposerMessage::MODE_REPLY, SendEmailComposerMessage::MODE_REPLY_ALL], true)) {
            throw ValidationException::withMessages(['mode' => 'Choose Reply or Reply all.']);
        }

        $this->assertTicketAndRelationship($ticket, $relationship, $actor);
        $placement = $this->sourcePlacement($relationship);
        $account = $placement->account;

        if (! $account
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::VIEW)
            || ! $this->mailboxAccess->canAccessAccount($actor, $account, MailboxAccess::SEND)
            || ! $this->providerRuntime->databaseReady($account)) {
            throw new AuthorizationException('Current ordinary Mail View and Send access is required for this conversation.');
        }

        $conversation = $this->conversations->assignPlacement($placement);
        if (! $conversation) {
            throw ValidationException::withMessages(['relationship' => 'The selected Mail conversation is not durable yet.']);
        }

        if ($existing = TicketEmailOutboundCommunication::query()
            ->with('draft')
            ->where('ticket_id', $ticket->id)
            ->where('email_ticket_conversation_link_id', $relationship->id)
            ->where(function ($query) use ($actor): void {
                $query->where('actor_id', $actor->id)
                    ->orWhereHas('draft', fn ($draftQuery) => $draftQuery
                        ->where('scope', \App\Modules\Email\Models\EmailComposerDraft::SCOPE_SHARED));
            })
            ->where('operation_kind', $mode)
            ->where('state', TicketEmailOutboundCommunication::STATE_DRAFT)
            ->latest('id')
            ->first()) {
            if ($existing->draft?->status === \App\Modules\Email\Models\EmailComposerDraft::STATUS_ACTIVE) {
                return $existing;
            }
        }

        $message = $placement->message;
        $replyAll = $mode === SendEmailComposerMessage::MODE_REPLY_ALL
            ? $this->composer->defaultReplyAllRecipientFields($message, $account)
            : ['to' => trim((string) $message->from_email), 'cc' => ''];
        $subject = $this->context->subjectWithTicketKey(
            $this->composer->defaultReplySubject($message),
            $ticket,
        );
        $draft = $this->drafts->save($actor, $mode, $account, $placement, [
            'to' => $replyAll['to'],
            'cc' => $replyAll['cc'],
            'subject' => $subject,
            'body_html' => $this->composer->defaultReplyBodyHtml(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $preparedRecipients = [
            'to' => $this->composer->parseRecipients($replyAll['to']),
            'cc' => $this->composer->parseRecipients($replyAll['cc']),
        ];
        $threading = $this->context->replyHeaders($message);
        $bindingVersion = $this->providerRuntime->captureBindingVersion($account);

        return DB::transaction(function () use (
            $actor, $bindingVersion, $conversation, $draft, $mode, $placement, $preparedRecipients,
            $relationship, $subject, $threading, $ticket,
        ): TicketEmailOutboundCommunication {
            $communication = TicketEmailOutboundCommunication::query()->create([
                'ticket_id' => $ticket->id,
                'email_ticket_conversation_link_id' => $relationship->id,
                'email_account_id' => $placement->account_id,
                'email_conversation_id' => $conversation->id,
                'source_email_message_id' => $placement->email_message_id,
                'source_email_mailbox_placement_id' => $placement->id,
                'email_composer_draft_id' => $draft->id,
                'operation_kind' => $mode,
                'audience' => $relationship->audience,
                'state' => TicketEmailOutboundCommunication::STATE_DRAFT,
                'recipient_fingerprint' => $this->context->recipientFingerprint($preparedRecipients['to'], $preparedRecipients['cc']),
                'thread_fingerprint' => $this->context->threadFingerprint($threading),
                'subject_fingerprint' => hash('sha256', trim($subject)),
                'source_fingerprint' => $this->context->sourceFingerprint($ticket, $relationship, $placement, $bindingVersion),
                'provider_binding_version' => $bindingVersion,
                'idempotency_key' => 'draft:'.$draft->generation_id,
                'actor_id' => $actor->id,
                'version' => 1,
            ]);
            TicketEmailOutboundEvent::query()->create([
                'ticket_email_outbound_communication_id' => $communication->id,
                'event_type' => 'draft_created',
                'actor_id' => $actor->id,
                'metadata' => [
                    'ticket_id' => $ticket->id,
                    'relationship_id' => $relationship->id,
                    'draft_id' => $draft->id,
                    'source_placement_id' => $placement->id,
                ],
                'occurred_at' => now(),
            ]);

            return $communication->fresh(['draft', 'sourcePlacement']);
        });
    }

    private function assertTicketAndRelationship(Ticket $ticket, EmailTicketConversationLink $relationship, User $actor): void
    {
        if ($reason = $this->ticketActions->reason($ticket, TicketAction::CUSTOMER_REPLY, $actor)) {
            throw ValidationException::withMessages(['ticket' => $reason]);
        }
        if (! $ticket->isPortalVisible()) {
            throw ValidationException::withMessages(['ticket' => 'Publish the Ticket before sending a customer reply.']);
        }
        if ((int) $relationship->ticket_id !== (int) $ticket->id
            || $relationship->status !== EmailTicketConversationLink::STATUS_ACTIVE
            || $relationship->audience !== EmailTicketConversationLink::AUDIENCE_CUSTOMER) {
            throw new AuthorizationException('The selected customer Mail relationship is not available.');
        }

        $relationship->loadMissing('message');
        $sender = mb_strtolower(trim((string) $relationship->message?->from_email));
        $sameClientContact = $sender !== '' && ClientUser::query()
            ->whereRaw('LOWER(email) = ?', [$sender])
            ->where('active', true)
            ->whereHas('site', fn ($query) => $query->where('client_id', $ticket->client_id))
            ->exists();
        if (! $sameClientContact) {
            throw ValidationException::withMessages([
                'relationship' => 'This slice only replies to an active Contact belonging to the Ticket client.',
            ]);
        }
    }

    private function sourcePlacement(EmailTicketConversationLink $relationship): EmailMailboxPlacement
    {
        $placement = EmailMailboxPlacement::query()
            ->with(['account', 'message'])
            ->whereKey($relationship->email_mailbox_placement_id)
            ->where('email_message_id', $relationship->email_message_id)
            ->where('account_id', $relationship->account_id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->first();

        if (! $placement) {
            throw new AuthorizationException('The exact provider-backed Mail source is no longer available.');
        }

        return $placement;
    }
}
