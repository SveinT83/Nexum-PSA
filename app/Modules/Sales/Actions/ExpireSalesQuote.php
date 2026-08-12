<?php

namespace App\Modules\Sales\Actions;

use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Support\Facades\DB;

class ExpireSalesQuote
{
    public function handle(SalesQuoteVersion $version): bool
    {
        $version->loadMissing('quote.opportunity');

        if ($version->status !== 'sent' || ! $version->expires_at || ! $version->expires_at->isPast()) {
            return false;
        }

        DB::transaction(function () use ($version): void {
            $version->forceFill([
                'status' => 'expired',
                'updated_by' => null,
            ])->save();

            $version->quote->forceFill(['status' => 'expired'])->save();
            $version->quote->opportunity->forceFill(['is_unread' => true])->save();

            SalesActivity::query()->firstOrCreate(
                [
                    'opportunity_id' => $version->quote->opportunity_id,
                    'type' => 'quote_expired',
                    'subject' => 'Quote expired',
                ],
                [
                    'body' => 'Quote '.$version->quote->quote_key.' v'.$version->version_number.' expired on '.$version->expires_at->toDateString().'.',
                    'is_unread' => true,
                    'metadata' => ['quote_version_id' => $version->id],
                ],
            );
        });

        return true;
    }
}
