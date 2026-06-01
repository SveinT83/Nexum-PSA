<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\UpdateUserProfile;
use App\Modules\UserManagement\Models\UserProfile;
use App\Modules\UserManagement\Support\UserProfileData;
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

    public function update(Request $request, UpdateUserProfile $updateProfile): RedirectResponse
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
            'avatar' => ['nullable', 'image', 'max:2048'],
            'remove_avatar' => ['nullable', 'boolean'],
        ]);

        $validated['remove_avatar'] = $request->boolean('remove_avatar');
        $updateProfile->handle($user, $validated, $request->file('avatar'));

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
                'working_hours' => UserProfileData::defaultWorkingHours(),
            ]
        );

        if (empty($profile->working_hours)) {
            $profile->forceFill(['working_hours' => UserProfileData::defaultWorkingHours()])->save();
        }

        return $profile;
    }
}
