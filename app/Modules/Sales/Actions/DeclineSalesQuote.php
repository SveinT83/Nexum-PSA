<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesQuoteVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeclineSalesQuote
{
    public function handle(SalesQuoteVersion $version, array $data, ?User $actor = null): SalesQuoteVersion
    {
        return DB::transaction(function () use ($version, $data, $actor): SalesQuoteVersion {
            $locked = SalesQuoteVersion::query()
                ->with('quote.opportunity')
                ->lockForUpdate()
                ->findOrFail($version->id);

            if ($locked->status === 'declined') {
                return $locked;
            }

            if ($locked->status !== 'sent') {
                throw ValidationException::withMessages(['quote' => 'Only a sent quote can be declined.']);
            }

            $declinedAt = now();
            $locked->forceFill([
                'status' => 'declined',
                'rejected_at' => $declinedAt,
                'declined_at' => $declinedAt,
                'declined_by_name' => $data['name'],
                'declined_reason' => $data['reason'] ?? null,
                'declined_ip' => $data['ip'] ?? null,
                'declined_ua' => $data['user_agent'] ?? null,
            ])->save();
            $locked->quote->forceFill(['status' => 'declined', 'current_version_id' => $locked->id])->save();

            $opportunity = $locked->quote->opportunity;
            if (! in_array($opportunity->status, ['won', 'lost'], true)) {
                $opportunity->forceFill([
                    'status' => 'negotiation',
                    'probability_percent' => 30,
                    'weighted_value_ex_vat' => round((float) $opportunity->estimated_value_ex_vat * 0.3, 2),
                    'is_unread' => true,
                ])->save();
            }

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor?->id,
                'type' => 'quote_declined',
                'direction' => 'inbound',
                'subject' => 'Quote declined',
                'body' => $data['name'].' declined quote '.$locked->quote->quote_key.' v'.$locked->version_number.'.',
                'is_unread' => true,
                'metadata' => [
                    'quote_version_id' => $locked->id,
                    'method' => $data['method'] ?? 'public_link',
                    'reason' => $data['reason'] ?? null,
                ],
            ]);

            return $locked->refresh();
        });
    }
}
