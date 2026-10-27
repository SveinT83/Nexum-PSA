<?php

namespace App\Modules\Booking\Actions;

use App\Models\Core\User;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveBookingServiceSetting
{
    public function defaults(): array
    {
        return ['status' => 'draft', 'booking_mode' => 'staff_confirmed', 'technician_routing_mode' => 'fixed',
            'working_hours_source' => 'company', 'duration_minutes' => 60, 'slot_step_minutes' => 15,
            'min_notice_hours' => 24, 'horizon_days' => 30, 'allow_new_clients' => true, 'spam_honeypot_field' => 'booking_website'];
    }

    public function handle(Request $request, ?BookingServiceSetting $setting = null): BookingServiceSetting
    {
        return DB::transaction(function () use ($request, $setting) {
            // Serialize unique service/slug allocation with other setting and public claims.
            DB::table('calendar_booking_write_mutex')->where('id', 1)->lockForUpdate()->first();
            $data = $this->validated($request, $setting);
            $ids = $data['eligible_user_ids'] ?? [];
            unset($data['eligible_user_ids']);
            $data['slug'] = $setting ? (($data['slug'] ?? null) ?: $setting->slug)
                : $this->uniqueSlug(($data['slug'] ?? null) ?: $data['public_name']);
            $data['booking_mode'] = BookingServiceSetting::MODE_STAFF_CONFIRMED;
            $data['allow_new_clients'] = true;
            $data['spam_honeypot_field'] = ($data['spam_honeypot_field'] ?? null) ?: 'booking_website';
            $this->normalizeTechnicianConfiguration($data, $ids);
            $setting ??= new BookingServiceSetting;
            $setting->fill($data)->save();
            $setting->eligibleUsers()->sync($ids);

            return $setting->refresh();
        });
    }

    public function toggle(BookingServiceSetting $setting): BookingServiceSetting
    {
        return DB::transaction(function () use ($setting) {
            DB::table('calendar_booking_write_mutex')->where('id', 1)->lockForUpdate()->first();
            $setting = BookingServiceSetting::query()->lockForUpdate()->findOrFail($setting->id);
            $setting->update(['status' => $setting->isActive() ? 'draft' : 'active']);

            return $setting->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    public function validated(Request $request, ?BookingServiceSetting $setting = null): array
    {
        $settingId = $setting?->id;

        return $request->validate([
            'service_id' => [
                'required',
                'integer',
                Rule::exists('services', 'id'),
                Rule::unique('booking_service_settings', 'service_id')->ignore($settingId),
            ],
            'status' => ['required', Rule::in([
                BookingServiceSetting::STATUS_DRAFT,
                BookingServiceSetting::STATUS_ACTIVE,
                BookingServiceSetting::STATUS_ARCHIVED,
            ])],
            'technician_routing_mode' => ['required', Rule::in([
                BookingServiceSetting::ROUTING_FIXED,
                BookingServiceSetting::ROUTING_AUTOMATIC,
                BookingServiceSetting::ROUTING_CUSTOMER_CHOICE,
            ])],
            'working_hours_source' => ['required', Rule::in([
                BookingServiceSetting::HOURS_COMPANY,
                BookingServiceSetting::HOURS_TECHNICIAN,
            ])],
            'assigned_user_id' => [
                'nullable',
                Rule::requiredIf(fn (): bool => $request->input('status') === BookingServiceSetting::STATUS_ACTIVE
                    && $request->input('technician_routing_mode') === BookingServiceSetting::ROUTING_FIXED),
                'integer',
                Rule::exists('user_management', 'id')->where('status', User::STATUS_ACTIVE),
            ],
            'eligible_user_ids' => [
                Rule::requiredIf(fn (): bool => $request->input('status') === BookingServiceSetting::STATUS_ACTIVE
                    && in_array($request->input('technician_routing_mode'), [BookingServiceSetting::ROUTING_AUTOMATIC, BookingServiceSetting::ROUTING_CUSTOMER_CHOICE], true)),
                'array',
                'min:1',
            ],
            'eligible_user_ids.*' => [
                'integer',
                Rule::exists('user_management', 'id')->where('status', User::STATUS_ACTIVE),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:120',
                'alpha_dash',
                Rule::unique('booking_service_settings', 'slug')->ignore($settingId),
            ],
            'public_name' => ['required', 'string', 'max:255'],
            'public_description' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:15', 'max:480'],
            'slot_step_minutes' => ['required', 'integer', Rule::in([15, 30, 60])],
            'min_notice_hours' => ['required', 'integer', 'min:0', 'max:720'],
            'horizon_days' => ['required', 'integer', 'min:1', 'max:365'],
            'opening_window_start' => ['nullable', 'required_with:opening_window_end', 'date_format:H:i', 'regex:/^(?:[01]\d|2[0-3]):(?:00|15|30|45)$/'],
            'opening_window_end' => ['nullable', 'required_with:opening_window_start', 'date_format:H:i', 'after:opening_window_start', 'regex:/^(?:[01]\d|2[0-3]):(?:00|15|30|45)$/'],
            'location' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'spam_honeypot_field' => ['nullable', 'string', 'max:80', 'alpha_dash'],
        ]);
    }

    private function normalizeTechnicianConfiguration(array &$data, array &$eligibleUserIds): void
    {
        $eligibleUserIds = array_values(array_unique(array_map('intval', $eligibleUserIds)));

        if (($data['technician_routing_mode'] ?? null) === BookingServiceSetting::ROUTING_FIXED) {
            $eligibleUserIds = [];
        } else {
            $data['assigned_user_id'] = null;
        }
    }

    private function uniqueSlug(string $value): string
    {
        $base = Str::slug($value) ?: 'booking-service';
        $slug = $base;
        $i = 2;

        while (BookingServiceSetting::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i;
            $i++;
        }

        return $slug;
    }

    public function serviceOptions(?BookingServiceSetting $current = null)
    {
        $configuredServiceIds = BookingServiceSetting::query()
            ->when($current?->service_id, fn ($query) => $query->where('service_id', '!=', $current->service_id))
            ->pluck('service_id')
            ->filter()
            ->all();

        return Services::query()
            ->where('orderable', true)
            ->when(! empty($configuredServiceIds), fn ($query) => $query->whereNotIn('id', $configuredServiceIds))
            ->orderBy('name')
            ->get();
    }

    public function userOptions()
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
