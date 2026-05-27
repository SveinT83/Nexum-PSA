<?php

namespace App\Modules\UserManagement\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\UserManagement\Actions\UpdateUserPreferences;
use App\Modules\UserManagement\Models\UserPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ProfilePreferencesController extends Controller
{
    public function show(Request $request): View
    {
        $user = $request->user();
        $preferences = UserPreference::query()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'timezone' => config('app.timezone', 'Europe/Oslo'),
                'default_calendar_view' => 'week',
                'workday_start' => '08:00',
                'workday_end' => '16:00',
            ]
        );

        return view('usermanagement::profile.preferences', [
            'preferences' => $preferences,
        ]);
    }

    public function update(Request $request, UpdateUserPreferences $updatePreferences): RedirectResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'timezone'],
            'default_calendar_view' => ['required', Rule::in(['day', 'week', 'month', 'list'])],
            'workday_start' => ['required', 'date_format:H:i'],
            'workday_end' => ['required', 'date_format:H:i', 'after:workday_start'],
        ]);

        $updatePreferences->handle($request->user(), $data);

        return redirect()
            ->route('tech.profile.preferences')
            ->with('success', 'Preferences updated.');
    }
}
