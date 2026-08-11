<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Controllers\Api\V1\TicketController;
use App\Modules\Ticket\Jobs\SendTicketReplyEmail;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketExternalMessageApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function external_message_route_is_registered_with_the_expected_controller_and_scope(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.tickets.external-messages.store');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/tickets/{ticket}/external-messages', $route->uri());
        $this->assertSame(['POST'], $route->methods());
        $this->assertSame(TicketController::class.'@storeExternalMessage', $route->getActionName());
        $this->assertContains('Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:tickets.update', $route->gatherMiddleware());
    }

    #[Test]
    public function token_without_ticket_update_scope_receives_forbidden_instead_of_not_found(): void
    {
        $ticket = $this->createTicket();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Sanctum::actingAs($user, ['tickets.read']);

        $this->postJson(route('api.v1.tickets.external-messages.store', $ticket), $this->internalNotePayload())
            ->assertForbidden();
    }

    #[Test]
    public function coordinator_can_store_an_idempotent_internal_note_without_customer_email(): void
    {
        Queue::fake([SendTicketReplyEmail::class]);

        $ticket = $this->createTicket();
        $user = User::factory()->create(['status' => User::STATUS_ACTIVE]);

        Sanctum::actingAs($user, ['tickets.update']);

        $this->postJson(route('api.v1.tickets.external-messages.store', $ticket), $this->internalNotePayload())
            ->assertCreated()
            ->assertJsonPath('created', true)
            ->assertJsonPath('data.type', 'internal_note')
            ->assertJsonPath('data.visibility', 'internal');

        $this->postJson(route('api.v1.tickets.external-messages.store', $ticket), $this->internalNotePayload())
            ->assertOk()
            ->assertJsonPath('created', false);

        $this->assertSame(1, TicketMessage::query()
            ->where('ticket_id', $ticket->id)
            ->where('metadata->external_source', 'coordinator')
            ->where('metadata->external_id', 'coord-note-1')
            ->count());

        Queue::assertNotPushed(SendTicketReplyEmail::class);
    }

    private function createTicket(): Ticket
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        return Ticket::query()->create([
            'ticket_key' => 'TD-2026-995195',
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'channel' => 'api',
            'subject' => 'Coordinator internal-note route verification',
            'is_unread' => false,
        ]);
    }

    private function internalNotePayload(): array
    {
        return [
            'source' => 'coordinator',
            'external_id' => 'coord-note-1',
            'type' => 'internal_note',
            'visibility' => 'internal',
            'body' => 'Internal coordinator trace note.',
        ];
    }
}
