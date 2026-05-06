<?php

namespace App\Modules\Risk\Actions;

use App\Models\Risk\RiskItem;
use Illuminate\Support\Facades\Auth;

/**
 * Updates descriptive fields on a risk item.
 *
 * This action protects the audit trail. Once an item has RiskItemUpdate rows,
 * likelihood, impact, and status are removed from the update payload and must
 * be changed through StoreRiskItemUpdate instead.
 */
class UpdateRiskItem
{
    /**
     * Update the item and create a history note when descriptive fields change.
     *
     * The generated note is intentionally simple and stable because tests and
     * users rely on it as the audit log description for non-scoring edits.
     */
    public function handle(RiskItem $item, array $data, bool $hasUpdates): RiskItem
    {
        if ($hasUpdates) {
            unset($data['likelihood'], $data['impact'], $data['status']);
        }

        $oldValues = $item->only(['title', 'description', 'recommended_actions', 'conclusion', 'category_id']);
        $item->update($data);

        $changes = [];
        if ($oldValues['title'] !== $item->title) {
            $changes[] = 'Title';
        }
        if ($oldValues['description'] !== $item->description) {
            $changes[] = 'Description';
        }
        if ($oldValues['recommended_actions'] !== $item->recommended_actions) {
            $changes[] = 'Recommended Actions';
        }
        if ($oldValues['conclusion'] !== $item->conclusion) {
            $changes[] = 'Conclusion';
        }
        if ($oldValues['category_id'] != $item->category_id) {
            $changes[] = 'Category';
        }

        if ($changes !== []) {
            $item->updates()->create([
                'created_by' => Auth::id(),
                'note' => 'Risk item details updated: '.implode(', ', $changes),
                'likelihood' => $item->likelihood,
                'impact' => $item->impact,
                'status' => $item->status,
            ]);
        }

        return $item;
    }
}
