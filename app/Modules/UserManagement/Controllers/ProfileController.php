<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Models\UserProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Owns the authenticated user's profile shell.
 *
 * Detailed profile data is intentionally still split across existing
 * controllers until the user profile data-model slice is implemented.
 */
class ProfileController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $profile = $this->profileFor($user);

        return view('usermanagement::profile.index', [
            'user' => $user,
            'profile' => $profile,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique($user->getTable(), 'email')->ignore($user->id),
            ],
            'work_phone' => ['nullable', 'string', 'max:50'],
            'private_phone' => ['nullable', 'string', 'max:50'],
            'timezone' => ['required', 'timezone'],
            'working_hours' => ['nullable', 'array'],
            'working_hours.*.enabled' => ['nullable', 'boolean'],
            'working_hours.*.start' => ['nullable', 'date_format:H:i'],
            'working_hours.*.end' => ['nullable', 'date_format:H:i'],
            'availability_notes' => ['nullable', 'string', 'max:5000'],
            'profile_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $user->forceFill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone_work' => $validated['work_phone'] ?? null,
            'phone_private' => $validated['private_phone'] ?? null,
        ])->save();

        UserProfile::query()->updateOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $validated['work_phone'] ?? null,
                'private_phone' => $validated['private_phone'] ?? null,
                'timezone' => $validated['timezone'],
                'working_hours' => $this->normalizedWorkingHours($validated['working_hours'] ?? []),
                'availability_notes' => $validated['availability_notes'] ?? null,
                'profile_notes' => $validated['profile_notes'] ?? null,
            ]
        );

        return redirect()
            ->route('tech.profile.index')
            ->with('success', 'Profile updated.');
    }

    public function integrations(): View
    {
        return view('usermanagement::profile.integrations');
    }

    public function viewPreferences(): View
    {
        return view('usermanagement::profile.view');
    }

    private function profileFor($user): UserProfile
    {
        $profile = UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $user->phone_work,
                'private_phone' => $user->phone_private,
                'timezone' => config('app.timezone', 'UTC'),
                'working_hours' => $this->defaultWorkingHours(),
            ]
        );

        if (empty($profile->working_hours)) {
            $profile->forceFill(['working_hours' => $this->defaultWorkingHours()])->save();
        }

        return $profile;
    }

    private function normalizedWorkingHours(array $workingHours): array
    {
        return collect($this->defaultWorkingHours())
            ->mapWithKeys(function (array $defaults, string $day) use ($workingHours) {
                $submitted = $workingHours[$day] ?? [];

                return [$day => [
                    'enabled' => (bool) ($submitted['enabled'] ?? false),
                    'start' => $submitted['start'] ?? $defaults['start'],
                    'end' => $submitted['end'] ?? $defaults['end'],
                ]];
            })
            ->all();
    }

    private function defaultWorkingHours(): array
    {
        return collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
            ->mapWithKeys(fn (string $day) => [$day => [
                'enabled' => in_array($day, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], true),
                'start' => '08:00',
                'end' => '16:00',
            ]])
            ->all();
    }
}
