<?php

namespace App\Modules\Nextcloud\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Calendar\Models\Calendar;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Nextcloud\Actions\DispatchDueNextcloudCalendars;
use App\Modules\Nextcloud\Actions\NextcloudCalendarLease;
use App\Modules\Nextcloud\Actions\RunNextcloudCalendarSync;
use App\Modules\Nextcloud\Jobs\SyncNextcloudCalendar;
use App\Modules\Nextcloud\Models\NextcloudConnection;
use App\Modules\Nextcloud\Models\NextcloudSyncConflict;
use App\Modules\Nextcloud\Models\NextcloudSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AutomaticCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    private NextcloudConnection $connection;

    private Calendar $calendar;

    private User $owner;

    private int $mappingId;

    private string $collection = '/remote.php/dav/calendars/svc/work/';

    private array $resources = [];

    private array $etags = [];

    private string $writeMode = 'normal';

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->calendar = Calendar::create([
            'uuid' => (string) Str::uuid(), 'name' => 'Test work', 'slug' => 'test-work',
            'type' => 'personal', 'owner_type' => User::class, 'owner_id' => $this->owner->id,
            'timezone' => 'Europe/Oslo', 'is_active' => true,
        ]);
        $this->connection = NextcloudConnection::create([
            'name' => 'Test cloud', 'scope' => 'global', 'mode' => 'sync', 'is_active' => true,
            'base_url' => 'https://cloud.example.test', 'service_username' => 'svc',
            'service_password' => 'test-only-password', 'sync_interval_minutes' => 15,
            'settings' => ['calendar_sync_enabled' => true],
        ]);
        $mapping = $this->connection->calendarMappings()->create([
            'calendar_id' => $this->calendar->id, 'user_id' => $this->owner->id,
            'remote_calendar_id' => $this->collection, 'remote_display_name' => 'Work',
            'sync_direction' => 'two_way', 'is_active' => true,
        ]);
        $this->mappingId = $mapping->id;
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            $path = parse_url($request->url(), PHP_URL_PATH);
            if ($request->method() === 'REPORT') {
                if ($this->writeMode === 'invalid_inventory') {
                    return Http::response('invalid inventory', 207);
                }
                $xml = '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">';
                foreach ($this->resources as $href => $ics) {
                    if (! str_starts_with($href, $path)) {
                        continue;
                    }
                    $xml .= '<d:response><d:href>'.htmlspecialchars($href).'</d:href><d:propstat><d:prop><d:getetag>&quot;'
                        .($this->etags[$href] ?? 'v1').'&quot;</d:getetag><c:calendar-data>'
                        .htmlspecialchars($ics).'</c:calendar-data></d:prop></d:propstat></d:response>';
                }

                return Http::response($xml.'</d:multistatus>', 207);
            }
            if ($request->method() === 'PUT') {
                if ($this->writeMode === 'conflict') {
                    return Http::response('', 412);
                }
                if (isset($this->resources[$path])) {
                    if ($request->header('If-Match') !== ['"'.($this->etags[$path] ?? 'v1').'"']) {
                        return Http::response('', 412);
                    }
                } elseif ($request->header('If-None-Match') !== ['*']) {
                    return Http::response('', 412);
                }
                $this->resources[$path] = $request->body();
                $this->etags[$path] = 'written';

                return Http::response('', $this->writeMode === 'unknown' ? 500 : 201, ['ETag' => '"written"']);
            }
            if ($request->method() === 'GET' && isset($this->resources[$path])) {
                $body = $this->resources[$path];
                if ($this->writeMode === 'wrong_uid') {
                    $body = preg_replace('/^UID:.*$/m', 'UID:another-identity', $body);
                }
                if ($this->writeMode === 'wrong_readback') {
                    $body = str_replace('SUMMARY:Local event', 'SUMMARY:Different event', $body);
                }

                return Http::response($body, 200, ['ETag' => '"'.($this->etags[$path] ?? 'v1').'"']);
            }

            return Http::response('', 404);
        });
    }

    private function remote(string $title = 'Remote event', string $extra = '', string $href = 'original-resource.ics'): string
    {
        $path = $this->collection.$href;
        $this->resources[$path] = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nUID:remote-1\r\nSUMMARY:{$title}\r\nDTSTART:20261023T100000Z\r\nDTEND:20261023T110000Z\r\n{$extra}END:VEVENT\r\nEND:VCALENDAR\r\n";
        $this->etags[$path] = 'v1';

        return $path;
    }

    private function local(array $changes = []): CalendarEvent
    {
        return CalendarEvent::create($changes + [
            'uuid' => (string) Str::uuid(), 'calendar_id' => $this->calendar->id,
            'title' => 'Local event', 'starts_at' => '2026-10-23 10:00:00',
            'ends_at' => '2026-10-23 11:00:00', 'timezone' => 'Europe/Oslo',
            'source' => 'local', 'status' => 'confirmed', 'transparency' => 'busy', 'visibility' => 'default',
            'created_by' => $this->owner->id, 'updated_by' => $this->owner->id,
        ]);
    }

    private function runSync(): array
    {
        return app(RunNextcloudCalendarSync::class)->manual($this->connection->fresh());
    }

    private function enable(): void
    {
        $this->connection->update(['settings' => [
            'calendar_sync_enabled' => true, 'automatic_calendar_sync_enabled' => true,
            'automatic_calendar_mapping_ids' => [$this->mappingId],
        ]]);
        $settings = $this->connection->settings;
        $settings['automatic_calendar_target_fingerprint'] = DispatchDueNextcloudCalendars::fingerprint($this->connection);
        $this->connection->update(['settings' => $settings]);
    }

    private function noPuts(): void
    {
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
    }

    #[Test]
    public function remote_event_is_imported_once_and_second_cycle_keeps_identity_and_timestamps(): void
    {
        $this->remote();
        $this->assertSame(1, $this->runSync()['imported']);
        $event = CalendarEvent::firstOrFail();
        $id = $event->id;
        $updated = $event->updated_at->toIso8601String();
        $this->travel(16)->minutes();
        $summary = $this->runSync();
        $this->assertSame(0, $summary['changed']);
        $this->assertSame(1, CalendarEvent::count());
        $this->assertSame($id, CalendarEvent::first()->id);
        $this->assertSame($updated, CalendarEvent::first()->updated_at->toIso8601String());
        $this->noPuts();
    }

    #[Test]
    public function local_event_is_conditionally_created_and_read_back_before_acknowledgement(): void
    {
        $event = $this->local();
        $this->assertSame(1, $this->runSync()['pushed']);
        $event->refresh();
        $this->assertSame('synced', $event->sync_status);
        $this->assertSame($this->collection.$event->uuid.'.ics', $event->external_event_id);
        $this->assertFalse($event->metadata['nextcloud_pending_write']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->header('If-None-Match') === ['*']);
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_ends_with($r->url(), $event->uuid.'.ics'));
        $this->assertSame(0, $this->runSync()['changed']);
        $this->assertCount(1, $this->resources);
    }

    #[Test]
    public function local_only_update_preserves_provider_href_and_uses_current_etag(): void
    {
        $href = $this->remote();
        $this->runSync();
        $this->travel(2)->seconds();
        $event = CalendarEvent::firstOrFail();
        $event->update(['title' => 'Local edit']);
        $this->assertSame(1, $this->runSync()['pushed']);
        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && $r->url() === 'https://cloud.example.test'.$href && $r->header('If-Match') === ['"v1"']);
        $this->assertCount(1, $this->resources);
        $this->assertStringContainsString('SUMMARY:Local edit', $this->resources[$href]);
    }

    #[Test]
    public function remote_only_update_is_pulled_without_provider_write(): void
    {
        $href = $this->remote();
        $this->runSync();
        $this->resources[$href] = str_replace('Remote event', 'Remote edit', $this->resources[$href]);
        $this->etags[$href] = 'v2';
        $this->assertSame(1, $this->runSync()['imported']);
        $this->assertSame('Remote edit', CalendarEvent::first()->title);
        $this->noPuts();
    }

    #[Test]
    public function both_sides_changed_create_durable_conflict_and_never_put(): void
    {
        $href = $this->remote();
        $this->runSync();
        $this->travel(2)->seconds();
        CalendarEvent::first()->update(['title' => 'Local edit']);
        $this->resources[$href] = str_replace('Remote event', 'Remote edit', $this->resources[$href]);
        $this->etags[$href] = 'v2';
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame(1, NextcloudSyncConflict::count());
        $this->assertSame('Local edit', CalendarEvent::first()->title);
        $this->noPuts();
    }

    #[Test]
    public function unlinked_duplicate_candidates_are_not_imported_exported_or_adopted(): void
    {
        $this->remote();
        $event = $this->local(['title' => 'Remote event']);
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame(1, CalendarEvent::count());
        $this->assertNull($event->fresh()->external_uid);
        $this->noPuts();
    }

    #[Test]
    public function manual_ambiguous_destinations_do_not_export_unbound_events(): void
    {
        $this->connection->calendarMappings()->create([
            'calendar_id' => $this->calendar->id, 'user_id' => $this->owner->id,
            'remote_calendar_id' => '/remote.php/dav/calendars/svc/private/',
            'sync_direction' => 'two_way', 'is_active' => true,
        ]);
        $this->local();
        $this->assertSame(2, $this->runSync()['conflicts']);
        $this->noPuts();
    }

    #[Test]
    public function automatic_allowlist_does_not_export_events_from_another_remote_calendar(): void
    {
        $this->enable();
        $this->local(['external_source' => 'nextcloud',
            'external_calendar_id' => '/remote.php/dav/calendars/svc/private/',
            'external_uid' => 'private-1', 'external_event_id' => '/remote.php/dav/calendars/svc/private/a.ics']);
        $claim = app(NextcloudCalendarLease::class)->claim($this->connection, true);
        $summary = app(RunNextcloudCalendarSync::class)->handle($this->connection->id, $claim['token'], $claim['log_id'], true);
        $this->assertSame(0, $summary['pushed']);
        $this->noPuts();
        Http::assertSentCount(1);
    }

    #[Test]
    public function owner_mismatch_is_refused_without_provider_access(): void
    {
        $this->calendar->update(['owner_id' => User::factory()->create()->id]);
        $this->assertSame(1, $this->runSync()['conflicts']);
        Http::assertNothingSent();
    }

    #[Test]
    public function recurring_resources_are_retained_without_flattening_or_overwriting(): void
    {
        $this->remote(extra: "RRULE:FREQ=WEEKLY\r\n");
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame(0, CalendarEvent::count());
        $this->noPuts();
    }

    #[Test]
    public function attendee_resources_import_as_private_read_only_projections(): void
    {
        $this->remote(extra: "CLASS:PRIVATE\r\nATTENDEE:mailto:test@example.test\r\n");
        $this->assertSame(1, $this->runSync()['imported']);
        $event = CalendarEvent::first();
        $this->assertSame('private', $event->visibility);
        $this->assertSame('read_only', $event->sync_status);
        $this->travel(2)->seconds();
        $event->update(['title' => 'Local unsafe edit']);
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->noPuts();
    }

    #[Test]
    public function duration_and_all_day_dates_are_preserved(): void
    {
        $href = $this->remote();
        $this->resources[$href] = str_replace('DTEND:20261023T110000Z', 'DURATION:PT1H', $this->resources[$href]);
        $this->runSync();
        $this->assertSame('2026-10-23 11:00:00', CalendarEvent::first()->ends_at->format('Y-m-d H:i:s'));
        $this->resources = [];
        CalendarEvent::query()->forceDelete();
        $this->remote();
        $this->resources[$href] = str_replace(
            ['DTSTART:20261023T100000Z', "DTEND:20261023T110000Z\r\n"],
            ['DTSTART;VALUE=DATE:20261023', ''], $this->resources[$href],
        );
        $this->runSync();
        $event = CalendarEvent::first();
        $this->assertTrue($event->all_day);
        $this->assertSame('2026-10-22 22:00:00', $event->starts_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-23 22:00:00', $event->ends_at->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function unknown_timezone_does_not_silently_shift_a_meeting(): void
    {
        $href = $this->remote();
        $this->resources[$href] = str_replace('DTSTART:20261023T100000Z', 'DTSTART;TZID=Unknown/Timezone:20261023T100000', $this->resources[$href]);
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame(0, CalendarEvent::count());
        $this->noPuts();
    }

    #[Test]
    public function missing_remote_binding_does_not_recreate_deleted_remote_event(): void
    {
        $this->remote();
        $this->runSync();
        $this->resources = [];
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->noPuts();
    }

    #[Test]
    public function deleted_local_event_is_not_resurrected(): void
    {
        $this->remote();
        $this->runSync();
        CalendarEvent::first()->delete();
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame(0, CalendarEvent::count());
        $this->noPuts();
    }

    #[Test]
    public function unknown_create_outcome_is_reconciled_by_uuid_without_a_second_put(): void
    {
        $event = $this->local();
        $this->writeMode = 'unknown';
        $this->assertSame(1, $this->runSync()['failed']);
        $this->assertTrue($event->fresh()->metadata['nextcloud_pending_write']);
        $this->writeMode = 'normal';
        $this->assertSame(0, $this->runSync()['pushed']);
        $this->assertSame('synced', $event->fresh()->sync_status);
        $this->assertSame(1, CalendarEvent::count());
        $this->assertCount(1, $this->resources);
        $puts = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT');
        $this->assertCount(1, $puts);
    }

    #[Test]
    public function conditional_failure_remains_pending_and_is_not_blindly_retried(): void
    {
        $this->remote();
        $this->runSync();
        $this->travel(2)->seconds();
        CalendarEvent::first()->update(['title' => 'Local edit']);
        $this->writeMode = 'conflict';
        $this->assertSame(1, $this->runSync()['failed']);
        $this->assertTrue(CalendarEvent::first()->metadata['nextcloud_pending_write']);
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'PUT'));
    }

    #[Test]
    public function mismatching_provider_readback_does_not_mark_event_synced(): void
    {
        $event = $this->local();
        $this->writeMode = 'wrong_readback';
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertSame('pending_write', $event->fresh()->sync_status);
        $this->assertTrue($event->fresh()->metadata['nextcloud_pending_write']);
    }

    #[Test]
    public function dispatcher_is_default_off_and_does_not_touch_provider(): void
    {
        Queue::fake();
        $this->assertSame(0, app(DispatchDueNextcloudCalendars::class)->handle());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function due_dispatch_is_durable_and_duplicate_ticks_do_not_enqueue_twice(): void
    {
        Queue::fake();
        $this->enable();
        $this->assertSame(1, app(DispatchDueNextcloudCalendars::class)->handle());
        $this->assertSame(0, app(DispatchDueNextcloudCalendars::class)->handle());
        Queue::assertPushed(SyncNextcloudCalendar::class, 1);
        $this->assertSame(1, NextcloudSyncLog::where('status', 'queued')->count());
        Http::assertNothingSent();
    }

    #[Test]
    public function queued_worker_rechecks_pause_and_never_calls_provider(): void
    {
        $this->enable();
        $claim = app(NextcloudCalendarLease::class)->claim($this->connection, true);
        $this->connection->update(['is_active' => false]);
        app(RunNextcloudCalendarSync::class)->handle($this->connection->id, $claim['token'], $claim['log_id'], true);
        $this->assertSame('cancelled', NextcloudSyncLog::find($claim['log_id'])->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function completed_attempt_obeys_interval_and_repeated_queue_delivery_is_noop(): void
    {
        $this->enable();
        $claim = app(NextcloudCalendarLease::class)->claim($this->connection, true);
        $runner = app(RunNextcloudCalendarSync::class);
        $runner->handle($this->connection->id, $claim['token'], $claim['log_id'], true);
        $runner->handle($this->connection->id, $claim['token'], $claim['log_id'], true);
        Queue::fake();
        $this->assertSame(0, app(DispatchDueNextcloudCalendars::class)->handle());
        $this->travel(16)->minutes();
        $this->assertSame(1, app(DispatchDueNextcloudCalendars::class)->handle());
        Http::assertSentCount(1);
    }

    #[Test]
    public function stale_claim_is_recovered_and_late_old_worker_cannot_execute(): void
    {
        $this->enable();
        $claim = app(NextcloudCalendarLease::class)->claim($this->connection, true);
        $this->travel(11)->minutes();
        Queue::fake();
        $this->assertSame(1, app(DispatchDueNextcloudCalendars::class)->handle());
        app(RunNextcloudCalendarSync::class)->handle($this->connection->id, $claim['token'], $claim['log_id'], true);
        $this->assertSame('failed', NextcloudSyncLog::find($claim['log_id'])->status);
        Http::assertNothingSent();
    }

    #[Test]
    public function manual_sync_cannot_overlap_queued_work(): void
    {
        app(NextcloudCalendarLease::class)->claim($this->connection, true);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already queued or running');
        $this->runSync();
    }

    #[Test]
    public function activation_command_requires_explicit_owned_targets_and_preserves_other_settings(): void
    {
        $this->artisan('nextcloud:sync-calendars --enable --connection='.$this->connection->id)->assertExitCode(2);
        $this->artisan('nextcloud:sync-calendars --enable --connection='.$this->connection->id.' --mapping='.$this->mappingId)->assertSuccessful();
        $this->assertTrue($this->connection->fresh()->settings['automatic_calendar_sync_enabled']);
        $this->assertSame([$this->mappingId], $this->connection->fresh()->settings['automatic_calendar_mapping_ids']);
        $this->artisan('nextcloud:sync-calendars --disable --connection='.$this->connection->id)->assertSuccessful();
        $this->assertFalse($this->connection->fresh()->settings['automatic_calendar_sync_enabled']);
        $this->assertTrue($this->connection->fresh()->settings['calendar_sync_enabled']);
        Http::assertNothingSent();
    }

    #[Test]
    public function scheduler_registers_minutely_calendar_only_command(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => $e->description === 'nextcloud.calendars.dispatch_due');
        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
        $this->assertStringContainsString('nextcloud:sync-calendars', $events->first()->command);
    }

    #[Test]
    public function another_connection_cannot_claim_the_same_local_calendar(): void
    {
        $other = $this->connection->replicate();
        $other->uuid = (string) Str::uuid();
        $other->save();
        $other->calendarMappings()->create([
            'calendar_id' => $this->calendar->id, 'user_id' => $this->owner->id,
            'remote_calendar_id' => '/remote.php/dav/calendars/svc/other/',
            'sync_direction' => 'two_way', 'is_active' => true,
        ]);
        $claim = app(NextcloudCalendarLease::class)->claim($this->connection, false);
        $this->assertNotNull($claim);
        $this->assertNull(app(NextcloudCalendarLease::class)->claim($other, false));
        app(NextcloudCalendarLease::class)->finish($this->connection, $claim['token'], true);
        $this->assertNotNull(app(NextcloudCalendarLease::class)->claim($other, false));
        Http::assertNothingSent();
    }

    #[Test]
    public function same_second_local_mutation_is_not_overwritten_by_stale_integration_data(): void
    {
        $event = $this->local()->fresh();
        $stale = $event->fresh();
        $event->update(['description' => 'New local content']);
        $this->expectException(\RuntimeException::class);
        app(\App\Modules\Calendar\Actions\ApplyExternalCalendarEvent::class)->handle($stale, ['title' => 'Stale title']);
    }

    #[Test]
    public function duplicate_remote_uid_resources_are_both_refused(): void
    {
        $href = $this->remote();
        $this->resources[$this->collection.'duplicate.ics'] = $this->resources[$href];
        $this->assertSame(2, $this->runSync()['conflicts']);
        $this->assertSame(0, CalendarEvent::count());
        $this->noPuts();
    }

    #[Test]
    public function read_only_connection_pulls_but_never_exports_local_changes(): void
    {
        $this->connection->update(['mode' => 'read_only']);
        $this->remote();
        $this->local();
        $this->assertSame(1, $this->runSync()['imported']);
        $this->assertSame(2, CalendarEvent::count());
        $this->noPuts();
    }

    #[Test]
    public function a_locally_created_all_day_event_round_trips_without_date_drift(): void
    {
        $event = $this->local([
            'all_day' => true, 'starts_at' => '2026-10-22 22:00:00', 'ends_at' => '2026-10-23 22:00:00',
        ]);
        $this->assertSame(1, $this->runSync()['pushed']);
        $this->assertSame('synced', $event->fresh()->sync_status);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:20261023', array_values($this->resources)[0]);
    }

    #[Test]
    public function confidential_visibility_is_preserved_in_the_provider_resource(): void
    {
        $this->local(['visibility' => 'confidential']);
        $this->assertSame(1, $this->runSync()['pushed']);
        $this->assertStringContainsString('CLASS:CONFIDENTIAL', array_values($this->resources)[0]);
    }

    #[Test]
    public function unknown_put_without_a_remote_resource_is_not_retried_automatically(): void
    {
        $this->local();
        $this->writeMode = 'conflict';
        $this->assertSame(1, $this->runSync()['failed']);
        $this->writeMode = 'normal';
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($p) => $p[0]->method() === 'PUT'));
        $this->assertCount(0, $this->resources);
    }

    #[Test]
    public function malformed_remote_inventory_cannot_trigger_outbound_creates(): void
    {
        $this->writeMode = 'invalid_inventory';
        $this->local();
        $this->assertSame(1, $this->runSync()['failed']);
        $this->noPuts();
    }

    #[Test]
    public function paused_mapping_is_not_dispatched(): void
    {
        Queue::fake();
        $this->enable();
        $this->connection->calendarMappings()->update(['is_active' => false]);
        $this->assertSame(0, app(DispatchDueNextcloudCalendars::class)->handle());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function readback_with_different_uid_cannot_bind_an_unrelated_event(): void
    {
        $event = $this->local();
        $this->writeMode = 'wrong_uid';
        $this->assertSame(1, $this->runSync()['conflicts']);
        $this->assertNull($event->fresh()->external_uid);
        $this->assertTrue($event->fresh()->metadata['nextcloud_pending_write']);
    }

    #[Test]
    public function activation_refuses_a_mapping_owned_by_another_person(): void
    {
        $this->calendar->update(['owner_id' => User::factory()->create()->id]);
        $this->artisan('nextcloud:sync-calendars --enable --connection='.$this->connection->id.' --mapping='.$this->mappingId)->assertExitCode(1);
        $this->assertArrayNotHasKey('automatic_calendar_sync_enabled', $this->connection->fresh()->settings);
        Http::assertNothingSent();
    }

    #[Test]
    public function changing_mapping_target_revokes_automatic_activation_until_reviewed_again(): void
    {
        Queue::fake();
        $this->enable();
        $this->connection->calendarMappings()->whereKey($this->mappingId)->update(['remote_calendar_id' => '/remote.php/dav/calendars/svc/different/']);
        $this->assertSame(0, app(DispatchDueNextcloudCalendars::class)->handle());
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }

    #[Test]
    public function ordinary_connection_save_preserves_reviewed_activation_but_endpoint_change_revokes_it(): void
    {
        $this->enable();
        $payload = app(\App\Modules\Nextcloud\Actions\StoreNextcloudConnection::class)->payload([
            'name' => 'Updated', 'scope' => 'global', 'mode' => 'sync', 'is_active' => true,
            'base_url' => $this->connection->base_url, 'service_username' => 'svc', 'calendar_sync_enabled' => true,
        ], $this->connection);
        $this->connection->fill($payload)->save();
        $this->assertTrue(DispatchDueNextcloudCalendars::enabled($this->connection->fresh()));
        $this->connection->update(['base_url' => 'https://other.example.test']);
        $this->assertFalse(DispatchDueNextcloudCalendars::enabled($this->connection->fresh()));
    }
}
