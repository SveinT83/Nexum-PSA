<?php

namespace App\Modules\Report\Tests\Feature;

use App\Models\Clients\Client;
use App\Models\Core\User;
use App\Modules\Report\Support\ReportRegistry;
use App\Modules\Report\Support\TimeReportAccess;
use App\Modules\Task\Actions\StoreTask;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/** Synthetic SQLite fixtures verify identified source authority without live financial mutations. */
class RegisteredTimeReportTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private Client $client;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->actor = $this->employee('Synthetic Reviewer');
        foreach (TimeReportAccess::PERMISSIONS as $right) {
            Permission::findOrCreate($right, 'web');
            $this->actor->givePermissionTo($right);
        }
        $this->client = Client::factory()->create(['name' => 'Synthetic Client Alpha']);
        $this->authenticate(['reports.time.read', 'reports.time.export', 'report.read']);
    }

    private function authenticate(array $scopes): void
    {
        $this->app['auth']->forgetGuards();
        $this->withToken($this->actor->createToken('Synthetic report test', $scopes)->plainTextToken);
    }

    private function employee(string $name, string $status = User::STATUS_ACTIVE): User
    {
        $user = User::factory()->create(compact('name', 'status'));
        $user->assignRole(Role::findOrCreate('Tech', 'web'));

        return $user;
    }

    private function ticket(?Client $client = null, array $data = []): Ticket
    {
        return Ticket::factory()->create($data + ['client_id' => ($client ?? $this->client)->id]);
    }

    private function entry(Ticket $ticket, int $minutes = 10, array $extra = []): TicketTimeEntry
    {
        return $ticket->timeEntries()->create($extra + ['user_id' => $this->actor->id, 'type' => 'manual',
            'work_date' => '2026-11-01', 'minutes' => $minutes, 'billable' => true,
            'billing_status' => 'pending', 'timebank_status' => 'pending', 'note' => 'Registered support']);
    }

    private function task(Ticket|Client|User $owner, int $minutes = 5, array $extra = []): Task
    {
        $task = app(StoreTask::class)->handle(['title' => 'Synthetic Task'], $this->actor, $owner);
        TaskTimeEntry::create($extra + ['task_id' => $task->id, 'user_id' => $this->actor->id,
            'work_date' => '2026-11-01', 'minutes' => $minutes, 'billable' => true, 'source_type' => 'manual', 'note' => 'Task effort']);

        return $task;
    }

    private function url(array $filters = []): string
    {
        return '/api/v1/reports/time/entries?'.http_build_query($filters + ['all_history' => 1]);
    }

    public function test_work_and_billing_projection_are_counted_once_with_full_filter_totals(): void
    {
        $ticket = $this->ticket();
        $task = $this->task($ticket);
        $this->entry($ticket, 30, ['task_id' => $task->id]);
        $this->entry($ticket, 12);
        $this->getJson($this->url(['per_page' => 1]))->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('totals.minutes', 17)->assertJsonPath('totals.ticket_minutes', 12)
            ->assertJsonPath('totals.task_minutes', 5)->assertJsonPath('meta.total', 2)
            ->assertJsonPath('filters.all_history', true)->assertJsonPath('filters.per_page', 1)
            ->assertJsonPath('groups.0.minutes', 17)->assertJsonPath('meta.truncated', false);
        $this->getJson($this->url(['page' => 2, 'per_page' => 1]))->assertOk()->assertJsonPath('totals.minutes', 17);
        $this->assertSame(2, TicketTimeEntry::count());
        $this->assertSame(1, TaskTimeEntry::count());
    }

    public function test_employee_and_client_filters_combine_without_requiring_a_client(): void
    {
        $other = $this->employee('Synthetic Second Worker');
        $secondClient = Client::factory()->create();
        $this->entry($this->ticket(), 11);
        $this->entry($this->ticket($secondClient), 13);
        $this->entry($this->ticket(), 19, ['user_id' => $other->id]);
        $this->getJson($this->url(['employee_id' => $this->actor->id]))->assertOk()->assertJsonPath('totals.minutes', 24);
        $this->getJson($this->url(['client_id' => $this->client->id]))->assertOk()->assertJsonPath('totals.minutes', 30);
        $this->getJson($this->url(['client_id' => $this->client->id, 'employee_id' => $this->actor->id]))
            ->assertOk()->assertJsonPath('totals.minutes', 11);
        $this->getJson($this->url(['group_by' => 'employee_client']))->assertOk()->assertJsonCount(3, 'groups');
    }

    public function test_work_dates_are_inclusive_and_source_billable_and_record_filters_intersect(): void
    {
        $ticket = $this->ticket();
        $this->entry($ticket, 3, ['work_date' => '2026-10-31']);
        $this->entry($ticket, 7, ['work_date' => '2026-11-01']);
        $this->entry($ticket, 11, ['work_date' => '2026-11-02', 'billable' => false]);
        $task = $this->task($ticket, 5);
        $f = ['date_from' => '2026-11-01', 'date_to' => '2026-11-02', 'ticket_id' => $ticket->id];
        $this->getJson($this->url($f))->assertOk()->assertJsonPath('totals.minutes', 23);
        $this->getJson($this->url($f + ['source' => 'ticket', 'billable' => 'no']))->assertOk()->assertJsonPath('totals.minutes', 11);
        $this->getJson($this->url($f + ['task_id' => $task->id]))->assertOk()->assertJsonPath('totals.minutes', 5);
        $this->getJson($this->url(['status' => 'ticket:'.$ticket->status_id]))->assertOk()->assertJsonPath('totals.minutes', 21);
        $this->getJson($this->url(['group_by' => 'date']))->assertOk()->assertJsonCount(3, 'groups');
        $this->getJson($this->url(['date_from' => '2026-11-02', 'date_to' => '2026-11-01']))->assertUnprocessable();
        $this->getJson($this->url(['include' => 'private']))->assertUnprocessable();
    }

    public function test_private_tasks_and_inconsistent_contexts_never_leak_in_report_or_lookups(): void
    {
        $ticket = $this->ticket();
        $other = $this->employee('Private owner');
        $task = app(StoreTask::class)->handle(['title' => 'SECRET hidden Task', 'visibility' => Task::VISIBILITY_PRIVATE], $other, $ticket);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $other->id, 'work_date' => '2026-11-01',
            'minutes' => 5, 'source_type' => 'manual', 'billable' => true]);
        $this->entry($ticket, 30, ['task_id' => $task->id, 'note' => 'SECRET billing']);
        $this->getJson($this->url())->assertOk()->assertJsonPath('totals.minutes', 0)->assertDontSee('SECRET');
        $this->getJson('/api/v1/reports/time/lookups/task?q=SECRET')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->url(['task_id' => $task->id]))->assertUnprocessable()->assertDontSee('SECRET');
        $task->update(['visibility' => Task::VISIBILITY_INTERNAL, 'client_id' => Client::factory()->create()->id]);
        $this->getJson($this->url())->assertOk()->assertJsonPath('totals.minutes', 0);
    }

    public function test_archived_and_legacy_owner_history_is_labelled_and_internal_work_is_separate(): void
    {
        $ticket = $this->ticket();
        $task = $this->task($ticket, 9, ['source_type' => 'estimate_completion']);
        $task->update(['client_id' => null, 'work_context_id' => null]);
        $task->delete();
        $ticket->delete();
        $this->task($this->actor, 4);
        $this->getJson($this->url(['client_id' => $this->client->id]))->assertOk()->assertJsonPath('totals.minutes', 9)
            ->assertJsonPath('data.0.archived', true)->assertJsonPath('data.0.provenance', 'estimate_completion');
        $this->getJson($this->url(['context' => 'internal']))->assertOk()->assertJsonPath('totals.minutes', 4)
            ->assertJsonPath('data.0.client', 'Internal');
        $this->getJson($this->url(['context' => 'client']))->assertOk()->assertJsonPath('totals.minutes', 9);
    }

    public function test_employee_lookup_includes_unicode_inactive_and_no_time_staff_but_no_portal_or_system_users(): void
    {
        $a = $this->employee('Åse Østby', User::STATUS_DISABLED);
        $b = $this->employee('Åse Østby');
        User::factory()->create(['name' => 'Portal identity']);
        $system = $this->employee('System hidden');
        $system->forceFill(['is_system_actor' => true])->save();
        $this->getJson('/api/v1/reports/time/lookups/employee?q=åse øs')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $a->id)->assertJsonPath('data.1.id', $b->id);
        $this->getJson('/api/v1/reports/time/lookups/employee')->assertOk()->assertDontSee('Portal identity')
            ->assertDontSee('System hidden')->assertDontSee($a->email);
        $this->getJson($this->url(['employee_id' => $a->id]))->assertOk()->assertJsonPath('totals.minutes', 0);
    }

    public function test_missing_current_native_permissions_or_api_scope_are_denied_and_discovery_matches(): void
    {
        foreach (TimeReportAccess::PERMISSIONS as $right) {
            $this->actor->revokePermissionTo($right);
            $this->getJson($this->url())->assertForbidden();
            $this->getJson('/api/v1/reports/time/lookups/employee')->assertForbidden();
            $this->assertFalse(app(ReportRegistry::class)->visibleFor($this->actor)->contains('key', 'time.registered'));
            $this->actor->givePermissionTo($right);
        }
        $this->authenticate(['worklog.read', 'time-entries.read', 'report.read']);
        $this->getJson($this->url())->assertForbidden();
        $this->authenticate(['reports.time.read']);
        $this->getJson('/api/v1/reports/time/export?format=xlsx')->assertForbidden();
        $this->getJson($this->url(['format' => 'xlsx']))->assertForbidden();
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson($this->url())->assertForbidden();
    }

    public function test_browser_renders_filters_and_exports_match_all_rows_with_formula_safe_text(): void
    {
        $ticket = $this->ticket(null, ['subject' => '=1+1']);
        $this->entry($ticket, 37, ['note' => '=SUM(1,2)']);
        $this->withoutHeader('Authorization');
        $html = $this->actingAs($this->actor, 'web')->get(route('tech.reports.time', ['all_history' => 1]))->assertOk()
            ->assertSee('Ticket and Task time')->assertSee('Group by')->assertSee('More suggestions')->assertSee('37');
        if ($qaDirectory = getenv('NEXUM_REPORT_QA_DIR')) {
            file_put_contents($qaDirectory.'/report.html', $html->getContent());
            file_put_contents($qaDirectory.'/lookups.json', json_encode([
                'employee' => ['data' => [['id' => $this->actor->id, 'label' => 'Synthetic Reviewer', 'hint' => ''],
                    ['id' => 999, 'label' => 'Øystein Åsen', 'hint' => 'Inactive / former employee']], 'meta' => ['next_page' => null]],
                'client' => ['data' => [['id' => $this->client->id, 'label' => $this->client->name, 'hint' => 'TEST']], 'meta' => ['next_page' => null]],
            ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }
        $response = $this->get(route('tech.reports.time', ['all_history' => 1, 'format' => 'xlsx', 'per_page' => 1]))->assertOk();
        $path = tempnam(sys_get_temp_dir(), 'registered-time-');
        try {
            file_put_contents($path, $response->streamedContent());
            $book = IOFactory::load($path);
            $sheet = $book->getSheetByName('Time entries');
            $this->assertSame(37, $sheet->getCell('G2')->getValue());
            $this->assertSame('=1+1', $sheet->getCell('F2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('F2')->getDataType());
            $this->assertSame('=SUM(1,2)', $sheet->getCell('M2')->getValue());
            $this->assertSame(DataType::TYPE_STRING, $sheet->getCell('M2')->getDataType());
            $book->disconnectWorksheets();
        } finally {
            unlink($path);
        }
        $response = $this->get(route('tech.reports.time', ['all_history' => 1, 'format' => 'pdf']))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_persisted_coordinator_binding_denies_identified_reads_even_with_wildcard(): void
    {
        $token = $this->actor->createToken('Synthetic coordinator', ['*']);
        $workload = \App\Modules\Integration\Models\AiWorkloadProfile::create([
            'name' => 'Synthetic coordinator', 'slug' => 'synthetic-report-coordinator', 'purpose' => 'Test report isolation',
            'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized', 'abilities' => ['worklog.read'],
            'is_approved' => true, 'is_active' => true, 'expires_at' => now()->addMonth(),
            'approved_by' => $this->actor->id, 'approved_at' => now(), 'created_by' => $this->actor->id,
        ]);
        \App\Modules\Integration\Models\AiWorkloadTokenBinding::create([
            'personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30, 'created_by' => $this->actor->id,
        ]);
        $this->app['auth']->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson($this->url())->assertForbidden();
        $this->getJson('/api/v1/reports/time/lookups/employee')->assertForbidden();
        $this->getJson('/api/v1/reports/time/export?format=pdf')->assertForbidden();
    }

    public function test_defaults_open_ended_ranges_group_paging_and_typed_api_contract(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-22 12:00', 'Europe/Oslo'));
        $this->entry($this->ticket(), 7);
        $this->getJson('/api/v1/reports/time/entries')->assertOk()
            ->assertJsonPath('filters.date_from', '2026-11-01')->assertJsonPath('filters.date_to', '2026-11-22');
        $this->getJson($this->url(['date_to' => '2026-11-01', 'group_page' => 2, 'per_page' => 1]))->assertOk()
            ->assertJsonPath('totals.minutes', 7)->assertJsonCount(0, 'groups');
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['entries', 'lookups/{kind}', 'export'] as $operation) {
            $expected = $operation === 'export' ? ['reports.time.read', 'reports.time.export'] : ['reports.time.read'];
            $this->assertSame($expected, $spec['paths']['/api/v1/reports/time/'.$operation]['get']['x-required-scopes']);
        }
        $this->assertSame(['workdays.read-all'], $spec['paths']['/api/v1/workdays/overview/employees']['get']['x-required-scopes']);
        $this->assertArrayHasKey('minutes', $spec['components']['schemas']['RegisteredTimeEntry']['properties']);
        $this->assertArrayHasKey('totals', $spec['components']['schemas']['RegisteredTimeResponse']['properties']);
        $this->assertArrayHasKey('TimeReportLookupResponse', $spec['components']['schemas']);
    }

    public function test_oversized_reports_fail_instead_of_returning_incomplete_totals_or_exports(): void
    {
        $ticket = $this->ticket();
        $row = ['ticket_id' => $ticket->id, 'user_id' => $this->actor->id, 'type' => 'manual', 'work_date' => '2026-11-01',
            'minutes' => 1, 'billable' => true, 'billing_status' => 'pending', 'timebank_status' => 'pending'];
        foreach (array_chunk(array_fill(0, 10001, $row), 500) as $batch) {
            TicketTimeEntry::insert($batch);
        }
        $this->getJson($this->url())->assertUnprocessable()->assertJsonValidationErrors('date_from');
        $this->getJson('/api/v1/reports/time/export?all_history=1&format=xlsx')->assertUnprocessable();
    }
}
