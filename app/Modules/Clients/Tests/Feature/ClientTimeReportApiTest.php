<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Clients\Queries\ClientTimeReportQuery;
use App\Modules\Clients\Reports\ClientTimeReportDefinition;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** API/browser parity with synthetic records; no live financial writes. */
class ClientTimeReportApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Role::findOrCreate('Tech', 'web');
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole('Tech');
        foreach (ClientTimeReportDefinition::PERMISSIONS as $right) {
            Permission::findOrCreate($right, 'web');
            $this->actor->givePermissionTo($right);
        }
        $this->client = Client::factory()->create();
        Sanctum::actingAs($this->actor, ['clients.reports.time.read', 'clients.reports.time.export', 'report.read']);
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/clients/'.$this->client->id.'/reports/time'.$suffix;
    }

    private function fixture(): Ticket
    {
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id]);
        $task = app(StoreTask::class)->handle(['title' => 'Synthetic registered effort'], $this->actor, $ticket);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->actor->id, 'source_type' => 'manual', 'work_date' => '2026-11-01', 'minutes' => 5]);
        $ticket->timeEntries()->create(['task_id' => $task->id, 'user_id' => $this->actor->id, 'type' => 'manual', 'work_date' => '2026-11-01', 'minutes' => 30, 'billable' => true, 'billing_status' => 'pending', 'timebank_status' => 'pending']);

        return $ticket;
    }

    public function test_json_summary_and_pages_match_shared_browser_authority(): void
    {
        $this->fixture();
        $expected = app(ClientTimeReportQuery::class)->build($this->client, $this->actor, null, null);
        $response = $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.work_minutes', 5)->assertJsonPath('data.totals.basis_minutes', 30)->assertJsonPath('meta.truncated', false);
        $this->assertSame($expected['totals'], $response->json('data.totals'));
        $this->assertTrue($response->headers->hasCacheControlDirective('no-store'));
        $this->getJson($this->url('/items').'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('meta.totals.work_minutes', 5);
        $this->getJson($this->url('/items').'?per_page=1&page=2')->assertOk()->assertJsonPath('data.0.type', 'Task');
        $this->getJson($this->url('/entries').'?per_page=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2);
        $this->getJson($this->url('/entries').'?page=99')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 2);
    }

    public function test_dates_and_validation_cover_all_operations(): void
    {
        $this->fixture();
        $this->getJson($this->url().'?date_from=2026-11-01&date_to=2026-11-01')->assertOk()->assertJsonPath('data.totals.work_minutes', 5);
        $this->getJson($this->url().'?date_to=2026-10-31')->assertOk()->assertJsonPath('data.entry_count', 0);
        foreach (['?date_from=no', '?date_from=2026-11-02&date_to=2026-11-01', '?page=0', '?per_page=101', '?format=pdf'] as $bad) {
            $this->getJson($this->url().$bad)->assertUnprocessable();
        }
        $this->getJson($this->url('/export'))->assertUnprocessable();
        $this->getJson($this->url('/export').'?format=csv')->assertUnprocessable();
        $this->getJson('/api/v1/clients/999999/reports/time')->assertNotFound();
    }

    public function test_metadata_and_worklog_scopes_do_not_grant_report_data_and_exports_need_both_abilities(): void
    {
        foreach ([['report.read'], ['worklog.read', 'time-entries.read'], ['clients.reports.time.export']] as $abilities) {
            Sanctum::actingAs($this->actor, $abilities);
            foreach (['', '/items', '/entries', '/export?format=pdf'] as $path) {
                $this->getJson($this->url($path))->assertForbidden();
            }
        }
        Sanctum::actingAs($this->actor, ['clients.reports.time.read']);
        $this->getJson($this->url())->assertOk();
        $this->getJson($this->url('/export').'?format=pdf')->assertForbidden();
    }

    public function test_each_native_permission_and_inactive_actor_denies_even_with_token_grants(): void
    {
        foreach (ClientTimeReportDefinition::PERMISSIONS as $right) {
            $this->actor->revokePermissionTo($right);
            foreach (['', '/items', '/entries', '/export?format=pdf'] as $path) {
                $this->getJson($this->url($path))->assertForbidden();
            }
            $this->actor->givePermissionTo($right);
        }
        $this->actor->update(['status' => 'inactive']);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_private_task_and_projection_and_other_customer_remain_hidden(): void
    {
        $ticket = $this->fixture();
        $task = \App\Modules\Task\Models\Task::first();
        $other = User::factory()->create();
        $task->update(['visibility' => 'private', 'created_by' => $other->id, 'assigned_to' => null]);
        $foreign = Ticket::factory()->create(['client_id' => Client::factory()->create()->id]);
        $foreign->timeEntries()->create(['user_id' => $this->actor->id, 'type' => 'manual', 'work_date' => '2026-11-01', 'minutes' => 999, 'billable' => true]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('data.totals.work_minutes', 0)->assertJsonPath('data.totals.basis_minutes', 0);
        $this->getJson($this->url('/entries'))->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_binary_downloads_and_discovery_contract(): void
    {
        $this->fixture();
        $pdf = $this->get($this->url('/export').'?format=pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $xlsx = $this->get($this->url('/export').'?format=xlsx')->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringStartsWith('PK', $xlsx->streamedContent());
        $this->getJson('/api/v1/reports/client.time')->assertOk()->assertJsonCount(4, 'data.api_endpoints')->assertJsonPath('data.api_endpoints.3.abilities.1', 'clients.reports.time.export');
        $this->getJson('/api/v1/reports/ticket.sla')->assertOk()->assertJsonPath('data.api_endpoints.0.abilities.0', 'tickets.reports.read');
        $catalog = app(ApiAbilityCatalog::class);
        $this->assertContains('clients.reports.time.read', $catalog->values());
        $this->assertTrue($catalog->isReadOnly('clients.reports.time.export'));
    }

    public function test_oversized_api_reports_fail_instead_of_silently_truncating(): void
    {
        $ticket = Ticket::factory()->create(['client_id' => $this->client->id]);
        foreach (array_chunk(array_fill(0, 10001, ['ticket_id' => $ticket->id, 'user_id' => $this->actor->id, 'type' => 'manual', 'work_date' => '2026-11-01', 'minutes' => 1, 'billable' => true]), 500) as $rows) {
            \App\Modules\Ticket\Models\TicketTimeEntry::insert($rows);
        }
        foreach (['', '/items', '/entries', '/export?format=xlsx'] as $path) {
            $this->getJson($this->url($path))->assertUnprocessable()->assertJsonValidationErrors('date_from');
        }
    }
}
