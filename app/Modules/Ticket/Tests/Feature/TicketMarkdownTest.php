<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketMarkdownTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function ticket_description_and_message_render_markdown_with_original_edit_source(): void
    {
        Notification::fake();
        Queue::fake();
        Role::firstOrCreate(['name' => 'Tech']);
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $actor->assignRole('Tech');
        $actor->givePermissionTo(['ticket.view', 'ticket.create', 'ticket.update', 'ticket.note_internal']);
        $source = "**Første forespørsel**\nNeste linje\n\n- En liste";
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Markdown ticket fixture',
            'description' => $source, 'owner_id' => $actor->id], $actor);
        $message = TicketMessage::create(['ticket_id' => $ticket->id, 'author_id' => $actor->id,
            'author_type' => 'user', 'type' => 'internal_note', 'visibility' => 'internal',
            'body' => '**Notat** og `kode`']);
        $this->actingAs($actor)->get(route('tech.tickets.show', $ticket))
            ->assertOk()->assertSee('<strong>Første forespørsel</strong>', false)
            ->assertSee('<li>En liste</li>', false)->assertSee('<strong>Notat</strong>', false)
            ->assertSee('<code>kode</code>', false)->assertSee('Markdown supported');
        $this->assertSame($source, $ticket->fresh()->description);
        $this->assertSame('**Notat** og `kode`', $message->fresh()->body);
        $this->actingAs($actor)->get(route('tech.tickets.edit', $ticket))
            ->assertOk()->assertSee('**Første forespørsel**')->assertSee('Markdown supported');
        \Laravel\Sanctum\Sanctum::actingAs($actor, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.show', $ticket))->assertOk()
            ->assertJsonPath('data.description', $source);
    }
}
