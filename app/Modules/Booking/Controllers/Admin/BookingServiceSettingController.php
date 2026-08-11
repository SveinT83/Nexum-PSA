<?php

namespace App\Modules\Booking\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class BookingServiceSettingController extends Controller
{
    public function create(): View
    {
        return view('booking::Admin.settings.create', [
            'setting' => new BookingServiceSetting([
                'status' => BookingServiceSetting::STATUS_DRAFT,
                'booking_mode' => BookingServiceSetting::MODE_STAFF_CONFIRMED,
                'technician_routing_mode' => BookingServiceSetting::ROUTING_FIXED,
                'working_hours_source' => BookingServiceSetting::HOURS_COMPANY,
                'duration_minutes' => 60,
                'slot_step_minutes' => 15,
                'min_notice_hours' => 24,
                'horizon_days' => 30,
                'allow_new_clients' => true,
                'spam_honeypot_field' => 'booking_website',
            ]),
            'services' => $this->serviceOptions(),
            'users' => $this->userOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $eligibleUserIds = $data['eligible_user_ids'] ?? [];
        unset($data['eligible_user_ids']);

        $data['slug'] = $this->uniqueSlug(($data['slug'] ?? null) ?: $data['public_name']);
        $data['booking_mode'] = BookingServiceSetting::MODE_STAFF_CONFIRMED;
        $data['allow_new_clients'] = true;
        $data['spam_honeypot_field'] = ($data['spam_honeypot_field'] ?? null) ?: 'booking_website';
        $this->normalizeTechnicianConfiguration($data, $eligibleUserIds);

        $setting = DB::transaction(function () use ($data, $eligibleUserIds): BookingServiceSetting {
            $setting = BookingServiceSetting::query()->create($data);
            $setting->eligibleUsers()->sync($eligibleUserIds);

            return $setting;
        });

        return redirect()
            ->route('tech.admin.system.booking.settings.edit', $setting)
            ->with('success', 'Booking service setting created.');
    }

    public function edit(BookingServiceSetting $setting): View
    {
        return view('booking::Admin.settings.edit', [
            'setting' => $setting->load(['service', 'assignedUser', 'eligibleUsers']),
            'services' => $this->serviceOptions($setting),
            'users' => $this->userOptions(),
        ]);
    }

    public function update(Request $request, BookingServiceSetting $setting): RedirectResponse
    {
        $data = $this->validated($request, $setting);
        $eligibleUserIds = $data['eligible_user_ids'] ?? [];
        unset($data['eligible_user_ids']);

        $data['slug'] = ($data['slug'] ?? null) ?: $setting->slug;
        $data['booking_mode'] = BookingServiceSetting::MODE_STAFF_CONFIRMED;
        $data['allow_new_clients'] = true;
        $data['spam_honeypot_field'] = ($data['spam_honeypot_field'] ?? null) ?: 'booking_website';
        $this->normalizeTechnicianConfiguration($data, $eligibleUserIds);

        DB::transaction(function () use ($setting, $data, $eligibleUserIds): void {
            $setting->update($data);
            $setting->eligibleUsers()->sync($eligibleUserIds);
        });

        return redirect()
            ->route('tech.admin.system.booking.settings.edit', $setting)
            ->with('success', 'Booking service setting updated.');
    }

    public function toggle(BookingServiceSetting $setting): RedirectResponse
    {
        $setting->update([
            'status' => $setting->isActive()
                ? BookingServiceSetting::STATUS_DRAFT
                : BookingServiceSetting::STATUS_ACTIVE,
        ]);

        return redirect()
            ->route('tech.admin.system.booking.index')
            ->with('success', 'Booking service setting status updated.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?BookingServiceSetting $setting = null): array
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

    private function serviceOptions(?BookingServiceSetting $current = null)
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

    private function userOptions()
    {
        return User::query()
            ->where('status', User::STATUS_ACTIVE)
            ->orderBy('name')
            ->get(['id', 'name', 'email']);
    }
}
