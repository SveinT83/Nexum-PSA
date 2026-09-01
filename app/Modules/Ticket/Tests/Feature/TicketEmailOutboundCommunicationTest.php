<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Actions\SubmitEmailComposerDraft;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Email\Services\EmailSentReconciliationService;
use App\Modules\Email\Services\EmailDraftFence;
use App\Modules\Email\Services\EmailLiveRuntimeReadiness;
use App\Modules\Email\Services\EmailSharedDraftLeaseContext;
use App\Modules\Email\Services\EmailSharedDraftService;
use App\Modules\Email\Services\SmtpAccountMailer;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\PrepareTicketEmailCommunication;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketEmailOutboundCommunicationTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;
    private Ticket $ticket;
    private EmailAccount $account;
    private EmailFolder $inbox;
    private ClientUser $contact;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('Tech');
        foreach (['ticket.view', 'ticket.update', 'ticket.reply_customer', 'email.inbox_view', 'email.inbox_manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        $this->actor->givePermissionTo(['ticket.view', 'ticket.update', 'ticket.reply_customer', 'email.inbox_view', 'email.inbox_manage']);

        $defaults = app(EnsureTicketDefaults::class)->handle();
        $client = Client::factory()->create(['name' => 'Order 16 Client']);
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $this->contact = ClientUser::factory()->create([
            'client_site_id' => $site->id,
            'name' => 'Order 16 Contact',
            'email' => 'customer@example.test',
            'active' => true,
        ]);
        $this->ticket = Ticket::query()->create([
            'ticket_key' => 'TD-2026-916001',
            'queue_id' => $defaults['queue']->id,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'client_id' => $client->id,
            'site_id' => $site->id,
            'contact_id' => $this->contact->id,
            'owner_id' => $this->actor->id,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'channel' => 'email',
            'subject' => 'Order 16 Ticket',
            'is_unread' => false,
            'portal_visible_at' => now(),
            'portal_visible_by' => $this->actor->id,
        ]);

        $secret = Crypt::encryptString('order-16-isolated-secret');
        $this->account = EmailAccount::query()->create([
            'address' => 'support@example.test',
            'from_name' => 'Order 16 Support',
            'account_kind' => EmailAccount::KIND_SHARED,
            'is_active' => true,
            'ticket_ingress_enabled' => true,
            'imap_host' => '8.8.8.8',
            'imap_port' => 993,
            'imap_encryption' => 'ssl',
            'imap_username' => 'support@example.test',
            'imap_secret' => $secret,
            'imap_auth_type' => 'password',
            'smtp_host' => '1.1.1.1',
            'smtp_port' => 587,
            'smtp_encryption' => 'tls',
            'smtp_username' => 'support@example.test',
            'smtp_secret' => $secret,
            'smtp_auth_type' => 'password',
        ]);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $this->actor->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => true,
            'granted_at' => now(),
        ]);
        $this->inbox = EmailFolder::query()->create([
            'account_id' => $this->account->id,
            'path' => 'INBOX',
            'name' => 'INBOX',
            'role' => EmailFolder::ROLE_INBOX,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 77,
        ]);
    }

    #[Test]
    public function selected_conversation_prepares_one_exact_mail_draft_without_recipient_bleed(): void
    {
        [, , $firstLink] = $this->linkedMessage(101, '<first-thread@example.test>', [
            'subject' => 'External thread token ALPHA',
            'to_json' => [['email' => $this->account->address], ['email' => 'first-copy@example.test']],
            'cc_json' => [['email' => 'first-cc@example.test']],
        ]);
        $this->linkedMessage(102, '<second-thread@example.test>', [
            'from_email' => 'other@example.test',
            'subject' => 'Separate BETA thread',
            'to_json' => [['email' => $this->account->address]],
            'cc_json' => [['email' => 'must-not-bleed@example.test']],
        ], false);

        $communication = app(PrepareTicketEmailCommunication::class)->handle(
            $this->ticket,
            $firstLink,
            $this->actor,
            'reply_all',
        );
        $draft = $communication->draft;

        $this->assertSame($firstLink->id, $communication->email_ticket_conversation_link_id);
        $this->assertSame('Order 16 Contact <customer@example.test>, first-copy@example.test', $draft->to_recipients);
        $this->assertSame('first-cc@example.test', $draft->cc_recipients);
        $this->assertStringContainsString('External thread token ALPHA', $draft->subject);
        $this->assertStringContainsString($this->ticket->ticket_key, $draft->subject);
        $this->assertStringNotContainsString('must-not-bleed@example.test', $draft->to_recipients.' '.$draft->cc_recipients);

        $same = app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $firstLink, $this->actor, 'reply_all');
        $this->assertSame($communication->id, $same->id);
        $this->assertDatabaseCount('ticket_email_outbound_communications', 1);
    }

    #[Test]
    public function smtp_acceptance_projects_one_ticket_message_and_sent_reconciliation_is_exact_and_idempotent(): void
    {
        Queue::fake();
        [, , $link] = $this->linkedMessage(201, '<accepted-thread@example.test>');
        $communication = app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $link, $this->actor, 'reply');
        $draft = $communication->draft;
        $draft->forceFill(['body_html' => '<p>One authoritative Mail reply.</p>', 'body_text' => 'One authoritative Mail reply.'])->save();
        $mailer = new class extends SmtpAccountMailer {
            public int $calls = 0;
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                return (string) $options['message_id'];
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $submission = app(SubmitEmailComposerDraft::class)->submit(
            $draft->fresh(),
            $this->actor,
            'ticket-mail-accepted-1',
            SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
            $draft->version,
        );
        $this->assertSame(EmailOutboundSubmission::STATUS_ACCEPTED, $submission->status);
        $this->assertSame(1, $mailer->calls);
        $this->assertDatabaseCount('ticket_messages', 1);
        $projected = TicketEmailOutboundCommunication::query()->findOrFail($communication->id);
        $this->assertSame(TicketEmailOutboundCommunication::STATE_ACCEPTED, $projected->state);
        $this->assertNotNull($projected->ticket_message_id);
        $this->assertSame('One authoritative Mail reply.', TicketMessage::findOrFail($projected->ticket_message_id)->body);
        Queue::assertNotPushed(SendTicketReplyEmail::class);

        $replay = app(SubmitEmailComposerDraft::class)->submit(
            $draft->fresh(),
            $this->actor,
            'ticket-mail-accepted-1',
            SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
            $draft->version,
        );
        $this->assertSame($submission->id, $replay->id);
        $this->assertSame(1, $mailer->calls);
        $this->assertDatabaseCount('ticket_messages', 1);

        $sentFolder = EmailFolder::query()->create([
            'account_id' => $this->account->id,
            'path' => 'Sent',
            'name' => 'Sent',
            'role' => EmailFolder::ROLE_SENT,
            'is_selectable' => true,
            'sync_enabled' => true,
            'uid_validity' => 88,
        ]);
        $sentMessage = EmailMessage::query()->create([
            'account_id' => $this->account->id,
            'mailbox' => 'Sent',
            'imap_uid' => 301,
            'message_id' => trim((string) $submission->reserved_message_id, '<>'),
            'subject' => $draft->subject,
            'from_email' => $this->account->address,
            'received_at' => now(),
            'state' => 'untriaged',
        ]);
        $sentPlacement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $sentMessage->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $sentFolder->id,
            'folder_path' => 'Sent',
            'imap_uid_validity' => 88,
            'imap_uid' => 301,
            'provider_seen' => true,
        ]);
        app(EmailSentReconciliationService::class)->reconcilePlacement($sentPlacement);
        app(EmailSentReconciliationService::class)->reconcilePlacement($sentPlacement);

        $projected->refresh();
        $this->assertSame(TicketEmailOutboundCommunication::STATE_RECONCILED, $projected->state);
        $this->assertSame($sentMessage->id, $projected->reconciled_sent_email_message_id);
        $this->assertSame($sentPlacement->id, $projected->reconciled_sent_email_mailbox_placement_id);
        $this->assertSame(1, EmailTicketConversationLink::query()
            ->where('ticket_id', $this->ticket->id)
            ->where('email_message_id', $sentMessage->id)
            ->count());
        $this->assertDatabaseCount('ticket_messages', 1);
    }

    #[Test]
    public function frozen_recipient_change_is_rejected_before_smtp_and_records_stale_state(): void
    {
        [, , $link] = $this->linkedMessage(401, '<stale-thread@example.test>');
        $communication = app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $link, $this->actor, 'reply');
        $draft = $communication->draft;
        $draft->forceFill([
            'to_recipients' => 'different@example.test',
            'body_html' => '<p>Stale recipient must not send.</p>',
            'body_text' => 'Stale recipient must not send.',
            'version' => $draft->version + 1,
        ])->save();
        $mailer = new class extends SmtpAccountMailer {
            public int $calls = 0;
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                return '<must-not-send@example.test>';
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        try {
            app(SubmitEmailComposerDraft::class)->submit(
                $draft->fresh(),
                $this->actor,
                'ticket-mail-stale-1',
                SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
                $draft->version,
            );
            $this->fail('The frozen Ticket/Mail recipient must reject the send.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('draft', $exception->errors());
        }

        $this->assertSame(0, $mailer->calls);
        $this->assertDatabaseCount('email_outbound_submissions', 0);
        $this->assertSame(TicketEmailOutboundCommunication::STATE_STALE, $communication->fresh()->state);
        $this->assertSame('FROZEN_TICKET_MAIL_CONTEXT_CHANGED', $communication->fresh()->safe_reason_code);
    }

    #[Test]
    public function unresolved_provider_outcome_is_visible_once_and_never_replayed(): void
    {
        Queue::fake();
        [, , $link] = $this->linkedMessage(501, '<unresolved-thread@example.test>');
        $communication = app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $link, $this->actor, 'reply');
        $draft = $communication->draft;
        $draft->forceFill([
            'body_html' => '<p>Ambiguous provider outcome.</p>',
            'body_text' => 'Ambiguous provider outcome.',
        ])->save();
        $mailer = new class extends SmtpAccountMailer {
            public int $calls = 0;
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                throw new RuntimeException('ambiguous provider result');
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        try {
            app(SubmitEmailComposerDraft::class)->submit(
                $draft->fresh(),
                $this->actor,
                'ticket-mail-unresolved-1',
                SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
                $draft->version,
            );
            $this->fail('An unresolved provider outcome must remain an explicit conflict.');
        } catch (\App\Modules\Email\Services\EmailSubmissionConflictException) {
            $this->assertTrue(true);
        }
        $this->assertSame(1, $mailer->calls);
        $this->assertSame(TicketEmailOutboundCommunication::STATE_UNRESOLVED, $communication->fresh()->state);
        $this->assertDatabaseCount('ticket_messages', 1);
        Queue::assertNotPushed(SendTicketReplyEmail::class);

        try {
            app(SubmitEmailComposerDraft::class)->submit(
                $draft->fresh(),
                $this->actor,
                'ticket-mail-unresolved-1',
                SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
                $draft->version,
            );
        } catch (\App\Modules\Email\Services\EmailSubmissionConflictException) {
            // Expected durable replay fence.
        }
        $this->assertSame(1, $mailer->calls);
        $this->assertDatabaseCount('ticket_messages', 1);
    }


    #[Test]
    public function ticket_route_opens_the_exact_existing_mail_draft_and_ticket_view_hides_legacy_reply(): void
    {
        [, $placement, $link] = $this->linkedMessage(601, '<route-thread@example.test>');

        $response = $this->actingAs($this->actor)
            ->post(route('tech.tickets.email-relationships.reply', [$this->ticket, $link->id]), [
                'mode' => 'reply',
            ]);

        $communication = TicketEmailOutboundCommunication::query()->sole();
        $response->assertRedirect(route('tech.mail.index', [
            'account' => $this->account->id,
            'message' => $placement->id,
            'compose' => 'reply',
        ]));
        $this->assertSame($communication->email_composer_draft_id, EmailComposerDraft::query()->sole()->id);

        $this->actingAs($this->actor)
            ->get(route('tech.tickets.show', $this->ticket))
            ->assertOk()
            ->assertSee('Mail conversations')
            ->assertSee('Reply in Mail')
            ->assertDontSee('Reply to contact');
    }

    #[Test]
    public function migration_rollback_refuses_to_erase_ticket_mail_evidence(): void
    {
        [, , $link] = $this->linkedMessage(701, '<rollback-thread@example.test>');
        app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $link, $this->actor, 'reply');
        $migration = require base_path('database/migrations/2026_09_01_090000_create_ticket_email_outbound_communications.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be preserved');
        $migration->down();
    }


    #[Test]
    public function authorized_peer_uses_the_same_shared_ticket_draft_and_submission(): void
    {
        config()->set('email_live.enabled', true);
        config()->set('email_live.collaboration_enabled', true);
        $this->app->instance(EmailLiveRuntimeReadiness::class, new class extends EmailLiveRuntimeReadiness
        {
            public function ready(): bool
            {
                return true;
            }
        });
        $peer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Order 16 Peer']);
        $peer->assignRole('Tech');
        $peer->givePermissionTo(['ticket.view', 'ticket.update', 'ticket.reply_customer', 'email.inbox_view', 'email.inbox_manage']);
        EmailAccountUserGrant::query()->create([
            'email_account_id' => $this->account->id,
            'user_id' => $peer->id,
            'can_view' => true,
            'can_organize' => true,
            'can_send' => true,
            'granted_at' => now(),
        ]);
        [, , $link] = $this->linkedMessage(801, '<shared-ticket-thread@example.test>');
        $communication = app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $link, $this->actor, 'reply');
        $private = $communication->draft;
        $shared = app(EmailSharedDraftService::class)->share(
            $private,
            $this->actor,
            $private->version,
            'ticket-share-1',
        );

        $peerCommunication = app(PrepareTicketEmailCommunication::class)->handle($this->ticket, $link, $peer, 'reply');
        $this->assertSame($communication->id, $peerCommunication->id);
        $lease = app(EmailSharedDraftService::class)->acquire($shared, $peer, 'ticket-peer-acquire-1');
        $context = new EmailSharedDraftLeaseContext(
            $lease['lease_token'],
            (int) $lease['lock']->fencing_token,
            (int) $lease['draft']->content_version,
            app(EmailSharedDraftService::class)->sourceVersion($lease['draft']),
        );
        $shared = app(EmailSharedDraftService::class)->save($lease['draft'], $peer, $context, [
            'body_html' => '<p>Shared Ticket reply by peer.</p>',
        ]);
        $context = new EmailSharedDraftLeaseContext(
            $lease['lease_token'],
            (int) $shared->sharedLock->fencing_token,
            (int) $shared->content_version,
            app(EmailSharedDraftService::class)->sourceVersion($shared),
        );
        $mailer = new class extends SmtpAccountMailer {
            public int $calls = 0;
            public function sendMessage(EmailAccount $account, array $toRecipients, string $subject, string $html, string $text, array $attachments = [], array $ccRecipients = [], array $options = []): string
            {
                $this->calls++;
                return (string) $options['message_id'];
            }
        };
        $this->app->instance(SmtpAccountMailer::class, $mailer);

        $sharedSubmission = app(SubmitEmailComposerDraft::class)->submit(
            $shared,
            $peer,
            'ticket-shared-peer-send-1',
            SubmitEmailComposerDraft::CHANNEL_MAIL_WEB,
            app(EmailDraftFence::class)->version($shared, app(EmailDraftFence::class)->issue($shared)),
            $context,
        );

        $this->assertSame(1, $mailer->calls);
        $this->assertSame(TicketEmailOutboundCommunication::STATE_ACCEPTED, $communication->fresh()->state);
        $this->assertDatabaseCount('ticket_messages', 1);
        $this->assertSame($peer->id, TicketMessage::query()->sole()->author_id);
        $this->assertDatabaseHas('ticket_events', [
            'ticket_id' => $this->ticket->id,
            'type' => 'notification_failed',
        ]);
    }

    /** @return array{EmailMessage, EmailMailboxPlacement, EmailTicketConversationLink} */
    private function linkedMessage(int $uid, string $messageId, array $overrides = [], bool $linkToTicket = true): array
    {
        $message = EmailMessage::query()->create(array_merge([
            'account_id' => $this->account->id,
            'mailbox' => 'INBOX',
            'imap_uid' => $uid,
            'message_id' => $messageId,
            'subject' => 'Order 16 source conversation',
            'from_name' => $this->contact->name,
            'from_email' => $this->contact->email,
            'to_json' => [['email' => $this->account->address]],
            'received_at' => now()->addSeconds($uid),
            'state' => 'untriaged',
            'body_text' => 'Source body.',
        ], $overrides));
        $placement = EmailMailboxPlacement::query()->create([
            'email_message_id' => $message->id,
            'account_id' => $this->account->id,
            'email_folder_id' => $this->inbox->id,
            'folder_path' => 'INBOX',
            'imap_uid_validity' => 77,
            'imap_uid' => $uid,
            'provider_seen' => false,
            'local_state' => EmailMailboxPlacement::LOCAL_ACTIVE,
        ]);
        $conversation = app(EmailConversationProjector::class)->assignPlacement($placement);
        $link = new EmailTicketConversationLink();
        if ($linkToTicket) {
            $link = EmailTicketConversationLink::query()->create([
                'ticket_id' => $this->ticket->id,
                'email_message_id' => $message->id,
                'email_mailbox_placement_id' => $placement->id,
                'account_id' => $this->account->id,
                'email_conversation_id' => $conversation->id,
                'linked_by' => $this->actor->id,
                'conversation_key' => $conversation->conversation_key,
                'relationship_role' => EmailTicketConversationLink::ROLE_PRIMARY,
                'audience' => EmailTicketConversationLink::AUDIENCE_CUSTOMER,
                'status' => EmailTicketConversationLink::STATUS_ACTIVE,
                'linked_at' => now(),
            ]);
        }

        return [$message, $placement, $link];
    }
}
