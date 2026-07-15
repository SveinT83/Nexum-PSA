<?php

namespace App\Modules\Sales\Support;

use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Database\Eloquent\Builder;

class PortalSalesQuoteAccess
{
    public function visibleQuoteVersions(CustomerPortalContext $context): Builder
    {
        $query = SalesQuoteVersion::query()
            ->whereIn('status', ['sent', 'accepted'])
            ->whereHas('quote.opportunity', fn (Builder $query) => $query->where('client_id', $context->client->id));

        if ($context->site) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function canView(CustomerPortalContext $context, SalesQuoteVersion $version): bool
    {
        if ($context->site) {
            return false;
        }

        if (! in_array($version->status, ['sent', 'accepted'], true)) {
            return false;
        }

        $opportunity = $version->quote?->opportunity;

        return $opportunity && (int) $opportunity->client_id === (int) $context->client->id;
    }

    public function canAccept(SalesQuoteVersion $version): bool
    {
        if ($version->status !== 'sent') {
            return false;
        }

        return ! $version->expires_at || ! $version->expires_at->isPast();
    }

    public function statusLabel(SalesQuoteVersion $version): string
    {
        if ($version->status === 'sent' && $version->expires_at?->isPast()) {
            return 'Expired';
        }

        return match ($version->status) {
            'sent' => 'Awaiting acceptance',
            'accepted' => 'Accepted',
            default => ucfirst(str_replace('_', ' ', $version->status)),
        };
    }
}
