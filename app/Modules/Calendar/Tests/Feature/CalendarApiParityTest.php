<?php

namespace App\Modules\Calendar\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\Calendar\Support\CalendarPermission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarApiParityTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (CalendarPermission::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->givePermissionTo(['calendar.view', 'calendar.create', 'calendar.update', 'calendar.delete', 'calendar.view_free_busy']);
        Sanctum::actingAs($this->actor, ['calendar.read', 'calendar.create', 'calendar.update', 'calendar.delete', 'calendar.free-busy.read']);
    }

    public function test_reads_are_pure_and_report_setup_required(): void
    {
        $before = [Calendar::count(), CalendarSetting::count(), Permission::count(), \App\Modules\UserManagement\Models\UserPreference::count()];
        $this->getJson('/api/v1/calendar/context')->assertOk()->assertJsonPath('data.setup_required', true);
        $this->getJson('/api/v1/calendars')->assertOk();
        $this->getJson('/api/v1/calendar/events')->assertOk();
        $this->assertSame($before, [Calendar::count(), CalendarSetting::count(), Permission::count(), \App\Modules\UserManagement\Models\UserPreference::count()]);
    }

    public function test_scope_does_not_replace_actor_permissions_or_active_state(): void
    {
        $this->actor->syncPermissions([]);
        $this->getJson('/api/v1/calendar/context')->assertForbidden();
        $this->actor->givePermissionTo('calendar.view');
        $this->actor->forceFill(['status' => 'inactive'])->save();
        $this->getJson('/api/v1/calendars')->assertForbidden();
    }

    public function test_private_search_and_counts_cannot_reveal_hidden_details(): void
    {
        $owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($owner);
        app(StoreCalendarEvent::class)->handle([
            'calendar_id' => $calendar->id, 'title' => 'Secret merger', 'description' => 'Confidential location',
            'starts_at' => '2026-10-26 09:00', 'ends_at' => '2026-10-26 10:00',
            'visibility' => 'private', 'participants' => [['email' => 'secret@example.test']],
        ], $owner);
        $this->getJson('/api/v1/calendar/events?from=2026-10-26&to=2026-10-27&event_search=Secret')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.count', 0);
        $response = $this->getJson('/api/v1/calendar/events?from=2026-10-26&to=2026-10-27');
        $response->assertOk()->assertJsonPath('data.0.title', 'Busy')->assertJsonPath('data.0.description', null)->assertJsonCount(0, 'data.0.participants');
        $this->assertStringNotContainsString('secret@example.test', $response->getContent());
    }

    public function test_filters_ranges_and_unexpected_fields_are_strict(): void
    {
        $this->getJson('/api/v1/calendar/events?timezone=Invalid')->assertUnprocessable();
        $this->getJson('/api/v1/calendar/events?from=2026-01-01&to=2027-01-01')->assertUnprocessable();
        $this->getJson('/api/v1/calendar/events?event_sort=metadata')->assertUnprocessable();
        $this->getJson('/api/v1/calendar/events?ignored_field=1')->assertUnprocessable();
        $this->getJson('/api/v1/calendar/events?view=month&date=2026-10-24&only_mine=1')
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.previous_date', '2026-09-24')->assertJsonPath('meta.next_date', '2026-11-24');
    }

    public function test_initialization_requires_explicit_admin_authority(): void
    {
        Sanctum::actingAs($this->actor, ['calendar.manage']);
        $this->postJson('/api/v1/calendar/initialize')->assertForbidden();
        $role = Role::findOrCreate('Admin', 'web');
        $this->actor->assignRole($role);
        $this->actor->givePermissionTo('calendar.manage_settings');
        $this->withHeader('Idempotency-Key', 'initialize-calendar-001')->postJson('/api/v1/calendar/initialize')->assertOk()->assertJsonPath('data.setup_required', false);
        $this->assertDatabaseHas('calendars', ['owner_id' => $this->actor->id, 'type' => 'personal']);
    }

    private function eventPayload(): array
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->actor);

        return ['calendar_id' => $calendar->id, 'title' => 'Planning', 'starts_at' => '2026-10-26 09:00', 'ends_at' => '2026-10-26 10:00', 'timezone' => 'Europe/Oslo'];
    }

    public function test_event_create_replay_is_durable_bound_to_input_and_reauthorized(): void
    {
        $payload = $this->eventPayload();
        $first = $this->withHeader('Idempotency-Key', 'create-planning-001')->postJson('/api/v1/calendar/events', $payload)->assertCreated();
        $id = $first->json('data.id');
        $this->postJson('/api/v1/calendar/events', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->assertDatabaseCount('calendar_events', 1);
        $this->postJson('/api/v1/calendar/events', array_merge($payload, ['title' => 'Different']))->assertConflict();
        $this->actor->revokePermissionTo('calendar.create');
        $this->postJson('/api/v1/calendar/events', $payload)->assertForbidden();
    }

    public function test_partial_update_preserves_omitted_fields_and_rejects_stale_revision(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', array_merge($this->eventPayload(), ['description' => 'Keep', 'participants' => [['email' => 'a@example.test']]]))->assertCreated()->json('data');
        $response = $this->withHeader('Idempotency-Key', 'edit-planning-0001')->patchJson('/api/v1/calendar/events/'.$event['id'], ['title' => 'Updated', 'revision' => $event['revision']])->assertOk();
        $response->assertJsonPath('data.description', 'Keep')->assertJsonCount(1, 'data.participants');
        $this->patchJson('/api/v1/calendar/events/'.$event['id'], ['title' => 'Updated', 'revision' => $event['revision']])->assertOk();
        $this->withHeader('Idempotency-Key', 'edit-planning-0002')->patchJson('/api/v1/calendar/events/'.$event['id'], ['title' => 'Stale', 'revision' => $event['revision']])->assertConflict();
        $this->withHeader('Idempotency-Key', 'edit-planning-0003')->patchJson('/api/v1/calendar/events/'.$event['id'], ['description' => null, 'participants' => null, 'ends_at' => '2026-10-26 10:30'])->assertOk()->assertJsonPath('data.description', null)->assertJsonCount(0, 'data.participants');
    }

    public function test_mutation_rejects_ignored_fields_participant_spoofing_and_unauthorized_moves(): void
    {
        $payload = $this->eventPayload();
        $this->postJson('/api/v1/calendar/events', array_merge($payload, ['source' => 'workday_absence']))->assertUnprocessable();
        $this->postJson('/api/v1/calendar/events', array_merge($payload, ['participants' => [['email' => 'a@example.test', 'response_status' => 'accepted']]]))->assertUnprocessable();
        $event = $this->postJson('/api/v1/calendar/events', $payload)->assertCreated()->json('data');
        $this->patchJson('/api/v1/calendar/events/'.$event['id'], ['recurrence_frequency' => 'daily'])->assertUnprocessable();
        $other = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar(User::factory()->create());
        $this->patchJson('/api/v1/calendar/events/'.$event['id'], ['calendar_id' => $other->id])->assertForbidden();
        $this->patchJson('/api/v1/calendar/events/'.$event['id'], ['ends_at' => '2026-10-26 08:00'])->assertUnprocessable();
    }

    public function test_occurrence_and_series_deletion_use_preview_and_replay_without_deleting_other_occurrences(): void
    {
        $payload = array_merge($this->eventPayload(), ['recurrence_frequency' => 'daily', 'recurrence_ends_at' => '2026-10-29']);
        $event = $this->postJson('/api/v1/calendar/events', $payload)->assertCreated()->json('data');
        $url = '/api/v1/calendar/events/'.$event['id'];
        $preview = $this->postJson($url.'/delete-preview', ['scope' => 'event', 'original_starts_at' => '2026-10-27 09:00'])->assertOk()->json('data');
        $command = array_intersect_key($preview, array_flip(['scope', 'original_starts_at', 'revision', 'preview']));
        $this->withHeader('Idempotency-Key', 'delete-occurrence-001')->deleteJson($url, $command)->assertNoContent();
        $this->deleteJson($url, $command)->assertNoContent();
        $this->assertDatabaseCount('calendar_event_exceptions', 1);
        $this->assertDatabaseHas('calendar_events', ['id' => $event['id'], 'deleted_at' => null]);
        $this->getJson('/api/v1/calendar/events?from=2026-10-27&to=2026-10-28')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/calendar/events?from=2026-10-28&to=2026-10-29')->assertOk()->assertJsonCount(1, 'data');
        $preview = $this->postJson($url.'/delete-preview', ['scope' => 'series'])->assertOk()->json('data');
        $command = array_intersect_key($preview, array_flip(['scope', 'revision', 'preview']));
        $this->withHeader('Idempotency-Key', 'delete-series-001')->deleteJson($url, $command)->assertNoContent();
        $this->deleteJson($url, $command)->assertNoContent();
        $this->assertSoftDeleted('calendar_events', ['id' => $event['id']]);
        $this->assertDatabaseCount('calendar_event_series', 0);
    }

    public function test_occurrence_delete_rejects_stale_preview_and_non_occurrence(): void
    {
        $event = $this->postJson('/api/v1/calendar/events', array_merge($this->eventPayload(), ['recurrence_frequency' => 'weekly']))->assertCreated()->json('data');
        $url = '/api/v1/calendar/events/'.$event['id'];
        $preview = $this->postJson($url.'/delete-preview', ['scope' => 'event', 'original_starts_at' => '2026-10-27 09:00'])->assertOk()->json('data');
        $command = array_intersect_key($preview, array_flip(['scope', 'original_starts_at', 'revision', 'preview']));
        $this->withHeader('Idempotency-Key', 'delete-invalid-001')->deleteJson($url, $command)->assertUnprocessable();
        $this->assertDatabaseCount('calendar_event_exceptions', 0);
    }

    private function adminActor(): void
    {
        $this->actor->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->actor->givePermissionTo('calendar.manage_settings');
        Sanctum::actingAs($this->actor, ['calendar.manage', 'calendar.read']);
        app(EnsureCalendarDefaults::class)->handle($this->actor);
    }

    public function test_admin_settings_require_actor_permission_and_preserve_omitted_settings(): void
    {
        Sanctum::actingAs($this->actor, ['calendar.manage']);
        $this->getJson('/api/v1/calendar/admin/settings')->assertForbidden();
        $this->adminActor();
        $context = $this->getJson('/api/v1/calendar/admin/settings')->assertOk()->json('data');
        $this->assertIsBool($context['settings']['allow_private_events']);
        $this->assertIsInt($context['settings']['default_event_duration_minutes']);
        $this->withHeader('Idempotency-Key', 'settings-update-001')->patchJson('/api/v1/calendar/admin/settings', ['revision' => $context['revision'], 'default_view' => 'month'])->assertOk()->assertJsonPath('data.settings.default_view', 'month')->assertJsonPath('data.settings.default_timezone', 'Europe/Oslo');
        $this->patchJson('/api/v1/calendar/admin/settings', ['revision' => $context['revision'], 'default_view' => 'month'])->assertOk();
        $this->withHeader('Idempotency-Key', 'settings-update-002')->patchJson('/api/v1/calendar/admin/settings', ['revision' => $context['revision'], 'default_view' => 'day'])->assertConflict();
        $this->patchJson('/api/v1/calendar/admin/settings', ['revision' => $context['revision'], 'arbitrary_secret' => 'no'])->assertUnprocessable();
    }

    public function test_sharing_and_archive_preview_bind_exact_target_and_changes(): void
    {
        $this->adminActor();
        $calendar = $this->withHeader('Idempotency-Key', 'admin-calendar-001')->postJson('/api/v1/calendar/admin/calendars', [
            'name' => 'Shared operations', 'type' => 'team', 'color' => '#123456', 'timezone' => 'Europe/Oslo',
            'visibility_default' => 'private', 'transparency_default' => 'busy',
        ])->assertCreated()->json('data');
        $base = '/api/v1/calendar/admin/calendars/'.$calendar['id'];
        $changes = ['subject_ref' => 'user:'.$this->actor->id, 'access_level' => 'viewer', 'can_view_private_details' => false];
        $preview = $this->postJson($base.'/preview', ['operation' => 'upsert_access', 'changes' => $changes])->assertOk()->json('data');
        $command = array_merge($changes, array_intersect_key($preview, array_flip(['revision', 'preview'])));
        $this->withHeader('Idempotency-Key', 'access-upsert-001')->postJson($base.'/access', array_merge($command, ['access_level' => 'owner']))->assertConflict();
        $entry = $this->postJson($base.'/access', $command)->assertOk()->json('data');
        $this->postJson($base.'/access', $command)->assertOk()->assertJsonPath('data.id', $entry['id']);
        $this->getJson($base.'/access')->assertOk()->assertJsonCount(1, 'data.entries');
        $preview = $this->postJson($base.'/preview', ['operation' => 'revoke_access', 'access_id' => $entry['id']])->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'access-revoke-001')->deleteJson($base.'/access/'.$entry['id'], array_intersect_key($preview, array_flip(['revision', 'preview'])))->assertOk()->assertJsonPath('data.revoked', true);
        $preview = $this->postJson($base.'/preview', ['operation' => 'archive'])->assertOk()->json('data');
        $command = array_intersect_key($preview, array_flip(['revision', 'preview']));
        $this->withHeader('Idempotency-Key', 'archive-calendar-001')->deleteJson($base, $command)->assertOk()->assertJsonPath('data.archived', true);
        $this->deleteJson($base, $command)->assertOk();
    }

    public function test_own_calendar_preferences_do_not_mutate_theme_or_availability(): void
    {
        \App\Modules\UserManagement\Models\UserPreference::create(['user_id' => $this->actor->id, 'timezone' => 'Europe/Oslo', 'default_calendar_view' => 'week', 'workday_start' => '08:00', 'workday_end' => '16:00', 'settings' => ['theme' => 'dark', 'other' => 'preserved']]);
        // Exercise the actual bearer boundary rather than a fabricated token identity.
        $token = $this->actor->createToken('calendar-preferences-regression', ['users.calendar-preferences.read', 'users.calendar-preferences.update'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token);
        $data = $this->getJson('/api/v1/users/me/calendar-preferences')->assertOk()->json('data');
        $this->patchJson('/api/v1/users/me/calendar-preferences', ['revision' => $data['revision'], 'timezone' => 'UTC'])->assertOk()->assertJsonPath('data.timezone', 'UTC');
        $this->patchJson('/api/v1/users/me/calendar-preferences', ['revision' => $data['revision'], 'default_calendar_view' => 'day'])->assertConflict();
        $this->patchJson('/api/v1/users/me/calendar-preferences', ['revision' => $data['revision'], 'theme' => 'light'])->assertUnprocessable();
        $this->assertSame(['theme' => 'dark', 'other' => 'preserved'], \App\Modules\UserManagement\Models\UserPreference::first()->settings);
        $this->assertDatabaseCount('calendar_availability_rules', 0);
    }

    public function test_availability_projects_only_slots_and_honors_private_busy_events(): void
    {
        $payload = $this->eventPayload();
        app(StoreCalendarEvent::class)->handle(array_merge($payload, ['visibility' => 'private', 'description' => 'Never expose busy details']), $this->actor);
        $response = $this->getJson('/api/v1/calendar/availability?from=2026-10-26T08:00:00&to=2026-10-26T12:00:00&timezone=Europe%2FOslo&duration=60');
        $response->assertOk()->assertJsonPath('meta.complete', false)->assertJsonPath('meta.result_kind', 'suggestions');
        foreach ($response->json('data') as $slot) {
            $this->assertSame($this->actor->id, $slot['user_id']);
            $this->assertFalse(\Illuminate\Support\Carbon::parse($slot['starts_at'])->lt(\Illuminate\Support\Carbon::parse('2026-10-26 10:00', 'Europe/Oslo'))
                && \Illuminate\Support\Carbon::parse($slot['ends_at'])->gt(\Illuminate\Support\Carbon::parse('2026-10-26 09:00', 'Europe/Oslo')));
        }
        $this->assertStringNotContainsString('Never expose', $response->getContent());
        $this->assertNotEmpty($response->json('data'));
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($other);
        $calendar->update(['is_visible_by_default' => false]);
        $this->getJson('/api/v1/calendar/availability?user_id='.$other->id.'&from=2026-10-26&to=2026-10-27')->assertForbidden();
        $this->getJson('/api/v1/calendar/availability?from=2026-10-26&to=2027-10-27')->assertUnprocessable();
        $this->getJson('/api/v1/calendar/availability?from=2026-10-26&to=2026-10-27&duration=0')->assertUnprocessable();
    }

    public function test_durable_replay_survives_activity_log_cleanup_without_private_snapshots(): void
    {
        $payload = array_merge($this->eventPayload(), ['description' => 'Private content must not enter receipt metadata']);
        $event = $this->withHeader('Idempotency-Key', 'durable-replay-001')->postJson('/api/v1/calendar/events', $payload)->assertCreated()->json('data');
        app(\App\Modules\Integration\Support\DomainApiAudit::class)->query('calendar')->delete();
        $this->postJson('/api/v1/calendar/events', $payload)->assertCreated()->assertJsonPath('data.id', $event['id']);
        $this->assertDatabaseCount('calendar_events', 1);
        $receipt = \Illuminate\Support\Facades\DB::table('calendar_api_command_receipts')->first();
        $this->assertStringNotContainsString('Private content', json_encode($receipt));
        $this->assertSame(hash('sha256', 'durable-replay-001'), $receipt->key_hash);
    }

    public function test_receipt_migration_is_additive_and_reversible_in_an_empty_test_database(): void
    {
        $migration = require database_path('migrations/2026_10_24_120000_create_calendar_api_command_receipts.php');
        $migration->down();
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('calendar_api_command_receipts'));
        $migration->up();
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('calendar_api_command_receipts'));
        $this->assertDatabaseCount('calendar_api_command_receipts', 0);
    }
}
