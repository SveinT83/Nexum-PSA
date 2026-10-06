<?php

namespace App\Modules\Workday\Controllers\Tech;

use App\Http\Controllers\Controller;
use App\Modules\Workday\Actions\MutateWorkday;
use App\Modules\Workday\Models\Workday;
use App\Modules\Workday\Models\WorkdayRevision;
use App\Modules\Workday\Queries\ReadWorkday;
use App\Modules\Workday\Queries\WorkdayEditor;
use App\Modules\Workday\Support\WorkdayAccess;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class WorkdayController extends Controller
{
    public function __construct(private WorkdayAccess $access, private ReadWorkday $reader, private MutateWorkday $mutate) {}

    private function api(Request $request): bool
    {
        return $request->routeIs('api.*');
    }

    #[OA\Get(path: '/api/v1/workdays', operationId: 'workdayIndex', summary: 'List own retained workdays', description: 'Requires workdays.read plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1)), new OA\Parameter(name: 'from', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'to', in: 'query', schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayList')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function index(Request $request)
    {
        $this->access->authorize($request->user(), 'view_own');
        $input = $request->validate(['from' => ['sometimes', 'date_format:Y-m-d'], 'to' => ['sometimes', 'date_format:Y-m-d', $request->filled('from') ? 'after_or_equal:from' : 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $days = Workday::query()->where('user_id', $request->user()->id)->where('expires_at', '>', now())
            ->when($input['from'] ?? null, fn ($q, $from) => $q->where('work_date', '>=', $from))
            ->when($input['to'] ?? null, fn ($q, $to) => $q->where('work_date', '<=', $to))
            ->orderByDesc('work_date')->paginate($input['per_page'] ?? 20)->withQueryString();
        $days->setCollection($days->getCollection()->map(fn ($day) => $this->reader->serialize($day)));

        if ($this->api($request)) {
            return response()->json($days);
        }
        $date = $request->validate(['work_date' => ['sometimes', 'date_format:Y-m-d'], 'month' => ['sometimes', 'date_format:Y-m']]);

        return view('workday::Tech.index', ['days' => $days] + app(WorkdayEditor::class)->forDate($request->user(), $date['work_date'] ?? null, $date['month'] ?? null));
    }

    #[OA\Get(path: '/api/v1/workdays/{work_date}/entry', operationId: 'workdayEntry', summary: 'Read own date entry context and next free planned interval',
        description: 'Read-only context for calendar/API clients, including dates without a saved day. Uses the same effective plan and first-free-hour calculation as the browser. Plans and suggestions never establish actual time. Requires an active human employee and workday.view_own; coordinator tokens are denied. No query parameters or worker delegation fields are accepted.',
        security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'work_date', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'date'))],
        responses: [
            new OA\Response(response: 200, description: 'Own date context; no write performed', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayEntryResponse')),
            new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission, scope or employee identity denied'),
            new OA\Response(response: 404, description: 'Feature disabled or date outside retention'),
            new OA\Response(response: 422, description: 'Invalid date or unexpected query/body input'),
        ])]
    public function entry(Request $request, string $work_date)
    {
        $this->access->authorize($request->user(), 'view_own');
        \Illuminate\Support\Facades\Validator::make(['work_date' => $work_date], [
            'work_date' => ['required', 'date_format:Y-m-d'],
        ])->validate();
        if ($request->all() !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(['input' => 'This operation accepts only the work_date path parameter.']);
        }

        return response()->json(['data' => app(\App\Modules\Workday\Queries\ReadWorkdayEntry::class)->handle($request->user(), $work_date)]);
    }

    public function create(Request $request)
    {
        $this->access->authorize($request->user(), 'manage_own');
        $input = $request->validate(['work_date' => ['sometimes', 'date_format:Y-m-d'], 'month' => ['sometimes', 'date_format:Y-m']]);

        return view('workday::Tech.show', app(WorkdayEditor::class)->forDate($request->user(), $input['work_date'] ?? null, $input['month'] ?? null));
    }

    #[OA\Get(path: '/api/v1/workdays/{id}', operationId: 'workdayShow', summary: 'Read own workday', description: 'Requires workdays.read plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid'))],
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function show(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'view_own');
        $record = $this->reader->own($request->user(), $id);

        return $this->api($request) ? response()->json(['data' => $this->reader->serialize($record)])
            : view('workday::Tech.show', app(WorkdayEditor::class)->forRecord($record, $request->validate(['month' => ['sometimes', 'date_format:Y-m']])['month'] ?? null));
    }

    #[OA\Get(path: '/api/v1/workdays/{id}/history', operationId: 'workdayHistory', summary: 'Read own revision history', description: 'Requires workdays.read plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'per_page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1, maximum: 100, default: 20)), new OA\Parameter(name: 'page', in: 'query', schema: new OA\Schema(type: 'integer', minimum: 1))],
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayHistory')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function history(Request $request, string $id)
    {
        $this->access->authorize($request->user(), 'view_own');
        $record = $this->reader->own($request->user(), $id);
        $request->validate(['per_page' => ['sometimes', 'integer', 'min:1', 'max:100']]);
        $history = WorkdayRevision::query()->where('workday_id', $record->id)->orderByDesc('version')->paginate($request->integer('per_page', 20));
        $history->setCollection($history->getCollection()->map(fn ($r) => $this->reader->revision($r)));

        return response()->json($history);
    }

    #[OA\Put(path: '/api/v1/workdays/{work_date}/draft', operationId: 'workdayDraft', summary: 'Save own draft', description: 'Requires workdays.write plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing. Mutations are versioned and retry-safe. Read data.id and data.version from the persisted response; list/history are paginated.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.write']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'work_date', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Unique per employee. Retry identical payloads with the same key; reuse for different operations or bodies returns 409. Receipts expire with the work date.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayDraftInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function draft(Request $request, string $work_date)
    {
        return $this->write($request, 'draft', $work_date);
    }

    #[OA\Post(path: '/api/v1/workdays/{id}/preview', operationId: 'workdayPreview', summary: 'Preview exact saved revision', description: 'Requires workdays.read plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing. Mutations are versioned and retry-safe. Read data.id and data.version from the persisted response; list/history are paginated.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.read']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Unique per employee. Retry identical payloads with the same key; reuse for different operations or bodies returns 409. Receipts expire with the work date.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayVersionInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function preview(Request $request, string $id)
    {
        return $this->write($request, 'preview', $id);
    }

    #[OA\Post(path: '/api/v1/workdays/{id}/confirm', operationId: 'workdayConfirm', summary: 'Confirm own actual time', description: 'Requires workdays.confirm plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing. Mutations are versioned and retry-safe. Read data.id and data.version from the persisted response; list/history are paginated.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.confirm']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Unique per employee. Retry identical payloads with the same key; reuse for different operations or bodies returns 409. Receipts expire with the work date.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayConfirmInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function confirm(Request $request, string $id)
    {
        return $this->write($request, 'confirm', $id);
    }

    #[OA\Post(path: '/api/v1/workdays/{id}/corrections', operationId: 'workdayCorrection', summary: 'Start own correction draft', description: 'Requires workdays.write plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Confirmation is personal, never manager approval or billing. Mutations are versioned and retry-safe. Read data.id and data.version from the persisted response; list/history are paginated.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.write']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'id', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'uuid')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Unique per employee. Retry identical payloads with the same key; reuse for different operations or bodies returns 409. Receipts expire with the work date.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdayCorrectionInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function correction(Request $request, string $id)
    {
        return $this->write($request, 'correction', $id);
    }

    /** Explicit save is effective immediately; the legacy draft endpoint remains private. */
    #[OA\Put(path: '/api/v1/workdays/{work_date}/save', operationId: 'workdaySave', summary: 'Save effective own time without separate confirmation', description: 'Requires workdays.write plus its explicit Workday permission. Active human employee identity is resolved from authentication; foreign owners and coordinator workload tokens are denied. Workday is default-off. Save is effective immediately, never payroll approval or billing. A mapped clock duration rounds once to hundredths of an hour; an incompatible clock placement becomes duration-only. Existing private drafts are not exported until explicitly saved. Mutations are versioned and retry-safe. Read data.id and data.version from the persisted response; list/history are paginated.', security: [['bearerAuth' => []]], x: ['required-scopes' => ['workdays.write']], tags: ['Workday'],
        parameters: [new OA\Parameter(name: 'work_date', in: 'path', required: true, schema: new OA\Schema(type: 'string', format: 'date')), new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, description: 'Unique per employee. Retry identical payloads with the same key; reuse for different operations or bodies returns 409. Receipts expire with the work date.', schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100, pattern: '^[A-Za-z0-9_.:-]+$'))],
        requestBody: new OA\RequestBody(required: true, content: new OA\JsonContent(ref: '#/components/schemas/WorkdaySaveInput')),
        responses: [new OA\Response(response: 200, description: 'Persisted result', content: new OA\JsonContent(ref: '#/components/schemas/WorkdayResponse')), new OA\Response(response: 401, description: 'Unauthenticated'), new OA\Response(response: 403, description: 'Permission or scope denied'), new OA\Response(response: 404, description: 'Disabled, expired, missing or foreign owner'), new OA\Response(response: 409, description: 'Version, preview or idempotency conflict; reload and review'), new OA\Response(response: 410, description: 'Expired receipt'), new OA\Response(response: 422, description: 'Invalid input')])]
    public function save(Request $request, string $work_date)
    {
        return $this->write($request, 'save', $work_date);
    }

    private function write(Request $request, string $operation, string $target)
    {
        $input = $request->except(['_token', '_method', 'request_key']);
        if (! $this->api($request) && in_array($operation, ['draft', 'save'], true) && ! array_key_exists('breaks', $input)) {
            $input['breaks'] = [];
            if ($operation === 'save' && ! isset($input['durations']) && ! isset($input['intervals'])) {
                $input['intervals'] = [];
            }
        }
        if (! $this->api($request)) {
            unset($input['selected_work_date'], $input['editor_index']);
        }
        $key = (string) ($this->api($request) ? $request->header('Idempotency-Key', '') : $request->input('request_key', ''));
        try {
            $result = $this->mutate->handle($request->user(), $operation, $target, $input, $key, $this->api($request) ? 'api' : 'ui');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $exception) {
            if (! $this->api($request) && $exception->getStatusCode() === 409) {
                return response()->view('workday::Tech.conflict', ['message' => $exception->getMessage()], 409);
            }
            throw $exception;
        }
        if ($this->api($request)) {
            return response()->json($result);
        }
        if ($operation === 'preview') {
            return view('workday::Tech.preview', ['day' => $result['data'], 'preview' => $result['preview']]);
        }

        return redirect()->route('tech.workdays.show', $result['data']['id'])->with('success', 'Workday saved.');
    }
}
