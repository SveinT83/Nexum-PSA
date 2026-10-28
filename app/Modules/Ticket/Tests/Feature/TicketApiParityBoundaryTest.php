<?php

namespace App\Modules\Ticket\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Email\Models\EmailTemplate;
use App\Modules\Ticket\Actions\EnsureTicketDefaults;
use App\Modules\Ticket\Actions\StoreTicket;
use App\Modules\Ticket\Models\TicketEvent;
use App\Modules\Ticket\Models\TicketStatus;
use App\Modules\Ticket\Models\TicketWorkflow;
use App\Modules\Ticket\Models\TicketWorkflowEvidence;
use App\Modules\Ticket\Models\TicketWorkflowReview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketApiParityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    private User $tech;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $this->tech = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->tech->assignRole('Tech');
        foreach (['ticket.view', 'ticket.update', 'ticket.manage_workflows', 'ticket.workflow_publish', 'ticket.workflow_migrate', 'ticket.manage_settings'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        app(EnsureTicketDefaults::class)->handle();
        Sanctum::actingAs($this->tech, ['*']);
    }

    public function test_legacy_workflow_operations_require_current_actor_rights_even_with_full_token(): void
    {
        $ticket = $this->ticket();
        $this->tech->revokePermissionTo('ticket.view');
        $this->getJson(route('api.v1.tickets.workflow-decisions.show', $ticket))->assertForbidden();
        $this->tech->revokePermissionTo('ticket.manage_workflows');
        $this->getJson(route('api.v1.ticket-workflows.catalog'))->assertForbidden();
        $this->postJson(route('api.v1.ticket-workflows.store'), $this->definition())->assertForbidden();
        $this->assertDatabaseMissing('ticket_workflows', ['name' => 'API atomic workflow']);
    }

    public function test_workflow_catalog_get_never_initializes_email_templates(): void
    {
        $count = EmailTemplate::count();
        $this->getJson(route('api.v1.ticket-workflows.catalog'))->assertOk()->assertJsonStructure(['data' => ['requirements', 'actions', 'customer_notification_email_templates']]);
        $this->assertSame($count, EmailTemplate::count());
    }

    public function test_complete_workflow_definition_can_create_edit_publish_and_read_back_without_optional_slug(): void
    {
        $payload = $this->definition();
        $id = $this->postJson(route('api.v1.ticket-workflows.store'), $payload)->assertCreated()->assertJsonPath('data.workflow.slug', 'api-atomic-workflow')->json('data.workflow.id');
        $payload['name'] = 'Edited API workflow';
        $this->putJson(route('api.v1.ticket-workflows.update', $id), $payload)->assertOk()->assertJsonPath('data.workflow.name', 'Edited API workflow');
        $version = $this->postJson(route('api.v1.ticket-workflows.publish', $id))->assertCreated()->assertJsonPath('data.definition.states.0.state_key', 'open')->json('data.id');
        $this->getJson(route('api.v1.ticket-workflows.show', $id))->assertOk()->assertJsonPath('data.workflow.published_version_id', $version);
        $this->getJson(route('api.v1.ticket-workflows.index', ['per_page' => 1]))->assertOk()->assertJsonPath('meta.per_page', 1);
        $this->getJson(route('api.v1.ticket-workflows.index', ['per_page' => 101]))->assertUnprocessable();
    }

    public function test_invalid_workflow_draft_rolls_back_create_and_update_metadata(): void
    {
        $payload = $this->definition();
        $payload['definition']['states'][0]['is_initial'] = false;
        $this->postJson(route('api.v1.ticket-workflows.store'), $payload)->assertUnprocessable();
        $this->assertDatabaseMissing('ticket_workflows', ['name' => 'API atomic workflow']);
        $id = $this->postJson(route('api.v1.ticket-workflows.store'), $this->definition())->assertCreated()->json('data.workflow.id');
        $payload['name'] = 'Must roll back';
        $this->putJson(route('api.v1.ticket-workflows.update', $id), $payload)->assertUnprocessable();
        $this->assertSame('API atomic workflow', TicketWorkflow::findOrFail($id)->name);
        unset($payload['definition']['states'][0]['ticket_status_id']);
        $this->putJson(route('api.v1.ticket-workflows.update', $id), $payload)->assertUnprocessable();
    }

    public function test_archived_detail_conversation_files_and_history_are_readable_but_never_writable(): void
    {
        Storage::fake('local');
        $ticket = $this->ticket();
        $message = $ticket->messages()->create(['author_type' => 'user', 'author_id' => $this->tech->id, 'type' => 'internal_note', 'visibility' => 'internal', 'body' => 'Archived content']);
        Storage::disk('local')->put('archived/report.txt', 'Archived bytes');
        $attachment = $ticket->attachments()->create(['ticket_message_id' => $message->id, 'disk' => 'local', 'path' => 'archived/report.txt', 'filename' => 'report.txt', 'source' => 'manual', 'size_bytes' => 14]);
        $event = TicketEvent::create(['ticket_id' => $ticket->id, 'type' => 'test_event', 'message' => 'Readable history', 'metadata' => ['private' => 'must not leak']]);
        $ticket->delete();
        $this->getJson(route('api.v1.tickets.show', $ticket))->assertOk();
        $this->getJson(route('api.v1.tickets.conversation.index', $ticket))->assertOk()->assertJsonPath('data.0.body', 'Archived content');
        $this->getJson(route('api.v1.tickets.attachments.show', [$ticket, $attachment]))->assertOk()->assertJsonPath('data.filename', 'report.txt');
        $this->get(route('api.v1.tickets.attachments.download', [$ticket, $attachment]))->assertOk()->assertDownload('report.txt');
        $this->getJson(route('api.v1.tickets.history.show', [$ticket, $event->id]))->assertOk()->assertJsonPath('data.message', 'Readable history')->assertJsonMissingPath('data.metadata');
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['subject' => 'Denied'])->assertNotFound();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.history.show', [$ticket, $event->id]))->assertForbidden();
    }

    public function test_review_and_evidence_readback_is_nested_and_excludes_raw_requirement_snapshots(): void
    {
        $ticket = $this->ticket();
        $other = $this->ticket();
        $review = TicketWorkflowReview::create(['ticket_id' => $ticket->id, 'gate_key' => 'approval', 'state_key' => 'open', 'status' => 'pending', 'requested_by' => $this->tech->id, 'evidence_fingerprint' => str_repeat('a', 64), 'requirements_snapshot' => ['secret' => 'hidden'], 'comment' => 'Review note']);
        $evidence = TicketWorkflowEvidence::create(['ticket_id' => $ticket->id, 'evidence_type' => 'customer_response', 'source_type' => 'message', 'source_id' => 100, 'fingerprint' => str_repeat('b', 64), 'metadata' => ['secret' => 'hidden']]);
        $this->getJson(route('api.v1.tickets.workflow-reviews.show', [$ticket, $review]))->assertOk()->assertJsonPath('data.comment', 'Review note')->assertJsonMissingPath('data.requirements_snapshot');
        $this->getJson(route('api.v1.tickets.workflow-evidence.show', [$ticket, $evidence]))->assertOk()->assertJsonMissingPath('data.metadata');
        $this->getJson(route('api.v1.tickets.workflow-reviews.show', [$other, $review]))->assertNotFound();
        $this->getJson(route('api.v1.tickets.workflow-evidence.show', [$other, $evidence]))->assertNotFound();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.workflow-reviews.index', $ticket))->assertForbidden();
    }

    public function test_all_browser_list_filters_sort_and_query_booleans_are_composable(): void
    {
        $a = $this->ticket('Alpha');
        $b = $this->ticket('Beta');
        $b->forceFill(['is_unread' => true, 'owner_id' => $this->tech->id])->save();
        $this->getJson(route('api.v1.tickets.index', ['unread' => 'false', 'sort' => 'subject', 'direction' => 'asc']))->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.id', $a->id);
        $this->getJson(route('api.v1.tickets.index', ['unread' => 'true', 'owner_id' => $this->tech->id, 'lifecycle' => 'open']))->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', $b->id);
        $this->getJson(route('api.v1.tickets.index', ['unread' => 'invalid']))->assertUnprocessable();
        $this->getJson(route('api.v1.tickets.index', ['sort' => 'raw_sql']))->assertUnprocessable();
        $this->getJson(route('api.v1.tickets.index', ['per_page' => 101]))->assertUnprocessable();
    }

    public function test_capabilities_report_all_cumulative_grants_instead_of_false_positive_access(): void
    {
        Sanctum::actingAs($this->tech, ['tickets.read', 'tickets.time.suggest']);
        $operations = $this->getJson(route('api.v1.tickets.capabilities'))->assertOk()->json('data.operations');
        $suggestion = collect($operations)->firstWhere('name', 'api.v1.tickets.time-entries.invoice-text-suggestion');
        $this->assertContains('tickets.messages.read', $suggestion['required_scopes']);
        $this->assertFalse($suggestion['token_authorized']);
        $this->assertTrue($suggestion['object_and_runtime_checks_required']);
    }

    public function test_unsupported_write_fields_fail_instead_of_reporting_a_silently_ignored_edit(): void
    {
        $ticket = $this->ticket();
        $this->patchJson(route('api.v1.tickets.update', $ticket), ['subject' => 'Must not save', 'resolved_at' => now()->toISOString()])
            ->assertUnprocessable()->assertJsonValidationErrors('resolved_at');
        $this->assertSame('Boundary Ticket', $ticket->refresh()->subject);
    }

    public function test_quote_create_and_readback_keep_sales_values_without_public_access_tokens(): void
    {
        foreach (['ticket.plan_cost', 'sales.quote_manage'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->tech->givePermissionTo($permission);
        }
        $client = \App\Models\Clients\Client::factory()->create(['active' => true]);
        $ticket = app(StoreTicket::class)->handle(['subject' => 'API quote', 'client_id' => $client->id], $this->tech);
        $this->postJson(route('api.v1.tickets.planned-lines.store', $ticket), ['name' => 'Scope item', 'quantity' => 2, 'unit_price_ex_vat' => 100])->assertCreated();
        $response = $this->postJson(route('api.v1.tickets.sales-quote.store', $ticket))->assertCreated()->assertJsonMissingPath('data.secure_token')->assertJsonMissingPath('data.pdf_snapshot_path');
        $version = $response->json('data.id');
        $this->getJson(route('api.v1.tickets.quote-versions.show', [$ticket, $version]))->assertOk()->assertJsonPath('data.lines.0.name', 'Scope item')
            ->assertJsonMissingPath('data.secure_token')->assertJsonMissingPath('data.snapshots');
        $this->getJson(route('api.v1.tickets.quote-versions.show', [$this->ticket(), $version]))->assertNotFound();
        Sanctum::actingAs($this->tech, ['tickets.read']);
        $this->getJson(route('api.v1.tickets.quote-versions.index', $ticket))->assertForbidden();
    }

    private function ticket(string $subject = 'Boundary Ticket')
    {
        return app(StoreTicket::class)->handle(['subject' => $subject, 'client_id' => null], $this->tech);
    }

    private function definition(): array
    {
        $open = TicketStatus::where('is_closed', false)->firstOrFail();
        $closed = TicketStatus::where('is_closed', true)->firstOrFail();

        return ['name' => 'API atomic workflow', 'definition' => ['states' => [
            ['state_key' => 'open', 'name' => 'Open', 'ticket_status_id' => $open->id, 'is_initial' => true, 'is_terminal' => false],
            ['state_key' => 'done', 'name' => 'Done', 'ticket_status_id' => $closed->id, 'is_initial' => false, 'is_terminal' => true]],
            'transitions' => [['transition_key' => 'finish', 'from_state_key' => 'open', 'to_state_key' => 'done', 'label' => 'Finish', 'manual_enabled' => true]], 'escalation_paths' => []]];
    }
}
