<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesOpportunity;
use App\Modules\Sales\Models\SalesQuote;
use App\Modules\Sales\Models\SalesQuoteVersion;
use App\Modules\Sales\Models\SalesSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EnsureSalesQuoteDraft
{
    public function handle(SalesOpportunity $opportunity, User $actor): SalesQuoteVersion
    {
        $opportunity->loadMissing('currentQuoteVersion');

        if ($opportunity->currentQuoteVersion?->isEditable()) {
            return $opportunity->currentQuoteVersion;
        }

        return DB::transaction(function () use ($opportunity, $actor): SalesQuoteVersion {
            $previous = $opportunity->currentQuoteVersion?->loadMissing('lines');
            $quote = $opportunity->quotes()->first() ?: SalesQuote::query()->create([
                'opportunity_id' => $opportunity->id,
                'quote_key' => 'Q-'.now()->format('Y').'-'.Str::upper(Str::random(6)),
                'status' => 'draft',
            ]);
            $nextVersion = ((int) $quote->versions()->max('version_number')) + 1;

            $version = SalesQuoteVersion::query()->create([
                'quote_id' => $quote->id,
                'version_number' => $nextVersion,
                'status' => 'draft',
                'secure_token' => Str::random(64),
                'title' => $previous?->title ?? $opportunity->title,
                'intro_text' => $previous?->intro_text ?? 'Thank you for the opportunity to provide this quote.',
                'scope_text' => $previous?->scope_text ?? $opportunity->needs,
                'assumptions_text' => $previous?->assumptions_text ?? 'Prices are shown excluding VAT unless otherwise stated.',
                'exclusions_text' => $previous?->exclusions_text,
                'next_steps_text' => $previous?->next_steps_text ?? 'Please accept the quote or ask a question if anything should be clarified.',
                'expires_at' => now()->addDays((int) SalesSetting::get('quote_expiry_days', 30))->toDateString(),
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            foreach ($previous?->lines ?? [] as $line) {
                $line->replicate(['id', 'quote_version_id', 'created_at', 'updated_at'])
                    ->forceFill(['quote_version_id' => $version->id])
                    ->save();
            }

            $quote->forceFill(['current_version_id' => $version->id, 'status' => 'draft'])->save();
            $opportunity->forceFill(['current_quote_version_id' => $version->id])->save();

            return $version;
        });
    }
}
