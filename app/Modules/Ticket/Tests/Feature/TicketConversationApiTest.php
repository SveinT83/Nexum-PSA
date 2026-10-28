<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Core\User;
use App\Modules\Ticket\Actions\AddTicketMessage;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Jobs\SendTicketInternalNotificationEmail;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketMessage;
use App\Modules\Ticket\Support\TicketSolutionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketConversationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        foreach (['ticket.view', 'ticket.note_internal', 'ticket.reply_customer'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
    }

    public function test_metadata_scope_cannot_read_bodies_or_download_files(): void
    {
        $ticket = $this->ticket();
        $message = $this->note($ticket, ['attachments' => [$this->file()]]);
        $attachment = $message->fileAttachments()->firstOrFail();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.messages.index', $ticket))->assertOk()->assertJsonMissingPath('data.0.body');
        $this->getJson(route('api.v1.tickets.conversation.index', $ticket))->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.messages.read']);
        $this->getJson(route('api.v1.tickets.conversation.show', [$ticket, $message]))->assertOk()->assertJsonPath('data.body', 'Private technician note');
        $this->getJson(route('api.v1.tickets.attachments.download', [$ticket, $attachment]))->assertForbidden();
        Sanctum::actingAs($this->tech, ['tickets.attachments.download']);
        $this->get(route('api.v1.tickets.attachments.download', [$ticket, $attachment]))->assertOk()->assertDownload('evidence.txt');
        $this->tech->revokePermissionTo('ticket.view');
        $this->getJson(route('api.v1.tickets.attachments.download', [$ticket, $attachment]))->assertForbidden();
    }

    public function test_conversation_is_bounded_safe_and_get_does_not_acknowledge_replies(): void
    {
        $ticket = $this->ticket();
        $message = $this->note($ticket, ['metadata' => ['untrusted_secret' => 'never-export', 'from_email' => 'customer@example.test'], 'attachments' => [$this->file()]]);
        $second = $this->note($ticket, ['body' => 'Second note']);
        $ticket->forceFill(['is_unread' => true])->save();
        $events = TicketEvent::count();
        Sanctum::actingAs($this->tech, ['tickets.messages.read']);
        $this->getJson(route('api.v1.tickets.conversation.index', ['ticket' => $ticket, 'per_page' => 1]))
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $message->id)
            ->assertJsonMissingPath('data.0.metadata.untrusted_secret')->assertJsonPath('data.0.metadata.from_email', 'customer@example.test')
            ->assertJsonMissingPath('data.0.attachments.0.path')->assertJsonMissingPath('data.0.attachments.0.disk');
        $this->getJson(route('api.v1.tickets.conversation.index', ['ticket' => $ticket, 'per_page' => 1, 'page' => 2]))->assertOk()->assertJsonPath('data.0.id', $second->id);
        $this->getJson(route('api.v1.tickets.conversation.index', ['ticket' => $ticket, 'per_page' => 101]))->assertUnprocessable();
        $this->assertTrue($ticket->refresh()->is_unread);
        $this->assertNull($message->refresh()->read_at);
        $this->assertSame($events, TicketEvent::count());
    }

    public function test_foreign_deleted_and_inconsistent_attachment_owners_are_hidden(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        $message = $this->note($ticket, ['attachments' => [$this->file()]]);
        $attachment = $message->fileAttachments()->firstOrFail();
        Sanctum::actingAs($this->tech, ['tickets.messages.read', 'tickets.attachments.download']);
        $this->getJson(route('api.v1.tickets.conversation.show', [$other, $message]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.attachments.show', [$other, $attachment]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.attachments.index', $ticket))->assertOk()->assertJsonPath('meta.total', 1);
        $attachment->forceFill(['ticket_id' => $other->id])->save();
        $this->getJson(route('api.v1.tickets.conversation.show', [$ticket, $message]))->assertOk()->assertJsonPath('data.attachments', []);
        $this->getJson(route('api.v1.tickets.attachments.download', [$other, $attachment]))->assertNotFound();
        $attachment->forceFill(['ticket_id' => $ticket->id])->save();
        $message->delete();
        $this->getJson(route('api.v1.tickets.attachments.show', [$ticket, $attachment]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.attachments.index', $ticket))->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_internal_note_has_explicit_rights_and_idempotent_attachment_and_notification_effects(): void
    {
        $ticket = $this->ticket();
        $recipient = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Sanctum::actingAs($this->tech, ['tickets.notes.create']);
        $payload = ['body' => 'Saved note', 'idempotency_key' => 'note-with-file', 'notify_user_id' => $recipient->id, 'attachments' => [$this->file()]];
        $first = $this->post(route('api.v1.tickets.internal-notes.store', $ticket), $payload, ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('created', true)->assertJsonPath('data.type', 'internal_note')->assertJsonPath('data.visibility', 'internal')
            ->assertJsonPath('data.attachments.0.filename', 'evidence.txt');
        $id = $first->json('data.id');
        $payload['attachments'] = [$this->file()];
        $this->post(route('api.v1.tickets.internal-notes.store', $ticket), $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('created', false)->assertJsonPath('data.id', $id);
        $this->assertSame(1, TicketMessage::where('idempotency_key', 'note-with-file')->count());
        $this->assertSame(1, TicketMessage::findOrFail($id)->fileAttachments()->count());
        Queue::assertPushed(SendTicketInternalNotificationEmail::class, 1);
        Queue::assertNotPushed(SendTicketReplyEmail::class);
        $payload['attachments'] = [$this->file('Changed bytes')];
        $this->post(route('api.v1.tickets.internal-notes.store', $ticket), $payload, ['Accept' => 'application/json'])->assertStatus(409);
        $this->tech->revokePermissionTo('ticket.note_internal');
        $this->postJson(route('api.v1.tickets.internal-notes.store', $ticket), ['body' => 'Denied', 'idempotency_key' => 'denied'])->assertForbidden();
    }

    public function test_note_policy_and_payload_limits_are_enforced_and_deleted_keys_stay_reserved(): void
    {
        $ticket = $this->ticket();
        Sanctum::actingAs($this->tech, ['tickets.notes.create']);
        app(TicketSolutionPolicy::class)->update(['allow_internal_solution_notes' => false]);
        $payload = ['body' => 'Solution', 'idempotency_key' => 'policy', 'mark_as_solution' => true];
        $this->postJson(route('api.v1.tickets.internal-notes.store', $ticket), $payload)->assertUnprocessable()->assertJsonValidationErrors('mark_as_solution');
        $payload['mark_as_solution'] = false;
        $id = $this->postJson(route('api.v1.tickets.internal-notes.store', $ticket), $payload)->assertCreated()->json('data.id');
        TicketMessage::findOrFail($id)->delete();
        $this->postJson(route('api.v1.tickets.internal-notes.store', $ticket), $payload)->assertStatus(409);
        $this->post(route('api.v1.tickets.internal-notes.store', $ticket), ['body' => 'Too many files', 'idempotency_key' => 'limits', 'attachments' => array_map(fn () => $this->file(), range(1, 6))], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('attachments');
        $inactive = User::factory()->create(['status' => 'pending']);
        $this->postJson(route('api.v1.tickets.internal-notes.store', $ticket), ['body' => 'Invalid recipient', 'idempotency_key' => 'inactive-recipient', 'notify_user_id' => $inactive->id])->assertUnprocessable()->assertJsonValidationErrors('notify_user_id');
    }

    public function test_selecting_existing_solution_is_nested_policy_checked_and_idempotent(): void
    {
        $ticket = $this->ticket();
        $message = $this->note($ticket);
        Sanctum::actingAs($this->tech, ['tickets.solutions.mark']);
        $route = route('api.v1.tickets.messages.solution', [$ticket, $message]);
        $this->postJson($route)->assertOk()->assertJsonPath('data.metadata.is_solution', true);
        $events = TicketEvent::where('ticket_id', $ticket->id)->count();
        $this->postJson($route)->assertOk();
        $this->assertSame($events, TicketEvent::where('ticket_id', $ticket->id)->count());
        $this->postJson(route('api.v1.tickets.messages.solution', [$this->ticket(), $message]))->assertNotFound();
        $next = $this->note($ticket, ['body' => 'Policy blocked note']);
        app(TicketSolutionPolicy::class)->update(['allow_internal_solution_notes' => false]);
        $this->postJson(route('api.v1.tickets.messages.solution', [$ticket, $next]))->assertUnprocessable();
    }

    public function test_customer_reply_accepts_files_with_stable_retry_and_preserves_customer_delivery(): void
    {
        $client = Client::factory()->create();
        $site = ClientSite::factory()->create(['client_id' => $client->id]);
        $contact = ClientUser::factory()->create(['client_site_id' => $site->id, 'active' => true, 'email' => 'customer@example.test']);
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Customer reply', 'client_id' => $client->id, 'site_id' => $site->id, 'contact_id' => $contact->id, 'owner_id' => $this->tech->id], $this->tech);
        $ticket->forceFill(['portal_visible_at' => now(), 'portal_visible_by' => $this->tech->id])->save();
        Sanctum::actingAs($this->tech, ['tickets.reply_customer']);
        $payload = ['body' => 'Reply with evidence', 'idempotency_key' => 'reply-attachment', 'attachments' => [$this->file()]];
        $url = route('api.v1.tickets.messages.store', $ticket);
        $first = $this->post($url, $payload, ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.message.attachments.0.filename', 'evidence.txt');
        $payload['attachments'] = [$this->file()];
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.message.id', $first->json('data.message.id'));
        $payload['attachments'] = [$this->file('Different bytes')];
        $this->post($url, $payload, ['Accept' => 'application/json'])->assertStatus(409);
        Queue::assertPushed(SendTicketReplyEmail::class, 1);
    }

    private function ticket(): Ticket
    {
        return app(StoreTicket::class)->handle(['subject' => 'Conversation test', 'client_id' => null, 'owner_id' => $this->tech->id], $this->tech);
    }

    private function note(Ticket $ticket, array $data = []): TicketMessage
    {
        return app(AddTicketMessage::class)->handle($ticket, array_merge(['body' => 'Private technician note', 'type' => 'internal_note', 'visibility' => 'internal'], $data), $this->tech);
    }

    private function file(string $bytes = 'Verified attachment bytes'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('evidence.txt', $bytes);
    }
}
