<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Models\Knowledge\DocumentationRequest;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Queries\TicketSlaReportQuery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TicketFollowUpApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        app(EnsureTicketDefaults::class)->handle();
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        foreach (['ticket.view', 'ticket.update', 'report.view'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->actor->givePermissionTo($permission);
        }
        Sanctum::actingAs($this->actor, ['tickets.documentation.read', 'tickets.documentation.request', 'tickets.reports.read']);
    }

    public function test_documentation_request_is_persisted_once_and_publication_status_is_independent(): void
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Documentation request'], $this->actor);
        $url = route('api.v1.tickets.documentation-requests.store', $ticket);
        $payload = ['reason' => 'Document the verified repair', 'idempotency_key' => 'docs-once'];
        $id = $this->postJson($url, $payload)->assertCreated()->assertJsonPath('data.status', 'open')->assertJsonPath('data.publication_read_back_at', null)->json('data.id');
        $count = TicketEvent::count();
        $this->postJson($url, $payload)->assertOk()->assertJsonPath('data.id', $id);
        $this->assertSame(1, DocumentationRequest::count());
        $this->assertSame($count, TicketEvent::count());
        $this->postJson($url, array_replace($payload, ['reason' => 'Different scope']))->assertStatus(409);
        $this->getJson(route('api.v1.tickets.documentation-requests.index', $ticket))->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson(route('api.v1.tickets.documentation-requests.show', [$ticket, $id]))->assertOk()->assertJsonPath('data.reason', 'Document the verified repair');
        $other = app(StoreTicket::class)->handle(['subject' => 'Other Ticket'], $this->actor);
        $this->getJson(route('api.v1.tickets.documentation-requests.show', [$other, $id]))->assertNotFound();
    }

    public function test_sla_report_uses_the_same_stored_timestamp_calculations_and_context_filters(): void
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Internal overdue fixture'], $this->actor);
        $ticket->forceFill(['first_response_due_at' => now()->subHour(), 'first_responded_at' => null])->save();
        $expected = app(TicketSlaReportQuery::class)->summary(null, context: 'all');
        $read = $this->getJson(route('api.v1.tickets.reports.sla', ['period' => 'all', 'context' => 'all', 'limit' => 1]))->assertOk()->assertJsonPath('data.summary', $expected)->assertJsonCount(1, 'data.overdue_tickets');
        $this->assertSame($ticket->id, $read->json('data.overdue_tickets.0.id'));
        $this->getJson(route('api.v1.tickets.reports.sla', ['context' => 'client']))->assertOk()->assertJsonCount(0, 'data.overdue_tickets');
        $this->getJson(route('api.v1.tickets.reports.sla', ['limit' => 101]))->assertUnprocessable();
    }

    public function test_read_only_scopes_and_actual_domain_permissions_remain_separate(): void
    {
        $ticket = app(StoreTicket::class)->handle(['subject' => 'Rights fixture'], $this->actor);
        Sanctum::actingAs($this->actor, ['tickets.documentation.read']);
        $this->postJson(route('api.v1.tickets.documentation-requests.store', $ticket), ['idempotency_key' => 'deny'])->assertForbidden();
        $this->getJson(route('api.v1.tickets.reports.sla'))->assertForbidden();
        Sanctum::actingAs($this->actor, ['tickets.reports.read']);
        $this->actor->revokePermissionTo('report.view');
        $this->getJson(route('api.v1.tickets.reports.sla'))->assertForbidden();
        $this->assertSame(0, DocumentationRequest::count());
    }
}
