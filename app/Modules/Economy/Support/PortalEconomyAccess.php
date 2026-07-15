<?php

namespace App\Modules\Economy\Support;

use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Economy\Models\EconomyOrder;
use Illuminate\Database\Eloquent\Builder;

class PortalEconomyAccess
{
    public function visibleOrders(CustomerPortalContext $context): Builder
    {
        $query = EconomyOrder::query()
            ->whereNotNull('portal_visible_at')
            ->where('client_id', $context->client->id);

        if ($context->site) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function canView(CustomerPortalContext $context, EconomyOrder $order): bool
    {
        if ($context->site) {
            return false;
        }

        if (! $order->isPortalVisible()) {
            return false;
        }

        return (int) $order->client_id === (int) $context->client->id;
    }

    public function statusLabel(EconomyOrder $order): string
    {
        return match ($order->status) {
            'ready' => 'Ready for billing',
            'approved' => 'Approved',
            'manual_invoiced' => 'Invoiced',
            default => ucfirst(str_replace('_', ' ', $order->status)),
        };
    }
}
