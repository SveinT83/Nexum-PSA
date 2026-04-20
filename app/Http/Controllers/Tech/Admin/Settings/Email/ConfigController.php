<?php

namespace App\Http\Controllers\Tech\Admin\Settings\Email;

use App\Http\Controllers\Controller;
use App\Models\Settings\CommonSetting;
use Illuminate\Http\Request;

class ConfigController extends Controller
{
    public function index()
    {
        $settings = CommonSetting::where('type', 'emailhub')->get()->pluck('value', 'name')->toArray();

        // Merge with defaults
        $config = array_merge([
            'poll_interval' => 1,
            'concurrency' => 2,
            'batch_size' => 20,
            'delete_on_success' => '1', // '1' or '0'
            'size_limit_mb' => 25,
            'retention_months' => 24,
            'pause_ingest' => '0',
            'max_failures' => 3,
        ], $settings);

        return view('tech.admin.settings.email.config.index', compact('config'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'poll_interval' => 'required|integer|min:1',
            'concurrency' => 'required|integer|min:1',
            'batch_size' => 'required|integer|min:1',
            'delete_on_success' => 'sometimes|boolean',
            'size_limit_mb' => 'required|integer|min:1',
            'retention_months' => 'required|integer|min:1',
            'pause_ingest' => 'sometimes|boolean',
            'max_failures' => 'required|integer|min:1',
        ]);

        foreach ($data as $key => $value) {
            CommonSetting::updateOrCreate(
                ['type' => 'emailhub', 'name' => $key],
                ['value' => (string)$value]
            );
        }

        // Handle booleans not present in request
        if (!$request->has('delete_on_success')) {
            CommonSetting::updateOrCreate(['type' => 'emailhub', 'name' => 'delete_on_success'], ['value' => '0']);
        }
        if (!$request->has('pause_ingest')) {
            CommonSetting::updateOrCreate(['type' => 'emailhub', 'name' => 'pause_ingest'], ['value' => '0']);
        }

        return redirect()->route('tech.admin.settings.email.config')
            ->with('status', 'Email configuration updated successfully.');
    }
}
