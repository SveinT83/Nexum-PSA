<?php

namespace App\Modules\Ticket\Actions;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item as StorageItem;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Services\TicketActionGuard;
use App\Modules\Ticket\Support\TicketAction;
use App\Modules\Ticket\Support\TicketQuoteCostPolicy;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class StoreTicketCostOrQuoteScope
{
    public const ROUTE_ACTUAL_COST = 'actual_cost';
    public const ROUTE_QUOTE_SCOPE = 'quote_scope';

    public function __construct(
        private readonly ReserveTicketStorageItem $reserveItem,
        private readonly StoreManualTicketCostEntry $storeManualCost,
        private readonly StoreTicketPlannedLine $storePlannedLine,
        private readonly TicketQuoteCostPolicy $policy,
        private readonly TicketActionGuard $guard,
    ) {}

    /**
     * @return array{route: string, record_type: string, record: mixed, reason: string|null, message: string}
     */
    public function handle(Ticket $ticket, array $data, User $actor): array
    {
        $mode = $data['cost_mode'] ?? 'storage';

        if ($mode === 'manual') {
            return $this->storeManual($ticket, $data, $actor);
        }

        return $this->storeStorageItem($ticket, $data, $actor);
    }

    /**
     * @return array{route: string, record_type: string, record: mixed, reason: string|null, message: string}
     */
    private function storeManual(Ticket $ticket, array $data, User $actor): array
    {
        if ($reason = $this->guard->reason($ticket, TicketAction::ADD_ACTUAL_COST, $actor)) {
            throw ValidationException::withMessages(['cost_entry' => $reason]);
        }

        $quantity = (int) $data['quantity'];
        $unitPrice = (float) $data['unit_price_ex_vat'];
        $reason = $this->policy->quoteRequiredReasonForManualLine($quantity, $unitPrice);

        if ($reason) {
            $line = $this->storePlannedLine->handle($ticket, [
                'line_type' => 'custom',
                'source_type' => 'manual_cost',
                'section' => 'one_time_costs',
                'downstream_type' => 'one_time_order',
                'name' => $data['item_name'],
                'description' => $data['invoice_text'] ?? $data['note'] ?? null,
                'quantity' => $quantity,
                'unit' => 'pcs',
                'unit_cost_ex_vat' => 0,
                'unit_price_ex_vat' => $unitPrice,
                'vat_rate' => 25,
                'metadata' => [
                    'origin' => 'ticket_add_cost',
                    'quote_required_reason' => $reason,
                    'currency' => Str::upper($data['currency'] ?? 'NOK'),
                    'internal_note' => $data['note'] ?? null,
                ],
            ], $actor);

            return [
                'route' => self::ROUTE_QUOTE_SCOPE,
                'record_type' => 'planned_line',
                'record' => $line,
                'reason' => $reason,
                'message' => 'Cost requires customer approval and was added to quote scope.',
            ];
        }

        $entry = $this->storeManualCost->handle($ticket, [
            'item_name' => $data['item_name'],
            'quantity' => $quantity,
            'unit_price_ex_vat' => $unitPrice,
            'currency' => Str::upper($data['currency'] ?? 'NOK'),
            'invoice_text' => $data['invoice_text'] ?? null,
            'note' => $data['note'] ?? null,
        ], $actor);

        return [
            'route' => self::ROUTE_ACTUAL_COST,
            'record_type' => 'cost_entry',
            'record' => $entry,
            'reason' => null,
            'message' => 'Manual cost added.',
        ];
    }

    /**
     * @return array{route: string, record_type: string, record: mixed, reason: string|null, message: string}
     */
    private function storeStorageItem(Ticket $ticket, array $data, User $actor): array
    {
        $item = StorageItem::query()
            ->where('status', 'active')
            ->findOrFail((int) $data['storage_item_id']);

        if ($guardReason = $this->guard->reason($ticket, TicketAction::RESERVE_ITEM, $actor)) {
            throw ValidationException::withMessages(['storage_item' => $guardReason]);
        }

        $quantity = (int) $data['quantity'];

        if ($quantity < 1) {
            throw new InvalidArgumentException('Quantity must be at least 1.');
        }

        $reason = $this->policy->quoteRequiredReasonForStorageItem($item, $quantity);

        if ($reason) {
            $line = $this->storePlannedLine->handle($ticket, [
                'storage_item_id' => $item->id,
                'section' => 'equipment',
                'downstream_type' => 'equipment',
                'name' => $item->name,
                'description' => $data['invoice_text'] ?? $item->short_description,
                'quantity' => $quantity,
                'unit' => 'pcs',
                'unit_cost_ex_vat' => $item->purchase_price ?? 0,
                'unit_price_ex_vat' => $item->sale_price ?? 0,
                'vat_rate' => $item->vat_rate ?? 25,
                'metadata' => [
                    'origin' => 'ticket_add_cost',
                    'quote_required_reason' => $reason,
                    'internal_note' => $data['note'] ?? null,
                ],
            ], $actor);

            return [
                'route' => self::ROUTE_QUOTE_SCOPE,
                'record_type' => 'planned_line',
                'record' => $line,
                'reason' => $reason,
                'message' => 'Storage item requires customer approval and was added to quote scope.',
            ];
        }

        $entry = $this->reserveItem->handle($ticket, $item, $data, $actor);

        return [
            'route' => self::ROUTE_ACTUAL_COST,
            'record_type' => 'cost_entry',
            'record' => $entry,
            'reason' => null,
            'message' => 'Storage item reserved.',
        ];
    }
}
