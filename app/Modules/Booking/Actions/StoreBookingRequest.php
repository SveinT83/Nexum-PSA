<?php

namespace App\Modules\Booking\Actions;

use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Booking\Notifications\BookingRequestReceived;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StoreBookingRequest
{
    public function __construct(
        private readonly FindBookingSlots $slots,
        private readonly NotifyBookingCustomer $notifyCustomer,
    ) {}

    public function handle(Request $request, BookingServiceSetting $setting): BookingRequest
    {
        abort_unless($setting->isBookable(), 404);

        $honeypotField = $setting->spam_honeypot_field ?: 'booking_website';
        $honeypot = trim((string) $request->input($honeypotField, ''));

        if ($honeypot !== '') {
            return $this->recordSpam($request, $setting, $honeypot);
        }

        $validated = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['required', 'string', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'contact_phone' => ['nullable', 'string', 'max:80'],
            'message' => ['nullable', 'string', 'max:2000'],
            'slot_starts_at' => ['required', 'date'],
            'timezone' => ['required', 'timezone'],
            'privacy_acknowledged' => ['accepted'],
            $honeypotField => ['nullable', 'max:0'],
        ]);

        $timezone = $validated['timezone'];
        $startsAt = Carbon::parse($validated['slot_starts_at'], $timezone);
        $endsAt = $startsAt->copy()->addMinutes((int) $setting->duration_minutes);

        if (! $this->slots->isSlotAvailable($setting, $startsAt, $endsAt)) {
            throw ValidationException::withMessages([
                'slot_starts_at' => 'The selected booking time is no longer available.',
            ]);
        }

        $bookingRequest = DB::transaction(function () use ($request, $setting, $validated, $startsAt, $endsAt, $timezone): BookingRequest {
            $bookingRequest = BookingRequest::query()->create([
                'booking_key' => $this->uniqueBookingKey(),
                'booking_service_setting_id' => $setting->id,
                'service_id' => $setting->service_id,
                'assigned_user_id' => $setting->assigned_user_id,
                'status' => BookingRequest::STATUS_REQUESTED,
                'booking_mode' => $setting->booking_mode,
                'company_name' => $validated['company_name'] ?? null,
                'contact_name' => $validated['contact_name'],
                'contact_email' => $validated['contact_email'],
                'contact_phone' => $validated['contact_phone'] ?? null,
                'message' => $validated['message'] ?? null,
                'requested_date' => $startsAt->copy()->timezone($timezone)->toDateString(),
                'requested_starts_at' => $startsAt->copy()->utc(),
                'requested_ends_at' => $endsAt->copy()->utc(),
                'timezone' => $timezone,
                'source_url' => $request->fullUrl(),
                'referrer' => $request->headers->get('referer'),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'raw_payload' => [
                    'company_name' => $validated['company_name'] ?? null,
                    'contact_name' => $validated['contact_name'],
                    'contact_email' => $validated['contact_email'],
                    'contact_phone' => $validated['contact_phone'] ?? null,
                    'message' => $validated['message'] ?? null,
                    'slot_starts_at' => $validated['slot_starts_at'],
                    'timezone' => $timezone,
                ],
            ]);

            $bookingRequest->events()->create([
                'type' => 'requested',
                'message' => 'Public booking request submitted.',
                'metadata' => [
                    'booking_service_setting_id' => $setting->id,
                    'service_id' => $setting->service_id,
                ],
            ]);

            return $bookingRequest;
        });

        $this->notifyCustomer->handle(
            $bookingRequest,
            new BookingRequestReceived($bookingRequest),
            'customer_request_notification_sent',
            'customer_requested_notification_sent_at',
        );

        return $bookingRequest->refresh();
    }

    private function recordSpam(Request $request, BookingServiceSetting $setting, string $honeypot): BookingRequest
    {
        $bookingRequest = BookingRequest::query()->create([
            'booking_key' => $this->uniqueBookingKey(),
            'booking_service_setting_id' => $setting->id,
            'service_id' => $setting->service_id,
            'assigned_user_id' => $setting->assigned_user_id,
            'status' => BookingRequest::STATUS_SPAM,
            'booking_mode' => $setting->booking_mode,
            'company_name' => (string) $request->input('company_name'),
            'contact_name' => (string) ($request->input('contact_name') ?: 'Spam submission'),
            'contact_email' => (string) ($request->input('contact_email') ?: 'spam@example.invalid'),
            'contact_phone' => (string) $request->input('contact_phone'),
            'message' => (string) $request->input('message'),
            'timezone' => (string) ($request->input('timezone') ?: 'Europe/Oslo'),
            'source_url' => $request->fullUrl(),
            'referrer' => $request->headers->get('referer'),
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'raw_payload' => $request->except(['_token']),
            'metadata' => ['honeypot_value' => $honeypot],
        ]);

        $bookingRequest->events()->create([
            'type' => 'spam_detected',
            'message' => 'Honeypot field was filled.',
        ]);

        return $bookingRequest;
    }

    private function uniqueBookingKey(): string
    {
        do {
            $key = 'BK-'.now()->format('Ymd').'-'.Str::upper(Str::random(6));
        } while (BookingRequest::query()->where('booking_key', $key)->exists());

        return $key;
    }
}
