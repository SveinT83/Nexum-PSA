<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Queries\ClientTicketListQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ClientTicketListQueryTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_returns_only_accessible_non_deleted_tickets_for_the_selected_client(): void
    {
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        Permission::findOrCreate('ticket.view', 'web');
        $actor->givePermissionTo('ticket.view');

        $client = Client::factory()->create();
        $otherClient = Client::factory()->create();

        $olderTicket = Ticket::factory()->create([
            'ticket_key' => 'TD-2026-188101',
            'client_id' => $client->id,
            'subject' => 'Older selected client ticket',
            'updated_at' => now()->subDay(),
        ]);
        $newerTicket = Ticket::factory()->create([
            'ticket_key' => 'TD-2026-188102',
            'client_id' => $client->id,
            'subject' => 'Newer selected client ticket',
            'updated_at' => now(),
        ]);
        Ticket::factory()->create([
            'ticket_key' => 'TD-2026-188103',
            'client_id' => $otherClient->id,
            'subject' => 'Other client ticket',
        ]);
        $deletedTicket = Ticket::factory()->create([
            'ticket_key' => 'TD-2026-188104',
            'client_id' => $client->id,
            'subject' => 'Deleted selected client ticket',
        ]);
        $deletedTicket->delete();

        $tickets = app(ClientTicketListQuery::class)->forClient($client, $actor);

        $this->assertSame(
            [$newerTicket->id, $olderTicket->id],
            $tickets->pluck('id')->all(),
        );
        $this->assertTrue($tickets->every(
            fn (Ticket $ticket): bool => $ticket->relationLoaded('status')
                && $ticket->relationLoaded('priority')
                && $ticket->relationLoaded('queue')
                && $ticket->relationLoaded('owner'),
        ));
    }

    #[Test]
    public function it_returns_no_tickets_when_the_actor_lacks_ticket_view_permission(): void
    {
        Permission::findOrCreate('ticket.view', 'web');
        $actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $client = Client::factory()->create();
        Ticket::factory()->create([
            'ticket_key' => 'TD-2026-188105',
            'client_id' => $client->id,
            'subject' => 'Permission protected client ticket',
        ]);

        $tickets = app(ClientTicketListQuery::class)->forClient($client, $actor);

        $this->assertTrue($tickets->isEmpty());
    }
}
