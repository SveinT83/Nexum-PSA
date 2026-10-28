<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Email\Models\EmailAccount;
use App\Modules\Email\Models\EmailAccountUserGrant;
use App\Modules\Email\Models\EmailComposerDraft;
use App\Modules\Email\Models\EmailFolder;
use App\Modules\Email\Models\EmailMailboxPlacement;
use App\Modules\Email\Models\EmailMessage;
use App\Modules\Email\Models\EmailOutboundSubmission;
use App\Modules\Email\Models\EmailTicketConversationLink;
use App\Modules\Email\Services\EmailConversationProjector;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEmailOutboundCommunication;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketEmailDraftApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Ticket $ticket;

    private ClientUser $contact;

    private EmailAccount $account;

    private EmailFolder $inbox;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();

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

        $this->ticket->forceFill(['work_context_id' => app(\App\Modules\WorkContext\Actions\ResolveWorkContext::class)->fromClientId($client->id)->id])->save();
        Sanctum::actingAs($this->actor, ['tickets.email-drafts.read', 'tickets.email-drafts.create', 'email.read', 'email.drafts.read', 'email.drafts.write', 'email.send']);

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

    public function test_selected_conversation_returns_exact_bound_draft_and_supported_native_mail_links(): void
    {
        [, $placement, $link] = $this->linkedMessage(9101, '<api-selected-source@example.test>');
        $this->getJson(route('api.v1.tickets.email-relationships.index', $this->ticket))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $link->id);
        $url = route('api.v1.tickets.email-relationships.reply-draft', [$this->ticket, $link->id]);
        $read = $this->postJson($url, ['mode' => 'reply', 'idempotency_key' => 'exact-source'])->assertOk()->assertJsonPath('data.source_placement_id', $placement->id)->assertJsonPath('data.relationship_id', $link->id)->assertJsonPath('data.state', 'draft');
        $communication = TicketEmailOutboundCommunication::sole();
        $this->assertSame($communication->email_composer_draft_id, $read->json('data.draft_id'));
        $this->getJson($read->json('data.links.self'))->assertOk()->assertJsonPath('data.id', $communication->id);
        $this->getJson($read->json('data.links.draft'))->assertOk();
        $this->assertStringContainsString('/preview', $read->json('data.links.preview'));
        $this->assertStringContainsString('/send', $read->json('data.links.send'));
        $this->assertSame(0, EmailOutboundSubmission::count());
        Queue::assertNotPushed(SendTicketReplyEmail::class);
        Http::assertNothingSent();
    }

    public function test_retry_does_not_create_another_draft_even_after_the_original_was_sent(): void
    {
        [, , $link] = $this->linkedMessage(9102, '<api-retry-source@example.test>');
        $url = route('api.v1.tickets.email-relationships.reply-draft', [$this->ticket, $link->id]);
        $payload = ['mode' => 'reply', 'idempotency_key' => 'draft-retry'];
        $id = $this->postJson($url, $payload)->assertOk()->json('data.id');
        $communication = TicketEmailOutboundCommunication::findOrFail($id);
        $communication->forceFill(['state' => TicketEmailOutboundCommunication::STATE_ACCEPTED])->save();
        $communication->draft->forceFill(['status' => EmailComposerDraft::STATUS_SENT])->save();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.state', 'accepted');
        $this->assertSame(1, TicketEmailOutboundCommunication::count());
        $this->assertSame(1, EmailComposerDraft::count());
        $this->postJson($url, array_replace($payload, ['mode' => 'reply_all']))->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_current_mail_rights_and_native_token_grants_are_required_before_receipt_replay(): void
    {
        [, , $link] = $this->linkedMessage(9103, '<api-rights-source@example.test>');
        $url = route('api.v1.tickets.email-relationships.reply-draft', [$this->ticket, $link->id]);
        $payload = ['mode' => 'reply_all', 'idempotency_key' => 'mail-grant'];
        $id = $this->postJson($url, $payload)->assertOk()->json('data.id');
        Sanctum::actingAs($this->actor, ['tickets.email-drafts.create']);
        $this->postJson($url, $payload)->assertForbidden();
        Sanctum::actingAs($this->actor, ['tickets.email-drafts.create', 'tickets.email-drafts.read', 'email.drafts.write', 'email.drafts.read', 'email.read']);
        EmailAccountUserGrant::query()->where('email_account_id', $this->account->id)->update(['can_send' => false]);
        $this->postJson($url, $payload)->assertForbidden();
        EmailAccountUserGrant::query()->where('email_account_id', $this->account->id)->delete();
        $this->getJson(route('api.v1.tickets.email-communications.show', [$this->ticket, $id]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.email-relationships.index', $this->ticket))->assertOk()->assertJsonPath('meta.total', 0);
        $this->assertSame(1, EmailComposerDraft::count());
    }

    public function test_foreign_relationship_is_hidden_and_unpublished_ticket_cannot_prepare_a_reply(): void
    {
        [, , $link] = $this->linkedMessage(9104, '<api-foreign-source@example.test>');
        $other = app(\App\Modules\Ticket\Actions\StoreTicket::class)->handle(['subject' => 'Other Ticket'], $this->actor);
        $this->postJson(route('api.v1.tickets.email-relationships.reply-draft', [$other, $link->id]), ['mode' => 'reply', 'idempotency_key' => 'foreign-source'])->assertNotFound();
        $this->ticket->forceFill(['portal_visible_at' => null])->save();
        $this->postJson(route('api.v1.tickets.email-relationships.reply-draft', [$this->ticket, $link->id]), ['mode' => 'reply', 'idempotency_key' => 'unpublished'])->assertUnprocessable();
        $this->assertSame(0, EmailComposerDraft::count());
        Http::assertNothingSent();
    }

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
        $link = new EmailTicketConversationLink;
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
