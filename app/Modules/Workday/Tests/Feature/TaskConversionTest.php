<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Task\Actions\CompleteTask;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\WorkContext\Actions\EnsureWorkContextDefaults;
use App\Modules\Workday\Models\WorkdayRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskConversionTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private const SCOPES = ['workday-task-conversion.write', 'tasks.read', 'tasks.create', 'tasks.update'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')->update([
            'json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0]),
        ]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->get());
        $role->givePermissionTo(['task.view', 'task.create', 'task.update', 'task.complete']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        app(EnsureTaskDefaults::class)->handle();
        app(EnsureWorkContextDefaults::class)->internalContext();
        Sanctum::actingAs($this->worker, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(array $breaks = [], string $date = '2026-10-01', ?array $intervals = null): array
    {
        return $this->putJson('/api/v1/workdays/'.$date.'/draft', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'description' => 'Work - unspecified', 'intervals' => $intervals ?? [['start' => $date.'T08:00', 'end' => $date.'T16:00', 'description' => 'Internal work']],
            'breaks' => $breaks], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    }

    private function preview(array $day, array $extra = [], ?string $key = null)
    {
        return $this->postJson('/api/v1/workdays/'.$day['id'].'/task-conversions/preview',
            array_replace(['version' => $day['version'], 'interval_index' => 0, 'title' => 'Synthetic internal Task',
                'description' => 'Synthetic work description', 'start' => '2026-10-01T08:00', 'end' => '2026-10-01T10:00'], $extra),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function createTask(array $day, array $preview, ?string $key = null, array $extra = [])
    {
        return $this->postJson('/api/v1/workdays/'.$day['id'].'/task-conversions',
            array_replace(['version' => $day['version'], 'preview_token' => $preview['token'], 'create_task' => true], $extra),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function operation(array $day, string $operation, array $extra = [])
    {
        return $this->postJson('/api/v1/workdays/'.$day['id'].'/'.$operation, ['version' => $day['version']] + $extra,
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function confirmed(array $day): array
    {
        $preview = $this->operation($day, 'preview')->assertOk()->json('preview');

        return $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true])->assertOk()->json('data');
    }

    private function allocate(array $day, array $allocations)
    {
        return $this->putJson('/api/v1/workdays/'.$day['id'].'/allocations', ['version' => $day['version'], 'allocations' => $allocations],
            ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_preview_create_and_retries_record_one_internal_non_billable_task_without_more_actual_time(): void
    {
        $day = $this->day();
        $key = (string) Str::uuid();
        $response = $this->preview($day, key: $key)->assertOk()->assertJsonPath('preview.activity.minutes', 120)->json();
        $preview = $response['preview'];
        $this->assertSame($response, $this->preview($day, key: $key)->assertOk()->json());
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_time_entries', 0);
        $this->assertDatabaseCount('workday_revisions', 1);
        $key = (string) Str::uuid();
        $created = $this->createTask($day, $preview, $key)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 480)
            ->assertJsonPath('data.current.snapshot.allocated_minutes', 120)->assertJsonPath('data.current.snapshot.unallocated_minutes', 360)->json();
        $this->assertSame($created, $this->createTask($day, $preview, $key)->assertOk()->json());
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_time_entries', 1);
        $task = Task::first();
        $entry = TaskTimeEntry::first();
        $this->assertSame($this->worker->id, $task->owner_id);
        $this->assertSame($this->worker->id, $task->assigned_to);
        $this->assertInstanceOf(User::class, $task->owner);
        $this->assertSame('internal', $task->workContext->type);
        $this->assertNull($task->client_id);
        $this->assertNull($task->completed_at);
        $this->assertFalse($task->status->is_done);
        $this->assertSame('Synthetic work description', $entry->note);
        $this->assertFalse($entry->billable);
        $this->assertSame('manual', $entry->source_type);
        $this->assertSame('2026-10-01 06:00:00', $entry->started_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('ticket_time_entries', 0);
        $this->assertDatabaseCount('client_contract_time_consumptions', 0);
        $this->getJson('/api/v1/workdays/'.$day['id'].'/task-conversions/'.$preview['token'])->assertOk()
            ->assertJsonPath('state', 'created')->assertJsonPath('conversion', $created['conversion']);
        $this->getJson('/api/v1/tasks/'.$task->id)->assertOk()->assertSee('Synthetic internal Task');
        app(CompleteTask::class)->handle($task, $this->worker);
        $this->assertSame(120, (int) TaskTimeEntry::sum('minutes'));
        $this->assertDatabaseCount('task_time_entries', 1);
        $this->assertDatabaseCount('ticket_time_entries', 0);
    }

    public function test_one_entry_replaces_only_selected_actual_minutes_and_excludes_unpaid_breaks(): void
    {
        $day = $this->day([['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:30', 'included' => false]]);
        $preview = $this->preview($day)->assertOk()->assertJsonPath('preview.activity.minutes', 90)->assertJsonCount(2, 'preview.activity.ranges')->json('preview');
        $day = $this->createTask($day, $preview)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 450)
            ->assertJsonPath('data.current.snapshot.allocated_minutes', 90)->assertJsonCount(2, 'data.current.snapshot.allocations')->json('data');
        $this->assertDatabaseCount('task_time_entries', 1);
        $this->assertNull(TaskTimeEntry::first()->started_at);
        $this->assertSame(90, TaskTimeEntry::first()->minutes);
        $this->confirmed($day);
    }

    public function test_confirmed_revision_is_preserved_and_conversion_requires_a_correction_reason(): void
    {
        $day = $this->confirmed($this->day());
        $prior = $day['confirmed'];
        $this->preview($day)->assertUnprocessable()->assertJsonValidationErrors('reason');
        $preview = $this->preview($day, ['reason' => 'Attribute saved activity'])->assertOk()->json('preview');
        $day = $this->createTask($day, $preview)->assertOk()->assertJsonPath('data.current.state', 'draft')
            ->assertJsonPath('data.current.correction_reason', 'Attribute saved activity')->json('data');
        $this->assertSame($prior, $day['confirmed']);
        $this->assertSame(1, WorkdayRevision::where('state', 'confirmed')->count());
        $this->assertSame(480, $day['current']['snapshot']['actual_minutes']);
    }

    public function test_stale_expired_and_consumed_previews_cannot_create_duplicate_time(): void
    {
        $day = $this->day();
        $first = $this->preview($day)->assertOk()->json('preview');
        $second = $this->preview($day)->assertOk()->json('preview');
        $new = $this->createTask($day, $first)->assertOk()->json('data');
        $this->createTask($day, $second)->assertConflict();
        $this->createTask($new, $first)->assertConflict();
        $this->createTask($new, $second)->assertConflict();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/task-conversions/'.$second['token'])->assertOk()->assertJsonPath('state', 'stale');
        $fresh = $this->preview($new, ['start' => '2026-10-01T10:00', 'end' => '2026-10-01T11:00'])->assertOk()->json('preview');
        Carbon::setTestNow(now()->addMinutes(31));
        $this->createTask($new, $fresh)->assertConflict();
        $this->assertDatabaseCount('tasks', 1);
        $this->assertDatabaseCount('task_time_entries', 1);
    }

    public function test_duplicate_conversion_stays_blocked_after_its_allocation_is_removed(): void
    {
        $day = $this->day();
        $preview = $this->preview($day)->assertOk()->json('preview');
        $day = $this->createTask($day, $preview)->assertOk()->json('data');
        $day = $this->allocate($day, [])->assertOk()->json('data');
        $this->preview($day)->assertUnprocessable()->assertJsonValidationErrors('start');
        $this->assertDatabaseCount('tasks', 1);
        // The existing eligible time can be linked again through the established source operation.
        $source = $this->getJson('/api/v1/workdays/'.$day['id'].'/sources?kind=task')->assertOk()->json('data.0');
        $selection = Arr::only($source, ['source_key', 'source_revision', 'kind', 'minutes', 'start', 'end']);
        $this->allocate($day, [$selection])->assertOk()->assertJsonPath('data.current.snapshot.allocated_minutes', 120);
        $this->assertDatabaseCount('task_time_entries', 1);
    }

    public function test_existing_sources_must_be_placed_and_cannot_overlap_new_time(): void
    {
        $day = $this->day();
        $task = Task::create(['title' => 'Existing source', 'owner_type' => User::class, 'owner_id' => $this->worker->id, 'created_by' => $this->worker->id]);
        TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->worker->id, 'source_type' => 'manual', 'work_date' => '2026-10-01', 'minutes' => 120]);
        $source = $this->getJson('/api/v1/workdays/'.$day['id'].'/sources?kind=task')->assertOk()->json('data.0');
        $selection = Arr::only($source, ['source_key', 'source_revision', 'kind', 'minutes']);
        $day = $this->allocate($day, [$selection])->assertOk()->json('data');
        $this->preview($day)->assertUnprocessable();
        $selection += ['start' => '2026-10-01T08:00', 'end' => '2026-10-01T10:00'];
        $day = $this->allocate($day, [$selection])->assertOk()->json('data');
        $this->preview($day)->assertUnprocessable();
        $preview = $this->preview($day, ['start' => '2026-10-01T10:00', 'end' => '2026-10-01T11:00'])->assertOk()->json('preview');
        $this->createTask($day, $preview)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 480)
            ->assertJsonPath('data.current.snapshot.allocated_minutes', 180);
        $this->assertDatabaseCount('task_time_entries', 2);
    }

    public function test_changed_internal_target_after_preview_fails_without_partial_task(): void
    {
        $day = $this->day();
        $preview = $this->preview($day)->assertOk()->json('preview');
        $context = app(EnsureWorkContextDefaults::class)->internalContext();
        $context->update(['name' => 'Changed target']);
        $this->createTask($day, $preview)->assertConflict();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_time_entries', 0);
    }

    public function test_transaction_rolls_back_task_time_source_revision_and_receipt_on_late_failure(): void
    {
        $day = $this->day();
        $preview = $this->preview($day)->assertOk()->json('preview');
        $before = DB::table('workday_mutation_receipts')->count();
        Event::listen('eloquent.creating: '.WorkdayRevision::class, function ($revision) {
            if ($revision->version === 2) {
                throw new \RuntimeException('Synthetic late revision failure');
            }
        });
        $this->createTask($day, $preview)->assertStatus(500);
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_time_entries', 0);
        $this->assertDatabaseCount('task_activities', 0);
        $this->assertDatabaseCount('workday_source_allocations', 0);
        $this->assertDatabaseCount('workday_revisions', 1);
        $this->assertSame($before, DB::table('workday_mutation_receipts')->count());
        $this->assertNull(DB::table('workday_task_conversion_previews')->first()->consumed_at);
    }

    public function test_each_token_scope_is_required_and_actual_bearer_can_preview_create_and_read_back(): void
    {
        $day = $this->day();
        foreach (self::SCOPES as $missing) {
            Sanctum::actingAs($this->worker, array_values(array_diff(self::SCOPES, [$missing])));
            $this->preview($day)->assertForbidden();
        }
        $token = $this->worker->createToken('Synthetic conversion', self::SCOPES);
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken);
        $preview = $this->preview($day)->assertOk()->json('preview');
        $this->createTask($day, $preview)->assertOk();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/task-conversions/'.$preview['token'])->assertOk()->assertJsonPath('state', 'created');
    }

    public function test_each_domain_permission_is_required_even_on_receipt_replay(): void
    {
        $day = $this->day();
        $preview = $this->preview($day)->assertOk()->json('preview');
        $key = (string) Str::uuid();
        $this->createTask($day, $preview, $key)->assertOk();
        foreach (['workday.manage_own', 'task.view', 'task.create', 'task.update'] as $permission) {
            $role = Role::findByName('Tech', 'web');
            $role->revokePermissionTo($permission);
            $this->worker->unsetRelation('roles')->unsetRelation('permissions');
            $this->createTask($day, $preview, $key)->assertForbidden();
            $this->getJson('/api/v1/workdays/'.$day['id'].'/task-conversions/'.$preview['token'])->assertForbidden();
            $role->givePermissionTo($permission);
            $this->worker->unsetRelation('roles')->unsetRelation('permissions');
        }
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_owner_actor_target_and_billing_spoofing_are_rejected(): void
    {
        $day = $this->day();
        foreach (['user_id', 'owner_id', 'client_id', 'task_id', 'ticket_id', 'billable', 'minutes', 'target'] as $field) {
            $this->preview($day, [$field => 123])->assertUnprocessable()->assertJsonValidationErrors('input');
        }
        $preview = $this->preview($day)->assertOk()->json('preview');
        $this->createTask($day, $preview, extra: ['minutes' => 500])->assertUnprocessable();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        Sanctum::actingAs($other, ['*']);
        $this->preview($day)->assertNotFound();
        $this->createTask($day, $preview)->assertNotFound();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/task-conversions/'.$preview['token'])->assertNotFound();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_missing_explicit_acceptance_and_idempotency_conflicts_do_not_write(): void
    {
        $day = $this->day();
        $key = (string) Str::uuid();
        $preview = $this->preview($day, key: $key)->assertOk()->json('preview');
        $this->preview($day, ['description' => 'Changed intent'], $key)->assertConflict();
        $this->createTask($day, $preview, extra: ['create_task' => false])->assertUnprocessable();
        $this->createTask($day, $preview, $key)->assertConflict();
        $this->assertDatabaseCount('tasks', 0);
        $this->assertDatabaseCount('task_time_entries', 0);
    }

    public function test_disabled_inactive_and_retention_expired_records_fail_closed(): void
    {
        $day = $this->day();
        $preview = $this->preview($day)->assertOk()->json('preview');
        config(['workday.enabled' => false]);
        $this->createTask($day, $preview)->assertNotFound();
        config(['workday.enabled' => true]);
        $this->worker->update(['status' => 'inactive']);
        $this->createTask($day, $preview)->assertForbidden();
        $this->worker->update(['status' => User::STATUS_ACTIVE]);
        Carbon::setTestNow(Carbon::parse($day['expires_at'])->addSecond());
        $this->preview($day)->assertNotFound();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/task-conversions/'.$preview['token'])->assertNotFound();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_invalid_ranges_unpaid_break_only_and_future_work_cannot_create_time(): void
    {
        $day = $this->day([['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:30', 'included' => false]]);
        foreach ([
            ['interval_index' => 23], ['start' => '2026-10-01T07:59'],
            ['end' => '2026-10-01T17:00'], ['end' => '2026-10-01T08:00'],
            ['start' => '2026-10-01T09:00', 'end' => '2026-10-01T09:30'],
            ['start' => '2026-10-01T08:00+01:00'],
        ] as $extra) {
            $this->preview($day, $extra)->assertUnprocessable();
        }
        $future = $this->day(date: '2026-10-29');
        $this->preview($future, ['start' => null, 'end' => null])->assertUnprocessable()->assertJsonValidationErrors('end');
        $this->assertDatabaseCount('task_time_entries', 0);
    }

    public function test_dst_and_overnight_work_use_elapsed_minutes_and_the_original_work_date(): void
    {
        $day = $this->day(date: '2026-10-25', intervals: [['start' => '2026-10-25T01:30+02:00', 'end' => '2026-10-25T03:30+01:00']]);
        $preview = $this->preview($day, ['start' => null, 'end' => null])->assertOk()->assertJsonPath('preview.activity.minutes', 180)->json('preview');
        $this->createTask($day, $preview)->assertOk()->assertJsonPath('data.current.snapshot.actual_minutes', 180);
        $night = $this->day(date: '2026-10-26', intervals: [['start' => '2026-10-26T23:00', 'end' => '2026-10-27T02:00']]);
        $preview = $this->preview($night, ['start' => null, 'end' => null])->assertOk()->assertJsonPath('preview.activity.minutes', 180)->json('preview');
        $this->createTask($night, $preview)->assertOk();
        $this->assertSame('2026-10-26', TaskTimeEntry::orderByDesc('id')->first()->work_date->toDateString());
    }

    public function test_browser_form_preview_explicit_create_and_receipt_read_back(): void
    {
        $day = $this->day();
        $base = '/tech/workdays/'.$day['id'].'/task-conversions';
        $this->actingAs($this->worker, 'web')->get('/tech/workdays/'.$day['id'])->assertOk()->assertSee('Create internal Task from saved activity');
        $this->get($base.'/create')->assertOk()->assertSee('Preview internal Task')->assertSee('link existing time in Sources');
        $response = $this->post($base.'/preview', ['version' => 1, 'interval_index' => 0, 'title' => 'Browser synthetic',
            'description' => '<script>alert("unsafe")</script> Årlig gjennomgang', 'start' => '2026-10-01T08:00', 'end' => '2026-10-01T10:00',
            'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('120 minutes')->assertSee('Årlig gjennomgang')->assertDontSee('<script>alert("unsafe")</script>', false);
        $preview = DB::table('workday_task_conversion_previews')->first();
        $response = $this->post($base, ['version' => 1, 'preview_token' => $preview->token, 'create_task' => '1',
            'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get($response->headers->get('Location'))->assertOk()->assertSee('Open internal Task')->assertSee('one time entry of 120 minutes');
        $this->get('/tech/workdays/'.$day['id'])->assertOk()->assertSee('Attributed: 120 minutes');
        $this->assertDatabaseCount('task_time_entries', 1);
    }

    public function test_previous_conversion_cannot_be_recreated_on_an_adjacent_work_date_after_correction(): void
    {
        $night = $this->day(date: '2026-10-01', intervals: [['start' => '2026-10-01T23:00', 'end' => '2026-10-02T02:00']]);
        $preview = $this->preview($night, ['start' => '2026-10-02T00:00', 'end' => '2026-10-02T01:00'])->assertOk()->json('preview');
        $night = $this->createTask($night, $preview)->assertOk()->json('data');
        $this->putJson('/api/v1/workdays/2026-10-01/draft', ['version' => $night['version'], 'timezone' => 'Europe/Oslo',
            'description' => 'Earlier finish', 'intervals' => [['start' => '2026-10-01T23:00', 'end' => '2026-10-02T00:00']],
            'breaks' => [], 'allocations' => []], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $next = $this->day(date: '2026-10-02', intervals: [['start' => '2026-10-02T00:00', 'end' => '2026-10-02T02:00']]);
        $this->preview($next, ['start' => '2026-10-02T00:00', 'end' => '2026-10-02T01:00'])->assertUnprocessable();
        $this->assertDatabaseCount('task_time_entries', 1);
    }

    public function test_changed_existing_source_blocks_conversion_and_prior_confirmation_keeps_its_reservation(): void
    {
        $day = $this->day();
        $preview = $this->preview($day)->assertOk()->json('preview');
        $day = $this->createTask($day, $preview)->assertOk()->json('data');
        $next = $this->preview($day, ['start' => '2026-10-01T10:00', 'end' => '2026-10-01T11:00'])->assertOk()->json('preview');
        TaskTimeEntry::first()->update(['note' => 'Changed source']);
        $this->createTask($day, $next)->assertUnprocessable();
        $this->assertDatabaseCount('tasks', 1);
        $source = $this->getJson('/api/v1/workdays/'.$day['id'].'/sources?kind=task')->assertOk()->json('data.0');
        $day = $this->allocate($day, [Arr::only($source, ['source_key', 'source_revision', 'kind', 'minutes', 'start', 'end'])])->assertOk()->json('data');
        $day = $this->confirmed($day);
        $day = $this->operation($day, 'corrections', ['reason' => 'Adjust attribution'])->assertOk()->json('data');
        $day = $this->allocate($day, [])->assertOk()->json('data');
        $this->preview($day)->assertUnprocessable();
    }

    public function test_system_actor_and_bound_coordinator_are_denied_even_with_wildcard_scope(): void
    {
        $day = $this->day();
        $this->worker->update(['is_system_actor' => true]);
        $this->preview($day)->assertForbidden();
        $this->worker->update(['is_system_actor' => false]);
        $token = $this->worker->createToken('Synthetic bound token', ['*']);
        $workload = \App\Modules\Integration\Models\AiWorkloadProfile::create([
            'name' => 'Synthetic coordinator', 'slug' => 'synthetic-conversion', 'purpose' => 'Scope isolation',
            'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized',
            'abilities' => ['worklog.read'], 'is_approved' => true, 'is_active' => true,
            'expires_at' => now()->addMonth(), 'approved_by' => $this->worker->id, 'approved_at' => now(),
            'created_by' => $this->worker->id,
        ]);
        \App\Modules\Integration\Models\AiWorkloadTokenBinding::create([
            'personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30, 'created_by' => $this->worker->id,
        ]);
        $this->worker->withAccessToken($token->accessToken);
        $this->actingAs($this->worker, 'sanctum');
        $this->preview($day)->assertForbidden();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_openapi_routes_scopes_and_closed_write_schemas_match_runtime(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['preview' => 'post', 'store' => 'post', 'show' => 'get'] as $name => $method) {
            $route = Route::getRoutes()->getByName('api.v1.workdays.task-conversions.'.$name);
            $this->assertSame(self::SCOPES, $spec['paths']['/'.$route->uri()][$method]['x-required-scopes']);
        }
        foreach (['WorkdayTaskConversionPreviewInput', 'WorkdayTaskConversionCreateInput'] as $schema) {
            $this->assertFalse($spec['components']['schemas'][$schema]['additionalProperties']);
        }
    }
}
