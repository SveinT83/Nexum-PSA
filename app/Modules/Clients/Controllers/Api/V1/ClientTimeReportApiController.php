<?php

namespace App\Modules\Clients\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Clients\Client;
use App\Modules\Clients\Actions\ExportClientTimeReport;
use App\Modules\Clients\Queries\ClientTimeReportQuery;
use App\Modules\Clients\Reports\ClientTimeReportDefinition;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use OpenApi\Attributes as OA;

/** Identified native reporting; existing Worklog tokens and metadata grants do not grant access. */
class ClientTimeReportApiController extends Controller
{
    #[OA\Get(path: '/api/v1/clients/{client}/reports/time', operationId: 'getClientTimeReport', summary: 'getClientTimeReport', security: [['bearerAuth' => []]], tags: ['Reports'],
        description: 'Identified internal customer report. Requires active native actor and all five report/Client/Ticket/Task/timebank permissions. No coordinator policy or grant activation. All-history when dates omitted. 10,000 per-source/object limit; 422 instead of truncation. Pages are live reads, not a cross-request snapshot.',
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),

        ],
        responses: [new OA\Response(response: 200, description: 'Authorized report', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportSummary')),
            new OA\Response(response: 401, description: 'Authentication required'), new OA\Response(response: 403, description: 'Native rights or token abilities missing'),
            new OA\Response(response: 404, description: 'Client not found'), new OA\Response(response: 422, description: 'Invalid filter, format, page or report ceiling', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportValidation'))],
        x: ['required-scopes' => ['clients.reports.time.read']]
    )]
    public function summary(Request $request, Client $client, ClientTimeReportQuery $query)
    {
        $report = $this->report($request, $client, $query);

        return response()->json(['data' => $this->summaryData($report), 'meta' => $this->meta($report)])
            ->header('Cache-Control', 'private, no-store');
    }

    #[OA\Get(path: '/api/v1/clients/{client}/reports/time/items', operationId: 'getClientTimeReportItems', summary: 'getClientTimeReportItems', security: [['bearerAuth' => []]], tags: ['Reports'],
        description: 'Identified internal customer report. Requires active native actor and all five report/Client/Ticket/Task/timebank permissions. No coordinator policy or grant activation. All-history when dates omitted. 10,000 per-source/object limit; 422 instead of truncation. Pages are live reads, not a cross-request snapshot.',
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),

        ],
        responses: [new OA\Response(response: 200, description: 'Authorized report', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportItems')),
            new OA\Response(response: 401, description: 'Authentication required'), new OA\Response(response: 403, description: 'Native rights or token abilities missing'),
            new OA\Response(response: 404, description: 'Client not found'), new OA\Response(response: 422, description: 'Invalid filter, format, page or report ceiling', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportValidation'))],
        x: ['required-scopes' => ['clients.reports.time.read']]
    )]
    public function items(Request $request, Client $client, ClientTimeReportQuery $query)
    {
        $report = $this->report($request, $client, $query);
        $rows = $report['objects'];
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 50);
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->except('page')]);

        return response()->json(['data' => $paginator->items(),
            'meta' => $this->meta($report) + ['total' => $rows->count(), 'returned' => count($paginator->items()), 'page' => $page,
                'per_page' => $perPage, 'last_page' => $paginator->lastPage()],
            'links' => ['next' => $paginator->nextPageUrl(), 'previous' => $paginator->previousPageUrl()]])
            ->header('Cache-Control', 'private, no-store');
    }

    #[OA\Get(path: '/api/v1/clients/{client}/reports/time/entries', operationId: 'getClientTimeReportEntries', summary: 'getClientTimeReportEntries', security: [['bearerAuth' => []]], tags: ['Reports'],
        description: 'Identified internal customer report. Requires active native actor and all five report/Client/Ticket/Task/timebank permissions. No coordinator policy or grant activation. All-history when dates omitted. 10,000 per-source/object limit; 422 instead of truncation. Pages are live reads, not a cross-request snapshot.',
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)),
            new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100)),

        ],
        responses: [new OA\Response(response: 200, description: 'Authorized report', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportEntries')),
            new OA\Response(response: 401, description: 'Authentication required'), new OA\Response(response: 403, description: 'Native rights or token abilities missing'),
            new OA\Response(response: 404, description: 'Client not found'), new OA\Response(response: 422, description: 'Invalid filter, format, page or report ceiling', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportValidation'))],
        x: ['required-scopes' => ['clients.reports.time.read']]
    )]
    public function entries(Request $request, Client $client, ClientTimeReportQuery $query)
    {
        $report = $this->report($request, $client, $query);
        $rows = $report['entries'];
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 50);
        $paginator = new LengthAwarePaginator($rows->forPage($page, $perPage)->values(), $rows->count(), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->except('page')]);

        return response()->json(['data' => $paginator->items(),
            'meta' => $this->meta($report) + ['total' => $rows->count(), 'returned' => count($paginator->items()), 'page' => $page,
                'per_page' => $perPage, 'last_page' => $paginator->lastPage()],
            'links' => ['next' => $paginator->nextPageUrl(), 'previous' => $paginator->previousPageUrl()]])
            ->header('Cache-Control', 'private, no-store');
    }

    #[OA\Get(path: '/api/v1/clients/{client}/reports/time/export', operationId: 'exportClientTimeReport', summary: 'exportClientTimeReport', security: [['bearerAuth' => []]], tags: ['Reports'],
        description: 'Identified internal customer report. Requires active native actor and all five report/Client/Ticket/Task/timebank permissions. No coordinator policy or grant activation. All-history when dates omitted. 10,000 per-source/object limit; 422 instead of truncation. Pages are live reads, not a cross-request snapshot.',
        parameters: [
            new OA\Parameter(name: 'client', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'date_from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'date_to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),

            new OA\Parameter(name: 'format', in: 'query', required: true, schema: new OA\Schema(type: 'string', enum: ['xlsx', 'pdf'])),
        ],
        responses: [new OA\Response(response: 200, description: 'Authorized report', content: [new OA\MediaType(mediaType: 'application/pdf', schema: new OA\Schema(type: 'string', format: 'binary')), new OA\MediaType(mediaType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', schema: new OA\Schema(type: 'string', format: 'binary'))]),
            new OA\Response(response: 401, description: 'Authentication required'), new OA\Response(response: 403, description: 'Native rights or token abilities missing'),
            new OA\Response(response: 404, description: 'Client not found'), new OA\Response(response: 422, description: 'Invalid filter, format, page or report ceiling', content: new OA\JsonContent(ref: '#/components/schemas/ClientTimeReportValidation'))],
        x: ['required-scopes' => ['clients.reports.time.read', 'clients.reports.time.export']]
    )]
    public function export(Request $request, Client $client, ClientTimeReportQuery $query, ExportClientTimeReport $export)
    {
        $report = $this->report($request, $client, $query, true);

        return $export->download($report, $request->string('format')->toString());
    }

    private function report(Request $request, Client $client, ClientTimeReportQuery $query, bool $export = false): array
    {
        // ClientApiGuard refreshes the native actor; scope checks are independent of actor rights.
        abort_unless(app(ClientTimeReportDefinition::class)->visibleTo($request->user()), 403);
        $input = $request->validate([
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => array_filter(['nullable', 'date_format:Y-m-d', $request->filled('date_from') ? 'after_or_equal:date_from' : null]),
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'format' => $export ? ['required', 'in:xlsx,pdf'] : ['prohibited'],
        ]);

        return $query->build($client, $request->user(), $input['date_from'] ?? null, $input['date_to'] ?? null);
    }

    private function meta(array $report): array
    {
        return ['date_from' => $report['from'], 'date_to' => $report['to'],
            'generated_at' => $report['generated_at']->toIso8601String(), 'units' => 'minutes',
            'truncated' => false, 'cross_request_snapshot' => false, 'totals' => $report['totals']];
    }

    private function summaryData(array $report): array
    {
        $parameters = array_filter(['client' => $report['client']->id, 'date_from' => $report['from'], 'date_to' => $report['to']]);

        return ['client' => $report['client']->only(['id', 'name', 'client_number']),
            'totals' => $report['totals'], 'object_count' => $report['objects']->count(), 'entry_count' => $report['entries']->count(),
            'metric_definitions' => [
                'work_minutes' => 'Registered direct Ticket, Task and quick time once; may include estimate-completion provenance; not approved payroll.',
                'basis_minutes' => 'Customer billing basis; Task billing projections are not extra effort.',
                'covered_minutes' => 'Stored processed coverage.',
                'processed_billable_minutes' => 'Stored processed billable minutes.',
                'unresolved_minutes' => 'Unallocated billable Ticket basis; not confirmed overuse.'],
            'links' => [
                'items' => route('api.v1.clients.reports.time.items', $parameters),
                'entries' => route('api.v1.clients.reports.time.entries', $parameters),
                'xlsx' => route('api.v1.clients.reports.time.export', $parameters + ['format' => 'xlsx']),
                'pdf' => route('api.v1.clients.reports.time.export', $parameters + ['format' => 'pdf'])]];
    }
}
