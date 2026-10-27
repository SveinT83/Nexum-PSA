<?php

namespace App\Modules\Booking\Support;

use App\Modules\Booking\Models\BookingRequest;
use App\Modules\Booking\Models\BookingServiceSetting;
use Illuminate\Support\Facades\DB;

/** Explicit projections keep raw source, arbitrary metadata and private Calendar content out of JSON. */
class BookingApiPayload
{
    public const SETTING_FIELDS = ['service_id', 'assigned_user_id', 'technician_routing_mode', 'working_hours_source',
        'status', 'slug', 'public_name', 'public_description', 'duration_minutes', 'slot_step_minutes', 'min_notice_hours',
        'horizon_days', 'opening_window_start', 'opening_window_end', 'location', 'instructions', 'spam_honeypot_field'];

    public static function setting(BookingServiceSetting $setting): array
    {
        $setting->loadMissing(['service', 'assignedUser', 'eligibleUsers']);
        $data = ['id' => $setting->id] + $setting->only(self::SETTING_FIELDS);
        $data['eligible_user_ids'] = $setting->eligibleUsers->pluck('id')->sort()->values()->all();
        $data['revision'] = BookingApiInput::revision($data);
        $data['booking_mode'] = $setting->booking_mode;
        $data['allow_new_clients'] = (bool) $setting->allow_new_clients;
        $data['is_bookable'] = $setting->isBookable();
        $data['service_name'] = $setting->service?->name;
        $data['requests_count'] = (int) ($setting->requests_count ?? $setting->requests()->count());

        return $data;
    }

    public static function publicSetting(BookingServiceSetting $setting): array
    {
        return $setting->only(['slug', 'public_name', 'public_description', 'duration_minutes', 'slot_step_minutes',
            'min_notice_hours', 'horizon_days', 'opening_window_start', 'opening_window_end', 'location', 'instructions', 'technician_routing_mode']);
    }

    public static function bookingRequest(BookingRequest $booking): array
    {
        $booking->loadMissing(['setting', 'assignedUser']);
        $data = $booking->only(['id', 'booking_key', 'status', 'booking_service_setting_id', 'service_id', 'assigned_user_id',
            'calendar_event_id', 'company_name', 'contact_name', 'contact_email', 'contact_phone', 'message', 'timezone',
            'decline_reason', 'confirmed_by', 'declined_by']);
        foreach (['requested_starts_at', 'requested_ends_at', 'confirmed_at', 'declined_at', 'created_at', 'updated_at'] as $field) {
            $data[$field] = $booking->$field?->toIso8601String();
        }
        $data['service_name'] = $booking->setting?->publicTitle();
        $data['technician_name'] = $booking->assignedUser?->name;
        $data['revision'] = BookingApiInput::revision([$booking->id, $booking->status, $booking->assigned_user_id,
            $booking->calendar_event_id, $booking->updated_at?->format('Y-m-d H:i:s.u')]);
        $data['notifications'] = DB::table('booking_notification_dispatches')->where('booking_request_id', $booking->id)
            ->orderBy('id')->get(['kind', 'state', 'claimed_at', 'submitted_at'])->map(fn ($row) => (array) $row)->all();

        return $data;
    }
}
