<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Queries\WorkdayEvidence;
use App\Modules\Integration\Support\ApiAbilityCatalog;
use App\Modules\Report\Support\ReportRegistry;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use App\Modules\Workday\Queries\SourceEvidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ConfirmedOversightTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    private User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:00', 'Europe/Oslo'));
        config(['workday.enabled' => true]);
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')->update([
            'json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0]),
        ]);
        $tech = Role::findOrCreate('Tech', 'web');
        $tech->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.confirm_own', 'task.view', 'calendar.view']);
        $hr = Role::findOrCreate('Synthetic HR', 'web');
        $hr->givePermissionTo(['workday.view_all', 'report.view']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Worker Alpha']);
        $this->worker->assignRole($tech);
        $this->viewer = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Synthetic Reviewer']);
        $this->viewer->assignRole($hr);
        Sanctum::actingAs($this->viewer, ['workdays.read-all']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function day(string $date = '2026-10-01', ?User $worker = null, bool $confirm = true): array
    {
        $worker ??= $this->worker;
        $action = app(MutateWorkday::class);
        $day = $action->handle($worker, 'draft', $date, ['version' => 0, 'timezone' => 'Europe/Oslo',
            'description' => 'Confirmed support work', 'intervals' => [['start' => $date.'T08:00', 'end' => $date.'T16:00']],
            'breaks' => [['start' => $date.'T12:00', 'end' => $date.'T12:30', 'included' => false]]], (string) Str::uuid(), 'ui')['data'];
        if (! $confirm) {
            return $day;
        }

        return $this->confirm($worker, $day);
    }

    private function confirm(User $worker, array $day): array
    {
        $action = app(MutateWorkday::class);
        $preview = $action->handle($worker, 'preview', $day['id'], ['version' => $day['version']], (string) Str::uuid(), 'ui')['preview'];

        return $action->handle($worker, 'confirm', $day['id'], ['version' => $day['version'], 'preview_token' => $preview['token'],
            'confirmed' => true], (string) Str::uuid(), 'ui')['data'];
    }

    private function url(array $day, string $suffix = ''): string
    {
        return '/api/v1/workdays/overview/'.$day['id'].$suffix;
    }

    private function addSource(array $day, array $source): array
    {
        $allocation = Arr::only($source, ['source_key', 'source_revision', 'kind', 'calendar_id', 'minutes']) + ['acknowledged' => true];
        $day = app(MutateWorkday::class)->handle($this->worker, 'allocations', $day['id'], ['version' => $day['version'],
            'allocations' => [$allocation]], (string) Str::uuid(), 'ui')['data'];

        return $this->confirm($this->worker, $day);
    }

    public function test_superuser_and_explicit_hr_can_read_confirmed_facts_without_own_grants(): void
    {
        $day = $this->day();
        $this->getJson('/api/v1/workdays/overview')->assertOk()->assertJsonPath('totals.actual_minutes', 450)
            ->assertJsonPath('data.0.worker.name', 'Worker Alpha')->assertJsonPath('meta.status', 'complete');
        $this->getJson($this->url($day))->assertOk()->assertJsonPath('data.confirmed.version', 2);
        $super = Role::findOrCreate('Superuser', 'web');
        $super->givePermissionTo('workday.view_all');
        $this->viewer->syncRoles([$super]);
        $this->getJson($this->url($day))->assertOk();
        $super->revokePermissionTo('workday.view_all');
        $this->viewer->unsetRelation('roles')->unsetRelation('permissions');
        foreach (['/api/v1/workdays/overview', $this->url($day), $this->url($day, '/history')] as $url) {
            $this->getJson($url)->assertForbidden();
        }
    }

    public function test_report_permission_or_own_scope_never_substitutes_for_oversight(): void
    {
        $this->day();
        Sanctum::actingAs($this->worker, ['workdays.read-all']);
        $this->getJson('/api/v1/workdays/overview')->assertForbidden();
        $this->worker->givePermissionTo('report.view');
        $this->getJson('/api/v1/workdays/overview')->assertForbidden();
        Sanctum::actingAs($this->viewer, ['workdays.read']);
        $this->getJson('/api/v1/workdays/overview')->assertForbidden();
    }

    public function test_pending_correction_and_private_only_day_are_absent_from_every_projection(): void
    {
        $day = $this->day();
        $private = $this->day('2026-10-02', confirm: false);
        $draft = app(MutateWorkday::class)->handle($this->worker, 'correction', $day['id'],
            ['version' => $day['version'], 'reason' => 'PRIVATE CORRECTION REASON'], (string) Str::uuid(), 'api')['data'];
        app(MutateWorkday::class)->handle($this->worker, 'draft', '2026-10-01', [
            'version' => $draft['version'], 'timezone' => 'Europe/Oslo', 'description' => 'PRIVATE DRAFT DESCRIPTION',
            'intervals' => [['start' => '2026-10-01T08:00', 'end' => '2026-10-01T09:00']], 'breaks' => [],
        ], (string) Str::uuid(), 'api');
        foreach (['/api/v1/workdays/overview', $this->url($day), $this->url($day, '/history')] as $url) {
            $response = $this->getJson($url)->assertOk()->assertDontSee('PRIVATE')->assertDontSee('correction_reason')
                ->assertDontSee('absence_overlap_acknowledged')->assertDontSee('current_revision')->assertDontSee('source_revision');
        }
        $this->getJson('/api/v1/workdays/overview')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('totals.actual_minutes', 450);
        $this->getJson($this->url($private))->assertNotFound();
        $this->getJson($this->url($private, '/history'))->assertNotFound();
        $this->getJson('/api/v1/workdays/overview?worker=PRIVATE')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_confirmed_history_excludes_drafts_and_updates_only_after_reconfirmation(): void
    {
        $day = $this->day();
        $draft = app(MutateWorkday::class)->handle($this->worker, 'correction', $day['id'],
            ['version' => $day['version'], 'reason' => 'Private explanation'], (string) Str::uuid(), 'ui')['data'];
        $latest = $this->confirm($this->worker, $draft);
        $this->getJson($this->url($day))->assertOk()->assertJsonPath('data.confirmed.version', $latest['confirmed']['version']);
        $this->getJson($this->url($day, '/history?per_page=1'))->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.next_page', 2)->assertJsonPath('meta.status', 'partial')->assertJsonCount(1, 'data');
        $this->getJson($this->url($day, '/history?page=2&per_page=1'))->assertOk()->assertJsonPath('data.0.confirmed.version', 2);
    }

    public function test_large_filtered_totals_cover_all_pages_without_including_drafts_or_expired_days(): void
    {
        // Synthetic persisted revisions make >100 rows in the bounded date window without source or billing records.
        $second = User::factory()->create(['status' => User::STATUS_ACTIVE, 'name' => 'Worker Beta']);
        $sample = $this->day();
        $snapshot = $sample['confirmed']['snapshot'];
        foreach ([$this->worker, $second] as $worker) {
            for ($i = 1; $i <= 60; $i++) {
                $date = CarbonImmutable::parse('2026-08-01')->addDays($i)->toDateString();
                $record = Workday::create(['uuid' => (string) Str::uuid(), 'user_id' => $worker->id, 'work_date' => $date,
                    'timezone' => 'Europe/Oslo', 'version' => 2, 'expires_at' => now()->addYears(3)]);
                $revision = WorkdayRevision::create(['uuid' => (string) Str::uuid(), 'workday_id' => $record->id, 'version' => 2,
                    'state' => 'confirmed', 'snapshot' => $snapshot, 'author_id' => $worker->id, 'origin' => 'api', 'created_at' => now()]);
                $record->update(['current_revision_id' => $revision->id, 'confirmed_revision_id' => $revision->id]);
            }
        }
        $this->day('2026-10-02', confirm: false);
        $base = '/api/v1/workdays/overview?from=2026-08-01&to=2026-10-02';
        $this->getJson($base.'&per_page=100')->assertOk()->assertJsonCount(100, 'data')->assertJsonPath('meta.total', 121)
            ->assertJsonPath('totals.actual_minutes', 54450)->assertJsonPath('meta.next_page', 2)->assertJsonPath('meta.truncated', false);
        $this->getJson($base.'&per_page=100&page=2')->assertOk()->assertJsonCount(21, 'data')->assertJsonPath('meta.status', 'partial');
        $this->getJson($base.'&worker_id='.$second->id)->assertOk()->assertJsonPath('meta.total', 60)->assertJsonPath('totals.actual_minutes', 27000);
        $this->getJson($base.'&worker=Alpha')->assertOk()->assertJsonPath('meta.total', 61);
        $this->getJson($base.'&worker=%25')->assertOk()->assertJsonPath('meta.total', 0);
        Workday::where('uuid', $sample['id'])->update(['expires_at' => now()->subSecond()]);
        $this->getJson($base)->assertOk()->assertJsonPath('meta.total', 120);
        $this->getJson($this->url($sample))->assertNotFound();
    }

    public function test_invalid_and_forged_filters_cannot_widen_the_query(): void
    {
        foreach (['from=2026-01-01&to=2026-04-04', 'from=2026-10-20&to=2026-10-01', 'per_page=101',
            'page=0', 'worker_id[]=1', 'company_id=2', 'include=drafts', 'from=2026-02-30'] as $query) {
            $this->getJson('/api/v1/workdays/overview?'.$query)->assertUnprocessable();
        }
        $this->getJson('/api/v1/workdays/overview?from=2026-01-01&to=2026-04-03')->assertOk();
        $this->getJson('/api/v1/workdays/overview?worker_id=999999')->assertOk()->assertJsonPath('meta.total', 0);
    }

    public function test_source_details_follow_viewer_permission_and_ability_without_impersonating_worker(): void
    {
        $task = Task::create(['title' => 'Restricted task label', 'owner_type' => User::class, 'owner_id' => $this->worker->id]);
        $entry = TaskTimeEntry::create(['task_id' => $task->id, 'user_id' => $this->worker->id,
            'work_date' => '2026-10-01', 'minutes' => 120, 'source_type' => 'manual', 'note' => 'Private source note']);
        $draft = $this->day(confirm: false);
        $source = app(SourceEvidence::class)->describeTime($entry->fresh('task'), 'task');
        $day = $this->addSource($draft, $source);
        $this->getJson($this->url($day))->assertOk()->assertDontSee('Restricted task label')->assertDontSee('source_key')
            ->assertDontSee('Private source note')->assertJsonPath('data.confirmed.snapshot.allocations.0.source.status', 'unavailable');
        $this->viewer->givePermissionTo('task.view');
        $this->getJson($this->url($day))->assertOk()->assertDontSee('Restricted task label');
        Sanctum::actingAs($this->viewer, ['workdays.read-all', 'tasks.read']);
        $this->getJson($this->url($day))->assertOk()->assertSee('Restricted task label')->assertDontSee('Private source note')
            ->assertJsonPath('data.confirmed.snapshot.allocations.0.source.status', 'current');
        $task->update(['title' => 'Updated source label']);
        $this->getJson($this->url($day))->assertOk()->assertJsonPath('data.confirmed.snapshot.allocations.0.source.status', 'stale')
            ->assertJsonPath('data.confirmed.snapshot.actual_minutes', 450);
        $this->viewer->revokePermissionTo('task.view');
        $this->viewer->unsetRelation('permissions');
        $this->getJson($this->url($day, '/history'))->assertOk()->assertDontSee('Updated source label');
        $entry->delete();
        $this->getJson($this->url($day))->assertOk()->assertJsonPath('data.confirmed.snapshot.allocations.0.source.status', 'unavailable');
    }

    public function test_private_calendar_details_and_absence_metadata_are_not_inherited_from_oversight(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker);
        $event = CalendarEvent::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id,
            'created_by' => $this->worker->id, 'title' => 'Private meeting reason', 'description' => 'Private notes',
            'visibility' => 'private', 'starts_at' => '2026-10-01 08:00:00', 'ends_at' => '2026-10-01 09:00:00',
            'timezone' => 'Europe/Oslo', 'status' => 'confirmed']);
        $source = app(WorkdayEvidence::class)->read($this->worker, $calendar->id,
            CarbonImmutable::parse('2026-10-01T00:00+02:00'), CarbonImmutable::parse('2026-10-02T00:00+02:00'))['rows'][0];
        $day = $this->addSource($this->day(confirm: false), $source);
        $this->viewer->givePermissionTo('calendar.view');
        Sanctum::actingAs($this->viewer, ['workdays.read-all', 'calendar.read']);
        $this->getJson($this->url($day))->assertOk()->assertDontSee('Private meeting reason')->assertDontSee('Private notes');
        $access = $calendar->access()->create(['subject_type' => 'user', 'subject_id' => $this->viewer->id,
            'access_level' => 'viewer', 'can_view_private_details' => false]);
        $this->getJson($this->url($day))->assertOk()->assertDontSee('Private meeting reason');
        $access->update(['can_view_private_details' => true]);
        $this->getJson($this->url($day))->assertOk()->assertSee('Private meeting reason')->assertDontSee('Private notes');
        $access->delete();
        $this->getJson($this->url($day, '/history'))->assertOk()->assertDontSee('Private meeting reason');
        $this->getJson($this->url($day))->assertOk()->assertDontSee('absence')->assertDontSee('calendar_id');
    }

    public function test_foreign_own_endpoints_and_mutations_remain_forbidden_even_with_all_scopes(): void
    {
        $day = $this->day();
        $this->viewer->givePermissionTo(['workday.view_own', 'workday.manage_own', 'workday.confirm_own', 'workday.absence_view_own']);
        Sanctum::actingAs($this->viewer, ['*']);
        $this->getJson('/api/v1/workdays/'.$day['id'])->assertNotFound();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/history')->assertNotFound();
        $this->getJson('/api/v1/workdays/'.$day['id'].'/sources')->assertNotFound();
        $this->postJson('/api/v1/workdays/'.$day['id'].'/corrections', ['version' => 2, 'reason' => 'Forbidden foreign edit'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_report_discovery_and_browser_pages_follow_feature_and_explicit_grants(): void
    {
        $day = $this->day();
        $this->actingAs($this->viewer, 'web')->get('/tech/reports')->assertOk()->assertSee('Confirmed workdays');
        $this->get('/tech/workdays/overview')->assertOk()->assertSee('Worker Alpha')->assertSee('450')
            ->assertDontSee('Confirm my workday');
        $this->get('/tech/workdays/overview/'.$day['id'])->assertOk()->assertSee('Confirmed revision history')->assertDontSee('Start correction');
        $this->get('/tech/workdays/overview/'.$day['id'].'/history')->assertOk()->assertSee('Revision 2');
        Role::findByName('Synthetic HR', 'web')->revokePermissionTo('workday.view_all');
        $this->viewer->unsetRelation('roles')->unsetRelation('permissions');
        $this->get('/tech/reports')->assertOk()->assertDontSee('Confirmed workdays')->assertSee('Ticket SLA')->assertDontSee('>Workday<', false);
        $this->get('/tech/workdays/overview/'.$day['id'])->assertForbidden();
        $this->viewer->givePermissionTo('workday.view_all');
        config(['workday.enabled' => false]);
        $this->get('/tech/workdays/overview')->assertNotFound();
        $this->assertFalse(app(ReportRegistry::class)->visibleFor($this->viewer)->contains('key', 'workday.confirmed'));
    }

    public function test_oversight_migration_and_reseeding_preserve_explicit_revocation_and_other_roles(): void
    {
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $super = Role::findByName('Superuser', 'web');
        $this->assertTrue($super->hasPermissionTo('workday.view_all'));
        $this->assertFalse(Role::findByName('Admin', 'web')->hasPermissionTo('workday.view_all'));
        $this->assertFalse(Role::findByName('Tech', 'web')->hasPermissionTo('workday.view_all'));
        $super->revokePermissionTo('workday.view_all');
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->assertFalse($super->fresh()->hasPermissionTo('workday.view_all'));
        $migration = require database_path('migrations/2026_10_02_180000_deploy_workday_oversight_permission.php');
        $migration->up();
        $this->assertTrue($super->fresh()->hasPermissionTo('workday.view_all'));
        $this->assertFalse(Role::findByName('Admin', 'web')->hasPermissionTo('workday.view_all'));
        $this->assertTrue(app(ApiAbilityCatalog::class)->isReadOnly('workdays.read-all'));
    }

    public function test_personal_bearer_and_identity_guards_apply_to_oversight(): void
    {
        $day = $this->day();
        $token = $this->viewer->createToken('Synthetic oversight', ['workdays.read-all']);
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson($this->url($day))->assertOk();
        $this->viewer->forceFill(['status' => User::STATUS_DISABLED])->save();
        app('auth')->forgetGuards();
        $this->getJson($this->url($day))->assertForbidden();
        $this->viewer->forceFill(['status' => User::STATUS_ACTIVE, 'is_system_actor' => true])->save();
        app('auth')->forgetGuards();
        $this->getJson($this->url($day))->assertForbidden();
    }

    public function test_absence_reason_and_other_workers_absence_records_are_never_exposed(): void
    {
        $day = $this->day();
        $this->worker->givePermissionTo(['workday.absence_manage_own', 'workday.absence_view_own']);
        $absence = app(\App\Modules\Workday\Actions\MutateAbsence::class)->handle($this->worker, 'create', null, [
            'version' => 0, 'category' => 'sickness', 'mode' => 'full_day', 'timezone' => 'Europe/Oslo',
            'start_date' => '2026-10-01', 'end_date' => '2026-10-01',
        ], (string) Str::uuid(), 'ui')['data'];
        foreach (['/api/v1/workdays/overview', $this->url($day), $this->url($day, '/history')] as $url) {
            $this->getJson($url)->assertOk()->assertDontSee('sickness')->assertDontSee($absence['id'])->assertDontSee('absence_warnings');
        }
        $this->viewer->givePermissionTo('workday.absence_view_own');
        Sanctum::actingAs($this->viewer, ['*']);
        $this->getJson('/api/v1/workday-absences/'.$absence['id'])->assertNotFound();
    }

    public function test_coordinator_bound_token_cannot_discover_or_read_identified_oversight(): void
    {
        $this->day();
        $token = $this->viewer->createToken('Synthetic workload', ['*']);
        $workload = \App\Modules\Integration\Models\AiWorkloadProfile::create([
            'name' => 'Synthetic coordinator', 'slug' => 'synthetic-coordinator', 'purpose' => 'Test read isolation',
            'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized',
            'abilities' => ['worklog.read'], 'is_approved' => true, 'is_active' => true,
            'expires_at' => now()->addMonth(), 'approved_by' => $this->viewer->id, 'approved_at' => now(),
            'created_by' => $this->viewer->id,
        ]);
        \App\Modules\Integration\Models\AiWorkloadTokenBinding::create([
            'personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30,
            'created_by' => $this->viewer->id,
        ]);
        app('auth')->forgetGuards();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/workdays/overview')->assertForbidden();
        $this->getJson('/api/v1/reports/workday.confirmed')->assertNotFound();
    }

    public function test_generated_contract_has_separate_scope_and_no_owner_response_schema(): void
    {
        $this->artisan('l5-swagger:generate')->assertSuccessful();
        $spec = json_decode(file_get_contents(storage_path('api-docs/api-docs.json')), true, flags: JSON_THROW_ON_ERROR);
        foreach (['', '/{id}', '/{id}/history'] as $suffix) {
            $this->assertSame(['workdays.read-all'], $spec['paths']['/api/v1/workdays/overview'.$suffix]['get']['x-required-scopes']);
        }
        $this->assertArrayNotHasKey('current', $spec['components']['schemas']['WorkdayOverviewDay']['properties']);
        $this->assertArrayNotHasKey('source_key', $spec['components']['schemas']['WorkdayOverviewAllocation']['properties']);
        $this->assertArrayNotHasKey('absence_overlap_acknowledged', $spec['components']['schemas']['WorkdayOverviewSnapshot']['properties']);
    }
}
