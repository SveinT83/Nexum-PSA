<?php

namespace App\Modules\Warroom\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Warroom\Actions\SaveWarroomSettings;
use Illuminate\Http\Request;

class WarroomSettingsController extends Controller
{
    public function edit(SaveWarroomSettings $action)
    {
        $snapshot = $action->snapshot();

        return view('warroom::Admin.Settings.edit', ['settings' => $snapshot['settings'],
            'revision' => $snapshot['revision'], 'sectionOptions' => $snapshot['section_options']]);
    }

    public function update(Request $request, SaveWarroomSettings $action)
    {
        // CSRF/method/revision belong to transport; every business field uses the shared validator.
        $action->execute($request->user(), $request->except(['_token', '_method', 'revision']), $request->input('revision'));

        return redirect()->route('tech.admin.settings.warroom')->with('success', 'Warroom settings were updated.');
    }
}
