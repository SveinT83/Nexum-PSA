<?php

namespace App\Modules\Report\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Report\Resources\Api\V1\ReportResource;
use App\Modules\Report\Support\ReportEntry;
use App\Modules\Report\Support\ReportRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

#[OA\Tag(
    name: 'Reports',
    description: 'API endpoints for report discovery.'
)]
class ReportController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Report discovery API
    |--------------------------------------------------------------------------
    |
    | This API mirrors the report hub. It exposes available report definitions
    | for integrations and AI agents while preserving domain ownership of actual
    | report calculations.
    |
    */

    #[OA\Get(path: '/api/v1/reports', operationId: 'getReports', summary: 'List available reports', security: [['bearerAuth' => []]], tags: ['Reports'], parameters: [
        new OA\Parameter(name: 'domain', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'Report list')])]
    public function index(Request $request, ReportRegistry $registry): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'domain' => ['nullable', 'string', 'max:100'],
            'q' => ['nullable', 'string', 'max:255'],
        ]);

        $reports = $registry
            ->visibleFor($request->user(), $validated['domain'] ?? null)
            ->when($validated['q'] ?? null, function ($reports, string $search) {
                $needle = mb_strtolower($search);

                return $reports->filter(fn (ReportEntry $report) => str_contains(mb_strtolower($report->title), $needle)
                    || str_contains(mb_strtolower($report->description), $needle)
                    || str_contains(mb_strtolower($report->key), $needle)
                    || str_contains(mb_strtolower($report->domain), $needle)
                    || collect($report->tags)->contains(fn (string $tag) => str_contains(mb_strtolower($tag), $needle)));
            })
            ->values();

        return ReportResource::collection($reports);
    }

    #[OA\Get(path: '/api/v1/reports/{reportKey}', operationId: 'getReport', summary: 'View report metadata', security: [['bearerAuth' => []]], tags: ['Reports'], parameters: [
        new OA\Parameter(name: 'reportKey', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
    ], responses: [new OA\Response(response: 200, description: 'Report metadata'), new OA\Response(response: 404, description: 'Report not found')])]
    public function show(Request $request, ReportRegistry $registry, string $reportKey): ReportResource
    {
        $report = $registry
            ->visibleFor($request->user())
            ->first(fn (ReportEntry $report) => $report->key === $reportKey);

        if (! $report) {
            throw new NotFoundHttpException('Report not found.');
        }

        return ReportResource::make($report);
    }
}
