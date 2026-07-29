<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReopenSalesOpportunity
{
    public const REOPEN_STATUSES = [
        'new_lead',
        'contact_lead',
        'contacted',
        'needs_discovery',
        'quote_ready',
        'quote_sent',
        'negotiation',
        'follow_up_later',
    ];

    public function handle(SalesOpportunity $opportunity, string $status, User $actor): SalesOpportunity
    {
        if ($opportunity->status !== 'lost') {
            throw ValidationException::withMessages([
                'status' => 'Only a lost opportunity can be reopened.',
            ]);
        }

        if (! in_array($status, self::REOPEN_STATUSES, true)) {
            throw ValidationException::withMessages([
                'status' => 'Choose an active status when reopening the opportunity.',
            ]);
        }

        return DB::transaction(function () use ($opportunity, $status, $actor): SalesOpportunity {
            $probability = EnsureSalesDefaults::STATUSES[$status]['probability'];

            $opportunity->forceFill([
                'status' => $status,
                'probability_percent' => $probability,
                'weighted_value_ex_vat' => round((float) $opportunity->estimated_value_ex_vat * ($probability / 100), 2),
                'lost_at' => null,
                'lost_reason' => null,
                'updated_by' => $actor->id,
            ])->save();

            SalesActivity::query()->create([
                'opportunity_id' => $opportunity->id,
                'actor_id' => $actor->id,
                'type' => 'opportunity_reopened',
                'subject' => 'Opportunity reopened',
                'body' => 'Opportunity reopened with status '.EnsureSalesDefaults::STATUSES[$status]['label'].'. A follow-up was not restored.',
                'is_unread' => false,
                'read_at' => now(),
                'metadata' => ['status' => $status],
            ]);

            return $opportunity->refresh();
        });
    }
}
