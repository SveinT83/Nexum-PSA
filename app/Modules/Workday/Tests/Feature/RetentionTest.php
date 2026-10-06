<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Notification\Notifications\WorkdayReminderNotification;
use App\Modules\Task\Actions\EnsureTaskDefaults;
use App\Modules\Task\Models\Task;
use App\Modules\Task\Models\TaskTimeEntry;
use App\Modules\WorkContext\Actions\EnsureWorkContextDefaults;
use App\Modules\Workday\Actions\PurgeExpiredWorkday;
use App\Modules\Workday\Actions\PurgeWorkdayDiagnostics;
use App\Modules\Workday\Jobs\DeliverWorkdayReminder;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Models\WorkdayReminder;
use App\Modules\Workday\Models\WorkdayReminderDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true, 'workday.retention_enabled' => false, 'telescope.storage.database.connection' => 'sqlite', 'queue.failed.database' => 'sqlite']);
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->get());
        $role->givePermissionTo(['task.view', 'task.create', 'task.update']);
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        Sanctum::actingAs($this->worker, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(string $date): array
    {
        return ['version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'Synthetic private description',
            'intervals' => [['start' => $date.'T08:00', 'end' => $date.'T16:00', 'description' => 'Synthetic activity']], 'breaks' => []];
    }

    private function day(string $date = '2026-10-01', ?string $key = null): array
    {
        return $this->putJson('/api/v1/workdays/'.$date.'/draft', $this->payload($date),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()])->assertOk()->json('data');
    }

    private function operation(array $day, string $operation, array $input = []): array
    {
        return $this->postJson('/api/v1/workdays/'.$day['id'].'/'.$operation, ['version' => $day['version']] + $input,
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json();
    }

    private function absence(string $date = '2026-10-01'): array
    {
        return $this->postJson('/api/v1/workday-absences', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'mode' => 'full_day', 'category' => 'sickness', 'start_date' => $date, 'end_date' => $date],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    }

    private function purge(int $limit = 100): array
    {
        config(['workday.retention_enabled' => true]);

        return app(PurgeExpiredWorkday::class)->handle($limit);
    }

    public function test_exact_cutoff_rejects_reads_replays_and_recreation_and_preserves_next_day(): void
    {
        $key = (string) Str::uuid();
        $old = $this->day(key: $key);
        $next = $this->day('2026-10-02');
        $cutoff = Workday::where('uuid', $old['id'])->firstOrFail()->expires_at;
        $this->assertSame('2029-10-01 22:00:00', $cutoff->utc()->format('Y-m-d H:i:s'));
        Carbon::setTestNow($cutoff->subSecond());
        $this->getJson('/api/v1/workdays/'.$old['id'])->assertOk();
        $this->assertSame(0, $this->purge()['removed']);
        Carbon::setTestNow($cutoff);
        $this->getJson('/api/v1/workdays/'.$old['id'])->assertNotFound();
        $this->putJson('/api/v1/workdays/2026-10-01/draft', $this->payload('2026-10-01'), ['Idempotency-Key' => $key])->assertGone();
        $this->assertSame(1, $this->purge()['removed']);
        foreach (['workday_revisions', 'workday_mutation_receipts'] as $table) {
            $this->assertDatabaseCount($table, 1);
        }
        $this->getJson('/api/v1/workdays/'.$next['id'])->assertOk();
        $this->putJson('/api/v1/workdays/2026-10-01/draft', $this->payload('2026-10-01'),
            ['Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable();
        Carbon::setTestNow($cutoff->addSecond());
        $this->assertSame(0, $this->purge()['removed']);
    }

    public function test_conversion_history_and_all_owned_copies_are_removed_but_task_and_time_survive(): void
    {
        app(EnsureTaskDefaults::class)->handle();
        app(EnsureWorkContextDefaults::class)->internalContext();
        $day = $this->day();
        $preview = $this->operation($day, 'preview')['preview'];
        $day = $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true])['data'];
        $expiry = Workday::first()->expires_at;
        $conversion = $this->operation($day, 'task-conversions/preview', ['interval_index' => 0, 'title' => 'Synthetic internal task',
            'description' => 'Source-domain work', 'reason' => 'Explicit source attribution', 'start' => '2026-10-01T08:00', 'end' => '2026-10-01T10:00'])['preview'];
        $day = $this->operation($day, 'task-conversions', ['preview_token' => $conversion['token'], 'create_task' => true])['data'];
        $this->assertTrue(Workday::first()->expires_at->eq($expiry));
        $this->assertDatabaseCount('workday_source_allocations', 1);
        $task = Task::first()->getAttributes();
        $time = TaskTimeEntry::first()->getAttributes();
        Carbon::setTestNow($expiry);
        $this->assertSame(1, $this->purge()['removed']);
        foreach (['workdays', 'workday_revisions', 'workday_previews', 'workday_task_conversion_previews', 'workday_source_allocations', 'workday_mutation_receipts'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame($task, Task::first()->getAttributes());
        $this->assertSame($time, TaskTimeEntry::first()->getAttributes());
    }

    public function test_absence_corrections_keep_original_deadline_and_only_owned_calendar_projection_is_removed(): void
    {
        $absence = $this->absence();
        $expiry = WorkdayAbsence::first()->expires_at;
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker, seedWorkingWeek: false);
        $event = CalendarEvent::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id, 'title' => 'Independent',
            'starts_at' => '2026-10-01 06:00', 'ends_at' => '2026-10-01 07:00', 'timezone' => 'Europe/Oslo', 'source' => 'local']);
        $rule = CalendarAvailabilityRule::create(['calendar_id' => $calendar->id, 'user_id' => $this->worker->id, 'weekday' => 1,
            'starts_at_local' => '08:00', 'ends_at_local' => '16:00', 'timezone' => 'Europe/Oslo', 'availability_type' => 'working', 'effective_from' => '2026-01-01']);
        $this->patchJson('/api/v1/workday-absences/'.$absence['id'], ['version' => 1, 'timezone' => 'Europe/Oslo', 'mode' => 'full_day',
            'category' => 'other', 'start_date' => '2026-10-02', 'end_date' => '2026-10-04'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertTrue(WorkdayAbsence::first()->expires_at->eq($expiry));
        Carbon::setTestNow($expiry);
        $this->assertNull(CalendarEvent::find($absence['calendar_event_id']));
        $this->assertSame(1, $this->purge()['removed']);
        foreach (['workday_absences', 'workday_absence_revisions', 'workday_absence_receipts', 'calendar_event_links'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertDatabaseMissing('calendar_events', ['id' => $absence['calendar_event_id']]);
        $this->assertNotNull($event->fresh());
        $this->assertNotNull($rule->fresh());
    }

    public function test_projection_integrity_failure_rolls_back_one_root_and_continues_other_roots(): void
    {
        $a = $this->absence();
        $b = $this->absence('2026-10-02');
        DB::table('calendar_events')->where('id', $a['calendar_event_id'])->update(['source' => 'local']);
        Carbon::setTestNow('2030-01-01');
        $result = $this->purge();
        $this->assertSame(1, $result['blocked']);
        $this->assertSame(['projection_integrity'], $result['reason_codes']);
        $this->assertSame(1, $result['removed']);
        $this->assertDatabaseHas('workday_absences', ['uuid' => $a['id']]);
        $this->assertDatabaseHas('calendar_events', ['id' => $a['calendar_event_id']]);
        $this->assertDatabaseMissing('workday_absences', ['uuid' => $b['id']]);
        $this->assertDatabaseCount('workday_absence_revisions', 1);
    }

    public function test_late_delete_failure_restores_children_and_outputs_only_a_reason_code(): void
    {
        $day = $this->day();
        Carbon::setTestNow('2030-01-01');
        Event::listen('eloquent.deleting: '.Workday::class, fn () => throw new \RuntimeException('SECRET SQL BINDINGS'));
        $result = $this->purge();
        $this->assertSame(1, $result['blocked']);
        $this->assertStringNotContainsString('SECRET', json_encode($result));
        $this->assertDatabaseHas('workdays', ['uuid' => $day['id'], 'version' => 1]);
        $this->assertNotNull(Workday::first()->current_revision_id);
        $this->assertDatabaseCount('workday_revisions', 1);
        $this->assertDatabaseCount('workday_mutation_receipts', 1);
    }

    public function test_leap_day_and_multi_day_absence_use_original_local_date(): void
    {
        $day = $this->day('2024-02-29');
        $this->assertSame('2027-02-28 23:00:00', Workday::where('uuid', $day['id'])->first()->expires_at->utc()->format('Y-m-d H:i:s'));
        $this->postJson('/api/v1/workday-absences', ['version' => 0, 'timezone' => 'Pacific/Auckland', 'mode' => 'full_day',
            'category' => 'other', 'start_date' => '2024-02-01', 'end_date' => '2024-02-29'],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        $this->assertSame('2027-02-28 11:00:00', WorkdayAbsence::first()->expires_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_reminder_generations_legacy_notifications_and_queued_job_are_cleaned_together(): void
    {
        $r = WorkdayReminder::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->worker->id, 'work_date' => '2023-10-01',
            'timezone' => 'Europe/Oslo', 'due_at' => now()->subYears(3), 'expires_at' => now(), 'notification_id' => (string) Str::uuid()]);
        $job = null;
        foreach ([1, 2] as $generation) {
            $delivery = WorkdayReminderDelivery::create(['reminder_id' => $r->id, 'generation' => $generation, 'channel' => 'database']);
            $job = new DeliverWorkdayReminder($delivery->id);
            $data = ['url' => route('tech.workday-reminders.open', $r->uuid, false)];
            if ($generation === 2) {
                $data += ['workday_reminder' => $r->uuid, 'workday_expires_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')];
            }
            $this->worker->notifications()->create(['id' => (string) Str::uuid(), 'type' => WorkdayReminderNotification::class, 'data' => $data]);
        }
        $this->worker->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'UnrelatedNotification', 'data' => ['title' => 'Keep']]);
        $this->assertSame(1, $this->purge()['removed']);
        $this->assertDatabaseCount('workday_reminders', 0);
        $this->assertDatabaseCount('workday_reminder_deliveries', 0);
        $this->assertDatabaseCount('notifications', 1);
        app()->call([$job, 'handle']);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertTrue(app(PurgeExpiredWorkday::class)->preview()['restore_ready']);
    }

    public function test_orphan_notification_after_partial_restore_is_removed_without_changing_other_types(): void
    {
        $this->worker->notifications()->create(['id' => (string) Str::uuid(), 'type' => WorkdayReminderNotification::class,
            'data' => ['workday_reminder' => (string) Str::uuid(), 'workday_expires_at' => now()->addYears(3)->format('Y-m-d\TH:i:s\Z')]]);
        $this->assertSame(1, app(PurgeExpiredWorkday::class)->preview()['eligible']['notification_copies']);
        $this->assertSame(1, $this->purge()['removed']);
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_retained_legacy_notice_has_known_provenance_but_orphaned_legacy_notice_blocks_restore(): void
    {
        $r = WorkdayReminder::create(['uuid' => (string) Str::uuid(), 'user_id' => $this->worker->id, 'work_date' => '2026-10-01',
            'timezone' => 'Europe/Oslo', 'due_at' => now(), 'expires_at' => now()->addYears(3), 'notification_id' => (string) Str::uuid()]);
        $this->worker->notifications()->create(['id' => (string) Str::uuid(), 'type' => WorkdayReminderNotification::class,
            'data' => ['url' => route('tech.workday-reminders.open', $r->uuid, false)]]);
        $this->assertTrue(app(PurgeExpiredWorkday::class)->preview()['restore_ready']);
        $this->worker->notifications()->create(['id' => (string) Str::uuid(), 'type' => WorkdayReminderNotification::class,
            'data' => ['url' => route('tech.workday-reminders.open', (string) Str::uuid(), false)]]);
        $this->assertSame(1, app(PurgeExpiredWorkday::class)->preview()['untracked_notification_copies']);
        $this->assertFalse(app(PurgeExpiredWorkday::class)->preview()['restore_ready']);
        $this->assertSame(0, $this->purge()['removed']);
        $this->assertDatabaseCount('notifications', 2);
    }

    public function test_commands_default_to_preview_and_disabled_execution_fails_but_separate_retention_works(): void
    {
        $this->day();
        Carbon::setTestNow('2030-01-01');
        $this->artisan('workday:retention')->assertSuccessful();
        $this->artisan('workday:retention --execute')->assertFailed();
        $this->artisan('workday:retention --restore-check')->assertFailed();
        $this->artisan('workday:retention --execute --limit=201')->assertExitCode(2);
        $this->assertDatabaseCount('workdays', 1);
        config(['workday.enabled' => false, 'workday.retention_enabled' => true]);
        $this->artisan('workday:retention --execute --limit=1')->assertSuccessful();
        $this->artisan('workday:retention --restore-check')->assertSuccessful();
    }

    public function test_bounded_interrupted_cleanup_can_resume_and_synthetic_restore_must_be_cleaned_again(): void
    {
        $this->day();
        $this->day('2026-10-02');
        $this->day('2026-10-03');
        $tables = ['workdays', 'workday_revisions', 'workday_mutation_receipts'];
        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->get()->map(fn ($row) => (array) $row)->all();
        }
        Carbon::setTestNow('2030-01-01');
        $this->assertSame(1, $this->purge(1)['removed']);
        $this->assertDatabaseCount('workdays', 2);
        $this->assertSame(2, $this->purge(2)['removed']);
        $this->assertSame(0, $this->purge()['removed']);
        foreach ($snapshot as $table => $rows) {
            DB::table($table)->insert($rows);
        }
        $this->artisan('workday:retention --restore-check')->assertFailed();
        $this->assertSame(3, $this->purge()['removed']);
        $this->artisan('workday:retention --restore-check')->assertSuccessful();
    }

    public function test_preview_requires_explicit_permission_and_token_scope_and_never_exposes_personal_content(): void
    {
        $this->day();
        Carbon::setTestNow('2030-01-01');
        config(['workday.enabled' => false]);
        Sanctum::actingAs($this->worker, ['workdays.read']);
        $this->postJson('/api/v1/workday-settings/retention-preview')->assertForbidden();
        Sanctum::actingAs($this->worker, ['workdays.settings']);
        $response = $this->postJson('/api/v1/workday-settings/retention-preview')->assertOk()->assertJsonPath('data.eligible.workdays', 1);
        foreach (['Synthetic', $this->worker->email, '2026-10-01', 'user_id', 'category'] as $private) {
            $this->assertStringNotContainsString($private, $response->getContent());
        }
        $this->postJson('/api/v1/workday-settings/retention-preview', ['cutoff' => '2035-01-01'])->assertUnprocessable();
        $this->assertDatabaseCount('workdays', 1);
        $role = Role::findByName('Tech', 'web');
        $role->revokePermissionTo('workday.manage_settings');
        $this->worker->unsetRelation('roles');
        $this->postJson('/api/v1/workday-settings/retention-preview')->assertForbidden();
    }

    public function test_browser_validation_preserves_form_without_flashing_employee_input_and_disables_caching(): void
    {
        $body = $this->payload('2026-10-01');
        $body['intervals'][0]['end'] = 'invalid';
        $body['request_key'] = (string) Str::uuid();
        $this->actingAs($this->worker)->put('/tech/workdays/2026-10-01/draft', $body)
            ->assertUnprocessable()->assertSee('Synthetic private description')->assertSessionMissing('_old_input');
        $this->get('/tech/workdays/create')->assertOk()->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_privacy_exception_handler_preserves_unauthenticated_responses(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workdays')->assertUnauthorized();
        $this->postJson('/api/v1/workday-settings/retention-preview')->assertUnauthorized();
        $this->postJson('/api/v1/workday-settings/retention-preview', [], ['Authorization' => 'Bearer synthetic-invalid'])->assertUnauthorized();
        $this->get('/tech/workdays')->assertRedirect('/login');
    }

    public function test_workday_debug_exception_response_and_log_exclude_raw_payload(): void
    {
        config(['app.debug' => true]);
        \Illuminate\Support\Facades\Log::spy();
        Route::get('/api/v1/workday-retention-error-fixture', fn () => throw new \RuntimeException('PRIVATE SQL WORK TEXT'));
        $this->getJson('/api/v1/workday-retention-error-fixture')->assertStatus(500)->assertDontSee('PRIVATE SQL WORK TEXT');
        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')->with('Workday request failed.', ['exception_class' => \RuntimeException::class])->once();
    }

    public function test_failed_job_copies_are_removed_but_unrelated_job_failures_remain(): void
    {
        foreach ([DeliverWorkdayReminder::class, 'OtherJob'] as $class) {
            DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default',
                'payload' => json_encode(['displayName' => $class]), 'exception' => 'Historical synthetic diagnostic', 'failed_at' => now()]);
        }
        $this->assertSame(1, app(PurgeExpiredWorkday::class)->preview()['eligible']['diagnostic_copies']);
        $this->assertSame(1, $this->purge(1)['removed']);
        $this->assertDatabaseCount('failed_jobs', 1);
    }

    public function test_retention_browser_preview_and_real_personal_bearer_are_metadata_only(): void
    {
        $this->actingAs($this->worker)->post('/tech/admin/settings/workday/retention-preview')
            ->assertOk()->assertSee('Workday retention preview')->assertSee('Workday diagnostic copies');
        $token = $this->worker->createToken('Synthetic retention test', ['workdays.settings'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/workday-settings', ['Authorization' => 'Bearer '.$token])->assertOk();
        $this->postJson('/api/v1/workday-settings/retention-preview', [], ['Authorization' => 'Bearer '.$token])
            ->assertOk()->assertJsonPath('data.work_retention_years', 3);
    }

    public function test_absence_validation_does_not_flash_private_input(): void
    {
        $this->actingAs($this->worker)->post('/tech/workday-absences', ['version' => 0, 'timezone' => 'Europe/Oslo',
            'mode' => 'partial', 'category' => 'sickness', 'starts_at' => 'invalid', 'ends_at' => 'invalid',
            'request_key' => (string) Str::uuid()])->assertUnprocessable()->assertSee('My absences')->assertSessionMissing('_old_input');
    }

    public function test_diagnostic_cleanup_is_bounded_keeps_batch_provenance_and_preserves_other_batches(): void
    {
        $batch = (string) Str::uuid();
        foreach ([['batch' => $batch, 'content' => ['uri' => '/api/v1/workdays']], ['batch' => $batch, 'content' => ['sql' => 'private binding']],
            ['batch' => (string) Str::uuid(), 'content' => ['uri' => '/api/v1/clients']]] as $entry) {
            DB::table('telescope_entries')->insert(['uuid' => (string) Str::uuid(), 'batch_id' => $entry['batch'],
                'type' => 'request', 'content' => json_encode($entry['content']), 'created_at' => now()]);
        }
        $this->assertSame(2, app(PurgeWorkdayDiagnostics::class)->count());
        $this->assertSame(1, $this->purge(1)['removed']);
        $this->assertSame(1, app(PurgeWorkdayDiagnostics::class)->count());
        $this->assertSame(1, $this->purge(1)['removed']);
        $this->assertDatabaseCount('telescope_entries', 1);
        $this->assertTrue(PurgeWorkdayDiagnostics::containsWorkday(['sql' => 'select * from workday_revisions']));
        $this->assertFalse(PurgeWorkdayDiagnostics::containsWorkday(['sql' => 'select * from tasks']));
    }
}
