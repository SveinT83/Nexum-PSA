<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Models\PurchaseOrderImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class RecoverStaleSupplierOrderImports
{
    /**
     * Release processing locks only after the bounded worker timeout has been exceeded safely.
     *
     * @return list<array{import_id: int, stage: string, stale_locked_at: ?string}>
     */
    public function handle(int $staleMinutes = 15, ?CarbonImmutable $at = null): array
    {
        $at ??= CarbonImmutable::now();
        $staleMinutes = max(5, min(1440, $staleMinutes));
        $ids = PurchaseOrderImport::query()
            ->where('status', PurchaseOrderImport::STATUS_PROCESSING)
            ->where(function ($query) use ($at, $staleMinutes): void {
                $cutoff = $at->subMinutes($staleMinutes);
                $query->where('locked_at', '<=', $cutoff)
                    ->orWhere(function ($missingLock) use ($cutoff): void {
                        $missingLock->whereNull('locked_at')->where('updated_at', '<=', $cutoff);
                    });
            })
            ->orderBy('id')
            ->pluck('id');
        $recovered = [];

        foreach ($ids as $id) {
            $result = DB::transaction(function () use ($id, $at, $staleMinutes): ?array {
                $import = PurchaseOrderImport::query()->lockForUpdate()->find($id);
                $lockReference = $import?->locked_at ?? $import?->updated_at;
                if (! $import
                    || $import->status !== PurchaseOrderImport::STATUS_PROCESSING
                    || ! $lockReference
                    || $lockReference->gt($at->subMinutes($staleMinutes))) {
                    return null;
                }

                $facts = [
                    'import_id' => $import->id,
                    'stage' => $import->stage,
                    'stale_locked_at' => $import->locked_at?->toIso8601String(),
                ];
                $import->forceFill([
                    'status' => PurchaseOrderImport::STATUS_RETRY_SCHEDULED,
                    'reason_code' => 'stale_processing_recovered',
                    'reason_context' => [
                        'recovered_from_stage' => $import->stage,
                        'stale_locked_at' => $facts['stale_locked_at'],
                    ],
                    'next_retry_at' => $at,
                    'locked_at' => null,
                    'processed_at' => $at,
                ])->save();

                return $facts;
            });
            if ($result) {
                $recovered[] = $result;
            }
        }

        return $recovered;
    }
}
