<?php

namespace App\Modules\Sales\Actions;

use App\Modules\CustomerPortal\Support\CustomerPortalContext;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Support\Facades\DB;

/** A view is an explicit command in the API; browser adapters retain their existing lifecycle. */
class RecordSalesQuoteView
{
    public function handle(SalesQuoteVersion $version, ?CustomerPortalContext $context = null): SalesQuoteVersion
    {
        return DB::transaction(function () use ($version, $context) {
            $locked = SalesQuoteVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            app(ExpireSalesQuote::class)->handle($locked);
            $locked->refresh();
            if (! $locked->viewed_at) {
                $locked->forceFill(['viewed_at' => now()])->save();
                SalesActivity::query()->create(['opportunity_id' => $locked->quote->opportunity_id, 'type' => 'quote_viewed', 'direction' => 'inbound', 'subject' => 'Quote viewed',
                    'body' => 'Quote '.$locked->quote->quote_key.' v'.$locked->version_number.' was viewed.',
                    'metadata' => ['quote_version_id' => $locked->id, 'method' => $context ? 'customer_portal' : 'public_link'] + ($context ? ['customer_portal_account_id' => $context->account->id] : [])]);
            }

            return $locked;
        });
    }
}
