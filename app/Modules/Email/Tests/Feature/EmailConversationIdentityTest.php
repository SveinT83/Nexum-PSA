<?php

namespace App\Modules\Email\Tests\Feature;

use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailConversation;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailSmartInboxSuggestion;
use App\Modules\Email\Models\EmailSmartInboxSuggestionEvent;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationIdentityReconciler;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class EmailConversationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private int $nextUid = 1000;

    #[Test]
    public function nested_rfc_reply_chain_projects_into_one_account_conversation(): void
    {
        [$account, $folder] = $this->mailbox('nested-chain@example.test');
        $projector = app(EmailConversationProjector::class);

        $root = $this->message($account, [
            'message_id' => '<root@example.test>',
            'subject' => 'Nested thread',
        ]);
        $rootPlacement = $this->placement($root, $folder);
        $rootConversation = $projector->assignPlacement($rootPlacement);

        $reply = $this->message($account, [
            'message_id' => '<reply@example.test>',
            'in_reply_to' => '<root@example.test>',
            'references' => '<root@example.test>',
            'subject' => 'Re: Nested thread',
        ]);
        $replyPlacement = $this->placement($reply, $folder);
        $replyConversation = $projector->assignPlacement($replyPlacement);

        $nested = $this->message($account, [
            'message_id' => '<nested@example.test>',
            'in_reply_to' => '<reply@example.test>',
            'references' => '<root@example.test> <reply@example.test>',
            'subject' => 'Re: Nested thread',
        ]);
        $nestedPlacement = $this->placement($nested, $folder);
        $nestedConversation = $projector->assignPlacement($nestedPlacement);

        $this->assertNotNull($rootConversation);
        $this->assertSame($rootConversation->id, $replyConversation?->id);
        $this->assertSame($rootConversation->id, $nestedConversation?->id);
        $this->assertSame($rootConversation->conversation_key, $projector->conversationKey($nested));
        $this->assertSame(3, $rootConversation->fresh()->message_count);
        $this->assertSame(3, $rootConversation->fresh()->active_placement_count);
        $this->assertSame(3, EmailMailboxPlacement::query()
            ->where('email_conversation_id', $rootConversation->id)
            ->count());
    }

    #[Test]
    public function incompatible_same_account_roots_reusing_message_id_stay_separate(): void
    {
        [$account, $folder] = $this->mailbox('reused-id@example.test');
        $projector = app(EmailConversationProjector::class);

        $first = $this->message($account, [
            'message_id' => '<reused@example.test>',
            'subject' => 'First unrelated message',
            'from_email' => 'first-sender@example.test',
            'checksum_sha1' => str_repeat('1', 40),
        ]);
        $firstPlacement = $this->placement($first, $folder, [
            'provider_seen' => true,
            'provider_flagged' => false,
        ]);
        $firstConversation = $projector->assignPlacement($firstPlacement);

        $second = $this->message($account, [
            'message_id' => 'REUSED@example.test',
            'subject' => 'Second unrelated message',
            'from_email' => 'second-sender@example.test',
            'checksum_sha1' => str_repeat('2', 40),
        ]);
        $secondPlacement = $this->placement($second, $folder, [
            'provider_seen' => false,
            'provider_flagged' => true,
            'flags_json' => ['\\Flagged'],
        ]);
        $providerState = $secondPlacement->only([
            'email_folder_id',
            'folder_path',
            'provider_seen',
            'provider_flagged',
            'flags_json',
            'local_state',
        ]);
        $secondConversation = $projector->assignPlacement($secondPlacement);

        $this->assertNotNull($firstConversation);
        $this->assertNotNull($secondConversation);
        $this->assertNotSame($firstConversation->id, $secondConversation->id);
        $this->assertStringStartsWith('collision:', $secondConversation->conversation_key);
        $this->assertSame($providerState, $secondPlacement->fresh()->only(array_keys($providerState)));
        $this->assertSame(str_repeat('2', 40), $second->fresh()->checksum_sha1);
        $this->assertDatabaseHas('email_conversation_correlation_issues', [
            'account_id' => $account->id,
            'email_message_id' => $second->id,
            'email_mailbox_placement_id' => $secondPlacement->id,
            'issue_type' => EmailConversationProjector::ISSUE_REUSED_MESSAGE_ID,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function identical_message_ids_never_cross_account_boundaries(): void
    {
        [$firstAccount, $firstFolder] = $this->mailbox('first-account@example.test');
        [$secondAccount, $secondFolder] = $this->mailbox('second-account@example.test');
        $projector = app(EmailConversationProjector::class);

        $firstMessage = $this->message($firstAccount, [
            'message_id' => '<shared-id@example.test>',
            'subject' => 'First account subject',
            'from_email' => 'one@example.test',
            'checksum_sha1' => str_repeat('a', 40),
        ]);
        $secondMessage = $this->message($secondAccount, [
            'message_id' => '<shared-id@example.test>',
            'subject' => 'Conflicting second account subject',
            'from_email' => 'two@example.test',
            'checksum_sha1' => str_repeat('b', 40),
        ]);

        $firstConversation = $projector->assignPlacement($this->placement($firstMessage, $firstFolder));
        $secondConversation = $projector->assignPlacement($this->placement($secondMessage, $secondFolder));

        $this->assertNotNull($firstConversation);
        $this->assertNotNull($secondConversation);
        $this->assertNotSame($firstConversation->id, $secondConversation->id);
        $this->assertSame($firstConversation->conversation_key, $secondConversation->conversation_key);
        $this->assertSame($firstAccount->id, $firstConversation->account_id);
        $this->assertSame($secondAccount->id, $secondConversation->account_id);
        $this->assertDatabaseCount('email_conversation_correlation_issues', 0);
    }

    #[Test]
    public function forward_reconciliation_moves_an_unambiguous_split_and_its_ticket_pointer(): void
    {
        [$account, $folder] = $this->mailbox('forward-reconcile@example.test');
        $projector = app(EmailConversationProjector::class);

        $root = $this->message($account, [
            'message_id' => '<forward-root@example.test>',
            'subject' => 'Forward reconcile',
        ]);
        $rootPlacement = $this->placement($root, $folder, ['provider_seen' => true]);
        $target = $projector->assignPlacement($rootPlacement);

        $reply = $this->message($account, [
            'message_id' => '<forward-reply@example.test>',
            'in_reply_to' => '<forward-root@example.test>',
            'references' => '<forward-root@example.test>',
            'subject' => 'Re: Forward reconcile',
        ]);
        $replyPlacement = $this->placement($reply, $folder, ['provider_seen' => false]);
        $projector->assignPlacement($replyPlacement);

        $nested = $this->message($account, [
            'message_id' => '<forward-nested@example.test>',
            'in_reply_to' => '<forward-reply@example.test>',
            'references' => '<forward-root@example.test> <forward-reply@example.test>',
            'subject' => 'Re: Forward reconcile',
            'body_text' => 'Canonical body must remain unchanged.',
            'checksum_sha1' => str_repeat('c', 40),
        ]);
        $wrong = $this->splitConversation($account, $reply, $replyPlacement, 'legacy-parent-key');
        $nestedPlacement = $this->placement($nested, $folder, [
            'email_conversation_id' => $wrong->id,
            'provider_seen' => false,
            'provider_flagged' => true,
            'flags_json' => ['\\Flagged', '\\Answered'],
        ]);
        $projector->refreshConversation($wrong);

        $ticket = Ticket::factory()->create(['ticket_key' => 'TD-2026-991001']);
        $ticketLink = EmailTicketConversationLink::create([
            'ticket_id' => $ticket->id,
            'email_message_id' => $nested->id,
            'email_mailbox_placement_id' => $nestedPlacement->id,
            'account_id' => $account->id,
            'email_conversation_id' => $wrong->id,
            'conversation_key' => $wrong->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_SECONDARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'linked_at' => now(),
        ]);
        $providerState = $nestedPlacement->only([
            'email_folder_id',
            'folder_path',
            'provider_seen',
            'provider_flagged',
            'flags_json',
            'local_state',
        ]);
        $messageState = $nested->only([
            'message_id',
            'subject',
            'from_email',
            'body_text',
            'checksum_sha1',
            'state',
            'ticket_id',
        ]);

        $result = app(EmailConversationIdentityReconciler::class)
            ->reconcilePlacement($nestedPlacement);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(1, $result['removed_shells']);
        $this->assertSame($target?->id, $nestedPlacement->fresh()->email_conversation_id);
        $this->assertSame($providerState, $nestedPlacement->fresh()->only(array_keys($providerState)));
        $this->assertSame($messageState, $nested->fresh()->only(array_keys($messageState)));
        $this->assertSame($target?->id, $ticketLink->fresh()->email_conversation_id);
        $this->assertSame($target?->conversation_key, $ticketLink->fresh()->conversation_key);
        $this->assertDatabaseMissing('email_conversations', ['id' => $wrong->id]);
        $this->assertSame(3, $target?->fresh()->message_count);
        $this->assertSame(3, $target?->fresh()->active_placement_count);
        $this->assertSame(2, $target?->fresh()->provider_unread_count);
    }

    #[Test]
    public function reconciliation_preserves_empty_conversation_shells_with_smart_inbox_audit(): void
    {
        [$account, $folder] = $this->mailbox('smart-audit-reconcile@example.test');
        $projector = app(EmailConversationProjector::class);
        $root = $this->message($account, [
            'message_id' => '<smart-audit-root@example.test>',
            'subject' => 'Smart audit reconcile',
        ]);
        $rootPlacement = $this->placement($root, $folder);
        $target = $projector->assignPlacement($rootPlacement);
        $reply = $this->message($account, [
            'message_id' => '<smart-audit-reply@example.test>',
            'in_reply_to' => '<smart-audit-root@example.test>',
            'references' => '<smart-audit-root@example.test>',
            'subject' => 'Re: Smart audit reconcile',
        ]);
        $source = $this->splitConversation($account, $reply, $rootPlacement, 'smart-audit-source');
        $replyPlacement = $this->placement($reply, $folder, [
            'email_conversation_id' => $source->id,
        ]);
        $projector->refreshConversation($source);
        $suggestion = EmailSmartInboxSuggestion::query()->create([
            'account_id' => $account->id,
            'email_conversation_id' => $source->id,
            'selected_email_mailbox_placement_id' => $replyPlacement->id,
            'effect_type' => EmailSmartInboxSuggestion::EFFECT_REVIEW_SUMMARY,
            'proposal_json' => ['summary' => 'Preserve this reviewed audit fact.'],
            'proposal_fingerprint' => hash('sha256', 'smart-audit-proposal'),
            'source_fingerprint' => hash('sha256', 'smart-audit-source'),
            'source_message_ids_json' => [$reply->id],
            'schema_version' => EmailSmartInboxSuggestion::SCHEMA_VERSION,
            'status' => EmailSmartInboxSuggestion::STATUS_PENDING,
            'idempotency_key' => hash('sha256', 'smart-audit-idempotency'),
            'generated_at' => now(),
        ]);
        $event = EmailSmartInboxSuggestionEvent::query()->create([
            'email_smart_inbox_suggestion_id' => $suggestion->id,
            'event_type' => EmailSmartInboxSuggestionEvent::TYPE_GENERATED,
            'to_status' => EmailSmartInboxSuggestion::STATUS_PENDING,
            'after_json' => ['status' => EmailSmartInboxSuggestion::STATUS_PENDING],
            'occurred_at' => now(),
            'created_at' => now(),
        ]);

        $result = app(EmailConversationIdentityReconciler::class)
            ->reconcilePlacement($replyPlacement);

        $this->assertSame(1, $result['moved']);
        $this->assertSame(0, $result['removed_shells']);
        $this->assertSame($target?->id, $replyPlacement->fresh()->email_conversation_id);
        $this->assertDatabaseHas('email_conversations', ['id' => $source->id]);
        $this->assertDatabaseHas('email_smart_inbox_suggestions', [
            'id' => $suggestion->id,
            'email_conversation_id' => $source->id,
        ]);
        $this->assertDatabaseHas('email_smart_inbox_suggestion_events', [
            'id' => $event->id,
            'email_smart_inbox_suggestion_id' => $suggestion->id,
        ]);
    }

    #[Test]
    public function conflicting_referenced_conversations_are_preserved_and_reported(): void
    {
        [$account, $folder] = $this->mailbox('ambiguous-references@example.test');
        $projector = app(EmailConversationProjector::class);

        $firstRoot = $this->message($account, [
            'message_id' => '<ambiguous-a@example.test>',
            'subject' => 'Thread A',
        ]);
        $firstConversation = $projector->assignPlacement($this->placement($firstRoot, $folder));
        $secondRoot = $this->message($account, [
            'message_id' => '<ambiguous-b@example.test>',
            'subject' => 'Thread B',
        ]);
        $secondPlacement = $this->placement($secondRoot, $folder);
        $secondConversation = $projector->assignPlacement($secondPlacement);

        $ambiguous = $this->message($account, [
            'message_id' => '<ambiguous-child@example.test>',
            'in_reply_to' => '<ambiguous-b@example.test>',
            'references' => '<ambiguous-a@example.test> <ambiguous-b@example.test>',
            'subject' => 'Ambiguous reply',
        ]);
        $existing = $this->splitConversation($account, $ambiguous, $secondPlacement, 'ambiguous-existing');
        $ambiguousPlacement = $this->placement($ambiguous, $folder, [
            'email_conversation_id' => $existing->id,
            'provider_flagged' => true,
        ]);
        $projector->refreshConversation($existing);

        $result = app(EmailConversationIdentityReconciler::class)
            ->reconcilePlacement($ambiguousPlacement);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['issues']);
        $this->assertSame($existing->id, $ambiguousPlacement->fresh()->email_conversation_id);
        $this->assertTrue($ambiguousPlacement->fresh()->provider_flagged);
        $this->assertNotSame($firstConversation?->id, $secondConversation?->id);
        $this->assertDatabaseHas('email_conversation_correlation_issues', [
            'account_id' => $account->id,
            'email_mailbox_placement_id' => $ambiguousPlacement->id,
            'issue_type' => EmailConversationProjector::ISSUE_CONFLICTING_REFERENCES,
            'source_email_conversation_id' => $existing->id,
            'target_email_conversation_id' => $existing->id,
            'status' => 'open',
        ]);
    }

    #[Test]
    public function competing_primary_ticket_links_block_reconciliation_and_are_reported(): void
    {
        [$account, $folder] = $this->mailbox('primary-conflict@example.test');
        $projector = app(EmailConversationProjector::class);

        $root = $this->message($account, [
            'message_id' => '<primary-root@example.test>',
            'subject' => 'Primary conflict',
        ]);
        $rootPlacement = $this->placement($root, $folder);
        $target = $projector->assignPlacement($rootPlacement);

        $reply = $this->message($account, [
            'message_id' => '<primary-reply@example.test>',
            'in_reply_to' => '<primary-root@example.test>',
            'references' => '<primary-root@example.test>',
            'subject' => 'Re: Primary conflict',
        ]);
        $source = $this->splitConversation($account, $reply, $rootPlacement, 'primary-source');
        $replyPlacement = $this->placement($reply, $folder, [
            'email_conversation_id' => $source->id,
            'provider_seen' => false,
        ]);
        $projector->refreshConversation($source);

        $sourceTicket = Ticket::factory()->create(['ticket_key' => 'TD-2026-991002']);
        $targetTicket = Ticket::factory()->create(['ticket_key' => 'TD-2026-991003']);
        $sourceLink = EmailTicketConversationLink::create([
            'ticket_id' => $sourceTicket->id,
            'email_message_id' => $reply->id,
            'email_mailbox_placement_id' => $replyPlacement->id,
            'account_id' => $account->id,
            'email_conversation_id' => $source->id,
            'conversation_key' => $source->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'linked_at' => now(),
        ]);
        EmailTicketConversationLink::create([
            'ticket_id' => $targetTicket->id,
            'email_message_id' => $root->id,
            'email_mailbox_placement_id' => $rootPlacement->id,
            'account_id' => $account->id,
            'email_conversation_id' => $target?->id,
            'conversation_key' => $target?->conversation_key,
            'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
            'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
            'status' => EmailTicketConversationLink::STATUS_ACTIVE,
            'linked_at' => now(),
        ]);

        $result = app(EmailConversationIdentityReconciler::class)
            ->reconcilePlacement($replyPlacement);

        $this->assertSame(0, $result['moved']);
        $this->assertSame(1, $result['issues']);
        $this->assertSame($source->id, $replyPlacement->fresh()->email_conversation_id);
        $this->assertSame($source->id, $sourceLink->fresh()->email_conversation_id);
        $this->assertSame($source->conversation_key, $sourceLink->fresh()->conversation_key);
        $this->assertDatabaseHas('email_conversation_correlation_issues', [
            'account_id' => $account->id,
            'email_mailbox_placement_id' => $replyPlacement->id,
            'email_ticket_conversation_link_id' => $sourceLink->id,
            'issue_type' => EmailConversationProjector::ISSUE_COMPETING_PRIMARY,
            'source_email_conversation_id' => $source->id,
            'target_email_conversation_id' => $target?->id,
            'status' => 'open',
        ]);
    }

    /** @return array{EmailAccount, EmailFolder} */
    private function mailbox(string $address): array
    {
        $account = EmailAccount::create([
            'address' => $address,
            'description' => 'Conversation identity test account',
            'from_name' => 'Conversation Identity',
            'account_kind' => EmailAccount::KIND_SHARED,
            'owner_id' => null,
            'is_active' => true,
            'is_global_default' => false,
            'defaults_for' => [],
            'ticket_ingress_enabled' => true,
            'delete_policy' => 'local_only',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => $address,
            'imap_secret' => 'secret',
            'imap_auth_type' => 'password',
            'smtp_host' => 'smtp.example.test',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => $address,
            'smtp_secret' => 'secret',
            'smtp_auth_type' => 'password',
        ]);
        $folder = EmailFolder::create([
            'account_id' => $account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 1,
        ]);

        return [$account, $folder];
    }

    /** @param array<string, mixed> $overrides */
    private function message(EmailAccount $account, array $overrides = []): EmailMessage
    {
        $uid = $this->nextUid++;

        return EmailMessage::create(array_merge([
            'account_id' => $account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => '<message-'.$uid.'@example.test>',
            'subject' => 'Conversation identity message',
            'from_email' => 'sender@example.test',
            'received_at' => now()->addSeconds($uid),
            'state' => 'untriaged',
            'body_text' => 'Conversation identity test body.',
        ], $overrides));
    }

    /** @param array<string, mixed> $overrides */
    private function placement(
        EmailMessage $message,
        EmailFolder $folder,
        array $overrides = [],
    ): EmailMailboxPlacement {
        return EmailMailboxPlacement::create(array_merge([
            'email_message_id' => $message->id,
            'account_id' => $message->account_id,
            'email_folder_id' => $folder->id,
            'folder_path' => $folder->path,
            'imap_uid_validity' => $folder->uid_validity,
            'imap_uid' => $message->imap_uid,
            'provider_seen' => false,
            'provider_flagged' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ], $overrides));
    }

    private function splitConversation(
        EmailAccount $account,
        EmailMessage $message,
        EmailMailboxPlacement $latestPlacement,
        string $suffix,
    ): EmailConversation {
        return EmailConversation::create([
            'account_id' => $account->id,
            'conversation_key' => 'legacy:'.hash('sha256', $suffix),
            'status' => EmailConversation::STATUS_ACTIVE,
            'subject' => $message->subject,
            'first_email_message_id' => $message->id,
            'latest_email_message_id' => $message->id,
            'latest_email_mailbox_placement_id' => $latestPlacement->id,
            'first_message_at' => $message->received_at,
            'last_message_at' => $message->received_at,
            'metadata' => ['source' => 'legacy_test_projection'],
        ]);
    }
}
