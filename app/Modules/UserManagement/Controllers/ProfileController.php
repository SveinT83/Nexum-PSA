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
        return UserProfile::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'work_phone' => $user->phone_work,
                'private_phone' => $user->phone_private,
                'timezone' => config('app.timezone', 'UTC'),
            ]
        );
    }
}
