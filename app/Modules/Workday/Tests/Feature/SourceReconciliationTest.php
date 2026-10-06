<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventSeries;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketTimeEntry;
use App\Modules\Workday\Models\WorkdayRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SourceReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

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
        $role->givePermissionTo(['task.view', 'ticket.view', 'calendar.view']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        Sanctum::actingAs($this->worker, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $date = '2026-10-01', ?array $intervals = null, array $breaks = []): array
    {
        return $this->putJson('/api/v1/workdays/'.$date.'/draft', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'description' => 'Work - unspecified', 'intervals' => $intervals ?? [['start' => $date.'T08:00', 'end' => $date.'T16:00']],
            'breaks' => $breaks], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    }

    private function entry(int $minutes = 120, string $type = 'manual', string $date = '2026-10-01', ?User $worker = null): TaskTimeEntry
    {
        $worker ??= $this->worker;
        $task = Task::create(['title' => 'Synthetic task', 'owner_type' => User::class, 'owner_id' => $worker->id,
            'created_by' => $worker->id, 'assigned_to' => $worker->id]);

        return TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $worker->id, 'source_type' => $type, 'work_date' => $date,
            'minutes' => $minutes, 'note' => 'Source-only private note']);
    }

    private function sources(array $day, string $query = 'kind=task')
    {
        return $this->getJson('/api/v1/workdays/'.$day['id'].'/sources?'.$query);
    }

    private function selection(array $source, ?int $minutes = null, array $extra = []): array
    {
        return array_replace(Arr::only($source, ['source_key', 'source_revision', 'kind', 'calendar_id']),
            ['minutes' => $minutes ?? $source['minutes'], 'acknowledged' => false], $extra);
    }

    private function allocations(array $day, array $allocations, ?string $key = null)
    {
        return $this->putJson('/api/v1/workdays/'.$day['id'].'/allocations', ['version' => $day['version'], 'allocations' => $allocations],
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

    private function calendarEvent(array $extra = []): CalendarEvent
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker);

        return CalendarEvent::create(array_replace(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id, 'title' => 'Synthetic meeting',
            'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-01 09:00:00', 'timezone' => 'Europe/Oslo', 'source' => 'local',
            'created_by' => $this->worker->id, 'status' => 'confirmed'], $extra));
    }

    public function test_eight_hours_with_two_task_hours_stays_eight_and_source_is_not_copied_or_mutated(): void
    {
        $entry = $this->entry();
        $before = $entry->fresh()->getAttributes();
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->assertJsonPath('meta.status', 'complete')->assertDontSee('Source-only private note')->json('data.0');
        $this->assertNull($source['start']);
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()
            ->assertJsonPath('data.current.snapshot.actual_minutes', 480)->assertJsonPath('data.current.snapshot.allocated_minutes', 120)
            ->assertJsonPath('data.current.snapshot.unallocated_minutes', 360)->json('data');
        $day = $this->confirmed($day);
        $this->assertSame(480, $day['confirmed']['snapshot']['actual_minutes']);
        $this->assertSame($before, $entry->fresh()->getAttributes());
        $this->assertStringNotContainsString('Synthetic task', json_encode($day['confirmed']['snapshot']));
        $this->assertSame(2, DB::table('workday_source_allocations')->count());
    }

    public function test_task_actual_five_and_ticket_billing_thirty_are_not_counted_twice(): void
    {
        $entry = $this->entry(5, 'ticket_time_entry');
        $ticket = Ticket::factory()->create();
        TicketTimeEntry::create(['ticket_id' => $ticket->id, 'task_id' => $entry->task_id, 'user_id' => $this->worker->id,
            'type' => 'task_billing', 'work_date' => '2026-10-01', 'minutes' => 30]);
        TicketTimeEntry::create(['ticket_id' => $ticket->id, 'user_id' => $this->worker->id,
            'type' => 'task_billing', 'work_date' => '2026-10-01', 'minutes' => 30]);
        $day = $this->day();
        $this->sources($day, 'kind=ticket')->assertOk()->assertJsonCount(0, 'data');
        $source = $this->sources($day)->assertOk()->assertJsonPath('data.0.minutes', 5)->json('data.0');
        $this->allocations($day, [$this->selection($source)])->assertOk()->assertJsonPath('data.current.snapshot.allocated_minutes', 5);
        $this->assertSame(60, (int) TicketTimeEntry::sum('minutes'));
    }

    public function test_estimated_and_unknown_entries_need_employee_verification_and_keep_their_basis(): void
    {
        $this->entry(120, 'estimated');
        $this->entry(30, 'legacy_unknown');
        $day = $this->day();
        $sources = $this->sources($day)->assertOk()->json('data');
        $this->allocations($day, [$this->selection($sources[0])])->assertUnprocessable()->assertJsonValidationErrors('allocations.0.acknowledged');
        $this->allocations($day, [$this->selection($sources[1])])->assertUnprocessable();
        $day = $this->allocations($day, [$this->selection($sources[0], 60, ['acknowledged' => true])])->assertOk()
            ->assertJsonPath('data.current.snapshot.allocations.0.basis', 'estimated')->json('data');
        $this->confirmed($day);
        $this->assertSame('estimated', TaskTimeEntry::first()->source_type);
    }

    public function test_placement_split_merge_and_excluded_breaks_are_validated_without_inventing_intervals(): void
    {
        $this->entry(120);
        $day = $this->day(breaks: [['start' => '2026-10-01T12:00', 'end' => '2026-10-01T12:30', 'included' => false]]);
        $source = $this->sources($day)->assertOk()->json('data.0');
        $a = $this->selection($source, 60, ['start' => '2026-10-01T10:00', 'end' => '2026-10-01T11:00']);
        $this->allocations($day, [$a, $a])->assertUnprocessable();
        $this->allocations($day, [$this->selection($source, 60, ['start' => '2026-10-01T12:00', 'end' => '2026-10-01T13:00'])])->assertUnprocessable();
        $b = $this->selection($source, 60, ['start' => '2026-10-01T11:00', 'end' => '2026-10-01T12:00']);
        $day = $this->allocations($day, [$a, $b])->assertOk()->assertJsonPath('data.current.snapshot.allocated_minutes', 120)->json('data');
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()
            ->assertJsonPath('data.current.snapshot.allocations.0.start', null)->json('data');
        $this->allocations($day, [])->assertOk()->assertJsonPath('data.current.snapshot.unallocated_minutes', 450);
    }

    public function test_total_and_source_capacity_fail_closed(): void
    {
        $this->entry(600);
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $this->allocations($day, [$this->selection($source)])->assertUnprocessable();
        $this->allocations($day, [$this->selection($source, 300), $this->selection($source, 300)])->assertUnprocessable();
        $entry = TaskTimeEntry::first();
        $entry->update(['minutes' => 120]);
        $source = $this->sources($day)->assertOk()->json('data.0');
        $this->allocations($day, [$this->selection($source, 121)])->assertUnprocessable();
        $this->assertSame(0, DB::table('workday_source_allocations')->count());
    }

    public function test_adjacent_work_dates_share_one_source_capacity_and_pending_correction_retains_reservation(): void
    {
        $this->entry(120, 'manual', '2026-10-02');
        $night = $this->day('2026-10-01', [['start' => '2026-10-01T23:00', 'end' => '2026-10-02T02:00']]);
        $morning = $this->day('2026-10-02', [['start' => '2026-10-02T08:00', 'end' => '2026-10-02T10:00']]);
        $source = $this->sources($night)->assertOk()->json('data.0');
        $night = $this->allocations($night, [$this->selection($source, 90)])->assertOk()->json('data');
        $night = $this->confirmed($night);
        $this->allocations($morning, [$this->selection($source, 31)])->assertUnprocessable();
        $night = $this->operation($night, 'corrections', ['reason' => 'Reconcile source'])->assertOk()->json('data');
        $night = $this->allocations($night, [])->assertOk()->json('data');
        $this->allocations($morning, [$this->selection($source, 120)])->assertUnprocessable();
        $this->confirmed($night);
        $this->allocations($morning, [$this->selection($source, 120)])->assertOk();
    }

    public function test_stale_source_after_preview_blocks_confirmation_and_confirmed_history_stays_immutable(): void
    {
        $entry = $this->entry();
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()->json('data');
        $preview = $this->operation($day, 'preview')->assertOk()->json('preview');
        $entry->update(['note' => 'Changed source content']);
        $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true])->assertUnprocessable();
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertOk()->assertJsonPath('data.reconciliation.sources.0.status', 'stale');
        $source = $this->sources($day)->assertOk()->json('data.0');
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()->json('data');
        $day = $this->confirmed($day);
        $saved = $day['confirmed'];
        $entry->delete();
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertOk()
            ->assertJsonPath('data.confirmed', $saved)->assertJsonPath('data.confirmed_reconciliation.sources.0.status', 'unavailable');
        $this->assertSame(1, WorkdayRevision::where('state', 'confirmed')->count());
    }

    public function test_source_permissions_and_scopes_are_rechecked_and_manual_work_remains_available(): void
    {
        $this->entry();
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()->json('data');
        Sanctum::actingAs($this->worker, ['workdays.read', 'workdays.write', 'workdays.confirm']);
        $this->sources($day)->assertOk()->assertJsonPath('meta.status', 'unavailable')->assertJsonPath('meta.total', null)->assertJsonCount(0, 'data');
        $this->operation($day, 'preview')->assertUnprocessable();
        $day = $this->allocations($day, [])->assertOk()->json('data');
        $this->confirmed($day);
        Sanctum::actingAs($this->worker, ['*']);
        Role::findByName('Tech', 'web')->revokePermissionTo('task.view');
        $this->worker->unsetRelation('roles')->unsetRelation('permissions');
        $this->sources($day)->assertOk()->assertJsonPath('meta.status', 'unavailable');
    }

    public function test_own_entries_only_and_workday_owner_is_enforced_for_superuser(): void
    {
        $this->entry();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->entry(60, worker: $other);
        $day = $this->day();
        $this->sources($day)->assertOk()->assertJsonPath('meta.total', 1);
        $role = Role::findOrCreate('Superuser', 'web');
        $role->givePermissionTo(Permission::all());
        $other->assignRole($role);
        Sanctum::actingAs($other, ['*']);
        $this->sources($day)->assertNotFound();
        $this->allocations($day, [])->assertNotFound();
    }

    public function test_pagination_and_cap_never_report_partial_discovery_as_complete(): void
    {
        $entry = $this->entry(1);
        $rows = [];
        for ($i = 0; $i < 500; $i++) {
            $rows[] = ['task_id' => $entry->task_id, 'user_id' => $this->worker->id, 'source_type' => 'manual',
                'work_date' => '2026-10-01', 'minutes' => 1, 'created_at' => now(), 'updated_at' => now()];
        }
        TaskTimeEntry::insert($rows);
        $day = $this->day();
        $this->sources($day, 'kind=task&per_page=50')->assertOk()->assertJsonPath('meta.status', 'partial')
            ->assertJsonPath('meta.total', 501)->assertJsonPath('meta.truncated', true)->assertJsonPath('meta.next_page', 2)->assertJsonCount(50, 'data');
        $this->sources($day, 'kind=task&per_page=50&page=10')->assertOk()->assertJsonPath('meta.status', 'partial')->assertJsonPath('meta.next_page', null);
        $this->sources($day, 'kind=task&per_page=51')->assertUnprocessable();
    }

    public function test_calendar_requires_explicit_selection_and_attendance_acknowledgement(): void
    {
        $event = $this->calendarEvent();
        $day = $this->day();
        $this->sources($day, 'kind=calendar')->assertOk()->assertJsonPath('meta.status', 'unavailable')->assertJsonCount(0, 'data')->assertJsonCount(1, 'calendars');
        $source = $this->sources($day, 'kind=calendar&calendar_id='.$event->calendar_id)->assertOk()->assertJsonPath('data.0.basis', 'planned')->json('data.0');
        $this->allocations($day, [$this->selection($source)])->assertUnprocessable();
        $day = $this->allocations($day, [$this->selection($source, 30, ['acknowledged' => true])])->assertOk()->json('data');
        $this->confirmed($day);
        $this->assertSame(1, CalendarEvent::count());
    }

    public function test_private_declined_cancelled_and_absence_calendar_events_are_excluded(): void
    {
        $event = $this->calendarEvent(['visibility' => 'private']);
        $event->participants()->create(['participant_type' => 'user', 'participant_id' => $this->worker->id, 'response_status' => 'declined']);
        $this->calendarEvent(['status' => 'cancelled']);
        $this->calendarEvent(['source' => 'workday_absence']);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $foreign = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($other);
        $this->calendarEvent(['calendar_id' => $foreign->id, 'created_by' => $other->id, 'visibility' => 'private', 'title' => 'Hidden personal reason']);
        $day = $this->day();
        $this->sources($day, 'kind=calendar&calendar_id='.$event->calendar_id)->assertOk()->assertJsonCount(0, 'data');
        $this->sources($day, 'kind=calendar&calendar_id='.$foreign->id)->assertOk()->assertJsonCount(0, 'data')->assertDontSee('Hidden personal reason');
    }

    public function test_recurring_occurrences_have_distinct_identity_and_cancellation_makes_a_selection_unavailable(): void
    {
        $event = $this->calendarEvent();
        $series = CalendarEventSeries::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $event->calendar_id, 'timezone' => 'Europe/Oslo',
            'rrule' => 'FREQ=DAILY', 'starts_at' => $event->starts_at, 'ends_at' => $event->ends_at, 'recurrence_starts_at' => $event->starts_at,
            'recurrence_ends_at' => '2026-10-10 20:00', 'metadata' => ['frequency' => 'daily']]);
        $event->update(['series_id' => $series->id]);
        $day = $this->day();
        $source = $this->sources($day, 'kind=calendar&calendar_id='.$event->calendar_id)->assertOk()->json('data.0');
        $next = $this->day('2026-10-02');
        $other = $this->sources($next, 'kind=calendar&calendar_id='.$event->calendar_id)->assertOk()->json('data.0');
        $this->assertNotSame($source['source_key'], $other['source_key']);
        $day = $this->allocations($day, [$this->selection($source, 60, ['acknowledged' => true])])->assertOk()->json('data');
        $series->exceptions()->create(['original_starts_at' => $event->starts_at, 'exception_type' => 'cancelled']);
        $this->operation($day, 'preview')->assertUnprocessable();
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertOk()->assertJsonPath('data.reconciliation.sources.0.status', 'unavailable');
    }

    public function test_repeated_selection_and_stale_browser_or_api_writes_do_not_duplicate_allocations(): void
    {
        $this->entry();
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $key = (string) Str::uuid();
        $first = $this->allocations($day, [$this->selection($source)], $key)->assertOk()->json();
        $this->assertSame($first, $this->allocations($day, [$this->selection($source)], $key)->assertOk()->json());
        $this->allocations($day, [])->assertConflict();
        $this->assertSame(1, DB::table('workday_source_allocations')->count());
    }

    public function test_draft_api_preserves_omitted_allocations_and_explicit_empty_clears_them(): void
    {
        $this->entry();
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $payload = ['version' => $day['version'], 'timezone' => 'Europe/Oslo', 'description' => 'Revised work',
            'intervals' => [['start' => '2026-10-01T08:00', 'end' => '2026-10-01T16:00']], 'breaks' => [],
            'allocations' => [$this->selection($source)]];
        $day = $this->putJson('/api/v1/workdays/2026-10-01/draft', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.current.snapshot.allocated_minutes', 120)->json('data');
        unset($payload['allocations']);
        $payload['version'] = $day['version'];
        $day = $this->putJson('/api/v1/workdays/2026-10-01/draft', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.current.snapshot.allocated_minutes', 120)->json('data');
        $payload['version'] = $day['version'];
        $payload['allocations'] = [];
        $this->putJson('/api/v1/workdays/2026-10-01/draft', $payload, ['Idempotency-Key' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.current.snapshot.allocated_minutes', 0);
    }

    public function test_direct_ticket_time_is_attributed_without_modifying_its_billing_data(): void
    {
        $ticket = Ticket::factory()->create();
        $entry = TicketTimeEntry::create(['ticket_id' => $ticket->id, 'user_id' => $this->worker->id,
            'type' => 'manual', 'work_date' => '2026-10-01', 'minutes' => 30, 'note' => 'Private ticket note']);
        $before = $entry->fresh()->getAttributes();
        $day = $this->day();
        $source = $this->sources($day, 'kind=ticket')->assertOk()->assertJsonPath('data.0.basis', 'recorded')
            ->assertDontSee('Private ticket note')->json('data.0');
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()->json('data');
        $this->confirmed($day);
        $this->assertSame($before, $entry->fresh()->getAttributes());
    }

    public function test_known_source_interval_bounds_placement_and_parent_edits_invalidate_preview(): void
    {
        $entry = $this->entry(60);
        $entry->update(['started_at' => '2026-10-01 08:00:00', 'ended_at' => '2026-10-01 09:00:00']);
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $this->allocations($day, [$this->selection($source, 60, ['start' => '2026-10-01T08:00+02:00',
            'end' => '2026-10-01T09:00+02:00'])])->assertUnprocessable();
        $day = $this->allocations($day, [$this->selection($source, 60, ['start' => $source['start'],
            'end' => $source['end']])])->assertOk()->json('data');
        $preview = $this->operation($day, 'preview')->assertOk()->json('preview');
        $entry->task->update(['title' => 'Changed parent title']);
        $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true])->assertUnprocessable();
    }

    public function test_calendar_iteration_ceiling_is_reported_as_partial_even_when_no_occurrences_are_returned(): void
    {
        $event = $this->calendarEvent(['starts_at' => '2025-01-01 08:00:00', 'ends_at' => '2025-01-01 09:00:00']);
        $series = CalendarEventSeries::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $event->calendar_id,
            'timezone' => 'Europe/Oslo', 'rrule' => 'FREQ=DAILY', 'starts_at' => $event->starts_at,
            'ends_at' => $event->ends_at, 'recurrence_starts_at' => $event->starts_at,
            'metadata' => ['frequency' => 'daily']]);
        $event->update(['series_id' => $series->id]);
        $this->sources($this->day(), 'kind=calendar&calendar_id='.$event->calendar_id)->assertOk()
            ->assertJsonPath('meta.status', 'partial')->assertJsonPath('meta.truncated', true)->assertJsonCount(0, 'data');
    }

    public function test_revoked_source_ability_between_preview_and_confirmation_blocks_confirmation(): void
    {
        $this->entry();
        $day = $this->day();
        $day = $this->allocations($day, [$this->selection($this->sources($day)->assertOk()->json('data.0'))])->assertOk()->json('data');
        $preview = $this->operation($day, 'preview')->assertOk()->json('preview');
        Sanctum::actingAs($this->worker, ['workdays.read', 'workdays.write', 'workdays.confirm']);
        $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true])->assertUnprocessable();
        $this->assertSame(0, WorkdayRevision::where('state', 'confirmed')->count());
    }

    public function test_personal_bearer_enforces_source_ability_for_discovery_and_allocation(): void
    {
        $this->entry();
        $day = $this->day();
        $without = $this->worker->createToken('Synthetic Workday only', ['workdays.read', 'workdays.write']);
        app('auth')->forgetGuards();
        $this->withToken($without->plainTextToken);
        $this->sources($day)->assertOk()->assertJsonPath('meta.status', 'unavailable');
        $with = $this->worker->createToken('Synthetic Workday sources', ['workdays.read', 'workdays.write', 'tasks.read']);
        app('auth')->forgetGuards();
        $this->withToken($with->plainTextToken);
        $source = $this->sources($day)->assertOk()->assertJsonPath('meta.status', 'complete')->json('data.0');
        $day = $this->allocations($day, [$this->selection($source)])->assertOk()->json('data');
        app('auth')->forgetGuards();
        $this->withToken($without->plainTextToken);
        $this->allocations($day, [$this->selection($source)])->assertUnprocessable();
    }

    public function test_generated_source_api_matches_routes_scopes_and_allocation_contract(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['sources' => ['get', 'workdays.read'], 'allocations' => ['put', 'workdays.write']] as $endpoint => [$method, $scope]) {
            $route = \Illuminate\Support\Facades\Route::getRoutes()->getByName('api.v1.workdays.'.$endpoint);
            $operation = $spec['paths']['/'.$route->uri()][$method];
            $this->assertSame([$scope], $operation['x-required-scopes']);
        }
        $schemas = $spec['components']['schemas'];
        $this->assertFalse($schemas['WorkdayAllocationInput']['additionalProperties']);
        $this->assertArrayHasKey('basis', $schemas['WorkdayAllocation']['properties']);
        $this->assertArrayHasKey('source_key', $schemas['WorkdayAllocation']['properties']);
        $this->assertArrayHasKey('allocations', $schemas['WorkdayDraftInput']['properties']);
        $this->assertArrayHasKey('reconciliation', $schemas['Workday']['properties']);
        $this->assertSame(['complete', 'partial', 'unavailable'], $schemas['WorkdaySourcesResponse']['properties']['meta']['properties']['status']['enum']);
    }

    public function test_browser_discovery_allocation_and_clear_use_same_versioned_action(): void
    {
        $this->entry();
        $day = $this->day();
        $source = $this->sources($day)->assertOk()->json('data.0');
        $this->actingAs($this->worker, 'web')->get('/tech/workdays/'.$day['id'].'/sources')->assertOk()->assertSee('Synthetic task')->assertSee('Save source allocations');
        $this->put('/tech/workdays/'.$day['id'].'/allocations', ['version' => 1, 'allocations' => [$this->selection($source)], 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get('/tech/workdays/'.$day['id'])->assertOk()->assertSee('Attributed: 120 minutes')->assertSee('Date-level, not placed');
        $this->put('/tech/workdays/'.$day['id'].'/allocations', ['version' => 2, 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get('/tech/workdays/'.$day['id'])->assertOk()->assertSee('Attributed: 0 minutes');
    }
}
