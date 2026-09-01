<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Controllers\Api\V1\TicketMessageController;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TicketMessageReadApiTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function message_metadata_route_is_registered_with_ticket_read_scope(): void
    {
        $route = Route::getRoutes()->getByName('api.v1.tickets.messages.index');

        $this->assertNotNull($route);
        $this->assertSame('api/v1/tickets/{ticket}/messages', $route->uri());
        $this->assertSame(['GET', 'HEAD'], $route->methods());
        $this->assertSame(TicketMessageController::class.'@index', $route->getActionName());
        $this->assertContains(
            'Laravel\\Sanctum\\Http\\Middleware\\CheckAbilities:tickets.read',
            $route->gatherMiddleware()
        );
    }

    #[Test]
    public function ticket_read_scope_returns_paginated_metadata_and_first_response_summary(): void
    {
        $ticket = $this->createTicket('TD-2026-995240');
        $otherTicket = $this->createTicket('TD-2026-995241');
        $dueAt = Carbon::parse('2026-08-25 12:00:00', 'UTC');
        $respondedAt = $dueAt->copy()->subMinutes(10);

        $ticket->forceFill([
            'first_response_due_at' => $dueAt,
            'first_responded_at' => $respondedAt,
        ])->save();

        $older = $this->createMessage($ticket, [
            'type' => 'customer_reply',
            'visibility' => 'public',
            'author_type' => 'contact',
            'body' => 'Customer content must remain private.',
            'subject' => 'Private subject',
            'metadata' => ['private_reference' => 'customer-secret'],
        ], $respondedAt->copy()->subMinute());

        $newer = $this->createMessage($ticket, [
            'type' => 'internal_note',
            'visibility' => 'internal',
            'author_type' => 'user',
            'body' => 'Internal content must remain private.',
            'attachments' => [['name' => 'private-file.txt']],
        ], $respondedAt->copy()->addMinute());

        $this->createMessage($otherTicket, [
            'body' => 'Other Ticket content.',
        ], $respondedAt);

        Sanctum::actingAs(User::factory()->create(['status' => User::STATUS_ACTIVE]), ['tickets.read']);

        $response = $this->getJson(route('api.v1.tickets.messages.index', [
            'ticket' => $ticket,
            'per_page' => 1,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.type', 'internal_note')
            ->assertJsonPath('data.0.visibility', 'internal')
            ->assertJsonPath('data.0.author_type', 'user')
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('summary.has_messages', true)
            ->assertJsonPath('summary.has_first_response', true)
            ->assertJsonPath('summary.first_responded_at', $respondedAt->toISOString())
            ->assertJsonPath('summary.first_response_due_at', $dueAt->toISOString())
            ->assertJsonPath('summary.first_response_within_due_at', true)
            ->assertJsonMissingPath('data.0.body')
            ->assertJsonMissingPath('data.0.subject')
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('data.0.attachments')
            ->assertJsonMissingPath('data.0.author_id')
            ->assertJsonMissing(['id' => $older->id]);

        $this->assertStringNotContainsString('Customer content must remain private.', $response->getContent());
        $this->assertStringNotContainsString('Internal content must remain private.', $response->getContent());
        $this->assertStringNotContainsString('customer-secret', $response->getContent());
        $this->assertStringNotContainsString('private-file.txt', $response->getContent());
    }

    #[Test]
    public function token_without_ticket_read_scope_is_forbidden(): void
    {
        $ticket = $this->createTicket('TD-2026-995242');

        Sanctum::actingAs(User::factory()->create(['status' => User::STATUS_ACTIVE]), ['tickets.update']);

        $this->getJson(route('api.v1.tickets.messages.index', $ticket))
            ->assertForbidden();
    }

    #[Test]
    public function message_metadata_pagination_is_bounded(): void
    {
        $ticket = $this->createTicket('TD-2026-995243');

        Sanctum::actingAs(User::factory()->create(['status' => User::STATUS_ACTIVE]), ['tickets.read']);

        $this->getJson(route('api.v1.tickets.messages.index', [
            'ticket' => $ticket,
            'per_page' => 101,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    private function createTicket(string $ticketKey): Ticket
    {
        $defaults = app(EnsureTicketDefaults::class)->handle();

        return Ticket::query()->create([
            'ticket_key' => $ticketKey,
            'queue_id' => $defaults['queue']->id,
            'ticket_type_id' => $defaults['type']->id,
            'type' => $defaults['type']->slug,
            'status_id' => $defaults['status']->id,
            'priority_id' => $defaults['priority']->id,
            'channel' => 'api',
            'subject' => 'Ticket message read API verification',
            'is_unread' => false,
        ]);
    }

    private function createMessage(Ticket $ticket, array $attributes, Carbon $createdAt): TicketMessage
    {
        $message = TicketMessage::query()->create(array_merge([
            'ticket_id' => $ticket->id,
            'type' => 'internal_note',
            'visibility' => 'internal',
            'author_type' => 'user',
            'body' => 'Hidden message body.',
        ], $attributes));

        $message->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $message;
    }
}
