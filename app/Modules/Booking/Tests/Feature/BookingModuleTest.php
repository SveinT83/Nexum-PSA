<?php

namespace App\Modules\Booking\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Booking\Actions\FindBookingSlots;
use App\Modules\Booking\Controllers\Admin\BookingController as AdminBookingController;
use App\Modules\Booking\Controllers\Public\BookingController as PublicBookingController;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Booking\Notifications\BookingRequestConfirmed;
use App\Modules\Booking\Notifications\BookingRequestDeclined;
use App\Modules\Booking\Notifications\BookingRequestReceived;
use App\Modules\Calendar\Actions\CheckAvailability;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Calendar\Models\CalendarSetting;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BookingModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $technician;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the frozen clock in the application's storage timezone so
        // datetime casts behave exactly like the UTC production runtime.
        Carbon::setTestNow(Carbon::parse('2026-07-04 06:00:00', 'UTC'));

        Role::create(['name' => 'Admin']);

        $this->admin = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Admin User',
        ]);
        $this->admin->assignRole('Admin');

        $this->technician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Ada Tech',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    #[Test]
    public function booking_routes_are_owned_by_booking_module(): void
    {
        $this->assertSame(PublicBookingController::class.'@index', Route::getRoutes()->getByName('booking.index')->getActionName());
        $this->assertSame(PublicBookingController::class.'@show', Route::getRoutes()->getByName('booking.services.show')->getActionName());
        $this->assertSame(AdminBookingController::class.'@index', Route::getRoutes()->getByName('tech.admin.system.booking.index')->getActionName());
    }

    #[Test]
    public function public_booking_index_only_lists_active_bookable_services(): void
    {
        $bookable = $this->bookingSetting([
            'public_name' => 'Remote consultation',
            'slug' => 'remote-consultation',
        ]);

        $inactive = $this->bookingSetting([
            'public_name' => 'Hidden setup',
            'slug' => 'hidden-setup',
            'status' => BookingServiceSetting::STATUS_DRAFT,
        ]);

        $inactiveService = $this->service(['name' => 'Archived service', 'status' => 'archived']);
        BookingServiceSetting::query()->create([
            'service_id' => $inactiveService->id,
            'assigned_user_id' => $this->technician->id,
            'status' => BookingServiceSetting::STATUS_ACTIVE,
            'slug' => 'archived-service',
            'public_name' => 'Archived service',
            'booking_mode' => BookingServiceSetting::MODE_STAFF_CONFIRMED,
            'duration_minutes' => 60,
            'slot_step_minutes' => 15,
            'min_notice_hours' => 0,
            'horizon_days' => 30,
            'allow_new_clients' => true,
        ]);

        $this->get(route('booking.index'))
            ->assertOk()
            ->assertSee($bookable->public_name)
            ->assertDontSee($inactive->public_name)
            ->assertDontSee('Archived service');
    }

    #[Test]
    public function public_booking_slots_use_calendar_conflicts(): void
    {
        $setting = $this->bookingSetting(['duration_minutes' => 60]);
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->technician);

        CalendarEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Busy customer work',
            'starts_at' => Carbon::parse('2026-07-06 09:00', 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse('2026-07-06 10:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $this->technician->id,
        ]);

        $this->get(route('booking.services.show', [$setting, 'date' => '2026-07-06']))
            ->assertOk()
            ->assertSee('2026-07-06T08:00:00+02:00', false)
            ->assertDontSee('2026-07-06T09:00:00+02:00', false)
            ->assertSee('2026-07-06T10:00:00+02:00', false);
    }

    #[Test]
    public function public_customer_can_submit_available_booking_request(): void
    {
        Notification::fake();

        $setting = $this->bookingSetting(['duration_minutes' => 60]);
        app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->technician);

        $this->post(route('booking.services.store', $setting), [
            'slot_starts_at' => '2026-07-06T09:00:00+02:00',
            'timezone' => 'Europe/Oslo',
            'company_name' => 'Example AS',
            'contact_name' => 'Eva Example',
            'contact_email' => 'eva@example.test',
            'contact_phone' => '+4712345678',
            'message' => 'Need planning help.',
            'privacy_acknowledged' => '1',
        ])->assertRedirect(route('booking.services.thanks', $setting));

        $bookingRequest = BookingRequest::query()->firstOrFail();

        $this->assertSame(BookingRequest::STATUS_REQUESTED, $bookingRequest->status);
        $this->assertSame('eva@example.test', $bookingRequest->contact_email);
        $this->assertNotNull($bookingRequest->customer_requested_notification_sent_at);

        Notification::assertSentOnDemand(BookingRequestReceived::class);
    }

    #[Test]
    public function admin_can_confirm_booking_request_into_calendar_event(): void
    {
        Notification::fake();

        $setting = $this->bookingSetting(['duration_minutes' => 60]);
        app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->technician);

        $this->post(route('booking.services.store', $setting), [
            'slot_starts_at' => '2026-07-06T11:00:00+02:00',
            'timezone' => 'Europe/Oslo',
            'company_name' => 'Example AS',
            'contact_name' => 'Eva Example',
            'contact_email' => 'eva@example.test',
            'privacy_acknowledged' => '1',
        ]);

        $bookingRequest = BookingRequest::query()->firstOrFail();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.booking.requests.confirm', $bookingRequest))
            ->assertRedirect(route('tech.admin.system.booking.requests.show', $bookingRequest));

        $bookingRequest->refresh();

        $this->assertSame(BookingRequest::STATUS_CONFIRMED, $bookingRequest->status);
        $this->assertNotNull($bookingRequest->calendar_event_id);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $bookingRequest->calendar_event_id,
            'source' => 'booking',
            'transparency' => 'busy',
        ]);
        $this->assertDatabaseHas('calendar_event_links', [
            'event_id' => $bookingRequest->calendar_event_id,
            'linkable_type' => BookingRequest::class,
            'linkable_id' => $bookingRequest->id,
            'relation' => 'booking_request',
        ]);
        $this->assertNotNull($bookingRequest->customer_confirmation_notification_sent_at);

        Notification::assertSentOnDemand(BookingRequestConfirmed::class);
    }

    #[Test]
    public function admin_can_decline_booking_request(): void
    {
        Notification::fake();

        $setting = $this->bookingSetting();
        $bookingRequest = BookingRequest::query()->create([
            'booking_key' => 'BK-20260704-DECLIN',
            'booking_service_setting_id' => $setting->id,
            'service_id' => $setting->service_id,
            'assigned_user_id' => $this->technician->id,
            'status' => BookingRequest::STATUS_REQUESTED,
            'booking_mode' => BookingServiceSetting::MODE_STAFF_CONFIRMED,
            'contact_name' => 'Eva Example',
            'contact_email' => 'eva@example.test',
            'requested_date' => '2026-07-06',
            'requested_starts_at' => Carbon::parse('2026-07-06 13:00', 'Europe/Oslo')->utc(),
            'requested_ends_at' => Carbon::parse('2026-07-06 14:00', 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
        ]);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.booking.requests.decline', $bookingRequest), [
                'decline_reason' => 'No technician available.',
            ])
            ->assertRedirect(route('tech.admin.system.booking.requests.show', $bookingRequest));

        $bookingRequest->refresh();

        $this->assertSame(BookingRequest::STATUS_DECLINED, $bookingRequest->status);
        $this->assertSame('No technician available.', $bookingRequest->decline_reason);
        $this->assertNotNull($bookingRequest->customer_decline_notification_sent_at);

        Notification::assertSentOnDemand(BookingRequestDeclined::class);
    }

    #[Test]
    public function admin_booking_form_uses_header_back_and_explains_advanced_spam_protection(): void
    {
        $this->actingAs($this->admin)
            ->get(route('tech.admin.system.booking.settings.create'))
            ->assertOk()
            ->assertSee(route('tech.admin.system.booking.index'), false)
            ->assertSeeText('Back')
            ->assertSee('data-routing-mode', false)
            ->assertSeeText('Advanced spam protection')
            ->assertSeeText('Hidden anti-spam field name');
    }

    #[Test]
    public function public_slots_intersect_company_hours_and_service_opening_window(): void
    {
        $this->setCompanyHours('09:00', '12:00');
        $setting = $this->bookingSetting([
            'duration_minutes' => 60,
            'opening_window_start' => '10:00',
            'opening_window_end' => '15:00',
        ]);

        $this->get(route('booking.services.show', [$setting, 'date' => '2026-07-06']))
            ->assertOk()
            ->assertDontSee('2026-07-06T09:00:00+02:00', false)
            ->assertSee('2026-07-06T10:00:00+02:00', false)
            ->assertSee('2026-07-06T11:00:00+02:00', false)
            ->assertDontSee('2026-07-06T12:00:00+02:00', false);
    }

    #[Test]
    public function public_slots_can_follow_technician_profile_working_hours(): void
    {
        $workingHours = UserProfileData::defaultWorkingHours();
        foreach ($workingHours as $day => $hours) {
            $workingHours[$day]['enabled'] = false;
        }
        $workingHours['monday'] = [
            'enabled' => true,
            'start' => '12:00',
            'end' => '14:00',
        ];

        UserProfile::query()->create([
            'user_id' => $this->technician->id,
            'timezone' => 'Europe/Oslo',
            'working_hours' => $workingHours,
        ]);

        $setting = $this->bookingSetting([
            'duration_minutes' => 60,
            'working_hours_source' => BookingServiceSetting::HOURS_TECHNICIAN,
        ]);

        $this->get(route('booking.services.show', [$setting, 'date' => '2026-07-06']))
            ->assertOk()
            ->assertDontSee('2026-07-06T11:00:00+02:00', false)
            ->assertSee('2026-07-06T12:00:00+02:00', false)
            ->assertSee('2026-07-06T13:00:00+02:00', false)
            ->assertDontSee('2026-07-06T14:00:00+02:00', false);
    }

    #[Test]
    public function automatic_routing_shows_slot_union_without_technician_identity(): void
    {
        $secondTechnician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Bea Tech',
        ]);
        $setting = $this->bookingSetting([
            'assigned_user_id' => null,
            'technician_routing_mode' => BookingServiceSetting::ROUTING_AUTOMATIC,
            'eligible_users' => [$this->technician, $secondTechnician],
        ]);

        $this->busy($this->technician, '2026-07-06 10:00', '2026-07-06 11:00');
        $this->busy($secondTechnician, '2026-07-06 09:00', '2026-07-06 10:00');

        $this->get(route('booking.services.show', [$setting, 'date' => '2026-07-06']))
            ->assertOk()
            ->assertSee('2026-07-06T09:00:00+02:00', false)
            ->assertSee('2026-07-06T10:00:00+02:00', false)
            ->assertDontSee($this->technician->name)
            ->assertDontSee($secondTechnician->name);
    }

    #[Test]
    public function automatic_request_is_rerouted_to_an_available_eligible_technician_on_confirmation(): void
    {
        Notification::fake();

        $secondTechnician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Bea Tech',
        ]);
        $setting = $this->bookingSetting([
            'assigned_user_id' => null,
            'technician_routing_mode' => BookingServiceSetting::ROUTING_AUTOMATIC,
            'eligible_users' => [$this->technician, $secondTechnician],
        ]);
        app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->technician);
        $secondCalendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($secondTechnician);

        $this->post(route('booking.services.store', $setting), $this->publicRequestPayload('2026-07-06T11:00:00+02:00'))
            ->assertRedirect(route('booking.services.thanks', $setting));

        $bookingRequest = BookingRequest::query()->firstOrFail();
        $this->assertSame($this->technician->id, $bookingRequest->assigned_user_id);

        $this->busy($this->technician, '2026-07-06 11:00', '2026-07-06 12:00');

        $firstCalendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($this->technician);
        $this->assertFalse(app(CheckAvailability::class)->isFree(
            [$firstCalendar],
            Carbon::parse('2026-07-06 11:00', 'Europe/Oslo'),
            Carbon::parse('2026-07-06 12:00', 'Europe/Oslo'),
        ));
        $this->assertSame(
            '2026-07-06 09:00:00',
            (string) $bookingRequest->getRawOriginal('requested_starts_at'),
        );
        $this->assertSame('Europe/Oslo', $bookingRequest->timezone);
        $this->assertSame(
            '2026-07-06 09:00:00+00:00 UTC',
            $bookingRequest->requested_starts_at->format('Y-m-d H:i:sP e'),
        );
        $this->assertSame(
            '2026-07-06 11:00:00+02:00',
            $bookingRequest->requested_starts_at->copy()->timezone($bookingRequest->timezone)->format('Y-m-d H:i:sP'),
        );
        $resolvedUser = app(FindBookingSlots::class)->resolveUserForConfirmation(
            $setting->fresh(['service', 'assignedUser', 'eligibleUsers']),
            $this->technician,
            $bookingRequest->requested_starts_at->copy()->timezone($bookingRequest->timezone),
            $bookingRequest->requested_ends_at->copy()->timezone($bookingRequest->timezone),
        );
        $this->assertSame($secondTechnician->id, $resolvedUser?->id);

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.booking.requests.confirm', $bookingRequest))
            ->assertRedirect(route('tech.admin.system.booking.requests.show', $bookingRequest));

        $bookingRequest->refresh();

        $this->assertSame($secondTechnician->id, $bookingRequest->assigned_user_id);
        $this->assertSame($secondCalendar->id, $bookingRequest->calendarEvent?->calendar_id);
        $this->assertDatabaseHas('booking_request_events', [
            'booking_request_id' => $bookingRequest->id,
            'type' => 'confirmed',
        ]);
    }

    #[Test]
    public function automatic_confirmation_is_blocked_when_every_eligible_technician_is_busy(): void
    {
        Notification::fake();

        $secondTechnician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Bea Tech',
        ]);
        $setting = $this->bookingSetting([
            'assigned_user_id' => null,
            'technician_routing_mode' => BookingServiceSetting::ROUTING_AUTOMATIC,
            'eligible_users' => [$this->technician, $secondTechnician],
        ]);

        $this->post(route('booking.services.store', $setting), $this->publicRequestPayload('2026-07-06T13:00:00+02:00'));
        $bookingRequest = BookingRequest::query()->firstOrFail();

        $this->busy($this->technician, '2026-07-06 13:00', '2026-07-06 14:00');
        $this->busy($secondTechnician, '2026-07-06 13:00', '2026-07-06 14:00');

        $this->actingAs($this->admin)
            ->from(route('tech.admin.system.booking.requests.show', $bookingRequest))
            ->post(route('tech.admin.system.booking.requests.confirm', $bookingRequest))
            ->assertRedirect(route('tech.admin.system.booking.requests.show', $bookingRequest))
            ->assertSessionHasErrors('requested_starts_at');

        $bookingRequest->refresh();
        $this->assertSame(BookingRequest::STATUS_REQUESTED, $bookingRequest->status);
        $this->assertNull($bookingRequest->calendar_event_id);
    }

    #[Test]
    public function customer_choice_lists_only_eligible_users_and_persists_the_selected_technician(): void
    {
        Notification::fake();

        $secondTechnician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Bea Tech',
        ]);
        $otherTechnician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Outside Tech',
        ]);
        $setting = $this->bookingSetting([
            'assigned_user_id' => null,
            'technician_routing_mode' => BookingServiceSetting::ROUTING_CUSTOMER_CHOICE,
            'eligible_users' => [$this->technician, $secondTechnician],
        ]);

        $this->get(route('booking.services.show', [$setting, 'date' => '2026-07-06']))
            ->assertOk()
            ->assertSee($this->technician->name)
            ->assertSee($secondTechnician->name)
            ->assertDontSee($otherTechnician->name)
            ->assertSeeText('Choose a technician and date to see available times.');

        $this->post(route('booking.services.store', $setting), $this->publicRequestPayload(
            '2026-07-06T11:00:00+02:00',
            ['technician_id' => $otherTechnician->id],
        ))->assertSessionHasErrors('technician_id');
        $this->assertDatabaseCount('booking_requests', 0);

        $this->get(route('booking.services.show', [
            $setting,
            'date' => '2026-07-06',
            'technician_id' => $secondTechnician->id,
        ]))
            ->assertOk()
            ->assertSee('2026-07-06T11:00:00+02:00', false);

        $this->post(route('booking.services.store', $setting), $this->publicRequestPayload(
            '2026-07-06T11:00:00+02:00',
            ['technician_id' => $secondTechnician->id],
        ))->assertRedirect(route('booking.services.thanks', $setting));

        $this->assertSame(
            $secondTechnician->id,
            BookingRequest::query()->firstOrFail()->assigned_user_id,
        );
    }

    #[Test]
    public function admin_can_store_opening_hours_and_an_automatic_technician_pool(): void
    {
        $service = $this->service();
        $secondTechnician = User::factory()->create([
            'status' => User::STATUS_ACTIVE,
            'name' => 'Bea Tech',
        ]);

        $response = $this->actingAs($this->admin)
            ->post(route('tech.admin.system.booking.settings.store'), [
                'service_id' => $service->id,
                'status' => BookingServiceSetting::STATUS_ACTIVE,
                'technician_routing_mode' => BookingServiceSetting::ROUTING_AUTOMATIC,
                'working_hours_source' => BookingServiceSetting::HOURS_TECHNICIAN,
                'eligible_user_ids' => [$this->technician->id, $secondTechnician->id],
                'public_name' => 'Automatic planning',
                'duration_minutes' => 60,
                'slot_step_minutes' => 15,
                'min_notice_hours' => 0,
                'horizon_days' => 30,
                'opening_window_start' => '10:00',
                'opening_window_end' => '15:00',
                'spam_honeypot_field' => 'booking_website',
            ]);

        $response->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');

        $setting = BookingServiceSetting::query()->firstOrFail();

        $this->assertSame(BookingServiceSetting::ROUTING_AUTOMATIC, $setting->technician_routing_mode);
        $this->assertSame(BookingServiceSetting::HOURS_TECHNICIAN, $setting->working_hours_source);
        $this->assertNull($setting->assigned_user_id);
        $this->assertSame('10:00', substr((string) $setting->opening_window_start, 0, 5));
        $this->assertSame('15:00', substr((string) $setting->opening_window_end, 0, 5));
        $this->assertEqualsCanonicalizing(
            [$this->technician->id, $secondTechnician->id],
            $setting->eligibleUsers()->pluck('user_management.id')->all(),
        );
    }

    #[Test]
    public function active_automatic_setting_requires_at_least_one_eligible_technician(): void
    {
        $service = $this->service();

        $this->actingAs($this->admin)
            ->post(route('tech.admin.system.booking.settings.store'), [
                'service_id' => $service->id,
                'status' => BookingServiceSetting::STATUS_ACTIVE,
                'technician_routing_mode' => BookingServiceSetting::ROUTING_AUTOMATIC,
                'working_hours_source' => BookingServiceSetting::HOURS_COMPANY,
                'public_name' => 'Automatic planning',
                'duration_minutes' => 60,
                'slot_step_minutes' => 15,
                'min_notice_hours' => 0,
                'horizon_days' => 30,
            ])
            ->assertSessionHasErrors('eligible_user_ids');

        $this->assertDatabaseCount('booking_service_settings', 0);
    }

    private function bookingSetting(array $overrides = []): BookingServiceSetting
    {
        $service = $overrides['service'] ?? $this->service();
        $eligibleUsers = $overrides['eligible_users'] ?? [];
        unset($overrides['service']);
        unset($overrides['eligible_users']);

        $setting = BookingServiceSetting::query()->create(array_merge([
            'service_id' => $service->id,
            'assigned_user_id' => $this->technician->id,
            'technician_routing_mode' => BookingServiceSetting::ROUTING_FIXED,
            'working_hours_source' => BookingServiceSetting::HOURS_COMPANY,
            'status' => BookingServiceSetting::STATUS_ACTIVE,
            'slug' => 'booking-service-'.Str::lower(Str::random(6)),
            'public_name' => 'Remote planning',
            'public_description' => 'Book a remote planning session.',
            'booking_mode' => BookingServiceSetting::MODE_STAFF_CONFIRMED,
            'duration_minutes' => 60,
            'slot_step_minutes' => 15,
            'min_notice_hours' => 0,
            'horizon_days' => 30,
            'allow_new_clients' => true,
            'spam_honeypot_field' => 'booking_website',
        ], $overrides));

        $setting->eligibleUsers()->sync(collect($eligibleUsers)->map(
            fn (User|int $user): int => $user instanceof User ? $user->id : $user,
        )->all());

        return $setting->load(['service', 'assignedUser', 'eligibleUsers']);
    }

    private function setCompanyHours(string $start, string $end): void
    {
        app(EnsureCalendarDefaults::class)->handle();

        foreach (['default_workday_start' => $start, 'default_workday_end' => $end] as $name => $value) {
            CalendarSetting::query()->updateOrCreate(
                ['scope_type' => 'system', 'scope_id' => null, 'name' => $name],
                ['value' => $value],
            );
        }
    }

    private function busy(User $user, string $startsAt, string $endsAt): CalendarEvent
    {
        $calendar = app(EnsureCalendarDefaults::class)->ensurePersonalCalendar($user);

        return CalendarEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'calendar_id' => $calendar->id,
            'title' => 'Busy booking test',
            'starts_at' => Carbon::parse($startsAt, 'Europe/Oslo')->utc(),
            'ends_at' => Carbon::parse($endsAt, 'Europe/Oslo')->utc(),
            'timezone' => 'Europe/Oslo',
            'status' => 'confirmed',
            'transparency' => 'busy',
            'visibility' => 'public',
            'created_by' => $user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicRequestPayload(string $startsAt, array $overrides = []): array
    {
        return array_merge([
            'slot_starts_at' => $startsAt,
            'timezone' => 'Europe/Oslo',
            'company_name' => 'Example AS',
            'contact_name' => 'Eva Example',
            'contact_email' => 'eva@example.test',
            'contact_phone' => '+4712345678',
            'message' => 'Need planning help.',
            'privacy_acknowledged' => '1',
        ], $overrides);
    }

    private function service(array $overrides = []): Services
    {
        $unit = Units::query()->create([
            'name' => 'Hour',
            'short' => 'h',
            'common_code' => 'HUR',
        ]);

        return Services::query()->create(array_merge([
            'name' => 'Consulting',
            'sku' => 'SVC-'.Str::upper(Str::random(8)),
            'unitId' => $unit->id,
            'status' => 'published',
            'orderable' => true,
            'taxable' => 25,
            'billing_cycle' => 'one_time',
            'price_including_tax' => 1250,
            'price_ex_vat' => 1000,
            'created_by_user_id' => $this->admin->id,
        ], $overrides));
    }
}
