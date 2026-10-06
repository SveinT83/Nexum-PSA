<?php

namespace App\Modules\Workday\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Actions\PurgeExpiredWorkday;
use App\Modules\Workday\Support\WorkdayAccess;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

class RetentionController extends Controller
{
    #[OA\Post(path: '/api/v1/workday-settings/retention-preview', operationId: 'workdayRetentionPreview',
        summary: 'Preview three-year Workday retention without deleting data',
        description: 'Active human with explicit workday.manage_settings and workdays.settings scope. Available while employee Workday access is off. Counts only; no worker identity, dates, descriptions or absence categories. No caller-supplied cutoff, retention override or purge endpoint. restore_ready covers application-owned copies only; operators must separately complete backup, log, cache and worker restore steps.',
        security: [['bearerAuth' => []]], tags: ['Workday'], x: ['required-scopes' => ['workdays.settings']],
        responses: [new OA\Response(response: 200, description: 'Metadata-only current inventory', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayRetentionPreview')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission, scope or actor denied'),
            new OA\Response(response: 422, description: 'Unexpected input')])]
    public function preview(Request $request, WorkdayAccess $access, PurgeExpiredWorkday $purge)
    {
        $access->authorize($request->user(), 'manage_settings', true);
        if ($request->except(['_token'])) {
            throw ValidationException::withMessages(['input' => 'Retention preview accepts no cutoff, identity or policy overrides.']);
        }
        $data = $purge->preview();

        return $request->routeIs('api.*') ? response()->json(['data' => $data]) : view('workday::Admin.retention', compact('data'));
    }
}
