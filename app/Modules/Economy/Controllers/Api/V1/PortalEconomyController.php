<?php

namespace App\Modules\Economy\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Economy\Actions\CalculateOrderTotals;
use App\Modules\Economy\Support\EconomyInput;
use App\Modules\Economy\Support\EconomyProjection;
use App\Modules\Economy\Support\PortalEconomyAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Existing portal-session identity is authoritative; no staff PAT or caller-selected customer. */
class PortalEconomyController extends Controller
{
    private function query(Request $request)
    {
        return app(PortalEconomyAccess::class)->visibleOrders($request->attributes->get('customerPortalContext'));
    }

    private function paging(Request $request): array
    {
        return EconomyInput::validate($request, ['page' => 'sometimes|integer|min:1', 'per_page' => 'sometimes|integer|min:1|max:100']);
    }

    public function index(Request $request): JsonResponse
    {
        $input = $this->paging($request);
        $orders = $this->query($request)->with(['client', 'lines.ticket'])->latest('period_end')->orderByDesc('id')->paginate($input['per_page'] ?? 15)->withQueryString();

        return response()->json(['data' => $orders->getCollection()->map(fn ($order) => EconomyProjection::order($order, $request, false, true)), 'meta' => ['total' => $orders->total(), 'current_page' => $orders->currentPage(), 'last_page' => $orders->lastPage()], 'links' => ['next' => $orders->nextPageUrl(), 'prev' => $orders->previousPageUrl()]]);
    }

    public function context(Request $request): JsonResponse
    {
        EconomyInput::validate($request, []);
        $context = $request->attributes->get('customerPortalContext');

        return response()->json(['data' => ['client' => ['id' => $context->client->id, 'name' => $context->client->name], 'client_level_orders_available' => ! $context->site, 'explicit_publication_required' => true, 'can_edit' => false, 'is_invoice_or_payment_request' => false]]);
    }

    public function show(Request $request, int $order): JsonResponse
    {
        EconomyInput::validate($request, []);

        return response()->json(['data' => EconomyProjection::order($this->query($request)->findOrFail($order), $request, true, true)]);
    }

    public function lines(Request $request, int $order): JsonResponse
    {
        $input = $this->paging($request);
        $record = $this->query($request)->with('lines')->findOrFail($order);
        $totals = app(CalculateOrderTotals::class)->forOrder($record);
        $lines = $record->lines()->where('status', 'active')->with('ticket')->orderBy('id')->paginate($input['per_page'] ?? 50)->withQueryString();

        return response()->json(['data' => $lines->getCollection()->map(fn ($line) => EconomyProjection::line($line, $totals['lines'][$line->id] ?? null, true)), 'meta' => ['total' => $lines->total(), 'current_page' => $lines->currentPage(), 'last_page' => $lines->lastPage()], 'links' => ['next' => $lines->nextPageUrl(), 'prev' => $lines->previousPageUrl()]]);
    }
}
