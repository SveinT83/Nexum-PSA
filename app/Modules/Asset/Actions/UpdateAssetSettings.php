<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Support\AssetSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** One validated administrative settings write for browser and API. */
class UpdateAssetSettings
{
    public function handle(Request $request): array
    {
        return DB::transaction(function () use ($request) {
            DB::table('asset_api_mutex')->where('id', 1)->lockForUpdate()->first();
            $data = $request->validate([
                'enabled_types' => ['required', 'array', 'min:1'],
                'enabled_types.*' => ['required', 'string', 'distinct', Rule::in(array_keys(AssetSettings::TYPE_OPTIONS))],
                'default_type' => ['required', 'string', Rule::in((array) $request->input('enabled_types', []))],
                'default_ip_type' => ['required', Rule::in(array_keys(AssetSettings::IP_TYPE_OPTIONS))],
                'default_status' => ['required', Rule::in(array_keys(AssetSettings::STATUS_OPTIONS))],
            ]);

            return app(AssetSettings::class)->update($data);
        });
    }
}
