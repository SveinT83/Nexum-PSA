<?php

namespace App\Modules\Sales\Actions;

use App\Models\Core\User;
use App\Modules\Sales\Models\SalesActivity;
use App\Modules\Sales\Models\SalesOpportunity;
use Illuminate\Support\Str;

class StoreSalesOpportunity
{
    public function __construct(private readonly SyncOpportunityFollowUpCalendar $syncCalendar)
    {
    }

    public function handle(array $data, User $actor): SalesOpportunity
    {
        $status = $data['status'] ?? 'new_lead';
        $probability = $data['probability_percent'] ?? (EnsureSalesDefaults::STATUSES[$status]['probability'] ?? 10);
        $estimated = (float) ($data['estimated_value_ex_vat'] ?? 0);

        $opportunity = SalesOpportunity::query()->create(array_merge($data, [
            'opportunity_key' => $this->nextKey(),
            'status' => $status,
            'probability_percent' => $probability,
            'weighted_value_ex_vat' => round($estimated * ($probability / 100), 2),
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
        ]));

        SalesActivity::query()->create([
            'opportunity_id' => $opportunity->id,
            'actor_id' => $actor->id,
            'type' => 'system',
            'subject' => 'Opportunity started',
            'body' => 'Sales process started.',
        ]);

        $this->syncCalendar->handle($opportunity, $actor);

        return $opportunity->refresh();
    }

    private function nextKey(): string
    {
        do {
            $key = 'SO-'.now()->format('Y').'-'.Str::upper(Str::random(6));
        } while (SalesOpportunity::query()->where('opportunity_key', $key)->exists());

        return $key;
    }
}
