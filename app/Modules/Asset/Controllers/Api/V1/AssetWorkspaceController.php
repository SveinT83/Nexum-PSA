<?php

namespace App\Modules\Asset\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\Clients\ClientUser;
use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Modules\Asset\Actions\ExecuteAssetCommand;
use App\Modules\Asset\Actions\UpdateAssetSettings;
use App\Modules\Asset\Models\AssetApiCommand;
use App\Modules\Asset\Support\AssetApiAccess;
use App\Modules\Asset\Support\AssetInput;
use App\Modules\Asset\Support\AssetSettings;
use App\Modules\Documentation\Models\Vendor;
use Illuminate\Http\Request;

/** Browser context, safe reference projections, alert tabs and administration. */
class AssetWorkspaceController extends Controller
{
    public function context(Request $request)
    {
        AssetInput::allowed($request, ['asset_id']);
        $request->validate(['asset_id' => 'sometimes|integer|exists:assets,id']);
        $asset = $request->filled('asset_id') ? Asset::findOrFail($request->integer('asset_id')) : null;
        $settings = app(AssetSettings::class);

        return ['data' => ['defaults' => $settings->get(), 'types' => $settings->typeOptions($asset?->type),
            'statuses' => $settings->statusOptions($asset?->status), 'ip_types' => $settings->ipTypeOptions(),
            'sensitivity_levels' => Asset::SENSITIVITY_LEVELS, 'criticality_levels' => Asset::CRITICALITY_LEVELS,
            'sorts' => ['name', 'type', 'client_site', 'status', 'last_seen_at'],
            'provider_execution_enabled' => (bool) config('assets-api.provider_execution_enabled'),
            'related_tickets_supported' => false]];
    }

    public function lookups(Request $request, string $kind)
    {
        AssetInput::allowed($request, ['client_id', 'search', 'page', 'per_page']);
        $request->validate(['client_id' => 'sometimes|nullable|integer|exists:clients,id',
            'search' => 'sometimes|string|max:255', 'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $model = match ($kind) {
            'clients' => Client::class, 'sites' => ClientSite::class, 'owners' => ClientUser::class, 'vendors' => Vendor::class, default => abort(404)
        };
        $query = $model::query();
        if ($kind === 'sites') {
            $query->where('client_id', $request->integer('client_id', 0));
        }
        if ($kind === 'owners') {
            $query->whereHas('site', fn ($q) => $q->where('client_id', $request->integer('client_id', 0)));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%'.$request->input('search').'%');
        }

        return $query->select('id', 'name')->orderBy('name')->orderBy('id')->paginate($request->integer('per_page', 25));
    }

    public function docs(Request $request)
    {
        AssetInput::allowed($request, []);

        return ['data' => ['format' => 'markdown', 'content' => file_get_contents(app_path('Modules/Asset/Docs/legacy-view-specs/Tech/assets.md'))]];
    }

    private function alertsQuery(Request $request)
    {
        $request->validate(['client_id' => 'sometimes|integer|exists:clients,id', 'asset_id' => 'sometimes|integer|exists:assets,id',
            'status' => 'sometimes|in:active,resolved', 'unresolved_only' => 'sometimes|boolean',
            'page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);

        return AssetAlert::query()->when($request->filled('client_id'), fn ($q) => $q->whereHas('asset', fn ($a) => $a->where('client_id', $request->integer('client_id'))))
            ->when($request->filled('asset_id'), fn ($q) => $q->where('asset_id', $request->integer('asset_id')));
    }

    public function alerts(Request $request)
    {
        AssetInput::allowed($request, ['client_id', 'asset_id', 'status', 'unresolved_only', 'page', 'per_page']);
        $query = $this->alertsQuery($request)->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->boolean('unresolved_only'), fn ($q) => $q->whereNull('resolved_at'));

        return $query->select(['id', 'asset_id', 'integration_type', 'external_check_id', 'external_alert_id', 'fingerprint',
            'title', 'message', 'status', 'severity', 'first_seen_at', 'last_seen_at', 'resolved_at', 'created_at', 'updated_at'])
            ->orderByDesc('last_seen_at')->orderByDesc('id')->paginate($request->integer('per_page', 25));
    }

    public function summary(Request $request)
    {
        AssetInput::allowed($request, ['client_id', 'asset_id']);
        $query = $this->alertsQuery($request);

        return ['data' => ['active_count' => (clone $query)->where('status', 'active')->count(),
            'resolved_last_day_count' => (clone $query)->where('status', 'resolved')->where('resolved_at', '>=', now()->subDay())->count()]];
    }

    public function settings(Request $request)
    {
        AssetInput::allowed($request, []);
        $value = app(AssetSettings::class)->get();

        return response()->json(['data' => $value, 'revision' => AssetInput::revision($value)])->header('ETag', AssetInput::revision($value));
    }

    public function updateSettings(Request $request, ExecuteAssetCommand $commands)
    {
        AssetInput::allowed($request, ['enabled_types', 'default_type', 'default_ip_type', 'default_status']);
        $result = $commands->handle($request, 'settings', function () use ($request) {
            AssetInput::match($request, app(AssetSettings::class)->get());
            $value = app(UpdateAssetSettings::class)->handle($request);

            return ['data' => $value, 'revision' => AssetInput::revision($value)];
        });

        return $result['result'] + ['operation_id' => $result['operation_id'], 'replayed' => $result['replayed']];
    }

    public function operation(Request $request, string $operation)
    {
        AssetInput::allowed($request, []);
        $actor = AssetApiAccess::actor();
        $command = AssetApiCommand::whereKey($operation)->where('actor_id', $actor->id)
            ->where('token_hash', hash('sha256', (string) ($actor->currentAccessToken()?->getKey() ?? 'session')))->firstOrFail();
        $permission = match (true) {
            $command->operation === 'create' => 'asset.create',
            str_starts_with($command->operation, 'update:') => 'asset.update',
            $command->operation === 'settings' => 'asset.manage_settings', default => 'asset.view'
        };
        AssetApiAccess::actor($permission);
        $scope = match ($permission) {
            'asset.create' => 'assets.create', 'asset.update' => 'assets.update',
            'asset.manage_settings' => 'assets.settings.update', default => 'assets.sync'
        };
        abort_unless($actor->tokenCan($scope), 403);
        if ($scope === 'assets.sync') {
            AssetApiAccess::actor('asset.update');
            if (str_starts_with($command->operation, 'rmm')) {
                AssetApiAccess::actor('integration.rmm_manage');
            }
        }

        return ['data' => ['id' => $command->id, 'operation' => $command->operation,
            'state' => $command->state, 'result' => $command->result,
            'reconciliation_required' => in_array($command->state, ['running', 'unknown'], true),
            'created_at' => $command->created_at, 'updated_at' => $command->updated_at]];
    }
}
