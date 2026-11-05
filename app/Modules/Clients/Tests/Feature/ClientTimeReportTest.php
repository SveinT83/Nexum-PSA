<?php

namespace App\Modules\Clients\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Clients\Queries\ClientTimeReportQuery;
use App\Modules\Clients\Reports\ClientTimeReportDefinition;
use App\Modules\Commercial\Models\Contracts\ClientContractTimeConsumption;
use App\Modules\Commercial\Models\Contracts\ContractItem;
use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Report\Support\ReportRegistry;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Ticket\Models\TicketTimeEntryAllocation;
use App\Modules\WorkContext\Actions\ResolveWorkContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Synthetic data only. Financial sources remain unchanged while reading and exporting. */
class ClientTimeReportTest extends TestCase
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
        foreach (ClientTimeReportDefinition::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
            $this->actor->givePermissionTo($permission);
        }
        $this->client = Client::factory()->create(['name' => 'Trønder Service synthetic', 'website' => 'https://tronderservice.test']);
        $this->actingAs($this->actor);
    }

    public function test_actual_and_task_billing_are_distinct_with_zero_time_and_archived_history(): void
    {
        $ticket = $this->ticket();
        $task = app(StoreTask::class)->handle(['title' => 'Five minutes actual'], $this->actor, $ticket);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->actor->id, 'source_type' => 'manual',
            'work_date' => '2026-11-01', 'minutes' => 5, 'billable' => true, 'note' => 'Actual work']);
        $this->entry($ticket, ['task_id' => $task->id, 'minutes' => 30]);
        $this->entry($ticket, ['minutes' => 12, 'billable' => false]);
        $zero = $this->ticket(['subject' => 'Zero-time closed archive']);
        $zero->delete();
        $report = $this->report();
        $this->assertSame(17, $report['totals']['work_minutes']);
        $this->assertSame(30, $report['totals']['basis_minutes']);
        $this->assertSame(30, $report['totals']['unresolved_minutes']);
        $this->assertCount(3, $report['objects']);
        $this->assertCount(3, $report['entries']);
        $taskRow = $report['objects']->firstWhere('type', 'Task');
        $this->assertSame(5, $taskRow['work_minutes']);
        $this->assertSame(30, $taskRow['basis_minutes']);
        $this->assertSame('ticket:'.$ticket->id, $taskRow['parent']);
        $this->get($this->url())->assertOk()->assertSee('Zero-time closed archive')->assertSee('Archived')
            ->assertSee('Actual work')->assertSee('Internal report');
    }

    public function test_filters_use_inclusive_work_dates_keep_zero_time_objects_and_support_one_sided_dates(): void
    {
        $ticket = $this->ticket();
        foreach (['2026-10-31', '2026-11-01', '2026-11-02', '2026-11-03'] as $date) {
            $this->entry($ticket, ['work_date' => $date, 'minutes' => 10]);
        }
        $response = $this->get($this->url(['date_from' => '2026-11-01', 'date_to' => '2026-11-02']))->assertOk();
        $this->assertSame(20, $response->viewData('report')['totals']['work_minutes']);
        $this->assertCount(2, $response->viewData('report')['entries']);
        $this->get($this->url(['date_to' => '2026-10-31']))->assertOk();
        $this->get($this->url(['date_from' => '2027-01-01']))->assertOk()
            ->assertSee('No time registered in the selected period.')->assertSee($ticket->subject);
        $this->getJson($this->url(['date_from' => '2026-11-02', 'date_to' => '2026-11-01']))
            ->assertUnprocessable()->assertJsonValidationErrors('date_to');
        $this->getJson($this->url(['date_from' => 'tomorrow']))->assertUnprocessable();
    }

    public function test_private_tasks_and_their_billing_are_hidden_but_visible_to_the_owner(): void
    {
        $ticket = $this->ticket();
        $other = User::factory()->create();
        $task = app(StoreTask::class)->handle(['title' => 'Private hidden title', 'visibility' => Task::VISIBILITY_PRIVATE], $other, $ticket);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $other->id, 'source_type' => 'manual',
            'work_date' => '2026-11-01', 'minutes' => 8, 'note' => 'Private hidden note']);
        $this->entry($ticket, ['task_id' => $task->id, 'minutes' => 30, 'note' => 'Private hidden billing']);
        $this->assertSame(0, $this->report()['totals']['basis_minutes']);
        $this->get($this->url())->assertOk()->assertDontSee('Private hidden');
        $task->update(['assigned_to' => $this->actor->id]);
        $this->assertSame(8, $this->report()['totals']['work_minutes']);
        $this->assertSame(30, $this->report()['totals']['basis_minutes']);
    }

    public function test_other_clients_internal_context_and_inconsistent_owners_do_not_leak(): void
    {
        $other = Client::factory()->create();
        $otherTicket = $this->ticket(['client_id' => $other->id, 'subject' => 'Other customer hidden']);
        $this->entry($otherTicket, ['minutes' => 900]);
        $internal = app(ResolveWorkContext::class)->internal();
        $mismatch = $this->ticket(['work_context_id' => $internal->id, 'subject' => 'Internal mismatch hidden']);
        $this->entry($mismatch, ['minutes' => 900]);
        $task = app(StoreTask::class)->handle(['title' => 'Wrong owner hidden'], $this->actor, $otherTicket);
        $task->update(['client_id' => $this->client->id, 'work_context_id' => null]);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->actor->id, 'source_type' => 'manual',
            'work_date' => '2026-11-01', 'minutes' => 900]);
        $this->assertCount(0, $this->report()['entries']);
        $this->get($this->url())->assertOk()->assertDontSee('Other customer hidden')
            ->assertDontSee('Internal mismatch hidden')->assertDontSee('Wrong owner hidden');
    }

    public function test_ordered_and_allocated_history_remains_visible_and_sources_are_unchanged(): void
    {
        $ticket = $this->ticket();
        $entry = $this->entry($ticket, ['minutes' => 60, 'billing_status' => 'processed']);
        TicketTimeEntryAllocation::create(['ticket_time_entry_id' => $entry->id, 'ticket_id' => $ticket->id,
            'client_id' => $this->client->id, 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'included_minutes' => 40, 'covered_minutes' => 40, 'billable_minutes' => 20, 'status' => 'allocated']);
        $order = EconomyOrder::create(['client_id' => $this->client->id,
            'period_start' => '2026-11-01', 'period_end' => '2026-11-30', 'status' => 'draft']);
        EconomyOrderLine::create(['economy_order_id' => $order->id, 'client_id' => $this->client->id,
            'source_type' => $entry->getMorphClass(), 'source_id' => $entry->id, 'line_type' => 'ticket_time',
            'description' => 'Stored billing', 'quantity' => 20, 'unit' => 'min', 'unit_price_ex_vat' => 10,
            'line_total_ex_vat' => 200, 'total_inc_vat' => 200]);
        $before = $entry->fresh()->getAttributes();
        $report = $this->report();
        $this->assertSame(60, $report['totals']['work_minutes']);
        $this->assertSame(40, $report['totals']['covered_minutes']);
        $this->assertSame(20, $report['totals']['processed_billable_minutes']);
        $this->assertSame(0, $report['totals']['unresolved_minutes']);
        $this->assertTrue($report['entries']->first()['ordered']);
        $this->get($this->url())->assertOk()->assertSee('On order');
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertSame(1, EconomyOrderLine::count());
    }

    public function test_quick_consumption_uses_stored_coverage_and_does_not_recalculate_balances(): void
    {
        $contract = Contracts::create(['client_id' => $this->client->id, 'created_by' => $this->actor->id,
            'description' => 'Synthetic timebank', 'approval_status' => 'won', 'start_date' => '2026-01-01']);
        $item = ContractItem::create(['contract_id' => $contract->id, 'name' => 'Synthetic timebank line',
            'sku' => 'TEST', 'unit' => 'hour', 'quantity' => 1, 'unit_price' => 0, 'billing_interval' => 'monthly']);
        $entry = ClientContractTimeConsumption::create(['client_id' => $this->client->id, 'contract_id' => $contract->id,
            'contract_item_id' => $item->id, 'user_id' => $this->actor->id, 'work_date' => '2026-11-01',
            'minutes' => 50, 'overused_minutes' => 15, 'source' => 'quick_client', 'rate_name' => 'Stored rate',
            'rate_code' => 'TEST', 'rate_type' => 'labor', 'rate_unit' => 'hour', 'rate_amount_ex_vat' => 650,
            'rate_currency' => 'NOK', 'period_start' => '2026-11-01', 'period_end' => '2026-11-30',
            'included_minutes_snapshot' => 100, 'used_before_minutes_snapshot' => 65]);
        $report = $this->report();
        $this->assertSame(50, $report['totals']['work_minutes']);
        $this->assertSame(50, $report['totals']['basis_minutes']);
        $this->assertSame(35, $report['totals']['covered_minutes']);
        $this->assertSame(15, $report['totals']['processed_billable_minutes']);
        $this->assertSame(65, $entry->fresh()->used_before_minutes_snapshot);
    }

    public function test_every_permission_is_required_for_discovery_html_and_downloads(): void
    {
        foreach (ClientTimeReportDefinition::PERMISSIONS as $permission) {
            $this->actor->revokePermissionTo($permission);
            $this->assertFalse(app(ClientTimeReportDefinition::class)->visibleTo($this->actor));
            foreach ([null, 'xlsx', 'pdf'] as $format) {
                $this->get($this->url(['format' => $format]))->assertForbidden();
            }
            $this->assertFalse(app(ReportRegistry::class)->visibleFor($this->actor)->contains('key', 'client.time'));
            $this->actor->givePermissionTo($permission);
        }
        $this->assertTrue(app(ReportRegistry::class)->visibleFor($this->actor)->contains('key', 'client.time'));
        $this->get(route('tech.reports.index'))->assertOk()->assertSee('Client time consumption');
    }

    public function test_excel_is_real_xlsx_with_numeric_minutes_and_formula_like_text_is_literal(): void
    {
        $ticket = $this->ticket(['subject' => '=HYPERLINK("https://example.test","danger")']);
        $this->entry($ticket, ['minutes' => 37, 'note' => '=1+1']);
        $response = $this->get($this->url(['format' => 'xlsx']))->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $path = tempnam(sys_get_temp_dir(), 'client-time-xlsx-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $this->assertSame($ticket->subject, $book->getSheetByName('Summary')->getCell('C9')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $book->getSheetByName('Summary')->getCell('C9')->getDataType());
            $this->assertSame('=1+1', $book->getSheetByName('Time entries')->getCell('G2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $book->getSheetByName('Time entries')->getCell('G2')->getDataType());
            $this->assertSame(37, $book->getSheetByName('Time entries')->getCell('J2')->getValue());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
    }

    public function test_pdf_renders_and_search_resolves_client_by_website_without_exporting_unselected_scope(): void
    {
        $this->entry($this->ticket(), ['note' => '<script>not executable</script>']);
        $response = $this->get($this->url(['format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        $this->get(route('tech.clients.time-report', ['q' => 'tronderservice.test']))->assertOk()->assertSee($this->client->name);
        $this->getJson(route('tech.clients.time-report', ['format' => 'xlsx']))->assertUnprocessable();
        $this->getJson($this->url(['format' => 'csv']))->assertUnprocessable();
        $this->get($this->url())->assertOk()->assertSee('&lt;script&gt;not executable&lt;/script&gt;', false);
    }

    public function test_legacy_customer_owned_archived_task_is_included_with_no_denormalized_client(): void
    {
        $task = app(StoreTask::class)->handle(['title' => 'Legacy archived task'], $this->actor, $this->client);
        $task->update(['client_id' => null, 'work_context_id' => null]);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->actor->id, 'source_type' => 'estimate_completion',
            'work_date' => '2026-11-01', 'minutes' => 18, 'note' => 'Historical completion']);
        $task->delete();
        $report = $this->report();
        $this->assertSame(18, $report['totals']['work_minutes']);
        $this->assertTrue($report['objects']->first()['archived']);
        $this->assertSame('estimate_completion', $report['entries']->first()['provenance']);
    }

    public function test_oversized_history_fails_explicitly_without_a_partial_export(): void
    {
        $ticket = $this->ticket();
        $values = ['ticket_id' => $ticket->id, 'user_id' => $this->actor->id, 'type' => 'manual',
            'work_date' => '2026-10-31', 'minutes' => 1, 'billable' => true,
            'billing_status' => 'pending', 'timebank_status' => 'pending'];
        foreach (array_chunk(array_fill(0, 10001, $values), 500) as $batch) {
            TicketTimeEntry::insert($batch);
        }
        foreach ([null, 'xlsx', 'pdf'] as $format) {
            $this->getJson($this->url(['format' => $format]))->assertUnprocessable()
                ->assertJsonValidationErrors('date_from');
        }
        $this->get($this->url(['date_from' => '2026-11-01']))->assertOk()
            ->assertSee('No time registered in the selected period.');
        $this->assertSame(10001, TicketTimeEntry::count());
    }

    private function ticket(array $data = []): Ticket
    {
        return Ticket::factory()->create($data + ['client_id' => $this->client->id, 'subject' => 'Synthetic customer Ticket']);
    }

    private function entry(Ticket $ticket, array $data = []): TicketTimeEntry
    {
        return $ticket->timeEntries()->create($data + ['user_id' => $this->actor->id, 'type' => 'manual', 'work_date' => '2026-11-01',
            'minutes' => 10, 'billable' => true, 'billing_status' => 'pending', 'timebank_status' => 'pending', 'invoice_text' => 'Synthetic work']);
    }

    private function report(): array
    {
        return app(ClientTimeReportQuery::class)->build($this->client, $this->actor, null, null);
    }

    private function url(array $filters = []): string
    {
        return route('tech.clients.time-report', $filters + ['client_id' => $this->client->id]);
    }
}
