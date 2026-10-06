<?php

namespace App\Modules\Workday\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Actions\MutateAbsence;
use App\Modules\Workday\Models\WorkdayAbsence;
use App\Modules\Workday\Models\WorkdayAbsenceRevision;
use App\Modules\Workday\Queries\ReadAbsence;
use App\Modules\Workday\Support\WorkdayAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class AbsenceController extends Controller
{
    public function __construct(private WorkdayAccess $access, private ReadAbsence $reader, private MutateAbsence $mutate) {}

    private function api(Request $request): bool
    {
        return $request->routeIs('api.*');
    }

    #[OA\Get(path: '/api/v1/workday-absences', operationId: 'workdayAbsenceIndex', summary: 'List own retained absences', description: 'Requires workday-absences.read and workday.absence_view_own. Active human employee owner only, including Superuser; coordinator workload tokens are denied. Default-off. Neutral Calendar projection is saved atomically and excluded from external sync. No diagnosis, notes, attachments, leave approval, balances or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-absences.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string', enum: ['active', 'cancelled'])), new OA\Parameter(name: 'from', in: 'query', description: 'First UTC date to overlap, included.', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', description: 'Last UTC date to overlap, included.', schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Persisted own result. Lists and history are paginated.', content: new OA\JsonContent(ref: '#/components/schemas/AbsenceList')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Stale version, cancelled source, inconsistent projection or reused key; reload'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid or overlapping period')])]
    public function index(Request $request)
    {
        $this->access->authorize($request->user(), 'absence_view_own');
        $data = $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'status' => ['sometimes', 'in:active,cancelled'], 'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : 'date']]);
        $rows = WorkdayAbsence::query()->where('user_id', $request->user()->id)->where('expires_at', '>', now())
            ->when($data['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($data['from'] ?? null, fn ($q, $from) => $q->where('ends_at', '>', $from.' 00:00:00'))
            ->when($data['to'] ?? null, fn ($q, $to) => $q->where('starts_at', '<', \Carbon\CarbonImmutable::parse($to, 'UTC')->addDay()))
            ->orderByDesc('starts_at')->paginate($data['per_page'] ?? 20)->withQueryString();
        $rows->setCollection($rows->getCollection()->map(fn ($a) => $this->reader->serialize($a, $request->user(), impact: false)));

        return $this->api($request) ? response()->json($rows) : view('workday::Tech.absences.index', ['absences' => $rows]);
    }

    public function create(Request $request)
    {
        $this->access->authorize($request->user(), 'absence_manage_own');
        $timezone = $request->user()->profile?->timezone ?: ($request->user()->preferences?->timezone ?: 'Europe/Oslo');

        return view('workday::Tech.absences.show', ['absence' => ['id' => null, 'version' => 0, 'timezone' => $timezone,
            'mode' => 'full_day', 'category' => 'sickness', 'status' => 'active', 'start_date' => now($timezone)->toDateString(),
            'end_date' => now($timezone)->toDateString(), 'starts_at' => '', 'ends_at' => ''], 'history' => null]);
    }

    #[OA\Get(path: '/api/v1/workday-absences/{id}', operationId: 'workdayAbsenceShow', summary: 'Read own absence and plan impact', description: 'Requires workday-absences.read and workday.absence_view_own. Active human employee owner only, including Superuser; coordinator workload tokens are denied. Default-off. Neutral Calendar projection is saved atomically and excluded from external sync. No diagnosis, notes, attachments, leave approval, balances or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-absences.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Persisted own result. Lists and history are paginated.', content: new OA\JsonContent(ref: '#/components/schemas/AbsenceResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Stale version, cancelled source, inconsistent projection or reused key; reload'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid or overlapping period')])]
    public function show(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'absence_view_own');
        $row = $this->reader->own($request->user(), $id);
        $absence = $this->reader->serialize($row, $request->user());

        return $this->api($request) ? response()->json(['data' => $absence])
            : view('workday::Tech.absences.show', ['absence' => $absence, 'history' => $this->revisions($row)]);
    }

    #[OA\Get(path: '/api/v1/workday-absences/{id}/history', operationId: 'workdayAbsenceHistory', summary: 'Read own absence revision history', description: 'Requires workday-absences.read and workday.absence_view_own. Active human employee owner only, including Superuser; coordinator workload tokens are denied. Default-off. Neutral Calendar projection is saved atomically and excluded from external sync. No diagnosis, notes, attachments, leave approval, balances or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-absences.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Persisted own result. Lists and history are paginated.', content: new OA\JsonContent(ref: '#/components/schemas/AbsenceHistory')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Stale version, cancelled source, inconsistent projection or reused key; reload'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid or overlapping period')])]
    public function history(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'absence_view_own');
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);

        return response()->json($this->revisions($this->reader->own($request->user(), $id), $request->integer('per_page', 20)));
    }

    private function revisions(WorkdayAbsence $absence, int $perPage = 20)
    {
        $rows = WorkdayAbsenceRevision::query()->where('absence_id', $absence->id)->orderByDesc('version')->paginate($perPage);
        $rows->setCollection($rows->getCollection()->map(fn ($r) => ['version' => $r->version,
            'snapshot' => $r->snapshot, 'origin' => $r->origin, 'created_at' => $r->created_at->toIso8601String()]));

        return $rows;
    }

    #[OA\Post(path: '/api/v1/workday-absences', operationId: 'workdayAbsenceStore', summary: 'Register own absence', description: 'Requires workday-absences.write and workday.absence_manage_own. Active human employee owner only, including Superuser; coordinator workload tokens are denied. Default-off. Neutral Calendar projection is saved atomically and excluded from external sync. No diagnosis, notes, attachments, leave approval, balances or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-absences.write']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Per-employee key. Retry identical operations and payloads with the same key; changed requests return 409. Receipt expires with the original absence.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AbsenceInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted own result. Lists and history are paginated.', content: new OA\JsonContent(ref: '#/components/schemas/AbsenceResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Stale version, cancelled source, inconsistent projection or reused key; reload'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid or overlapping period')])]
    public function store(Request $request)
    {
        return $this->write($request, 'create', null);
    }

    #[OA\Patch(path: '/api/v1/workday-absences/{id}', operationId: 'workdayAbsenceUpdate', summary: 'Correct own absence', description: 'Requires workday-absences.write and workday.absence_manage_own. Active human employee owner only, including Superuser; coordinator workload tokens are denied. Default-off. Neutral Calendar projection is saved atomically and excluded from external sync. No diagnosis, notes, attachments, leave approval, balances or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-absences.write']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Per-employee key. Retry identical operations and payloads with the same key; changed requests return 409. Receipt expires with the original absence.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/AbsenceInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted own result. Lists and history are paginated.', content: new OA\JsonContent(ref: '#/components/schemas/AbsenceResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Stale version, cancelled source, inconsistent projection or reused key; reload'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid or overlapping period')])]
    public function update(Request $request, string $id)
    {
        return $this->write($request, 'update', $id);
    }

    #[OA\Post(path: '/api/v1/workday-absences/{id}/cancel', operationId: 'workdayAbsenceCancel', summary: 'Cancel own absence and availability block', description: 'Requires workday-absences.write and workday.absence_manage_own. Active human employee owner only, including Superuser; coordinator workload tokens are denied. Default-off. Neutral Calendar projection is saved atomically and excluded from external sync. No diagnosis, notes, attachments, leave approval, balances or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workday-absences.write']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Per-employee key. Retry identical operations and payloads with the same key; changed requests return 409. Receipt expires with the original absence.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayVersionInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted own result. Lists and history are paginated.', content: new OA\JsonContent(ref: '#/components/schemas/AbsenceResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Stale version, cancelled source, inconsistent projection or reused key; reload'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid or overlapping period')])]
    public function cancel(Request $request, string $id)
    {
        return $this->write($request, 'cancel', $id);
    }

    private function write(Request $request, string $operation, ?string $id)
    {
        try {
            $result = $this->mutate->handle($request->user(), $operation, $id,
                $request->except(['_token', '_method', 'request_key']),
                (string) ($this->api($request) ? $request->header('Idempotency-Key', '') : $request->input('request_key', '')),
                $this->api($request) ? 'api' : 'ui');
        } catch (HttpExceptionInterface $exception) {
            if (! $this->api($request) && $exception->getStatusCode() === 409) {
                return response()->view('workday::Tech.conflict', ['message' => $exception->getMessage(),
                    'reloadUrl' => route('tech.absences.index')], 409);
            }
            throw $exception;
        }

        return $this->api($request) ? response()->json($result)
            : redirect()->route('tech.absences.show', $result['data']['id'])->with('success', 'Absence and Calendar updated.');
    }
}
