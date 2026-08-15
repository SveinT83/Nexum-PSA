<?php

namespace App\Modules\Integration\Controllers\Api\V1\IntegrationHub;

use App\Http\Controllers\Controller;
use App\Modules\Integration\Exceptions\IntegrationHubDeniedException;
use App\Modules\Integration\Services\IntegrationHub\GrantIssuer;
use App\Modules\Integration\Services\IntegrationHub\HubAudit;
use App\Modules\Integration\Support\IntegrationHubResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class GrantController extends Controller
{
    public function store(Request $request, GrantIssuer $issuer, HubAudit $audit): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'capability_key' => ['required', 'string', 'max:160'],
            'capability_version' => ['nullable', 'regex:/^\d+(?:\.\d+)?$/', 'max:20'],
            'correlation_id' => ['nullable', 'uuid'],
            'ttl_seconds' => ['nullable', 'integer', 'min:30', 'max:300'],
            'scope' => ['nullable', 'array'],
            'scope.client_ids' => ['nullable', 'array', 'max:100'],
            'scope.client_ids.*' => ['integer', 'distinct'],
            'scope.site_ids' => ['nullable', 'array', 'max:100'],
            'scope.site_ids.*' => ['integer', 'distinct'],
            'scope.integration_ids' => ['nullable', 'array', 'max:50'],
            'scope.integration_ids.*' => ['uuid', 'distinct'],
            'scope.environment' => ['nullable', 'string', 'in:production,staging,development,test,unknown'],
        ]);

        $requestedCorrelation = (string) $request->input('correlation_id', '');
        $correlation = Str::isUuid($requestedCorrelation) ? $requestedCorrelation : (string) Str::uuid();
        $capabilityKey = is_string($request->input('capability_key'))
            ? mb_substr((string) $request->input('capability_key'), 0, 160)
            : null;
        $capabilityVersion = is_string($request->input('capability_version'))
            ? mb_substr((string) $request->input('capability_version'), 0, 20)
            : '1.0';
        $request->attributes->set('integration_hub_correlation_id', $correlation);
        $request->attributes->set('integration_hub_capability_key', $capabilityKey);
        $request->attributes->set('integration_hub_capability_version', $capabilityVersion);
        $startedAt = hrtime(true);

        if ($validator->fails()) {
            $fields = array_values(array_slice(array_keys($validator->errors()->toArray()), 0, 25));
            $audit->record($request, 'denied', 'failed', 'request_validation_failed', 422, $this->duration($startedAt), [
                'invalid_fields' => $fields,
            ]);

            return IntegrationHubResult::response('failed', null, [
                'correlation_id' => $correlation,
                'capability_key' => $capabilityKey,
                'capability_version' => $capabilityVersion,
                'reason_code' => 'request_validation_failed',
                'reason_message' => 'The request parameters are invalid.',
                'meta' => ['invalid_fields' => $fields],
            ], 422)->header('Cache-Control', 'no-store')->header('Pragma', 'no-cache');
        }
        $validated = $validator->validated();

        $request->attributes->set('integration_hub_capability_key', $validated['capability_key']);
        $request->attributes->set('integration_hub_capability_version', $validated['capability_version'] ?? '1.0');

        try {
            $issued = $issuer->issue($request, $validated);
        } catch (IntegrationHubDeniedException $exception) {
            $audit->record($request, 'denied', $exception->resultStatus, $exception->reasonCode, $exception->httpStatus, $this->duration($startedAt));

            return IntegrationHubResult::response($exception->resultStatus, null, [
                'correlation_id' => $correlation,
                'capability_key' => $validated['capability_key'],
                'capability_version' => $validated['capability_version'] ?? '1.0',
                'reason_code' => $exception->reasonCode,
                'reason_message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }

        $record = $issued['record'];
        unset($issued['record']);
        $request->attributes->set('integration_hub_grant_record', $record);
        $request->attributes->set('integration_hub_claims', ['scope' => $issued['scope']]);
        $audit->record($request, 'allowed', 'ok', 'grant_issued', 201, $this->duration($startedAt));

        return IntegrationHubResult::response('ok', $issued, [
            'correlation_id' => $correlation,
            'capability_key' => $validated['capability_key'],
            'capability_version' => $validated['capability_version'] ?? '1.0',
            'scope' => $issued['scope'],
        ], 201)
            ->header('Cache-Control', 'no-store')
            ->header('Pragma', 'no-cache');
    }

    private function duration(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }
}
