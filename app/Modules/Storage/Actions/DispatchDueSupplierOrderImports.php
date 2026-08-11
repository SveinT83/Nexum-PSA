<?php

namespace App\Modules\Storage\Actions;

use App\Modules\Storage\Jobs\ProcessScheduledSupplierOrderImport;
use App\Modules\Storage\Models\PurchaseOrderAutomationPolicy;
use App\Modules\Storage\Models\PurchaseOrderImport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DispatchDueSupplierOrderImports
{
    private const PENDING_GRACE_MINUTES = 5;

    public function __construct(
        private readonly UpdateSupplierOrderImportOperationalState $operationalState,
        private readonly PublishSupplierOrderImportOperationalAlert $alerts,
    ) {}

    /**
     * Claim due imports before queueing so overlapping scheduler invocations are idempotent.
     */
    public function handle(int $limit = 100, ?CarbonImmutable $at = null): int
    {
        $at ??= CarbonImmutable::now();
        $limit = max(1, min(500, $limit));
        $this->operationalState->handle([
            'scheduler_heartbeat_at' => $at,
            'last_dispatch_started_at' => $at,
        ]);

        $policy = PurchaseOrderAutomationPolicy::query()->where('is_current', true)->first();
        if (! $policy || $policy->runtime_mode === PurchaseOrderAutomationPolicy::MODE_OFF) {
            $this->alerts->resolveMissing(['queue_dispatch_failed'], []);
            $this->completeState($at, 0);

            return 0;
        }

        $candidateIds = PurchaseOrderImport::query()
            ->where(function ($query) use ($at): void {
                $query->where(function ($pending) use ($at): void {
                    $pending->where('status', PurchaseOrderImport::STATUS_PENDING)
                        ->where('created_at', '<=', $at->subMinutes(self::PENDING_GRACE_MINUTES));
                })->orWhere(function ($retry) use ($at): void {
                    $retry->where('status', PurchaseOrderImport::STATUS_RETRY_SCHEDULED)
                        ->where('next_retry_at', '<=', $at);
                });
            })
            ->orderByRaw('COALESCE(next_retry_at, created_at) ASC')
            ->limit($limit)
            ->pluck('id');

        $dispatched = 0;
        $failedIdentities = [];
        foreach ($candidateIds as $importId) {
            $claim = $this->claim((int) $importId, $at);
            if (! $claim) {
                continue;
            }

            try {
                ProcessScheduledSupplierOrderImport::dispatch($claim['import_id'], $claim['token']);
                DB::table('storage_purchase_order_import_dispatches')
                    ->where('import_id', $claim['import_id'])
                    ->where('claim_token', $claim['token'])
                    ->where('status', 'claimed')
                    ->update([
                        'status' => 'dispatched',
                        'dispatched_at' => $at,
                        'updated_at' => $at,
                    ]);
                $dispatched++;
            } catch (Throwable $exception) {
                if (! $this->releaseFailedClaim($claim, $at, $exception)) {
                    $dispatched++;

                    continue;
                }
                $identity = 'queue_dispatch_failed:'.$claim['import_id'];
                $failedIdentities[] = $identity;
                $this->alerts->handle([
                    'identity' => $identity,
                    'type' => 'queue_dispatch_failed',
                    'severity' => 'critical',
                    'import_id' => $claim['import_id'],
                    'reason_code' => 'queue_dispatch_failed',
                    'title' => 'Supplier-order import could not be queued',
                    'summary' => 'A durable import remains pending after its queue dispatch failed.',
                    'context' => [
                        'import_id' => $claim['import_id'],
                        'exception_class' => $exception::class,
                    ],
                ]);
            }
        }

        $this->alerts->resolveMissing(['queue_dispatch_failed'], $failedIdentities);
        $this->completeState($at, $dispatched);

        return $dispatched;
    }

    /** @return array{import_id: int, token: string, previous_status: string}|null */
    private function claim(int $importId, CarbonImmutable $at): ?array
    {
        return DB::transaction(function () use ($importId, $at): ?array {
            $import = PurchaseOrderImport::query()->lockForUpdate()->find($importId);
            if (! $import || ! $this->isDue($import, $at)) {
                return null;
            }

            $token = (string) Str::uuid();
            $previousStatus = $import->status;
            $dispatch = DB::table('storage_purchase_order_import_dispatches')
                ->where('import_id', $import->id)
                ->lockForUpdate()
                ->first();
            $dispatchCount = ((int) ($dispatch?->dispatch_count ?? 0)) + 1;
            DB::table('storage_purchase_order_import_dispatches')->updateOrInsert(
                ['import_id' => $import->id],
                [
                    'claim_token' => $token,
                    'dispatch_count' => $dispatchCount,
                    'previous_status' => $previousStatus,
                    'status' => 'claimed',
                    'claimed_at' => $at,
                    'dispatched_at' => null,
                    'worker_started_at' => null,
                    'worker_completed_at' => null,
                    'last_outcome' => null,
                    'created_at' => $dispatch?->created_at ?? $at,
                    'updated_at' => $at,
                ],
            );

            $import->forceFill([
                'status' => PurchaseOrderImport::STATUS_PROCESSING,
                'reason_code' => 'scheduled_dispatch_claimed',
                'reason_context' => [
                    'previous_status' => $previousStatus,
                    'claim_token' => $token,
                ],
                'locked_at' => $at,
                'next_retry_at' => null,
            ])->save();

            return ['import_id' => $import->id, 'token' => $token, 'previous_status' => $previousStatus];
        });
    }

    private function isDue(PurchaseOrderImport $import, CarbonImmutable $at): bool
    {
        if ($import->status === PurchaseOrderImport::STATUS_PENDING) {
            return $import->created_at?->lte($at->subMinutes(self::PENDING_GRACE_MINUTES)) ?? false;
        }

        return $import->status === PurchaseOrderImport::STATUS_RETRY_SCHEDULED
            && $import->next_retry_at?->lte($at);
    }

    /** @param array{import_id: int, token: string, previous_status: string} $claim */
    private function releaseFailedClaim(array $claim, CarbonImmutable $at, Throwable $exception): bool
    {
        return DB::transaction(function () use ($claim, $at, $exception): bool {
            // Match claim() lock order so a late transport failure cannot deadlock a newer claim.
            $import = PurchaseOrderImport::query()->lockForUpdate()->find($claim['import_id']);
            $dispatch = DB::table('storage_purchase_order_import_dispatches')
                ->where('import_id', $claim['import_id'])
                ->where('claim_token', $claim['token'])
                ->where('status', 'claimed')
                ->lockForUpdate()
                ->first();
            if (! $dispatch || ! $import) {
                return false;
            }
            if ($import->status !== PurchaseOrderImport::STATUS_PROCESSING
                || $import->reason_code !== 'scheduled_dispatch_claimed'
                || data_get($import->reason_context, 'claim_token') !== $claim['token']) {
                return false;
            }

            $released = DB::table('storage_purchase_order_import_dispatches')
                ->where('id', $dispatch->id)
                ->where('claim_token', $claim['token'])
                ->where('status', 'claimed')
                ->update([
                    'status' => 'dispatch_failed',
                    'last_outcome' => $exception::class,
                    'worker_completed_at' => $at,
                    'updated_at' => $at,
                ]);
            if ($released !== 1) {
                return false;
            }

            $isRetry = $claim['previous_status'] === PurchaseOrderImport::STATUS_RETRY_SCHEDULED;
            $import->forceFill([
                'status' => $claim['previous_status'],
                'reason_code' => 'queue_dispatch_failed',
                'reason_context' => [
                    'exception_class' => $exception::class,
                    'failed_claim_token' => $claim['token'],
                ],
                'next_retry_at' => $isRetry ? $at->addMinute() : null,
                'locked_at' => null,
            ])->save();

            return true;
        });
    }

    private function completeState(CarbonImmutable $at, int $dispatched): void
    {
        $this->operationalState->handle([
            'last_dispatch_completed_at' => $at,
            'last_dispatched_import_count' => $dispatched,
        ]);
    }
}
