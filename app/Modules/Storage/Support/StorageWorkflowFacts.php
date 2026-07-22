<?php

namespace App\Modules\Storage\Support;

use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class StorageWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'storage.');
    }

    public function catalog(): array
    {
        return [
            'storage.approved_lines_reserved' => ['label' => 'Approved stock lines are reserved', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'storage.approved_lines_picked' => ['label' => 'Approved stock lines are picked', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'storage.approved_lines_available' => ['label' => 'Approved stock lines are available', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'storage.purchase_needs_created' => ['label' => 'Approved orderable lines have draft purchase needs', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        $approved = $ticket->plannedLines()->whereNotNull('storage_item_id')->where('status', 'approved');
        $approvedCount = (clone $approved)->count();

        $value = match ($fact) {
            'storage.approved_lines_reserved' => $approvedCount === 0 || (clone $approved)
                ->whereHas('convertedCostEntry', fn ($query) => $query->whereIn('status', ['reserved', 'picked']))
                ->count() === $approvedCount,
            'storage.approved_lines_picked' => $approvedCount === 0 || (clone $approved)
                ->whereHas('convertedCostEntry', fn ($query) => $query->where('status', 'picked'))
                ->count() === $approvedCount,
            'storage.approved_lines_available' => $approvedCount === 0 || (clone $approved)->with('storageItem')->get()
                ->every(fn ($line) => $line->storageItem && $line->storageItem->qty_available >= (int) ceil((float) $line->quantity)),
            'storage.purchase_needs_created' => $approvedCount === 0 || (clone $approved)->whereHas('purchaseOrderLine')->count() === $approvedCount,
            default => false,
        };

        return ['value' => $value, 'evidence' => ['approved_storage_lines' => $approvedCount]];
    }
}
