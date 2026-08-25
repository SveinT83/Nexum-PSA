<?php

namespace App\Modules\Email\Services;

use App\Modules\Email\Actions\SendEmailComposerMessage;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailMailboxPlacement;
use Illuminate\Auth\Access\AuthorizationException;

class EmailComposerSourceContext
{
    public function __construct(
        private readonly EmailAccountProviderRuntimeResolver $providerRuntime,
        private readonly SendEmailComposerMessage $composer,
    ) {}

    /** @return array<string, mixed> */
    public function capture(EmailComposerDraft $draft): array
    {
        $draft->loadMissing(['account', 'conversation', 'placement.message']);

        if (! $draft->account || ! $draft->conversation || ! $draft->placement?->message) {
            throw new AuthorizationException('The draft source context is not available.');
        }

        return $this->captureFor(
            $draft,
            $draft->conversation,
            $draft->placement,
            (string) $draft->to_recipients,
            (string) $draft->cc_recipients,
            (string) $draft->subject,
        );
    }

    /**
     * Build a content-free rebase proposal from the newest exact active
     * placement in the same account-scoped conversation.
     *
     * @return array<string, mixed>
     */
    public function rebaseProposal(EmailComposerDraft $draft): array
    {
        $draft->loadMissing(['account', 'conversation']);
        $conversation = $draft->conversation;

        if (! $draft->account || ! $conversation) {
            throw new AuthorizationException('The draft source context is not available.');
        }

        $placement = EmailMailboxPlacement::query()
            ->with('message')
            ->where('account_id', $draft->email_account_id)
            ->where('email_conversation_id', $conversation->id)
            ->where('local_state', EmailMailboxPlacement::LOCAL_ACTIVE)
            ->whereNull('provider_missing_at')
            ->orderByDesc('id')
            ->first();

        if (! $placement?->message) {
            throw new AuthorizationException('The shared conversation has no active rebase source.');
        }

        $replyAll = $draft->mode === SendEmailComposerMessage::MODE_REPLY_ALL
            ? $this->composer->defaultReplyAllRecipientFields($placement->message, $draft->account)
            : ['to' => '', 'cc' => ''];
        $to = match ($draft->mode) {
            SendEmailComposerMessage::MODE_REPLY => trim((string) $placement->message->from_email),
            SendEmailComposerMessage::MODE_REPLY_ALL => $replyAll['to'],
            SendEmailComposerMessage::MODE_FORWARD => (string) $draft->to_recipients,
            default => throw new AuthorizationException('This draft mode cannot be shared.'),
        };
        $cc = $draft->mode === SendEmailComposerMessage::MODE_REPLY_ALL
            ? $replyAll['cc']
            : (string) $draft->cc_recipients;
        $subject = $draft->mode === SendEmailComposerMessage::MODE_FORWARD
            ? $this->composer->defaultForwardSubject($placement->message)
            : $this->composer->defaultReplySubject($placement->message);
        $snapshot = $this->captureFor($draft, $conversation, $placement, $to, $cc, $subject);

        return $snapshot + [
            'to' => $to,
            'cc' => $cc,
            'subject' => $subject,
            'source_placement_id' => (int) $placement->id,
            'source_message_id' => (int) $placement->email_message_id,
        ];
    }

    /** @return array<string, mixed> */
    private function captureFor(
        EmailComposerDraft $draft,
        EmailConversation $conversation,
        EmailMailboxPlacement $placement,
        string $to,
        string $cc,
        string $subject,
    ): array {
        $placement->loadMissing('message');
        $message = $placement->message;

        if (! $message
            || (int) $conversation->account_id !== (int) $draft->email_account_id
            || (int) $placement->account_id !== (int) $draft->email_account_id
            || (int) $placement->email_conversation_id !== (int) $conversation->id
            || ! $message->hasActiveProviderPlacement($placement)) {
            throw new AuthorizationException('The draft source context is no longer exact.');
        }

        $bindingVersion = $this->providerRuntime->captureBindingVersion($draft->account);
        $evidence = [
            'schema' => EmailComposerDraft::SOURCE_CONTEXT_SCHEMA,
            'account_id' => (int) $draft->email_account_id,
            'account_kind' => (string) $draft->account->account_kind,
            'provider_binding_version' => $bindingVersion,
            'conversation_id' => (int) $conversation->id,
            'conversation_key_hash' => hash('sha256', (string) $conversation->conversation_key),
            'conversation_status' => (string) $conversation->status,
            'conversation_updated_at' => $conversation->updated_at?->format('Y-m-d\TH:i:s.uP'),
            'conversation_message_count' => (int) $conversation->message_count,
            'conversation_latest_message_id' => (int) $conversation->latest_email_message_id,
            'conversation_latest_placement_id' => (int) $conversation->latest_email_mailbox_placement_id,
            'conversation_last_message_at' => $conversation->last_message_at?->format('Y-m-d\TH:i:s.uP'),
            'source_placement_id' => (int) $placement->id,
            'source_message_id' => (int) $placement->email_message_id,
            'source_canonical_message_id' => (int) $placement->canonical_email_message_id,
            'source_sync_version' => (int) $placement->sync_version,
            'source_uid_namespace_id' => (int) $placement->uid_namespace_id,
            'source_remote_identity_hash' => (string) $placement->last_provider_observed_identity_hash,
            'source_message_id_hash' => hash('sha256', (string) $message->message_id),
            'source_in_reply_to_hash' => hash('sha256', (string) $message->in_reply_to),
            'source_references_hash' => hash('sha256', (string) $message->references),
            'mode' => (string) $draft->mode,
            'to' => $this->normalizedRecipients($to),
            'cc' => $this->normalizedRecipients($cc),
            'subject_hash' => hash('sha256', trim($subject)),
        ];

        return [
            'schema' => EmailComposerDraft::SOURCE_CONTEXT_SCHEMA,
            'fingerprint' => hash('sha256', json_encode($evidence, JSON_THROW_ON_ERROR)),
            'provider_binding_version' => $bindingVersion,
            'source_placement_sync_version' => (int) $placement->sync_version,
            'captured_at' => now(),
        ];
    }

    /** @return list<string> */
    private function normalizedRecipients(string $value): array
    {
        return collect($this->composer->parseRecipients($value))
            ->pluck('email')
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->values()
            ->all();
    }
}
