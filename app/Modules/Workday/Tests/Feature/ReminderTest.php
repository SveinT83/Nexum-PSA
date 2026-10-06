<?php

namespace App\Modules\Workday\Tests\Feature;

use App\Models\Core\User;
use App\Models\Settings\CommonSetting;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarAvailabilityRule;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarEventSeries;
use App\Modules\Notification\Livewire\NotificationBell;
use App\Modules\Notification\Models\NotificationSetting;
use App\Modules\Notification\Support\InternalWebPushTargetAuthorizer;
use App\Modules\UserManagement\Actions\UserWorkPlan;
use App\Modules\Workday\Actions\WorkdayReminders;
use App\Modules\Workday\Jobs\DeliverWorkdayReminder;
use App\Modules\Workday\Models\WorkdayReminder;
use App\Modules\Workday\Models\WorkdayReminderDelivery;
use App\Modules\Workday\Support\ReminderEligibility;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Mockery;
use NotificationChannels\WebPush\WebPushChannel;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();
        config(['workday.enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-10-28 15:45', 'Europe/Oslo'));
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')->update([
            'json' => json_encode(['enabled' => true, 'retention_years' => 3, 'version' => 0]),
        ]);
        $role = Role::findOrCreate('Tech', 'web');
        $role->givePermissionTo(Permission::where('name', 'like', 'workday.%')->get());
        $this->worker = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $this->worker->assignRole($role);
        $this->worker->profile()->create(['timezone' => 'Europe/Oslo']);
        Sanctum::actingAs($this->worker, ['*']);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rule(int $weekday = 3, string $start = '08:00', string $end = '16:00', array $extra = []): CalendarAvailabilityRule
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker, seedWorkingWeek: false);

        return CalendarAvailabilityRule::create(array_replace([
            'calendar_id' => $calendar->id, 'user_id' => $this->worker->id,
            'weekday' => $weekday, 'starts_at_local' => $start, 'ends_at_local' => $end, 'timezone' => 'Europe/Oslo',
            'effective_from' => '2026-01-01', 'availability_type' => 'working',
            'metadata' => ['source' => UserWorkPlan::SOURCE, 'enabled' => true],
        ], $extra));
    }

    private function scan(): array
    {
        return app(WorkdayReminders::class)->scan();
    }

    private function deliver(): void
    {
        foreach (WorkdayReminderDelivery::orderBy('id')->pluck('id') as $id) {
            // Exercise the same payload round-trip as a queued worker without any live recipient.
            $job = unserialize(serialize(new DeliverWorkdayReminder($id)));
            app()->call([$job, 'handle']);
        }
    }

    private function preferences(array $extra = []): array
    {
        return $extra + ['database_enabled' => true, 'mail_enabled' => false, 'web_push_enabled' => false];
    }

    private function storedPreferences(array $extra): void
    {
        NotificationSetting::updateOrCreate(['user_id' => $this->worker->id, 'notification_type' => 'workday_reminder'],
            $this->preferences($extra));
    }

    private function absence(string $start, string $end): void
    {
        $this->postJson('/api/v1/workday-absences', [
            'version' => 0, 'timezone' => 'Europe/Oslo', 'category' => 'sickness',
            'mode' => 'partial', 'starts_at' => $start, 'ends_at' => $end,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
    }

    public function test_profile_and_api_share_default_in_app_only_preferences_and_opt_out(): void
    {
        $this->getJson('/api/v1/workday-reminder-preferences')->assertOk()->assertJsonPath('data', $this->preferences());
        $this->actingAs($this->worker)->get(route('tech.profile.notifications'))->assertOk()
            ->assertSee('Workday confirmation reminder')->assertSee('Snooze postpones')
            ->assertSee('A configured system Email account is required.');
        $this->post(route('tech.profile.notifications.update'), ['settings' => [
            ['notification_type' => 'workday_reminder'],
        ]])->assertSessionHasNoErrors()->assertRedirect();
        $this->getJson('/api/v1/workday-reminder-preferences')->assertOk()->assertJsonPath('data.database_enabled', false);
        $this->putJson('/api/v1/workday-reminder-preferences', $this->preferences())->assertOk()->assertJsonPath('data.database_enabled', true);
        $this->assertSame(1, NotificationSetting::where('notification_type', 'workday_reminder')->count());
    }

    public function test_not_ready_external_channels_unknown_fields_and_unsupported_channels_are_rejected(): void
    {
        foreach ([['mail_enabled' => true], ['web_push_enabled' => true], ['nextcloud_talk_enabled' => true],
            ['web_push_preview_enabled' => true], ['user_id' => $this->worker->id]] as $input) {
            $this->putJson('/api/v1/workday-reminder-preferences', $input + $this->preferences())->assertUnprocessable();
        }
        $this->assertSame(0, NotificationSetting::where('notification_type', 'workday_reminder')->count());
    }

    public function test_disabled_feature_hides_profile_controls_and_never_discovers_or_delivers(): void
    {
        $this->rule();
        $this->scan();
        config(['workday.enabled' => false]);
        $this->actingAs($this->worker)->get(route('tech.profile.notifications'))->assertOk()->assertDontSee('Workday confirmation reminder');
        $this->getJson('/api/v1/workday-reminder-preferences')->assertNotFound();
        $this->postJson(route('tech.profile.notifications.update'), ['settings' => [
            ['notification_type' => 'workday_reminder', 'database_enabled' => true],
        ]])->assertForbidden();
        $this->assertFalse($this->scan()['enabled']);
        $this->deliver();
        $this->assertSame(0, $this->worker->notifications()->count());
    }

    public function test_independent_settings_switch_also_disables_runtime(): void
    {
        CommonSetting::where('type', 'workday')->where('name', 'manual_workflow')->update(['json' => '{"enabled":false}']);
        $this->assertFalse($this->scan()['enabled']);
        $this->getJson('/api/v1/workday-reminders')->assertNotFound();
    }

    public function test_part_time_window_deduplicates_discovery_workers_and_multiple_tabs(): void
    {
        $this->rule(3, '08:00', '12:00');
        Carbon::setTestNow(Carbon::parse('2026-10-28 11:44', 'Europe/Oslo'));
        $this->scan();
        $this->assertSame(0, WorkdayReminder::count());
        $this->travel(1)->minutes();
        $this->scan();
        $this->scan();
        $this->deliver();
        $this->deliver();
        $this->assertSame(1, WorkdayReminder::count());
        $this->assertSame(1, $this->worker->notifications()->count());
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(1, WorkdayReminderDelivery::count());
    }

    public function test_unknown_default_and_disabled_weekend_plans_do_not_invent_reminders(): void
    {
        app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker);
        $this->scan();
        $this->assertSame(0, WorkdayReminder::count());
        $this->rule(6, '08:00', '16:00', ['metadata' => ['source' => UserWorkPlan::SOURCE, 'enabled' => false]]);
        Carbon::setTestNow(Carbon::parse('2026-10-31 18:00', 'Europe/Oslo'));
        $this->scan();
        $this->assertSame(0, WorkdayReminder::count());
    }

    public function test_dated_custom_rule_overrides_weekly_end_time(): void
    {
        $this->rule();
        $this->rule(3, '09:00', '19:00', ['effective_until' => '2026-10-28', 'metadata' => ['source' => 'custom']]);
        $this->scan();
        $this->assertSame(0, WorkdayReminder::count());
        Carbon::setTestNow(Carbon::parse('2026-10-28 18:45', 'Europe/Oslo'));
        $this->scan();
        $this->assertSame(1, WorkdayReminder::count());
    }

    public function test_overnight_remainder_keeps_previous_work_date(): void
    {
        $this->rule(3, '22:00', '06:00');
        Carbon::setTestNow(Carbon::parse('2026-10-29 05:45', 'Europe/Oslo'));
        $this->absence('2026-10-28T22:00', '2026-10-29T02:00');
        $this->scan();
        $this->deliver();
        $this->assertSame(['2026-10-28'], WorkdayReminder::pluck('work_date')->all());
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonPath('data.0.work_date', '2026-10-28');
    }

    public function test_dst_elapsed_time_uses_zone_and_ambiguous_plan_is_skipped(): void
    {
        $rule = $this->rule(7, '00:00', '04:00');
        Carbon::setTestNow(Carbon::parse('2026-10-25 03:45', 'Europe/Oslo'));
        $plan = app(ReminderEligibility::class)->plan($this->worker, '2026-10-25', 'Europe/Oslo');
        $this->assertSame('2026-10-25T02:45:00+00:00', $plan['due']->toIso8601String());
        $this->assertSame(300, \App\Modules\Workday\Support\TimeRanges::minutes($plan['intervals']));
        $rule->update(['ends_at_local' => '02:30']);
        $this->assertNull(app(ReminderEligibility::class)->plan($this->worker, '2026-10-25', 'Europe/Oslo'));
        $this->assertNull(app(ReminderEligibility::class)->plan($this->worker, '2026-03-29', 'Europe/Oslo'));
    }

    public function test_partial_absence_moves_end_but_full_absence_suppresses(): void
    {
        $this->rule();
        $this->absence('2026-10-28T12:00', '2026-10-28T16:00');
        $this->scan();
        $this->deliver();
        $this->assertSame('11:45', WorkdayReminder::first()->due_at->setTimezone('Europe/Oslo')->format('H:i'));
        $this->absence('2026-10-28T08:00', '2026-10-28T12:00');
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_full_absence_entered_after_queueing_suppresses_delivery(): void
    {
        $this->rule();
        $this->scan();
        $this->absence('2026-10-28T08:00', '2026-10-28T16:00');
        $this->deliver();
        $this->assertSame(0, $this->worker->notifications()->count());
        $this->assertSame('suppressed', WorkdayReminderDelivery::first()->state);
    }

    public function test_opt_out_and_inactive_user_are_rechecked_at_delivery(): void
    {
        $this->rule();
        $this->scan();
        $this->storedPreferences(['database_enabled' => false]);
        $this->deliver();
        $this->assertSame('suppressed', WorkdayReminderDelivery::first()->state);
        WorkdayReminderDelivery::query()->update(['state' => 'pending']);
        $this->storedPreferences(['database_enabled' => true]);
        $this->worker->update(['status' => User::STATUS_DISABLED]);
        $this->deliver();
        $this->assertSame(0, $this->worker->notifications()->count());
    }

    public function test_permission_removal_and_system_actor_are_rechecked(): void
    {
        $this->rule();
        $this->scan();
        Role::findByName('Tech', 'web')->revokePermissionTo('workday.manage_own');
        $this->deliver();
        $this->assertSame(0, $this->worker->notifications()->count());
        Role::findByName('Tech', 'web')->givePermissionTo('workday.manage_own');
        WorkdayReminderDelivery::query()->update(['state' => 'pending']);
        $this->worker->update(['is_system_actor' => true]);
        $this->deliver();
        $this->assertSame(0, $this->worker->notifications()->count());
    }

    public function test_api_confirmation_suppresses_queued_and_existing_reminders(): void
    {
        $this->rule();
        $this->scan();
        $this->deliver();
        $day = $this->putJson('/api/v1/workdays/2026-10-28/draft', [
            'version' => 0, 'timezone' => 'Europe/Oslo', 'description' => 'PRIVATE WORK DESCRIPTION',
            'intervals' => [['start' => '2026-10-28T08:00', 'end' => '2026-10-28T15:00']], 'breaks' => [],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('data');
        $preview = $this->postJson('/api/v1/workdays/'.$day['id'].'/preview', ['version' => $day['version']],
            ['Idempotency-Key' => (string) Str::uuid()])->assertOk()->json('preview');
        $this->postJson('/api/v1/workdays/'.$day['id'].'/confirm', [
            'version' => $day['version'], 'preview_token' => $preview['token'], 'confirmed' => true,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertOk();
        WorkdayReminderDelivery::query()->update(['state' => 'pending']);
        $this->deliver();
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/notifications?unread=1')->assertOk()->assertJsonCount(0, 'data');
        $this->assertSame('suppressed', WorkdayReminderDelivery::first()->state);
        $this->assertStringNotContainsString('PRIVATE', json_encode($this->worker->notifications()->first()->data));
    }

    public function test_snooze_retries_do_not_extend_time_and_next_generation_delivers_once(): void
    {
        $this->rule();
        $this->scan();
        $this->deliver();
        $reminder = WorkdayReminder::first();
        $path = '/api/v1/workday-reminders/'.$reminder->uuid.'/snooze';
        $first = $this->postJson($path, ['generation' => 1])->assertOk()->assertJsonPath('data.generation', 2)->json('data');
        $this->travel(5)->minutes();
        $this->postJson($path, ['generation' => 1])->assertOk()->assertJsonPath('data.snoozed_until', $first['snoozed_until']);
        $this->postJson($path, ['generation' => 3])->assertConflict();
        $this->deliver();
        $this->assertSame(1, $this->worker->notifications()->count());
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonCount(0, 'data');
        $this->travel(25)->minutes();
        $this->scan();
        $this->deliver();
        $this->deliver();
        $this->assertSame(2, $this->worker->notifications()->count());
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonPath('data.0.generation', 2);
    }

    public function test_next_visit_is_passive_and_opens_correct_date_without_creating_time(): void
    {
        $this->rule();
        $this->scan();
        $this->deliver();
        $this->travel(5)->days();
        $this->actingAs($this->worker);
        Livewire::test(NotificationBell::class)->assertSee('Review your workday')->assertSee('Snooze 30 minutes');
        $reminder = WorkdayReminder::first();
        $this->get(route('tech.workday-reminders.open', $reminder->uuid))
            ->assertOk()->assertSee('Open workday')->assertSee('Snooze 30 minutes')
            ->assertSee(route('tech.workdays.create', ['work_date' => '2026-10-28']), false);
        $this->get(route('tech.workdays.create', ['work_date' => '2026-10-28']))->assertOk()->assertSee('2026-10-28');
        $this->assertDatabaseCount('workdays', 0);
        $this->assertSame(0, $this->worker->unreadNotifications()->count());
    }

    public function test_api_scope_foreign_owner_and_expiry_are_guarded(): void
    {
        $this->rule();
        $this->scan();
        $this->deliver();
        $reminder = WorkdayReminder::first();
        Sanctum::actingAs($this->worker, ['workday-reminders.read']);
        $this->putJson('/api/v1/workday-reminder-preferences', $this->preferences())->assertForbidden();
        $this->postJson('/api/v1/workday-reminders/'.$reminder->uuid.'/snooze', ['generation' => 1])->assertForbidden();
        $other = User::factory()->create(['status' => User::STATUS_ACTIVE]);
        $other->assignRole('Tech');
        Sanctum::actingAs($other, ['*']);
        $this->postJson('/api/v1/workday-reminders/'.$reminder->uuid.'/snooze', ['generation' => 1])->assertNotFound();
        $this->assertNull(app(InternalWebPushTargetAuthorizer::class)->authorizedPath($other, 'workday_reminder', $reminder->uuid));
        Sanctum::actingAs($this->worker, ['*']);
        $this->travel(4)->years();
        $this->getJson('/api/v1/workday-reminders')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_database_only_notice_and_job_payload_are_generic(): void
    {
        $this->rule();
        $this->scan();
        Queue::assertPushed(DeliverWorkdayReminder::class, fn ($job) => array_keys(get_object_vars($job))[0] !== 'user');
        $this->deliver();
        $data = $this->worker->notifications()->first()->data;
        $this->assertSame(['type', 'title', 'message', 'url', 'workday_reminder', 'workday_expires_at'], array_keys($data));
        $receipt = WorkdayReminder::firstOrFail();
        $this->assertSame($receipt->uuid, $data['workday_reminder']);
        $this->assertSame($receipt->expires_at->utc()->format('Y-m-d\TH:i:s\Z'), $data['workday_expires_at']);
        $this->assertStringStartsWith('/tech/workday-reminders/', $data['url']);
        $this->assertSame(1, WorkdayReminderDelivery::where('channel', 'database')->count());
        $this->assertSame(0, WorkdayReminderDelivery::whereIn('channel', ['mail', 'web_push'])->count());
    }

    public function test_rescheduled_end_keeps_pending_job_until_new_end(): void
    {
        $rule = $this->rule();
        $this->scan();
        $rule->update(['ends_at_local' => '18:00']);
        $this->deliver();
        $this->assertSame('pending', WorkdayReminderDelivery::first()->state);
        $this->assertSame(0, $this->worker->notifications()->count());
        $this->travel(2)->hours();
        $this->deliver();
        $this->assertSame(1, $this->worker->notifications()->count());
    }

    public function test_recurring_education_is_work_and_cancellation_removes_reminder(): void
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->worker, seedWorkingWeek: false);
        $series = CalendarEventSeries::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id,
            'timezone' => 'Europe/Oslo', 'rrule' => 'FREQ=WEEKLY;BYDAY=WE;COUNT=4',
            'starts_at' => '2026-10-21 08:00:00', 'ends_at' => '2026-10-21 14:00:00',
            'recurrence_starts_at' => '2026-10-21 08:00:00']);
        $event = CalendarEvent::create(['uuid' => (string) Str::uuid(), 'calendar_id' => $calendar->id,
            'series_id' => $series->id, 'title' => 'Private education', 'source' => 'work_plan',
            'starts_at' => '2026-10-21 08:00:00', 'ends_at' => '2026-10-21 14:00:00',
            'timezone' => 'Europe/Oslo', 'status' => 'confirmed', 'created_by' => $this->worker->id]);
        $this->scan();
        $this->assertSame(1, WorkdayReminder::count());
        $event->update(['status' => 'cancelled']);
        $this->deliver();
        $this->assertSame(0, $this->worker->notifications()->count());
    }

    public function test_scheduler_cursor_bounds_scanning_and_recovers_broker_gap(): void
    {
        $this->rule();
        $first = app(WorkdayReminders::class)->scan(1);
        $this->assertSame(1, $first['scanned']);
        $this->assertSame(1, DB::table('workday_reminder_cursors')->count());
        $this->scan();
        $this->scan();
        $this->deliver();
        $this->assertSame(1, $this->worker->notifications()->count());
        $this->artisan('workday:reminders', ['--limit' => 1])->assertSuccessful();
    }

    public function test_push_opt_in_readiness_target_authorization_and_single_transport_attempt(): void
    {
        config(['webpush.enabled' => true, 'webpush.vapid.subject' => 'mailto:test@example.test',
            'webpush.vapid.public_key' => 'test-public-key', 'webpush.vapid.private_key' => 'test-private-key']);
        $this->worker->updatePushSubscription('https://push.example.test/synthetic', 'key', 'auth', 'aes128gcm');
        $this->putJson('/api/v1/workday-reminder-preferences', $this->preferences(['web_push_enabled' => true]))->assertOk();
        $channel = Mockery::mock(WebPushChannel::class);
        $channel->shouldReceive('send')->once()->withArgs(function ($user, $notification) {
            $payload = $notification->toWebPush($user, $notification)->toArray();

            return $user->is($this->worker) && $payload['title'] === 'Review your workday'
                && str_starts_with($payload['data']['url'], '/tech/workday-reminders/');
        });
        $this->app->instance(WebPushChannel::class, $channel);
        $this->rule();
        $this->scan();
        $this->deliver();
        $this->deliver();
        $this->assertSame('submitted', WorkdayReminderDelivery::where('channel', 'web_push')->first()->state);
    }

    public function test_lost_external_claim_is_not_automatically_replayed(): void
    {
        $this->storedPreferences(['mail_enabled' => true]);
        $this->rule();
        $this->scan();
        $mail = WorkdayReminderDelivery::where('channel', 'mail')->first();
        $mail->update(['state' => 'attempting', 'attempted_at' => now()->subMinutes(10)]);
        $this->deliver();
        $this->assertSame('attempting', $mail->fresh()->state);
        $this->assertSame(1, $this->worker->notifications()->count());
    }

    private function mailAccount(): \App\Modules\Email\Models\EmailAccount
    {
        return \App\Modules\Email\Models\EmailAccount::create([
            'address' => 'reminder-provider@example.test', 'description' => 'Synthetic reminder provider',
            'from_name' => 'Nexum', 'account_kind' => 'system', 'is_active' => true,
            'is_global_default' => true, 'defaults_for' => ['system'],
            'ticket_ingress_enabled' => false, 'delete_policy' => 'local_only',
            'provider_credential_source' => 'legacy', 'provider_binding_version' => 1,
            'imap_host' => '8.8.8.8', 'imap_port' => 993, 'imap_encryption' => 'ssl',
            'imap_username' => 'synthetic@example.test', 'imap_auth_type' => 'password',
            'imap_secret' => \Illuminate\Support\Facades\Crypt::encryptString('synthetic-only'),
            'smtp_host' => '1.1.1.1', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
            'smtp_username' => 'synthetic@example.test', 'smtp_auth_type' => 'password',
            'smtp_secret' => \Illuminate\Support\Facades\Crypt::encryptString('synthetic-only'),
        ]);
    }

    public function test_opt_in_mail_uses_frozen_system_provider_with_generic_content_once(): void
    {
        $account = $this->mailAccount();
        $mailer = Mockery::mock(\App\Modules\Email\Services\SmtpAccountMailer::class);
        $mailer->shouldReceive('sendMessage')->once()->withArgs(function ($sender, $recipients, $subject, $html) use ($account) {
            return $sender->id === $account->id && $recipients[0]['email'] === $this->worker->email
                && $subject === 'Review your workday' && str_contains($html, 'Open workday')
                && ! str_contains($html, 'sickness');
        })->andReturn('<synthetic-reminder@example.test>');
        $this->app->instance(\App\Modules\Email\Services\SmtpAccountMailer::class, $mailer);
        $this->putJson('/api/v1/workday-reminder-preferences', $this->preferences(['mail_enabled' => true]))->assertOk();
        $this->rule();
        $this->scan();
        $this->deliver();
        $this->deliver();
        $mail = WorkdayReminderDelivery::where('channel', 'mail')->first();
        $this->assertSame('submitted', $mail->state);
        $this->assertSame($account->id, $mail->mail_snapshot['account_id']);
        $this->assertStringNotContainsString('synthetic-only', json_encode($mail->mail_snapshot));
    }

    public function test_provider_rebinding_blocks_queued_mail_without_adopting_new_binding(): void
    {
        $account = $this->mailAccount();
        $mailer = Mockery::mock(\App\Modules\Email\Services\SmtpAccountMailer::class);
        $mailer->shouldNotReceive('sendMessage');
        $this->app->instance(\App\Modules\Email\Services\SmtpAccountMailer::class, $mailer);
        $this->storedPreferences(['mail_enabled' => true]);
        $this->rule();
        $this->scan();
        $account->forceFill(['provider_binding_version' => 2])->save();
        $this->deliver();
        $this->assertSame('blocked', WorkdayReminderDelivery::where('channel', 'mail')->first()->state);
    }

    public function test_ambiguous_mail_submission_is_not_resent_by_worker_retry(): void
    {
        $this->mailAccount();
        $mailer = Mockery::mock(\App\Modules\Email\Services\SmtpAccountMailer::class);
        $mailer->shouldReceive('sendMessage')->once()
            ->andThrow(new \App\Modules\Email\Services\EmailProviderSendOutcomeUnresolvedException('Synthetic ambiguous result'));
        $this->app->instance(\App\Modules\Email\Services\SmtpAccountMailer::class, $mailer);
        $this->storedPreferences(['mail_enabled' => true]);
        $this->rule();
        $this->scan();
        $this->deliver();
        $this->deliver();
        $this->assertSame('unresolved', WorkdayReminderDelivery::where('channel', 'mail')->first()->state);
    }

    public function test_mail_opt_out_after_discovery_never_enters_transport(): void
    {
        $this->mailAccount();
        $mailer = Mockery::mock(\App\Modules\Email\Services\SmtpAccountMailer::class);
        $mailer->shouldNotReceive('sendMessage');
        $this->app->instance(\App\Modules\Email\Services\SmtpAccountMailer::class, $mailer);
        $this->storedPreferences(['mail_enabled' => true]);
        $this->rule();
        $this->scan();
        $this->putJson('/api/v1/workday-reminder-preferences', $this->preferences())->assertOk();
        $this->deliver();
        $this->assertSame('suppressed', WorkdayReminderDelivery::where('channel', 'mail')->first()->state);
    }

    public function test_livewire_snooze_reuses_guarded_generation_and_other_notifications_survive(): void
    {
        $this->rule();
        $this->scan();
        $this->deliver();
        $reminder = WorkdayReminder::first();
        $this->worker->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'OrdinaryNotice',
            'data' => ['type' => 'system_announcement', 'title' => 'Ordinary announcement', 'url' => '/tech/profile/notifications']]);
        Livewire::actingAs($this->worker)->test(NotificationBell::class)
            ->assertSee('Review your workday')->assertSee('Ordinary announcement')
            ->call('snoozeWorkday', $reminder->uuid, 1)->assertDontSee('Review your workday')->assertSee('Ordinary announcement');
        $this->assertSame(2, $reminder->fresh()->generation);
    }

    public function test_bound_coordinator_token_cannot_read_or_change_employee_preferences(): void
    {
        $token = $this->worker->createToken('Synthetic reminder credential', ['workday-reminders.read', 'workday-reminders.write']);
        $this->worker->withAccessToken($token->accessToken);
        $this->actingAs($this->worker, 'sanctum')->getJson('/api/v1/workday-reminder-preferences')->assertOk();
        $workload = \App\Modules\Integration\Models\AiWorkloadProfile::create([
            'name' => 'Synthetic coordinator', 'slug' => 'synthetic-reminder-coordinator', 'purpose' => 'Scope isolation',
            'processing_mode' => 'local_only', 'maximum_data_profile' => 'pseudonymized',
            'abilities' => ['worklog.read'], 'is_approved' => true, 'is_active' => true,
            'expires_at' => now()->addMonth(), 'approved_by' => $this->worker->id, 'approved_at' => now(),
            'created_by' => $this->worker->id,
        ]);
        \App\Modules\Integration\Models\AiWorkloadTokenBinding::create([
            'personal_access_token_id' => $token->accessToken->id, 'ai_workload_profile_id' => $workload->id,
            'expires_at' => now()->addWeek(), 'allowed_networks' => [], 'requests_per_minute' => 30,
            'created_by' => $this->worker->id,
        ]);
        $this->getJson('/api/v1/workday-reminder-preferences')->assertForbidden();
        $this->putJson('/api/v1/workday-reminder-preferences', $this->preferences())->assertForbidden();
    }

    public function test_expiry_matches_workday_boundary_and_snooze_does_not_extend_it(): void
    {
        $this->rule();
        $this->scan();
        $this->deliver();
        $reminder = WorkdayReminder::first();
        $this->assertSame('2029-10-28T23:00:00+00:00', $reminder->expires_at->toIso8601String());
        $expiry = $reminder->expires_at;
        app(WorkdayReminders::class)->snooze($this->worker, $reminder->uuid, 1);
        $this->assertTrue($expiry->equalTo($reminder->fresh()->expires_at));
        Carbon::setTestNow(Carbon::instance($expiry)->subSecond());
        $this->assertNotNull(app(WorkdayReminders::class)->current($this->worker, $reminder->fresh()));
        Carbon::setTestNow(Carbon::instance($expiry));
        $this->assertNull(app(WorkdayReminders::class)->current($this->worker, $reminder->fresh()));
    }

    public function test_old_external_catch_up_is_suppressed_while_in_app_remains(): void
    {
        $this->storedPreferences(['mail_enabled' => true]);
        $this->rule();
        $this->scan();
        $this->travel(2)->days();
        $this->deliver();
        $this->assertSame('suppressed', WorkdayReminderDelivery::where('channel', 'mail')->first()->state);
        $this->assertSame('delivered', WorkdayReminderDelivery::where('channel', 'database')->first()->state);
    }

    public function test_missing_mail_binding_is_blocked_without_fallback_or_retry(): void
    {
        $this->storedPreferences(['mail_enabled' => true]);
        $this->rule();
        $this->scan();
        $this->deliver();
        $this->deliver();
        $mail = WorkdayReminderDelivery::where('channel', 'mail')->first();
        $this->assertSame('blocked', $mail->state);
        $this->assertNull($mail->mail_snapshot['account_id']);
    }
}
