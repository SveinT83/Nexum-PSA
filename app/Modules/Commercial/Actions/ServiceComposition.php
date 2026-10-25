<?php

namespace App\Modules\Commercial\Actions;

use App\Modules\Commercial\Models\Cost;
use App\Modules\Commercial\Models\CostRelations;
use App\Modules\Commercial\Models\Services\Services;
use App\Modules\Commercial\Models\Terms\terms;
use Illuminate\Support\Facades\DB;

/** Preserve provider-owned links while allowing the same manual composition from web and API. */
class ServiceComposition
{
    public function costs(Services $service, array $ids): array
    {
        return DB::transaction(function () use ($service, $ids) {
            $service = Services::query()->with('sourceIntegration')->lockForUpdate()->findOrFail($service->id);
            abort_if($service->isIntegrationManaged(), 403, 'The active source Integration owns this Service.');
            $existing = $service->costRelations()->pluck('costId')->all();
            $managed = Cost::query()->whereIn('id', $existing)->integrationManaged()->pluck('id')->all();
            $newManaged = Cost::query()->whereIn('id', array_diff($ids, $existing))->integrationManaged()->exists();
            abort_if($newManaged, 403, 'Provider-owned cost links must be managed by synchronization.');
            $manual = Cost::query()->whereIn('id', $ids)->editableInNexum()->pluck('id')->all();
            $keep = array_values(array_unique(array_merge($managed, $manual)));
            CostRelations::query()->where('serviceId', $service->id)->whereNotIn('costId', $keep)->delete();
            foreach ($keep as $id) {
                CostRelations::query()->firstOrCreate(['serviceId' => $service->id, 'costId' => $id]);
            }

            return array_map('strval', $keep);
        });
    }

    public function terms(Services $service, array $ids): array
    {
        return DB::transaction(function () use ($service, $ids) {
            $service = Services::query()->with('sourceIntegration')->lockForUpdate()->findOrFail($service->id);
            abort_if($service->isIntegrationManaged(), 403, 'The active source Integration owns this Service.');
            $provider = $service->serviceTerms()->where(fn ($q) => $q->where('origin', 'provider')->orWhere('managed_externally', true))->pluck('terms.id')->all();
            abort_if(terms::query()->whereIn('id', array_diff($ids, $provider))->where(fn ($q) => $q->where('origin', 'provider')->orWhere('managed_externally', true))->exists(), 403, 'Provider legal links must be managed by synchronization.');
            $keep = array_values(array_unique(array_merge($ids, $provider)));
            $service->serviceTerms()->sync($keep);

            return array_map('strval', $keep);
        });
    }

    public function preview(array $costIds, float $sale): array
    {
        $total = (float) Cost::query()->whereIn('id', $costIds)->sum('cost');
        $suggested = $total * 2;
        if ($sale == 0) {
            $sale = $suggested;
        }

        return ['cost' => $total, 'suggested_sales_price' => $suggested, 'sales_price' => $sale, 'margin' => $sale - $total];
    }
}
