<?php

namespace App\Modules\Contact\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Contact\Support\ContactSettings;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactSettingsController extends Controller
{
    public function edit(ContactSettings $settings)
    {
        return view('contact::Admin.Settings.edit', [
            'settings' => $settings->get(),
            'contactTypeOptions' => ContactSettings::CONTACT_TYPE_OPTIONS,
            'statusOptions' => ContactSettings::STATUS_OPTIONS,
            'relationOptions' => ContactSettings::RELATION_OPTIONS,
        ]);
    }

    public function update(Request $request, ContactSettings $settings)
    {
        $validated = $request->validate([
            'default_contact_type' => ['required', 'string', Rule::in(array_keys(ContactSettings::CONTACT_TYPE_OPTIONS))],
            'default_status' => ['required', 'string', Rule::in(array_keys(ContactSettings::STATUS_OPTIONS))],
            'enabled_relation_types' => ['required', 'array', 'min:1'],
            'enabled_relation_types.*' => ['string', Rule::in(array_keys(ContactSettings::RELATION_OPTIONS))],
            'default_relation_type' => ['required', 'string', Rule::in((array) $request->input('enabled_relation_types', []))],
        ]);

        $settings->update($validated);

        return redirect()
            ->route('tech.admin.settings.contacts')
            ->with('success', 'Contact settings were updated.');
    }
}
