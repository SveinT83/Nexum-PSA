<?php

namespace App\Modules\Asset\Actions;

use App\Models\Tech\Work\Assets\Asset;
use App\Modules\Asset\Support\AssetInput;
use App\Modules\Asset\Support\AssetSettings;
use App\Modules\Asset\Support\AssetWorkContextPayload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Shared manual form/API validation; provider enrichment remains API-compatible on update. */
class SaveManualAsset
{
    public const FIELDS = ['client_id', 'site_id', 'user_id', 'vendor_id', 'name', 'type', 'vendor', 'model',
        'serial_number', 'mac_address', 'ip_address', 'ip_type', 'hostname', 'sensitivity_level', 'criticality_level', 'status'];

    public const ENRICHMENT = ['source', 'is_managed', 'last_seen_at', 'metadata'];

    public function handle(Request $request, ?Asset $asset = null, bool $api = false): Asset
    {
        return DB::transaction(function () use ($request, $asset, $api) {
            // The same mutex protects browser writes and API receipts/revisions.
            DB::table('asset_api_mutex')->where('id', 1)->lockForUpdate()->first();
            $asset = $asset ? Asset::query()->lockForUpdate()->findOrFail($asset->id) : null;
            if ($api) {
                AssetInput::allowed($request, array_merge(self::FIELDS, $asset ? self::ENRICHMENT : []));
                if ($asset) {
                    AssetInput::match($request, $asset->getAttributes());
                }
            }
            $settings = app(AssetSettings::class);
            if (! $asset) {
                $request->merge($settings->manualCreateDefaults($request->all()));
            }
            $required = $asset && $api ? 'sometimes' : 'required';
            $rules = [
                'client_id' => 'sometimes|nullable|integer|exists:clients,id',
                'site_id' => 'sometimes|nullable|integer|exists:client_sites,id',
                'user_id' => 'sometimes|nullable|integer|exists:client_users,id',
                'vendor_id' => 'sometimes|nullable|integer|exists:vendors,id',
                'name' => [$required, 'required', 'string', 'max:255'],
                'type' => [$required, 'required', Rule::in($settings->enabledTypeValues($asset?->type))],
                'ip_type' => [$required, 'required', Rule::in(array_keys(AssetSettings::IP_TYPE_OPTIONS))],
                'ip_address' => 'sometimes|nullable|ip',
                'sensitivity_level' => ['sometimes', 'nullable', Rule::in(Asset::SENSITIVITY_LEVELS)],
                'criticality_level' => ['sometimes', 'nullable', Rule::in(Asset::CRITICALITY_LEVELS)],
                'status' => ['sometimes', 'nullable', Rule::in($settings->statusValues($asset?->status))],
            ];
            foreach (['vendor', 'model', 'serial_number', 'mac_address', 'hostname'] as $key) {
                $rules[$key] = 'sometimes|nullable|string|max:255';
            }
            if ($api && $asset) {
                $rules += ['source' => 'sometimes|nullable|string|max:100', 'is_managed' => 'sometimes|boolean',
                    'last_seen_at' => 'sometimes|nullable|date', 'metadata' => 'sometimes|nullable|array'];
            }
            $data = $request->validate($rules);
            // Omitted ownership survives PATCH; changing Client clears omitted dependent relations.
            if ($asset && array_key_exists('client_id', $data) && (int) $data['client_id'] !== (int) $asset->client_id) {
                $data += ['site_id' => null, 'user_id' => null];
            }
            $data = app(AssetWorkContextPayload::class)->normalize($data, $asset, ! $asset);
            if ($asset) {
                $asset->fill($data)->save();
            } else {
                $asset = Asset::create($data);
            }

            return $asset->refresh();
        });
    }
}
