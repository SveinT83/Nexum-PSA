<?php

namespace App\Modules\Booking\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Core\User;
use App\Modules\Booking\Models\BookingServiceSetting;
use App\Modules\Commercial\Models\Services\Services;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $data['slug'] = $this->uniqueSlug($data['slug'] ?: $data['public_name']);
        $data['booking_mode'] = BookingServiceSetting::MODE_STAFF_CONFIRMED;
        $data['allow_new_clients'] = true;
        $data['spam_honeypot_field'] = $data['spam_honeypot_field'] ?: 'booking_website';

        $setting = BookingServiceSetting::query()->create($data);

        return redirect()
            ->route('tech.admin.system.booking.settings.edit', $setting)
            ->with('success', 'Booking service setting created.');
    }

    public function edit(BookingServiceSetting $setting): View
    {
        return view('booking::Admin.settings.edit', [
            'setting' => $setting->load(['service', 'assignedUser']),
            'services' => $this->serviceOptions($setting),
            'users' => $this->userOptions(),
        ]);
    }

    public function update(Request $request, BookingServiceSetting $setting): RedirectResponse
    {
        $data = $this->validated($request, $setting);
        $data['slug'] = $data['slug'] ?: $setting->slug;
        $data['booking_mode'] = BookingServiceSetting::MODE_STAFF_CONFIRMED;
        $data['allow_new_clients'] = true;
        $data['spam_honeypot_field'] = $data['spam_honeypot_field'] ?: 'booking_website';

        $setting->update($data);

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
            'assigned_user_id' => ['nullable', 'integer', Rule::exists('user_management', 'id')],
            'status' => ['required', Rule::in([
                BookingServiceSetting::STATUS_DRAFT,
                BookingServiceSetting::STATUS_ACTIVE,
                BookingServiceSetting::STATUS_ARCHIVED,
            ])],
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
            'location' => ['nullable', 'string', 'max:255'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'spam_honeypot_field' => ['nullable', 'string', 'max:80', 'alpha_dash'],
        ]);
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
