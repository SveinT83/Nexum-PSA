<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskItemUpdate;
use App\Modules\Risk\Support\RiskMutation;
use App\Modules\Risk\Support\RiskPolicy;

/** Removing history repairs the exact newest remaining snapshot, never fabricates a baseline. */
class DeleteRiskItemUpdate
{
    public function handle(RiskItemUpdate $update): void
    {
        $item = RiskPolicy::item($update->risk_item_id);
        app(RiskMutation::class)->run('risk.delete', $item->risk_assessment_id, function ($assessment) use ($update, $item) {
            $update = RiskPolicy::update($update->id);
            RiskPolicy::deleteUpdate($update->created_by);
            $item = RiskPolicy::item($item->id);
            $update->delete();
            $latest = $item->updates()->reorder()->orderByDesc('created_at')->orderByDesc('id')->first();
            if ($latest) {
                $item->update(['status' => $latest->status, 'likelihood' => $latest->likelihood, 'impact' => $latest->impact]);
            }
            RiskMutation::reopen($assessment);
        });
    }
}
