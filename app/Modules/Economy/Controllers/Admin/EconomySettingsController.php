<?php

namespace App\Modules\Economy\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class EconomySettingsController extends Controller
{
    public function index(EnsureEconomyDefaults $defaults): View
    {
        return view('economy::Admin.settings', [
            'settings' => $defaults->effective(),
        ]);
    }

    public function update(Request $request, EnsureEconomyDefaults $defaults): RedirectResponse
    {
        $data = $request->validate(\App\Modules\Economy\Actions\UpdateEconomySettings::rules(false));
        foreach (\App\Modules\Economy\Actions\UpdateEconomySettings::BOOLEANS as $field) {
            $data[$field] = $request->boolean($field);
        }
        app(\App\Modules\Economy\Actions\UpdateEconomySettings::class)->handle($data);

        return redirect()->route('tech.admin.settings.economy')
            ->with('success', 'Economy settings updated.');
    }
}
