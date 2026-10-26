<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskItem;
use App\Modules\Risk\Support\RiskMutation;
use App\Modules\Risk\Support\RiskPolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/** Shared owner authorization and explicit immutable-scoring validation under the parent lock. */
class UpdateRiskItem
{
    public function handle(RiskItem $item, array $data, bool $hasUpdates): RiskItem
    {
        return app(RiskMutation::class)->run('risk.update', $item->risk_assessment_id, function ($assessment) use ($item, $data) {
            $item = RiskPolicy::item($item->id);
            RiskPolicy::editItem($item);
            if ($item->updates()->exists()) {
                foreach (['likelihood', 'impact', 'status'] as $field) {
                    if (array_key_exists($field, $data) && $data[$field] !== null && (string) $data[$field] !== (string) $item->$field) {
                        throw ValidationException::withMessages([$field => 'Use Add Update to change historical scoring or status.']);
                    }
                    unset($data[$field]);
                }
            }
            $before = $item->only(['title', 'description', 'recommended_actions', 'conclusion', 'category_id', 'likelihood', 'impact', 'status']);
            $oldDate = $item->next_review_at?->format('Y-m-d');
            $item->update($data);
            $changes = [];
            foreach ($before as $key => $value) {
                if ($value != $item->$key) {
                    $changes[] = str_replace('_', ' ', ucfirst($key));
                }
            }
            if ($oldDate !== $item->next_review_at?->format('Y-m-d')) {
                $changes[] = 'Next review';
            }
            if ($changes) {
                $item->updates()->create(['created_by' => Auth::id(), 'note' => 'Risk item details updated: '.implode(', ', $changes), 'status' => $item->status, 'likelihood' => $item->likelihood, 'impact' => $item->impact]);
                RiskMutation::reopen($assessment);
            }

            return $item->fresh();
        });
    }
}
