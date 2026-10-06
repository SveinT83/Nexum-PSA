<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\ProjectWorkdayAbsence;
use App\Modules\Calendar\Models\CalendarAvailabilityOverride;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventLink;
use App\Modules\Calendar\Services\CalendarVisibility;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Services\NextcloudReadClient;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Models\WorkdayAbsenceRevision;
use App\Modules\Workday\Models\WorkdayRevision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AbsenceTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:00', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')
            ->update(['json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0])]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->get());
        $role->givePermissionTo(Permission::where('name', 'like', 'calendar.%')->get());
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        Sanctum::actingAs($this->worker, ['*']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function payload(string $date = '2026-10-01'): array
    {
        return ['version' => 0, 'timezone' => 'Europe/Oslo', 'category' => 'sickness',
            'mode' => 'full_day', 'start_date' => $date, 'end_date' => $date];
    }

    private function partial(string $start = '2026-10-01T10:00', string $end = '2026-10-01T12:00'): array
    {
        return ['version' => 0, 'timezone' => 'Europe/Oslo', 'category' => 'other',
            'mode' => 'partial', 'starts_at' => $start, 'ends_at' => $end];
    }

    private function create(?array $body = null, ?string $key = null)
    {
        return $this->postJson('/api/v1/workday-absences', $body ?? $this->payload(),
            ['Idempotency-Key' => $key ?? (string) Str::uuid()]);
    }

    private function update(array $absence, array $body)
    {
        $body['version'] = $absence['version'];

        return $this->patchJson('/api/v1/workday-absences/'.$absence['id'], $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function cancel(array $absence)
    {
        return $this->postJson('/api/v1/workday-absences/'.$absence['id'].'/cancel',
            ['version' => $absence['version']], ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function rule(int $weekday = 4, string $start = '08:00', string $end = '16:00', array $extra = []): CalendarAvailabilityRule
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker, seedWorkingWeek: false);

        return CalendarAvailabilityRule::create(array_replace(['calendar_id' => $calendar->id, 'user_id' => $this->worker->id,
            'weekday' => $weekday, 'starts_at_local' => $start, 'ends_at_local' => $end, 'timezone' => 'Europe/Oslo',
            'effective_from' => '2026-01-01', 'availability_type' => 'working',
            'metadata' => ['source' => UserWorkPlan::SOURCE, 'enabled' => true]], $extra));
    }

    private function workday(): array
    {
        return $this->putJson('/api/v1/workdays/2026-10-01/draft', [
            'version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'Work - unspecified',
            'intervals' => [['start' => '2026-10-01T08:00', 'end' => '2026-10-01T16:00']],
            'breaks' => [['start' => '2026-10-01T12:00', 'end' => '2026-10-01T12:30', 'included' => false]],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
    }

    private function operation(array $day, string $op, array $body = [])
    {
        return $this->postJson('/api/v1/workdays/'.$day['id'].'/'.$op,
            ['version' => $day['version']] + $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_create_correct_cancel_and_replay_keep_one_neutral_projection_and_history(): void
    {
        $this->rule();
        $key = (string) Str::uuid();
        $first = $this->create(null, $key)->assertOk()->assertJsonPath('data.plan_impact.affected_minutes', 480)->json();
        $this->assertSame($first, $this->create(null, $key)->assertOk()->json());
        $a = $first['data'];
        $event = CalendarEvent::findOrFail($a['calendar_event_id']);
        $this->assertSame('Unavailable', $event->title);
        $this->assertNull($event->description);
        $this->assertNull($event->metadata);
        $this->assertSame(0, $event->participants()->count());
        $this->assertNull($event->series_id);
        $this->assertTrue($event->all_day);
        $a = $this->update($a, $this->partial())->assertOk()->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.plan_impact.affected_minutes', 120)->json('data');
        $this->assertSame($event->id, $a['calendar_event_id']);
        $a = $this->cancel($a)->assertOk()->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.plan_impact.affected_minutes', 0)->json('data');
        $this->assertSame('cancelled', $event->fresh()->status);
        $this->assertSame(1, WorkdayAbsence::count());
        $this->assertSame(1, CalendarEvent::where('source', ProjectWorkdayAbsence::SOURCE)->count());
        $this->assertSame(1, CalendarEventLink::whereNotNull('workday_absence_id')->count());
        $this->getJson('/api/v1/workday-absences/'.$a['id'].'/history?per_page=2')->assertOk()
            ->assertJsonPath('total', 3)->assertJsonCount(2, 'data');
        $this->getJson('/api/v1/workday-absences?status=cancelled')->assertOk()->assertJsonPath('total', 1);
        $this->update($a, $this->partial())->assertConflict();
        $this->assertSame($first, $this->create(null, $key)->assertOk()->json());
        $this->create($this->partial(), $key)->assertConflict();
    }

    public function test_partial_absence_preserves_remaining_intervals_and_ordinary_meetings_do_not_reduce_plan(): void
    {
        $rule = $this->rule();
        CalendarEvent::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $rule->calendar_id,
            'title' => 'Existing meeting', 'starts_at' => '2026-10-01 06:00', 'ends_at' => '2026-10-01 14:00',
            'timezone' => 'Europe/Oslo', 'source' => 'local', 'transparency' => 'busy']);
        $this->create($this->partial())->assertOk()->assertJsonPath('data.plan_impact.planned_minutes', 480)
            ->assertJsonPath('data.plan_impact.affected_minutes', 120)
            ->assertJsonPath('data.plan_impact.remaining_intervals', [
                ['start' => '2026-10-01T06:00:00Z', 'end' => '2026-10-01T08:00:00Z'],
                ['start' => '2026-10-01T10:00:00Z', 'end' => '2026-10-01T14:00:00Z'],
            ]);
    }

    public function test_missing_plan_does_not_seed_defaults_and_disabled_day_is_known_zero(): void
    {
        $a = $this->create()->assertOk()->assertJsonPath('data.plan_impact.planned_minutes', 0)
            ->assertJsonPath('data.plan_impact.unknown_dates', ['2026-10-01'])->json('data');
        $this->assertSame(0, CalendarAvailabilityRule::count());
        $this->rule(extra: ['metadata' => ['source' => UserWorkPlan::SOURCE, 'enabled' => false]]);
        $this->getJson('/api/v1/workday-absences/'.$a['id'])->assertOk()
            ->assertJsonPath('data.plan_impact.planned_minutes', 0)->assertJsonPath('data.plan_impact.unknown_dates', []);
    }

    public function test_dated_custom_plan_and_unavailability_override_are_respected(): void
    {
        $rule = $this->rule();
        $this->rule(start: '10:00', end: '14:00', extra: ['effective_from' => '2026-10-01', 'effective_until' => '2026-10-01', 'metadata' => null]);
        CalendarAvailabilityOverride::create(['calendar_id' => $rule->calendar_id, 'date' => '2026-10-01',
            'starts_at_local' => '11:00', 'ends_at_local' => '12:00', 'availability_type' => 'unavailable']);
        $this->create()->assertOk()->assertJsonPath('data.plan_impact.planned_minutes', 180)
            ->assertJsonPath('data.plan_impact.affected_minutes', 180);
        $this->create($this->payload('2026-10-08'))->assertOk()->assertJsonPath('data.plan_impact.planned_minutes', 480);
    }

    public function test_overnight_and_dst_count_elapsed_time_and_ambiguous_input_needs_offset(): void
    {
        $this->rule(7, '01:00', '04:00');
        $this->create($this->payload('2026-10-25'))->assertOk()->assertJsonPath('data.plan_impact.affected_minutes', 240);
        $this->create($this->partial('2026-03-29T02:30', '2026-03-29T04:00'))->assertUnprocessable();
        $this->create($this->partial('2027-10-31T02:30', '2027-10-31T04:00'))->assertUnprocessable();
        $this->create($this->partial('2027-10-31T02:30+02:00', '2027-10-31T04:00+01:00'))->assertOk()
            ->assertJsonPath('data.plan_impact.affected_minutes', 150);
        $this->rule(3, '22:00', '06:00');
        $this->create($this->partial('2026-10-01T02:00', '2026-10-01T04:00'))->assertOk()
            ->assertJsonPath('data.plan_impact.affected_minutes', 120);
    }

    public function test_conflicts_and_confirmation_require_explicit_acknowledgement_without_changing_actual_work(): void
    {
        $day = $this->workday();
        $a = $this->create()->assertOk()->assertJsonPath('data.has_work_conflicts', true)
            ->assertJsonPath('data.work_conflicts.0.overlap_minutes', 450)->json('data');
        $preview = $this->operation($day, 'preview')->assertOk()
            ->assertJsonPath('data.absence_warnings.0.overlap_minutes', 450)->json('preview');
        $body = ['preview_token' => $preview['token'], 'confirmed' => true];
        $this->operation($day, 'confirm', $body)->assertUnprocessable()->assertJsonValidationErrors('accept_absence_conflicts');
        $this->operation($day, 'confirm', $body + ['accept_absence_conflicts' => true])->assertOk()
            ->assertJsonPath('data.confirmed.snapshot.actual_minutes', 450)
            ->assertJsonPath('data.confirmed.snapshot.absence_overlap_acknowledged', true);
        $this->cancel($a)->assertOk();
        $this->assertSame(450, WorkdayRevision::where('state', 'confirmed')->firstOrFail()->snapshot['actual_minutes']);
    }

    public function test_absence_changes_invalidate_existing_confirmation_preview(): void
    {
        $day = $this->workday();
        $preview = $this->operation($day, 'preview')->assertOk()->json('preview');
        $a = $this->create()->assertOk()->json('data');
        $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true, 'accept_absence_conflicts' => true])->assertConflict();
        $preview = $this->operation($day, 'preview')->assertOk()->json('preview');
        $this->cancel($a)->assertOk();
        $this->operation($day, 'confirm', ['preview_token' => $preview['token'], 'confirmed' => true])->assertConflict();
        $this->assertSame(1, WorkdayRevision::count());
    }

    public function test_excluded_break_only_overlap_does_not_warn(): void
    {
        $day = $this->workday();
        $this->create($this->partial('2026-10-01T12:00', '2026-10-01T12:30'))->assertOk()
            ->assertJsonPath('data.has_work_conflicts', false);
        $this->operation($day, 'preview')->assertOk()->assertJsonPath('data.absence_warnings', []);
    }

    public function test_scopes_permissions_and_ownership_are_required_even_for_superuser(): void
    {
        $a = $this->create()->assertOk()->json('data');
        Sanctum::actingAs($this->worker, ['workday-absences.read']);
        $this->cancel($a)->assertForbidden();
        $this->getJson('/api/v1/workday-absences/'.$a['id'])->assertOk();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::findOrCreate('Superuser', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->get());
        $other->assignRole($role);
        Sanctum::actingAs($other, ['*']);
        $this->getJson('/api/v1/workday-absences')->assertOk()->assertJsonPath('total', 0);
        $this->getJson('/api/v1/workday-absences/'.$a['id'])->assertNotFound();
        $this->getJson('/api/v1/workday-absences/'.$a['id'].'/history')->assertNotFound();
        $this->update($a, $this->payload())->assertNotFound();
        $this->cancel($a)->assertNotFound();
        Sanctum::actingAs($this->worker, ['*']);
        Role::findByName('Tech', 'web')->revokePermissionTo('workday.absence_manage_own');
        $this->worker->unsetRelation('roles')->unsetRelation('permissions');
        $this->cancel($a)->assertForbidden();
    }

    public function test_system_inactive_and_unprivileged_portal_users_cannot_register_absence(): void
    {
        foreach (['system', 'inactive', 'portal'] as $kind) {
            $user = User::factory()->create(['status' => $kind === 'inactive' ? User::STATUS_DISABLED : User::STATUS_ACTIVE,
                'is_system_actor' => $kind === 'system']);
            if ($kind !== 'portal') {
                $user->assignRole('Tech');
            }
            Sanctum::actingAs($user, ['*']);
            $this->create()->assertForbidden();
        }
        $this->assertSame(0, WorkdayAbsence::count());
    }

    public function test_stale_edits_overlap_identity_and_medical_fields_do_not_write(): void
    {
        $a = $this->create()->assertOk()->json('data');
        $this->create()->assertUnprocessable();
        $this->update(array_replace($a, ['version' => 0]), $this->payload())->assertConflict();
        foreach (['user_id' => 1, 'notes' => 'No medical notes', 'diagnosis' => 'Forbidden', 'attachments' => [], 'calendar_event_id' => 1] as $field => $value) {
            $this->create($this->payload('2026-10-02') + [$field => $value])->assertUnprocessable();
        }
        $this->assertSame(1, WorkdayAbsenceRevision::count());
        $this->assertSame(1, CalendarEventLink::whereNotNull('workday_absence_id')->count());
    }

    public function test_calendar_and_source_roll_back_together_on_projection_failure(): void
    {
        $this->mock(ProjectWorkdayAbsence::class)->shouldReceive('handle')->once()->andReturnUsing(function ($absence) {
            (new ProjectWorkdayAbsence)->handle($absence, $this->worker);
            throw new \RuntimeException('Synthetic projection failure');
        });
        $this->create()->assertStatus(500);
        $this->assertSame(0, WorkdayAbsence::count());
        $this->assertSame(0, WorkdayAbsenceRevision::count());
        $this->assertSame(0, DB::table('workday_absence_receipts')->count());
        $this->assertSame(0, CalendarEvent::where('source', ProjectWorkdayAbsence::SOURCE)->count());
    }

    public function test_calendar_admin_sees_neutral_block_and_generic_edits_and_deletes_are_denied(): void
    {
        $a = $this->create()->assertOk()->json('data');
        $event = CalendarEvent::with('links.linkable')->findOrFail($a['calendar_event_id']);
        $admin = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $role = Role::findOrCreate('Admin', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'calendar.%')->get());
        $admin->assignRole($role);
        $masked = app(CalendarVisibility::class)->maskEvent($event, $admin);
        $this->assertSame('Unavailable', $masked['title']);
        $this->assertCount(0, $masked['links']);
        $this->assertNull($masked['source_edit_url']);
        $this->assertStringNotContainsString('sickness', json_encode($event));
        Sanctum::actingAs($admin, ['*']);
        $this->getJson(route('api.v1.calendar.events.show', $event))->assertOk()->assertJsonPath('data.title', 'Unavailable')->assertDontSee('sickness');
        foreach (['this', 'following', 'all'] as $scope) {
            $this->patchJson(route('api.v1.calendar.events.update', $event), ['title' => 'Overwrite', 'scope' => $scope])->assertConflict();
            $this->deleteJson(route('api.v1.calendar.events.destroy', $event), ['scope' => $scope])->assertConflict();
        }
        $this->actingAs($admin, 'web')->patch(route('tech.calendar.events.update', $event), ['title' => 'Overwrite'])->assertConflict();
        $this->delete(route('tech.calendar.events.destroy', $event))->assertConflict();
        $this->assertSame('Unavailable', $event->fresh()->title);
        $this->assertNull($event->fresh()->deleted_at);
    }

    public function test_direct_model_edits_and_external_export_are_denied(): void
    {
        $a = $this->create()->assertOk()->json('data');
        $event = CalendarEvent::findOrFail($a['calendar_event_id']);
        Http::fake();
        foreach (['update', 'delete', 'export'] as $op) {
            try {
                match ($op) {
                    'update' => $event->update(['title' => 'Overwrite']),
                    'delete' => $event->delete(),
                    'export' => app(NextcloudReadClient::class)->putCalendarEvent(new NextcloudConnection, '/calendar/', $event),
                };
                $this->fail('Owned absence operation should be rejected.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                $this->assertSame(409, $e->getStatusCode());
            }
            $event->refresh();
        }
        Http::assertNothingSent();
    }

    public function test_browser_can_register_correct_cancel_and_render_calendar_source_link(): void
    {
        $this->actingAs($this->worker, 'web');
        $this->get('/tech/workday-absences/create')->assertOk()->assertSee('Register absence')->assertDontSee('name="notes"', false);
        $this->post('/tech/workday-absences', $this->payload() + ['request_key' => (string) Str::uuid()])->assertRedirect();
        $a = WorkdayAbsence::firstOrFail();
        $this->get('/tech/workday-absences/'.$a->uuid)->assertOk()->assertSee('Registered period')->assertSee('Revision history');
        $this->patch('/tech/workday-absences/'.$a->uuid, array_replace($this->partial(), ['version' => 1, 'request_key' => (string) Str::uuid()]))->assertRedirect();
        $this->patch('/tech/workday-absences/'.$a->uuid, array_replace($this->payload(), ['version' => 1, 'request_key' => (string) Str::uuid()]))->assertConflict()->assertSee('Reload absences');
        $event = CalendarEvent::findOrFail($a->calendar_event_id);
        $masked = app(CalendarVisibility::class)->maskEvent($event, $this->worker);
        $this->assertTrue($masked['source_owned']);
        $this->assertSame(route('tech.absences.index'), $masked['source_edit_url']);
        $this->get('/tech/calendar?date=2026-10-01&view=day')->assertOk()->assertSee('data-source-owned="1"', false);
        $this->post('/tech/workday-absences/'.$a->uuid.'/cancel', ['version' => 2, 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->get('/tech/workday-absences/'.$a->uuid)->assertOk()->assertSee('This absence is cancelled');
    }

    public function test_original_retention_boundary_is_not_extended_by_correction_and_feature_switches_stay_enforced(): void
    {
        $this->create($this->payload('2023-10-01'))->assertUnprocessable();
        $a = $this->create()->assertOk()->json('data');
        $changed = $this->update($a, $this->payload('2026-12-01'))->assertOk()->json('data');
        $this->assertSame($a['expires_at'], $changed['expires_at']);
        $this->assertSame('2029-10-01T22:00:00+00:00', $a['expires_at']);
        config(['workday.enabled' => false]);
        $this->getJson('/api/v1/workday-absences')->assertNotFound();
        $this->cancel($changed)->assertNotFound();
        config(['workday.enabled' => true]);
        Carbon::setTestNow('2029-10-02 00:01:00');
        $this->getJson('/api/v1/workday-absences/'.$a['id'])->assertNotFound();
        $this->getJson('/api/v1/workday-absences')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_calendar_archive_cannot_orphan_a_retained_absence(): void
    {
        $a = $this->create()->assertOk()->json('data');
        $calendar = CalendarEvent::findOrFail($a['calendar_event_id'])->calendar;
        try {
            $calendar->delete();
            $this->fail('Archiving a source calendar must be rejected.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertNull($calendar->fresh()->deleted_at);
        $this->cancel($a)->assertOk();
    }

    public function test_database_uniqueness_prevents_duplicate_provenance_links(): void
    {
        $this->create()->assertOk();
        $link = CalendarEventLink::whereNotNull('workday_absence_id')->firstOrFail()->getAttributes();
        unset($link['id']);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('calendar_event_links')->insert($link);
    }

    public function test_separate_time_permission_controls_absence_work_conflict_details(): void
    {
        $this->workday();
        Role::findByName('Tech', 'web')->revokePermissionTo('workday.view_own');
        $this->worker->unsetRelation('roles')->unsetRelation('permissions');
        $this->create()->assertOk()->assertJsonPath('data.has_work_conflicts', true)->assertJsonPath('data.work_conflicts', []);
    }

    public function test_recurring_education_is_planned_work_and_absence_does_not_change_its_phone_setting(): void
    {
        $response = $this->postJson('/api/v1/calendar/work-plan/blocks', [
            'request_id' => (string) Str::uuid(), 'title' => 'Education', 'activity' => 'education', 'timezone' => 'Europe/Oslo',
            'starts_at' => '2026-10-05T08:00', 'ends_at' => '2026-10-05T16:00',
            'phone_duty_available' => false, 'blocks_booking' => true, 'recurrence_frequency' => 'weekly', 'recurrence_ends_at' => '2026-12-21',
        ])->assertSuccessful();
        $block = CalendarEvent::where('source', 'work_plan')->firstOrFail();
        $before = $block->getAttributes();
        $this->create($this->payload('2026-10-12'))->assertOk()->assertJsonPath('data.plan_impact.affected_minutes', 480);
        $this->assertSame($before, $block->fresh()->getAttributes());
    }

    public function test_personal_bearer_authentication_and_absence_permission_deployment_preserve_revocation(): void
    {
        $token = $this->worker->createToken('Synthetic absence employee', ['workday-absences.read', 'workday-absences.write']);
        auth()->forgetGuards();
        $this->withToken($token->plainTextToken)->create()->assertOk();
        $this->withToken($token->plainTextToken)->getJson('/api/v1/workday-absences?from=2026-10-01&per_page=1')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/workday-absences?per_page=101')->assertUnprocessable();
        $migration = require database_path('migrations/2026_10_02_140100_deploy_workday_absence_permissions.php');
        $migration->up();
        $role = Role::findByName('Tech', 'web');
        $role->revokePermissionTo('workday.absence_manage_own');
        $this->seed(\Database\Seeders\RoleSeeder::class);
        $this->assertFalse($role->fresh()->hasPermissionTo('workday.absence_manage_own'));
    }

    public function test_confirm_browser_requires_overlap_acknowledgement_and_shows_neutral_warning(): void
    {
        $day = $this->workday();
        $this->create()->assertOk();
        $this->actingAs($this->worker, 'web')->post('/tech/workdays/'.$day['id'].'/preview',
            ['version' => 1, 'request_key' => (string) Str::uuid()])->assertOk()
            ->assertSee('accept_absence_conflicts')->assertDontSee('sickness');
    }
}
