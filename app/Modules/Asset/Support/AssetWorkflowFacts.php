<?php

namespace App\Modules\Asset\Support;

use App\Modules\Ticket\Contracts\WorkflowFactProvider;
use App\Modules\Ticket\Models\Ticket;

class AssetWorkflowFacts implements WorkflowFactProvider
{
    public function supports(string $fact): bool
    {
        return str_starts_with($fact, 'asset.');
    }

    public function catalog(): array
    {
        return [
            'asset.linked' => ['label' => 'Asset is linked', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
            'asset.status' => ['label' => 'Asset status', 'operators' => ['equals', 'not_equals', 'present'], 'value_type' => 'text'],
            'asset.managed' => ['label' => 'Asset is managed', 'operators' => ['is_true', 'is_false'], 'value_type' => 'none'],
        ];
    }

    public function resolve(Ticket $ticket, string $fact, array $condition = []): array
    {
        $ticket->loadMissing('asset');
        $asset = $ticket->asset;
        $inScope = $asset && (int) $asset->client_id === (int) $ticket->client_id;

        $value = match ($fact) {
            'asset.linked' => (bool) $inScope,
            'asset.status' => $inScope ? $asset->status : null,
            'asset.managed' => $inScope ? (bool) $asset->is_managed : false,
            default => false,
        };

        return [
            'value' => $value,
            'evidence' => $inScope ? ['asset_id' => $asset->id, 'name' => $asset->name] : [],
        ];
    }
}
