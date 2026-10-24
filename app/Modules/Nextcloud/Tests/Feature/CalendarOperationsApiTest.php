<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Actions\StoreCalendarEvent;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Nextcloud\Actions\ReconcileNextcloudCalendarConflict;
use App\Modules\Nextcloud\Jobs\ReconcileRequestedCalendarConflict;
use App\Modules\Nextcloud\Jobs\RunRequestedCalendarSync;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CalendarOperationsApiTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private NextcloudConnection $connection;

    private $calendar;

    private $mapping;

    private string $base;

    private string $collection = '/remote.php/dav/calendars/svc/work/';

    private array $resources = [];

    private array $etags = [];

    private bool $failPut = false;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['nextcloud.sync', 'nextcloud.connection_manage', 'calendar.view', 'calendar.update'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->actor = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->actor->assignRole(Role::findOrCreate('Admin', 'web'));
        $this->actor->givePermissionTo(['nextcloud.sync', 'nextcloud.connection_manage', 'calendar.view', 'calendar.update']);
        $this->calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->actor);
        $this->connection = NextcloudConnection::create([
            'name' => 'Calendar cloud', 'scope' => 'global', 'mode' => 'sync', 'is_active' => true,
            'base_url' => 'https://calendar.example.test', 'service_username' => 'svc',
            'service_password' => 'test-secret-never-return', 'settings' => ['calendar_sync_enabled' => true],
        ]);
        $this->mapping = $this->connection->calendarMappings()->create(['calendar_id' => $this->calendar->id,
            'user_id' => $this->actor->id, 'remote_calendar_id' => $this->collection, 'sync_direction' => 'two_way', 'is_active' => true]);
        $this->base = '/api/v1/nextcloud/calendar/connections/'.$this->connection->id;
        Sanctum::actingAs($this->actor, ['nextcloud.calendar.read', 'nextcloud.calendar.preview', 'nextcloud.calendar.mapping',
            'nextcloud.calendar.activate', 'nextcloud.calendar.sync', 'nextcloud.calendar.health', 'nextcloud.calendar.reconcile']);
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'REPORT') {
                $xml = '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
                foreach ($this->resources as $href => $ics) {
                    if (! str_starts_with($href, $path)) {
                        continue;
                    }
                    $xml .= '<d:response><d:href>'.htmlspecialchars($href).'</d:href><d:propstat><d:prop><d:getetag>&quot;'.($this->etags[$href] ?? 'v1').'&quot;</d:getetag><c:calendar-data>'.htmlspecialchars($ics).'</c:calendar-data></d:prop></d:propstat></d:response>';
                }

                return Http::response($xml.'</d:multistatus>', 207);
            }
            if ($request->method() === 'GET' && isset($this->resources[$path])) {
                return Http::response($this->resources[$path], 200, ['ETag' => '"'.($this->etags[$path] ?? 'v1').'"']);
            }
            if ($request->method() === 'PUT') {
                if ($request->header('If-Match') !== ['"'.($this->etags[$path] ?? 'v1').'"']) {
                    return Http::response('', 412);
                }
                $this->resources[$path] = $request->body();
                $this->etags[$path] = 'v2';

                return Http::response('', $this->failPut ? 500 : 204, ['ETag' => '"v2"']);
            }

            return Http::response('', 404);
        });
    }

    private function remote(string $title = 'Remote event', string $extra = ''): string
    {
        $href = $this->collection.'original.ics';
        $this->resources[$href] = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:remote-1\r\nSUMMARY:{$title}\r\nDTSTART:20261026T080000Z\r\nDTEND:20261026T090000Z\r\n{$extra}END:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->etags[$href] = 'v1';

        return $href;
    }

    private function local(string $title = 'Local event'): CalendarEvent
    {
        return app(StoreCalendarEvent::class)->handle(['calendar_id' => $this->calendar->id, 'title' => $title,
            'starts_at' => '2026-10-26 09:00', 'ends_at' => '2026-10-26 10:00', 'timezone' => 'Europe/Oslo'], $this->actor)->fresh();
    }

    private function proof(array $preview): array
    {
        return array_intersect_key($preview, array_flip(['fingerprint', 'expires_at', 'preview']));
    }

    private function conflict(CalendarEvent $event): NextcloudSyncConflict
    {
        return NextcloudSyncConflict::create(['connection_id' => $this->connection->id, 'conflictable_type' => CalendarEvent::class,
            'conflictable_id' => $event->id, 'remote_object_type' => 'calendar_event', 'remote_object_id' => $this->collection.'original.ics',
            'status' => 'open', 'local_snapshot' => ['event_id' => $event->id, 'mapping_id' => $this->mapping->id],
            'remote_snapshot' => ['uid' => 'remote-1'], 'message' => 'A historical private body must never escape the status API.']);
    }

    private function runResolution(NextcloudSyncConflict $conflict, array $input, int $logId): void
    {
        $token = DB::table('nextcloud_calendar_sync_states')->where('connection_id', $this->connection->id)->value('token');
        (new ReconcileRequestedCalendarConflict($this->connection->id, $conflict->id, $this->actor->id, $token, $logId, $input))
            ->handle(app(ReconcileNextcloudCalendarConflict::class));
    }

    public function test_reads_are_sanitized_paginated_and_never_activate_or_contact_provider(): void
    {
        $this->getJson('/api/v1/nextcloud/calendar/connections')->assertOk()->assertJsonPath('total', 1);
        $response = $this->getJson($this->base)->assertOk()->assertJsonPath('data.automatic_enabled', false);
        $this->assertStringNotContainsString('test-secret-never-return', $response->getContent());
        $this->assertStringNotContainsString('service_username', $response->getContent());
        $this->getJson($this->base.'/mappings?per_page=1')->assertOk()->assertJsonPath('total', 1);
        Http::assertNothingSent();
        $this->actor->revokePermissionTo('nextcloud.sync');
        $this->getJson($this->base)->assertForbidden();
    }

    public function test_activation_requires_exact_reviewed_preview_and_does_not_start_a_sync(): void
    {
        $choices = ['enabled' => true, 'mapping_ids' => [$this->mapping->id]];
        $preview = $this->postJson($this->base.'/preview', ['operation' => 'activation'] + $choices)->assertOk()->json('data');
        $this->postJson($this->base.'/activation', $choices + $this->proof($preview))->assertUnprocessable();
        $this->withHeader('Idempotency-Key', 'activate-calendar-001')->postJson($this->base.'/activation', $choices + $this->proof($preview))->assertOk()->assertJsonPath('data.automatic_enabled', true);
        $this->postJson($this->base.'/activation', $choices + $this->proof($preview))->assertOk();
        $this->assertDatabaseCount('nextcloud_sync_logs', 0);
        Queue::assertNothingPushed();
        $this->mapping->update(['sync_direction' => 'pull_only']);
        $this->withHeader('Idempotency-Key', 'activate-calendar-002')->postJson($this->base.'/activation', $choices + $this->proof($preview))->assertConflict();
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_mapping_api_shares_owner_validation_and_invalidates_automatic_activation(): void
    {
        $this->connection->update(['settings' => ['calendar_sync_enabled' => true, 'automatic_calendar_sync_enabled' => true]]);
        $mapping = ['remote_calendar_id' => $this->collection, 'remote_display_name' => 'Reviewed work',
            'calendar_id' => $this->calendar->id, 'user_id' => $this->actor->id, 'sync_direction' => 'pull_only'];
        $preview = $this->postJson($this->base.'/preview', ['operation' => 'mapping', 'mapping' => $mapping])->assertOk()->json('data');
        $this->withHeader('Idempotency-Key', 'mapping-calendar-001')->postJson($this->base.'/mappings', ['mapping' => $mapping] + $this->proof($preview))->assertOk()->assertJsonPath('data.sync_direction', 'pull_only');
        $this->assertFalse($this->connection->fresh()->settings['automatic_calendar_sync_enabled']);
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $mapping['user_id'] = $other->id;
        $this->postJson($this->base.'/preview', ['operation' => 'mapping', 'mapping' => $mapping])->assertUnprocessable();
        $this->getJson($this->base.'/attempts')->assertOk()->assertJsonPath('total', 0);
    }

    public function test_manual_sync_returns_one_attempt_and_runs_only_selected_calendar_phase(): void
    {
        $this->remote();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $otherCalendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($other);
        $this->connection->calendarMappings()->create(['calendar_id' => $otherCalendar->id, 'user_id' => $other->id,
            'remote_calendar_id' => '/remote.php/dav/calendars/svc/excluded/', 'sync_direction' => 'two_way', 'is_active' => true]);
        $choices = ['mapping_ids' => [$this->mapping->id]];
        $preview = $this->postJson($this->base.'/preview', ['operation' => 'sync'] + $choices)->assertOk()->json('data');
        $input = $choices + $this->proof($preview);
        $log = $this->withHeader('Idempotency-Key', 'manual-calendar-001')->postJson($this->base.'/sync', $input)->assertAccepted()->json('data');
        $this->postJson($this->base.'/sync', $input)->assertAccepted()->assertJsonPath('data.id', $log['id']);
        Queue::assertPushed(RunRequestedCalendarSync::class, 1);
        $this->assertDatabaseMissing('nextcloud_calendar_sync_locks', ['calendar_id' => $otherCalendar->id]);
        $this->assertDatabaseCount('nextcloud_sync_logs', 1);
        $token = DB::table('nextcloud_calendar_sync_states')->where('connection_id', $this->connection->id)->value('token');
        (new RunRequestedCalendarSync($this->connection->id, $token, $log['id'], $this->actor->id, [$this->mapping->id],
            \App\Modules\Nextcloud\Actions\CalendarApiAccess::revision($this->connection)))->handle(app(\App\Modules\Nextcloud\Actions\RunNextcloudCalendarSync::class));
        $this->getJson($this->base.'/attempts/'.$log['id'])->assertOk()->assertJsonPath('data.status', 'success');
        $this->assertDatabaseHas('calendar_events', ['external_uid' => 'remote-1']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ocs/') || str_contains($request->url(), '/files/'));
    }

    public function test_keep_remote_reconciles_exact_event_and_preserves_audit_without_provider_write(): void
    {
        $this->remote();
        $event = $this->local();
        $conflict = $this->conflict($event);
        $path = $this->base.'/conflicts/'.$conflict->id;
        $preview = $this->postJson($path.'/preview', ['resolution' => 'keep_remote'])->assertOk()->json('data');
        $input = ['resolution' => 'keep_remote', 'event_id' => $event->id] + $this->proof($preview);
        $log = $this->withHeader('Idempotency-Key', 'resolve-remote-001')->postJson($path.'/apply', $input)->assertAccepted()->json('data');
        $this->runResolution($conflict, $input, $log['id']);
        $this->assertSame('Remote event', $event->fresh()->title);
        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->getJson($this->base.'/attempts/'.$log['id'])->assertOk()->assertJsonPath('data.status', 'success');
        $this->postJson($path.'/apply', $input)->assertAccepted()->assertJsonPath('data.id', $log['id']);
        $status = $this->getJson($this->base.'/conflicts')->assertOk();
        $this->assertStringNotContainsString('historical private', $status->getContent());
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_keep_local_is_conditional_and_requires_exact_provider_readback(): void
    {
        $href = $this->remote();
        $event = $this->local();
        $conflict = $this->conflict($event);
        $path = $this->base.'/conflicts/'.$conflict->id;
        $preview = $this->postJson($path.'/preview', ['resolution' => 'keep_local'])->assertOk()->json('data');
        $input = ['resolution' => 'keep_local', 'event_id' => $event->id] + $this->proof($preview);
        $log = $this->withHeader('Idempotency-Key', 'resolve-local-001')->postJson($path.'/apply', $input)->assertAccepted()->json('data');
        $this->runResolution($conflict, $input, $log['id']);
        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertStringContainsString('SUMMARY:Local event', $this->resources[$href]);
        $this->assertSame('remote-1', $event->fresh()->external_uid);
        $this->assertFalse($event->fresh()->metadata['nextcloud_pending_write']);
        Http::assertSent(fn ($request) => $request->method() === 'PUT' && $request->header('If-Match') === ['"v1"']);
    }

    public function test_unknown_write_outcome_is_not_repeated_and_can_be_observed_then_bound(): void
    {
        $this->remote();
        $event = $this->local();
        $conflict = $this->conflict($event);
        $path = $this->base.'/conflicts/'.$conflict->id;
        $preview = $this->postJson($path.'/preview', ['resolution' => 'keep_local'])->assertOk()->json('data');
        $input = ['resolution' => 'keep_local', 'event_id' => $event->id] + $this->proof($preview);
        $log = $this->withHeader('Idempotency-Key', 'resolve-unknown-001')->postJson($path.'/apply', $input)->assertAccepted()->json('data');
        $this->failPut = true;
        $this->runResolution($conflict, $input, $log['id']);
        $this->assertSame('open', $conflict->fresh()->status);
        $this->assertTrue($event->fresh()->metadata['nextcloud_pending_write']);
        $this->postJson($path.'/apply', $input)->assertAccepted()->assertJsonPath('data.status', 'failed');
        $preview = $this->postJson($path.'/preview', ['resolution' => 'bind_existing'])->assertOk()->json('data');
        $input = ['resolution' => 'bind_existing', 'event_id' => $event->id] + $this->proof($preview);
        $log = $this->withHeader('Idempotency-Key', 'resolve-bind-001')->postJson($path.'/apply', $input)->assertAccepted()->json('data');
        $this->runResolution($conflict, $input, $log['id']);
        $this->assertSame('resolved', $conflict->fresh()->status);
        $this->assertFalse($event->fresh()->metadata['nextcloud_pending_write']);
        $this->assertCount(1, Http::recorded(fn ($request) => $request->method() === 'PUT'));
    }

    public function test_stale_provider_preview_unsupported_series_and_runtime_off_never_write(): void
    {
        $href = $this->remote();
        $event = $this->local();
        $conflict = $this->conflict($event);
        $path = $this->base.'/conflicts/'.$conflict->id;
        $preview = $this->postJson($path.'/preview', ['resolution' => 'keep_remote'])->assertOk()->json('data');
        $input = ['resolution' => 'keep_remote', 'event_id' => $event->id] + $this->proof($preview);
        $this->etags[$href] = 'changed';
        $this->withHeader('Idempotency-Key', 'resolve-stale-001')->postJson($path.'/apply', $input)->assertConflict();
        $this->remote('Remote series', "RRULE:FREQ=DAILY\r\n");
        $this->postJson($path.'/preview', ['resolution' => 'keep_remote'])->assertUnprocessable();
        $this->connection->update(['settings' => ['calendar_sync_enabled' => false]]);
        $this->postJson($this->base.'/preview', ['operation' => 'sync', 'mapping_ids' => [$this->mapping->id]])->assertConflict();
        $this->connection->update(['settings' => ['calendar_sync_enabled' => true]]);
        unset($this->resources[$href]);
        $this->postJson($path.'/preview', ['resolution' => 'keep_remote'])->assertStatus(503)->assertJsonPath('message', 'Calendar provider resource cannot be verified; no change performed.');
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_missing_scopes_inactive_actor_and_unknown_fields_are_denied(): void
    {
        Sanctum::actingAs($this->actor, ['nextcloud.calendar.read']);
        $this->postJson($this->base.'/preview', ['operation' => 'activation', 'enabled' => false])->assertForbidden();
        $this->getJson($this->base.'?service_password=ignored')->assertUnprocessable();
        $this->actor->update(['status' => User::STATUS_DISABLED]);
        $this->getJson($this->base)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_mapping_removal_replays_without_deleting_events_and_health_is_sanitized(): void
    {
        $event = $this->local();
        $preview = $this->postJson($this->base.'/preview', ['operation' => 'remove_mapping', 'mapping_id' => $this->mapping->id])->assertOk()->json('data');
        $input = $this->proof($preview);
        $this->withHeader('Idempotency-Key', 'remove-mapping-001')->deleteJson($this->base.'/mappings/'.$this->mapping->id, $input)->assertOk()->assertJsonPath('data.events_retained', true);
        $this->deleteJson($this->base.'/mappings/'.$this->mapping->id, $input)->assertOk();
        $this->assertNotNull($event->fresh());
        $this->withHeader('Idempotency-Key', 'health-calendar-001')->postJson($this->base.'/health')->assertOk()->assertJsonPath('data.health_status', 'error')->assertDontSee('test-secret-never-return');
    }

    public function test_queued_sync_rechecks_revoked_actor_before_any_provider_access(): void
    {
        $choices = ['mapping_ids' => [$this->mapping->id]];
        $preview = $this->postJson($this->base.'/preview', ['operation' => 'sync'] + $choices)->assertOk()->json('data');
        $log = $this->withHeader('Idempotency-Key', 'revoked-sync-001')->postJson($this->base.'/sync', $choices + $this->proof($preview))->assertAccepted()->json('data');
        $before = count(Http::recorded());
        $this->actor->revokePermissionTo('nextcloud.sync');
        $token = DB::table('nextcloud_calendar_sync_states')->where('connection_id', $this->connection->id)->value('token');
        (new RunRequestedCalendarSync($this->connection->id, $token, $log['id'], $this->actor->id, [$this->mapping->id],
            \App\Modules\Nextcloud\Actions\CalendarApiAccess::revision($this->connection)))->handle(app(\App\Modules\Nextcloud\Actions\RunNextcloudCalendarSync::class));
        $this->assertSame($before, count(Http::recorded()));
        $this->assertSame('failed', NextcloudSyncLog::findOrFail($log['id'])->status);
    }

    public function test_duplicate_uid_and_local_edits_invalidate_reconciliation(): void
    {
        $href = $this->remote();
        $event = $this->local();
        $conflict = $this->conflict($event);
        $path = $this->base.'/conflicts/'.$conflict->id;
        $preview = $this->postJson($path.'/preview', ['resolution' => 'keep_remote'])->assertOk()->json('data');
        $input = ['resolution' => 'keep_remote', 'event_id' => $event->id] + $this->proof($preview);
        $event->update(['title' => 'Changed in the same second']);
        $this->withHeader('Idempotency-Key', 'local-stale-001')->postJson($path.'/apply', $input)->assertConflict();
        $this->resources[$this->collection.'duplicate.ics'] = $this->resources[$href];
        $this->postJson($path.'/preview', ['resolution' => 'keep_remote'])->assertConflict();
        Http::assertNotSent(fn ($request) => $request->method() === 'PUT');
    }
}
