<?php

namespace App\Modules\Commercial\Support;

use App\Modules\Commercial\Models\Contracts\Contracts;
use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use Illuminate\Database\Eloquent\Builder;

class PortalContractAccess
{
    private const VISIBLE_STATUSES = ['sent_quote', 'sent_contract', 'approved', 'won'];

    public function visibleContracts(CustomerPortalContext $context): Builder
    {
        $query = Contracts::query()
            ->where('client_id', $context->client->id)
            ->whereIn('approval_status', self::VISIBLE_STATUSES);

        if ($context->site) {
            $query->whereRaw('1 = 0');
        }

        return $query;
    }

    public function canView(CustomerPortalContext $context, Contracts $contract): bool
    {
        if ($context->site) {
            return false;
        }

        if ((int) $contract->client_id !== (int) $context->client->id) {
            return false;
        }

        return in_array($contract->approval_status, self::VISIBLE_STATUSES, true);
    }

    public function canAccept(Contracts $contract): bool
    {
        return in_array($contract->approval_status, ['sent_quote', 'sent_contract'], true);
    }

    public function statusLabel(Contracts $contract): string
    {
        return match ($contract->approval_status) {
            'sent_quote' => 'Quote pending',
            'sent_contract' => 'Awaiting acceptance',
            'won' => 'Accepted',
            'approved' => 'Approved',
            default => ucfirst(str_replace('_', ' ', $contract->approval_status ?? '')),
        };
    }
}
