<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Models\Clients\ClientSite;
use App\Models\System\Integrations\Integration;
use App\Modules\Integration\Models\IntegrationHubCapability;
use App\Modules\Integration\Models\IntegrationHubEmergencyControl;
use App\Modules\Integration\Models\IntegrationHubSetting;
use App\Modules\Integration\Services\IntegrationHub\CapabilityRegistry;
use App\Modules\Integration\Services\IntegrationHub\EmergencyControlEvaluator;
use App\Modules\Integration\Services\IntegrationHub\HubAudit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmergencyControlController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $controls = IntegrationHubEmergencyControl::query()
            ->where('installation_key', (string) config('integration-hub.installation_key'))
            ->orderBy('control_key')->paginate(min(50, max(1, $request->integer('per_page', 25))));

        return response()->json([
            'data' => collect($controls->items())->map(fn (IntegrationHubEmergencyControl $control): array => $this->payload($control))->all(),
            'meta' => ['current_page' => $controls->currentPage(), 'per_page' => $controls->perPage(), 'total' => $controls->total()],
        ]);
    }

    public function readiness(CapabilityRegistry $registry): JsonResponse
    {
        $settings = IntegrationHubSetting::current();
        $globalDisabled = IntegrationHubEmergencyControl::query()
            ->where('installation_key', (string) config('integration-hub.installation_key'))
            ->where('control_key', 'global')
            ->where('is_disabled', true)
            ->exists();
        $defined = count($registry->definitions());
        $enabled = IntegrationHubCapability::query()
            ->where('enabled', true)
            ->where('lifecycle_state', 'active')
            ->count();
        $ready = $settings->enabled
            && ! $globalDisabled
            && $enabled === $defined
                       && strlen((string) config('integration-hub.active_grant_key')) >= 32;

        return response()->json(['data' => [
            'status' => $ready ? 'ok' : 'unavailable',
            'hub_enabled' => (bool) $settings->enabled,
            'global_disabled' => $globalDisabled,
            'grant_signing_configured' => strlen((string) config('integration-hub.active_grant_key')) >= 32,
            'capabilities' => ['defined' => $defined, 'enabled' => $enabled],
            'observed_at' => now()->toIso8601String(),
        ]], $ready ? 200 : 503);
    }

    public function store(Request $request, HubAudit $audit): JsonResponse
    {
        $actor = $request->user();
        $validated = $request->validate([
            'scope_type' => ['required', 'in:global,capability,integration,client,site'],
            'scope_id' => ['nullable', 'string', 'max:191'],
            'capability_key' => ['nullable', 'string', 'max:160'],
            'capability_version' => ['nullable', 'regex:/^\d+(?:\.\d+)?$/', 'max:20'],
            'disabled' => ['required', 'boolean'],
            'reason_code' => ['required', 'string', 'regex:/^[a-z0-9_:-]+$/', 'max:120'],
            'reason_summary' => ['nullable', 'string', 'max:500'],
            'correlation_id' => ['nullable', 'uuid'],
        ]);
        $correlation = (string) ($validated['correlation_id'] ?? Str::uuid());
        $resolved = $this->resolveScope($validated);
        $request->attributes->set('integration_hub_correlation_id', $correlation);
        $request->attributes->set('integration_hub_actor_id', $actor->id);
        $request->attributes->set('integration_hub_capability_key', $resolved['capability_key']);
        $request->attributes->set('integration_hub_capability_version', $resolved['capability_version']);
        $request->attributes->set('integration_hub_claims', ['scope' => [
            'client_ids' => $resolved['client_id'] ? [$resolved['client_id']] : [],
            'site_ids' => $resolved['client_site_id'] ? [$resolved['client_site_id']] : [],
            'integration_ids' => $resolved['integration_id'] ? [$resolved['integration_id']] : [],
        ]]);

        [$control, $previousDisabled] = DB::transaction(function () use ($actor, $validated, $resolved, $correlation): array {
            $previous = IntegrationHubEmergencyControl::query()
                ->where('installation_key', (string) config('integration-hub.installation_key'))
                ->where('control_key', $resolved['control_key'])
                ->lockForUpdate()
                ->first();
            $control = IntegrationHubEmergencyControl::query()->updateOrCreate([
                'installation_key' => (string) config('integration-hub.installation_key'),
                'control_key' => $resolved['control_key'],
            ], array_merge($resolved, [
                'is_disabled' => (bool) $validated['disabled'],
                'reason_code' => $validated['reason_code'],
                'reason_summary' => $validated['reason_summary'] ?? null,
                'changed_by' => $actor->id,
                'correlation_id' => $correlation,
                'disabled_at' => $validated['disabled'] ? now() : null,
                'enabled_at' => $validated['disabled'] ? null : now(),
            ]));

            if ($resolved['scope_type'] === 'global' && $validated['disabled']) {
                IntegrationHubSetting::current()->forceFill([
                    'grants_invalid_before' => now(),
                    'updated_by' => $actor->id,
                ])->save();
            }

            return [$control, $previous?->is_disabled];
        });

        $audit->record($request, 'allowed', $control->is_disabled ? 'denied' : 'ok', $control->is_disabled ? 'emergency_control_disabled' : 'emergency_control_enabled', 200, 0, [
            'control_key' => $control->control_key,
            'previous_disabled' => $previousDisabled,
            'new_disabled' => $control->is_disabled,
            'operator_reason_code' => $validated['reason_code'],
        ]);

        return response()->json(['data' => $this->payload($control)]);
    }

    /** @param array<string, mixed> $input @return array<string, mixed> */
    private function resolveScope(array $input): array
    {
        $type = $input['scope_type'];
        $scopeId = $input['scope_id'] ?? null;
        $resolved = [
            'scope_type' => $type, 'scope_id' => $scopeId, 'capability_key' => null,
            'capability_version' => null, 'integration_id' => null, 'client_id' => null,
            'client_site_id' => null,
        ];

        if ($type === 'global') {
            $resolved['scope_id'] = null;
            $resolved['control_key'] = 'global';
        } elseif ($type === 'capability') {
            $key = (string) ($input['capability_key'] ?? '');
            $version = (string) ($input['capability_version'] ?? '');
            abort_unless(IntegrationHubCapability::query()->where('capability_key', $key)->where('contract_version', $version)->exists(), 422);
            $resolved['capability_key'] = $key;
            $resolved['capability_version'] = $version;
            $resolved['scope_id'] = $key.'@'.$version;
            $resolved['control_key'] = EmergencyControlEvaluator::capabilityKey($key, $version);
        } elseif ($type === 'integration') {
            abort_unless(is_string($scopeId) && Integration::query()->where('installation_key', (string) config('integration-hub.installation_key'))->whereKey($scopeId)->exists(), 422);
            $resolved['integration_id'] = $scopeId;
            $resolved['control_key'] = 'integration:'.$scopeId;
        } elseif ($type === 'client') {
            abort_unless(is_numeric($scopeId) && Client::query()->whereKey((int) $scopeId)->exists(), 422);
            $resolved['client_id'] = (int) $scopeId;
            $resolved['scope_id'] = (string) (int) $scopeId;
            $resolved['control_key'] = 'client:'.(int) $scopeId;
        } else {
            abort_unless(is_numeric($scopeId) && ClientSite::query()->whereKey((int) $scopeId)->exists(), 422);
            $resolved['client_site_id'] = (int) $scopeId;
            $resolved['scope_id'] = (string) (int) $scopeId;
            $resolved['control_key'] = 'site:'.(int) $scopeId;
        }

        return $resolved;
    }

    /** @return array<string, mixed> */
    private function payload(IntegrationHubEmergencyControl $control): array
    {
        return [
            'id' => $control->id, 'control_key' => $control->control_key,
            'scope' => ['type' => $control->scope_type, 'id' => $control->scope_id],
            'capability' => $control->capability_key ? ['key' => $control->capability_key, 'version' => $control->capability_version] : null,
            'disabled' => $control->is_disabled, 'reason_code' => $control->reason_code,
            'reason_summary' => $control->reason_summary, 'changed_by' => $control->changed_by,
            'correlation_id' => $control->correlation_id,
            'changed_at' => $control->updated_at?->toIso8601String(),
        ];
    }
}
