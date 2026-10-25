<?php

namespace App\Modules\Economy\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Actions\DeleteEconomyOrder;
use App\Modules\Economy\Actions\EconomyOrderWorkflow;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Actions\ExecuteEconomyCommand;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Actions\UpdateEconomySettings;
use App\Modules\Economy\Models\EconomyCommandReceipt;
use App\Modules\Economy\Models\EconomyNotificationIntent;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Queries\EconomyOrderQuery;
use App\Modules\Economy\Queries\PreviewEconomyGeneration;
use App\Modules\Economy\Support\EconomyInput;
use App\Modules\Economy\Support\EconomyProjection;
use App\Modules\Economy\Support\EconomyRevision;
use App\Modules\Integration\Support\DomainApiAuthorization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Staff transport only: shared queries/actions own financial behavior and authoritative effects. */
class EconomyController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = EconomyOrderQuery::filters($request);
        $query = EconomyOrderQuery::make($filters);
        $stats = EconomyOrderQuery::stats($query);
        $orders = $query->with(['client', 'lines.ticket', 'updatedBy'])->orderBy($filters['sort'] ?? 'id', $filters['direction'] ?? 'desc')->orderBy('id')->paginate($filters['per_page'] ?? 25)->withQueryString();

        return response()->json(['data' => $orders->getCollection()->map(fn ($o) => EconomyProjection::order($o, $request, false)), 'meta' => ['current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage(), 'per_page' => $orders->perPage(), 'total' => $orders->total(), 'stats' => $stats], 'links' => ['next' => $orders->nextPageUrl(), 'prev' => $orders->previousPageUrl()]]);
    }

    public function context(Request $request): JsonResponse
    {
        EconomyInput::validate($request, []);

        return response()->json(['data' => [
            'statuses' => ['draft', 'ready', 'approved', 'exported', 'manual_invoiced', 'cancelled'],
            'sort_fields' => ['id', 'order_number', 'period_start', 'period_end', 'status', 'created_at'],
            'period_defaults' => ['period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString()],
            'generation_scope' => 'installation', 'is_invoice_provider' => false, 'currency_conversion' => false,
            'capabilities' => ['manual_invoiced' => true, 'portal_visibility' => true, 'settings' => true, 'data_exchange_export' => true, 'arbitrary_order_or_line_create' => false, 'invoice_send' => false],
            'owner_apis' => ['rates' => '/api/v1/commercial/time-rates', 'units' => '/api/v1/commercial/units', 'source_time_and_cost' => '/api/v1/tickets', 'export_files' => '/api/v1/data-exchange'],
            'new_mutation_headers' => ['Idempotency-Key', 'If-Match'],
        ]]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        EconomyInput::validate($request, []);
        $data = EconomyProjection::order(EconomyOrder::query()->findOrFail($order), $request);

        return response()->json(['data' => $data])->header('ETag', '"'.$data['revision'].'"');
    }

    public function lines(Request $request, int $order): JsonResponse
    {
        $input = EconomyInput::validate($request, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
        $record = EconomyOrder::query()->with('lines.ticket')->findOrFail($order);
        $totals = app(CalculateOrderTotals::class)->forOrder($record);
        $lines = $record->lines()->with('ticket')->orderBy('id')->paginate($input['per_page'] ?? 50)->withQueryString();

        return response()->json(['data' => $lines->getCollection()->map(fn ($line) => EconomyProjection::line($line, $totals['lines'][$line->id] ?? null)), 'meta' => ['total' => $lines->total(), 'current_page' => $lines->currentPage(), 'last_page' => $lines->lastPage(), 'revision' => EconomyRevision::order($record)], 'links' => ['next' => $lines->nextPageUrl(), 'prev' => $lines->previousPageUrl()]]);
    }

    public static function periodRules(): array
    {
        return ['period_start' => 'sometimes|nullable|date_format:Y-m-d', 'period_end' => 'sometimes|nullable|date_format:Y-m-d|after_or_equal:period_start'];
    }

    public function generationPreview(Request $request): JsonResponse
    {
        $input = EconomyInput::validate($request, self::periodRules());

        return response()->json(['data' => app(PreviewEconomyGeneration::class)->handle($input)]);
    }

    public function generate(Request $request): JsonResponse
    {
        $input = EconomyInput::validate($request, self::periodRules() + ['preview_hash' => 'sometimes|string|size:64']);
        $result = app(ExecuteEconomyCommand::class)->handle($request, $input, function () use ($request, $input) {
            if (isset($input['preview_hash']) || $request->header('If-Match')) {
                $preview = app(PreviewEconomyGeneration::class)->handle($input);
                EconomyRevision::check($request, $preview['preview_hash'], false);
                if (isset($input['preview_hash'])) {
                    abort_unless(hash_equals($preview['preview_hash'], $input['preview_hash']), 409, 'Generation inputs changed; preview again.');
                }
            }
            [$start, $end] = PreviewEconomyGeneration::dates($input);

            return ['data' => ['summary' => app(GenerateOrders::class)->handle($start, $end, $request->user())]];
        }, false);

        return response()->json($result, 201);
    }

    public function markReady(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'ready', false);
    }

    public function markDraft(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'draft', false);
    }

    public function markInvoiced(Request $request, int $order): JsonResponse
    {
        return $this->transition($request, $order, 'manual_invoiced', true);
    }

    public function visibilityPreview(Request $request, int $order): JsonResponse
    {
        $input = EconomyInput::validate($request, ['portal_visible' => 'required|boolean']);
        $record = EconomyOrder::query()->findOrFail($order);
        $revision = EconomyRevision::order($record);

        return response()->json(['data' => ['order_id' => $order, 'portal_visible' => $record->isPortalVisible(), 'requested_visible' => (bool) $input['portal_visible'], 'client_id' => $record->client_id, 'notification_type' => ! $record->isPortalVisible() && $input['portal_visible'] ? 'portal_order_published' : null, 'audience' => 'current_active_client_wide_portal_members_with_notification_preferences', 'revision' => $revision, 'preview_hash' => EconomyRevision::hash([$revision, (bool) $input['portal_visible']])]]);
    }

    public function visibility(Request $request, int $order): JsonResponse
    {
        $input = EconomyInput::validate($request, ['portal_visible' => 'required|boolean', 'preview_hash' => 'required|string|size:64']);
        $result = app(ExecuteEconomyCommand::class)->handle($request, $input, function () use ($request, $order, $input) {
            $record = EconomyOrder::query()->lockForUpdate()->findOrFail($order);
            $revision = EconomyRevision::order($record);
            EconomyRevision::check($request, $revision);
            abort_unless(hash_equals(EconomyRevision::hash([$revision, (bool) $input['portal_visible']]), $input['preview_hash']), 409, 'Visibility inputs changed; preview again.');
            $effect = app(EconomyOrderWorkflow::class)->handle($order, 'visibility', $request->user(), (bool) $input['portal_visible'], $request);

            return ['data' => EconomyProjection::order($record->refresh(), $request), 'notification_intents' => $effect['notification_intents']];
        });

        return response()->json($result);
    }

    private function transition(Request $request, int $order, string $action, bool $required): JsonResponse
    {
        $input = EconomyInput::validate($request, []);
        $result = app(ExecuteEconomyCommand::class)->handle($request, $input, function () use ($request, $order, $action, $required) {
            $record = EconomyOrder::query()->lockForUpdate()->findOrFail($order);
            EconomyRevision::check($request, EconomyRevision::order($record), $required);
            $effect = app(EconomyOrderWorkflow::class)->handle($order, $action, $request->user(), request: $request);

            return ['data' => EconomyProjection::order($record->refresh(), $request), 'notification_intents' => $effect['notification_intents']];
        }, $required);

        return response()->json($result);
    }

    public function destroyOrder(Request $request, int $order): JsonResponse|\Illuminate\Http\Response
    {
        $result = $this->deletion($request, $order);

        return $request->header('Idempotency-Key') ? response()->json($result) : response()->noContent();
    }

    public function destroyLine(Request $request, int $order, int $line): JsonResponse
    {
        return response()->json($this->deletion($request, $order, $line));
    }

    private function deletion(Request $request, int $order, ?int $line = null): array
    {
        $input = EconomyInput::validate($request, []);

        return app(ExecuteEconomyCommand::class)->handle($request, $input, function () use ($request, $order, $line) {
            $record = EconomyOrder::query()->lockForUpdate()->findOrFail($order);
            EconomyRevision::check($request, EconomyRevision::order($record), false);
            $result = app(DeleteEconomyOrder::class)->handle($order, $line);

            return ['data' => $result['deleted_order'] ? $result : EconomyProjection::order($record->refresh(), $request) + $result];
        }, false);
    }

    public function settings(Request $request): JsonResponse
    {
        EconomyInput::validate($request, []);
        $settings = app(EnsureEconomyDefaults::class)->effective();
        $data = array_intersect_key($settings->toArray(), UpdateEconomySettings::rules());

        return response()->json(['data' => $data + ['revision' => EconomyRevision::settings(), 'persisted' => $settings->exists, 'enums' => ['time_order_line_grouping' => ['per_entry'], 'order_line_text_format' => ['ticket_date_text']], 'null_vat_behavior' => 'current_common_economy_vat_or_25_fallback']]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $input = EconomyInput::validate($request, UpdateEconomySettings::rules());
        $result = app(ExecuteEconomyCommand::class)->handle($request, $input, function () use ($request, $input) {
            EconomyRevision::check($request, EconomyRevision::settings());
            app(UpdateEconomySettings::class)->handle($input);
            $settings = app(EnsureEconomyDefaults::class)->effective();

            return ['data' => array_intersect_key($settings->toArray(), UpdateEconomySettings::rules()) + ['revision' => EconomyRevision::settings(), 'persisted' => true]];
        });

        return response()->json($result);
    }

    public function operation(Request $request, int $operation): JsonResponse
    {
        EconomyInput::validate($request, []);
        $receipt = EconomyCommandReceipt::query()->where('actor_id', $request->user()->id)->findOrFail($operation);
        $permission = match (true) {
            str_contains($receipt->operation, 'generate') => 'economy.generate_orders',
            str_contains($receipt->operation, 'settings') => 'economy.manage_settings',
            str_contains($receipt->operation, 'exports') || str_contains($receipt->operation, 'data-exchange') => 'data_exchange.run',
            str_contains($receipt->operation, 'destroy') => 'economy.delete_orders',
            default => 'economy.order_manage',
        };
        app(DomainApiAuthorization::class)->authorize($request, $permission);
        if ($permission === 'data_exchange.run') {
            app(DomainApiAuthorization::class)->authorize($request, 'economy.view');
        }
        $result = $receipt->result;
        $effects = EconomyNotificationIntent::query()->whereIn('id', $result['notification_intents'] ?? [])->get()->map(fn ($i) => ['id' => $i->id, 'state' => $i->state, 'recipients' => $i->recipients, 'error_code' => $i->error_code]);

        return response()->json(['data' => ['id' => $receipt->id, 'operation' => $receipt->operation, 'result' => $result, 'notification_status' => $effects, 'inbox_delivery_verified' => false, 'created_at' => $receipt->created_at?->toIso8601String()]]);
    }
}
