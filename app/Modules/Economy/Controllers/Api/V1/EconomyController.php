<?php

namespace App\Modules\Economy\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Economy\Actions\DeleteOrderLine;
use App\Modules\Economy\Actions\EnsureEconomyDefaults;
use App\Modules\Economy\Actions\GenerateOrders;
use App\Modules\Economy\Models\EconomyOrder;
use App\Modules\Economy\Models\EconomyOrderLine;
use App\Modules\Economy\Resources\Api\V1\EconomyOrderResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use OpenApi\Attributes as OA;

#[OA\Tag(
    name: 'Economy',
    description: 'API endpoints for internal economy order preparation.'
)]
class EconomyController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Economy order API
    |--------------------------------------------------------------------------
    |
    | This API exposes the order preparation surface used by technicians. It does
    | not create invoices or exports; it prepares and manages internal order
    | records generated from ticket time and ticket cost data.
    |
    */

    #[OA\Get(path: '/api/v1/economy/orders', operationId: 'getEconomyOrders', summary: 'List economy orders', security: [['bearerAuth' => []]], tags: ['Economy'], parameters: [
        new OA\Parameter(name: 'q', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'status', in: 'query', schema: new OA\Schema(type: 'string')),
        new OA\Parameter(name: 'client_id', in: 'query', schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'period_start', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
        new OA\Parameter(name: 'period_end', in: 'query', schema: new OA\Schema(type: 'string', format: 'date')),
    ], responses: [new OA\Response(response: 200, description: 'Paginated order list')])]
    public function index(Request $request, EnsureEconomyDefaults $defaults): AnonymousResourceCollection
    {
        $defaults->handle();

        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'string', 'max:50'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $orders = EconomyOrder::query()
            ->with('client')
            ->withCount('lines')
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['client_id'] ?? null, fn ($query, int $clientId) => $query->where('client_id', $clientId))
            ->when($validated['period_start'] ?? null, fn ($query, string $date) => $query->whereDate('period_end', '>=', $date))
            ->when($validated['period_end'] ?? null, fn ($query, string $date) => $query->whereDate('period_start', '<=', $date))
            ->when($validated['q'] ?? null, function ($query, string $search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('client', fn ($clientQuery) => $clientQuery->where('name', 'like', "%{$search}%"));
                });
            })
            ->latest('id')
            ->paginate((int) ($validated['per_page'] ?? 25))
            ->withQueryString();

        return EconomyOrderResource::collection($orders);
    }

    #[OA\Post(path: '/api/v1/economy/orders/generate', operationId: 'generateEconomyOrders', summary: 'Generate economy orders from billable ticket data', security: [['bearerAuth' => []]], tags: ['Economy'], requestBody: new OA\RequestBody(content: new OA\JsonContent(properties: [
        new OA\Property(property: 'period_start', type: 'string', format: 'date'),
        new OA\Property(property: 'period_end', type: 'string', format: 'date'),
    ])), responses: [new OA\Response(response: 201, description: 'Generation summary'), new OA\Response(response: 422, description: 'Validation error')])]
    public function generate(Request $request, GenerateOrders $generateOrders): JsonResponse
    {
        $validated = $request->validate([
            'period_start' => ['nullable', 'date'],
            'period_end' => ['nullable', 'date', 'after_or_equal:period_start'],
        ]);

        $summary = $generateOrders->handle(
            isset($validated['period_start']) ? Carbon::parse($validated['period_start']) : null,
            isset($validated['period_end']) ? Carbon::parse($validated['period_end']) : null,
            $request->user()
        );

        return response()->json(['data' => ['summary' => $summary]], 201);
    }

    #[OA\Get(path: '/api/v1/economy/orders/{order}', operationId: 'getEconomyOrder', summary: 'View an economy order', security: [['bearerAuth' => []]], tags: ['Economy'], parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Order')])]
    public function show(EconomyOrder $order): EconomyOrderResource
    {
        return EconomyOrderResource::make($order->load(['client', 'lines.ticket']));
    }

    #[OA\Post(path: '/api/v1/economy/orders/{order}/ready', operationId: 'markEconomyOrderReady', summary: 'Mark an economy order ready', security: [['bearerAuth' => []]], tags: ['Economy'], parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Updated order'), new OA\Response(response: 422, description: 'Invalid order state')])]
    public function markReady(Request $request, EconomyOrder $order): EconomyOrderResource
    {
        abort_unless($order->status === 'draft', 422);

        $order->forceFill([
            'status' => 'ready',
            'ready_at' => now(),
            'updated_by' => $request->user()?->id,
        ])->save();

        return EconomyOrderResource::make($order->refresh()->load(['client', 'lines.ticket']));
    }

    #[OA\Post(path: '/api/v1/economy/orders/{order}/draft', operationId: 'markEconomyOrderDraft', summary: 'Move a ready economy order back to draft', security: [['bearerAuth' => []]], tags: ['Economy'], parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 200, description: 'Updated order'), new OA\Response(response: 422, description: 'Invalid order state')])]
    public function markDraft(Request $request, EconomyOrder $order): EconomyOrderResource
    {
        abort_unless($order->status === 'ready', 422);

        $order->forceFill([
            'status' => 'draft',
            'ready_at' => null,
            'updated_by' => $request->user()?->id,
        ])->save();

        return EconomyOrderResource::make($order->refresh()->load(['client', 'lines.ticket']));
    }

    #[OA\Delete(path: '/api/v1/economy/orders/{order}', operationId: 'deleteEconomyOrder', summary: 'Delete an empty draft or ready economy order', security: [['bearerAuth' => []]], tags: ['Economy'], parameters: [new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))], responses: [new OA\Response(response: 204, description: 'Deleted'), new OA\Response(response: 422, description: 'Invalid order state')])]
    public function destroyOrder(EconomyOrder $order): Response
    {
        abort_unless(in_array($order->status, ['draft', 'ready'], true), 422);
        abort_unless($order->lines()->count() === 0, 422);

        $order->delete();

        return response()->noContent();
    }

    #[OA\Delete(path: '/api/v1/economy/orders/{order}/lines/{line}', operationId: 'deleteEconomyOrderLine', summary: 'Delete an economy order line and unlock the source record', security: [['bearerAuth' => []]], tags: ['Economy'], parameters: [
        new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        new OA\Parameter(name: 'line', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
    ], responses: [new OA\Response(response: 200, description: 'Updated order or order deletion result'), new OA\Response(response: 422, description: 'Invalid order state')])]
    public function destroyLine(EconomyOrder $order, EconomyOrderLine $line, DeleteOrderLine $deleteOrderLine): JsonResponse|EconomyOrderResource
    {
        $orderId = $order->id;

        $deleteOrderLine->handle($order, $line);

        $order->refresh();

        if ($order->lines()->count() === 0) {
            $order->delete();

            return response()->json([
                'data' => [
                    'deleted_order' => true,
                    'order_id' => $orderId,
                ],
            ]);
        }

        return EconomyOrderResource::make($order->load(['client', 'lines.ticket']));
    }
}
