<?php

namespace App\Modules\Booking\Tests\Feature;

use App\Models\Core\User;
use App\Modules\Booking\Controllers\Admin\BookingController as AdminBookingController;
use App\Modules\Booking\Controllers\Public\BookingController as PublicBookingController;
use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Booking\Notifications\BookingRequestConfirmed;
use App\Modules\Booking\Notifications\BookingRequestDeclined;
use App\Modules\Booking\Notifications\BookingRequestReceived;
use App\Modules\Calendar\Actions\EnsureCalendarDefaults;
use App\Modules\Calendar\Models\CalendarEvent;
use App\Modules\Commercial\Models\Economy\Units;
use App\Modules\Commercial\Models\Services\Services;
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

        Carbon::setTestNow(Carbon::parse('2026-07-04 08:00:00', 'Europe/Oslo'));

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

    private function bookingSetting(array $overrides = []): BookingServiceSetting
    {
        $service = $overrides['service'] ?? $this->service();
        unset($overrides['service']);

        return BookingServiceSetting::query()->create(array_merge([
            'service_id' => $service->id,
            'assigned_user_id' => $this->technician->id,
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
