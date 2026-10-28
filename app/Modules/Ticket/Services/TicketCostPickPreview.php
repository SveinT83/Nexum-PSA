<?php

namespace App\Modules\Ticket\Services;

use App\Models\Core\User;
use App\Modules\Storage\Models\Item;
use App\Modules\Ticket\Models\Ticket;
use App\Modules\Ticket\Models\TicketCostEntry;
use App\Modules\Ticket\Support\TicketAction;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

#[OA\Schema(schema: 'TicketCostPickPreview', type: 'object', properties: [
    new OA\Property(property: 'cost_entry_id', type: 'integer'), new OA\Property(property: 'quantity', type: 'integer'),
    new OA\Property(property: 'on_hand', type: 'integer', nullable: true), new OA\Property(property: 'allowed', type: 'boolean'),
    new OA\Property(property: 'reason', type: 'string', nullable: true), new OA\Property(property: 'preview_token', type: 'string', nullable: true),
    new OA\Property(property: 'expires_in_seconds', type: 'integer'),
])]
final class TicketCostPickPreview
{
    public function preview(Ticket $ticket, TicketCostEntry $entry, User $actor): array
    {
        $item = Item::withTrashed()->find($entry->storage_item_id);
        $reason = app(TicketActionGuard::class)->reason($ticket, TicketAction::PICK_ITEM, $actor);
        $reason ??= $entry->status !== 'reserved' ? 'Only reserved Ticket costs can be picked.' : null;
        $reason ??= ! $item ? 'The reserved storage item no longer exists.' : null;
        $reason ??= $item?->requiresUnitAwareInventoryMutation() ? 'Serial, batch, or expiry-tracked stock requires unit-aware picking.' : null;
        $reason ??= $item && $item->qty_on_hand < $entry->quantity ? 'Not enough on-hand stock to pick this item.' : null;

        return [
            'cost_entry_id' => $entry->id, 'quantity' => $entry->quantity, 'on_hand' => $item?->qty_on_hand,
            'allowed' => $reason === null, 'reason' => $reason,
            'preview_token' => $reason === null ? Crypt::encryptString(json_encode([
                'actor_id' => $actor->id, 'expires_at' => now()->addMinutes(10)->timestamp,
                'snapshot' => $this->snapshot($entry, $item),
            ], JSON_THROW_ON_ERROR)) : null,
            'expires_in_seconds' => 600,
        ];
    }

    public function assertCurrent(string $token, TicketCostEntry $entry, User $actor): void
    {
        try {
            $payload = json_decode(Crypt::decryptString($token), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException $exception) {
            throw ValidationException::withMessages(['preview_token' => 'Request a current pick preview.']);
        }
        $item = Item::withTrashed()->lockForUpdate()->find($entry->storage_item_id);
        if (! $item || ! is_array($payload) || ($payload['actor_id'] ?? null) !== $actor->id
            || ($payload['expires_at'] ?? 0) < now()->timestamp
            || ($payload['snapshot'] ?? null) !== $this->snapshot($entry, $item)) {
            throw ValidationException::withMessages(['preview_token' => 'The pick preview expired or the cost/stock changed. Request a new preview.']);
        }
    }

    private function snapshot(TicketCostEntry $entry, Item $item): array
    {
        return [
            'ticket_id' => $entry->ticket_id, 'cost_entry_id' => $entry->id, 'item_id' => $item->id,
            'quantity' => (int) $entry->quantity, 'status' => $entry->status, 'billing_status' => $entry->billing_status,
            'reservation_id' => $entry->storage_reservation_id, 'qty_on_hand' => (int) $item->qty_on_hand,
            'qty_reserved' => (int) $item->qty_reserved, 'entry_updated_at' => $entry->updated_at?->toISOString(),
            'item_updated_at' => $item->updated_at?->toISOString(),
        ];
    }
}
