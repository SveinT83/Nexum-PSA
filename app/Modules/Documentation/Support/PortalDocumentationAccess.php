<?php

namespace App\Modules\Documentation\Support;

use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Documentation\Models\Documentation;
use Illuminate\Database\Eloquent\Builder;

class PortalDocumentationAccess
{
    public function visibleDocumentations(CustomerPortalContext $context): Builder
    {
        return Documentation::query()
            ->whereNotNull('portal_visible_at')
            ->where('client_id', $context->client->id)
            ->when($context->site, fn (Builder $query) => $query->where(function (Builder $scope) use ($context): void {
                $scope->whereNull('site_id')
                    ->orWhere('site_id', $context->site->id);
            }));
    }

    public function canView(CustomerPortalContext $context, Documentation $documentation): bool
    {
        if (! $documentation->isPortalVisible()) {
            return false;
        }

        if ((int) $documentation->client_id !== (int) $context->client->id) {
            return false;
        }

        if ($context->site && $documentation->site_id && (int) $documentation->site_id !== (int) $context->site->id) {
            return false;
        }

        return true;
    }
}
