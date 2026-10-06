<?php

namespace App\Modules\Workday\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Support\WorkdayAccess;
use App\Modules\Workday\Support\WorkdaySettings;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WorkdaySettingsController extends Controller
{
    public function __construct(private WorkdayAccess $access, private MutateWorkday $mutate) {}

    private function api(Request $request): bool
    {
        return $request->routeIs('api.*');
    }

    #[OA\Get(path: '/api/v1/workday-settings', operationId: 'workdaySettings', summary: 'Read Workday settings', description: 'Requires workdays.settings plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.settings']], tags: ['Workday'],
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdaySettingsResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function settings(Request $request)
    {
        $this->access->authorize($request->user(), 'manage_settings', true);
        $settings = app(WorkdaySettings::class)->read();

        return $this->api($request) ? response()->json(['data' => $settings]) : view('workday::Admin.settings', compact('settings'));
    }

    #[OA\Patch(path: '/api/v1/workday-settings', operationId: 'workdayUpdatesettings', summary: 'Update Workday settings', description: 'Requires workdays.settings plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing. Mutations require the current settings version and a unique idempotency key.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.settings']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Unique per employee. Retry identical payloads with the same key; reuse for different operations or bodies returns 409. Receipts expire with the work date.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdaySettingsInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdaySettingsResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function updateSettings(Request $request)
    {
        $result = $this->mutate->handle($request->user(), 'settings', 'manual_workflow',
            $request->except(['_token', '_method', 'request_key']),
            (string) ($this->api($request) ? $request->header('Idempotency-Key', '') : $request->input('request_key', '')),
            $this->api($request) ? 'api' : 'ui');

        return $this->api($request) ? response()->json($result)
            : redirect()->route('tech.admin.settings.workday')->with('success', 'Workday settings saved.');
    }
}
