<?php

namespace App\Modules\Integration\Actions;

use App\Models\Tech\Work\Assets\Asset;
use App\Models\Tech\Work\Assets\AssetAlert;
use App\Modules\Integration\Models\RmmAlertOccurrence;
use App\Modules\Integration\Support\RmmAlertSeverity;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class RecordRmmAlertObservation
{
    public function __construct(private readonly ProcessRmmAlertRules $rules) {}

    /**
     * Persist one normalized provider observation and route only new/reopened occurrences.
     *
     * @param  array<string, mixed>  $observation
     */
    public function handle(Asset $asset, array $observation): ?AssetAlert
    {
        $fingerprint = trim((string) ($observation['fingerprint'] ?? ''));
        if ($fingerprint === '') {
            throw new \InvalidArgumentException('RMM alert fingerprint is required.');
        }

        $active = (bool) ($observation['active'] ?? false);
        $severity = RmmAlertSeverity::normalize($observation['severity'] ?? null);
        $context = $this->safeContext((array) ($observation['provider_context'] ?? []));

        /** @var array{alert: AssetAlert|null, occurrence: RmmAlertOccurrence|null} $result */
        $result = DB::transaction(function () use ($asset, $observation, $fingerprint, $active, $severity, $context): array {
            $alert = AssetAlert::query()->where('fingerprint', $fingerprint)->lockForUpdate()->first();
            $occurrence = null;

            if (! $alert && ! $active) {
                return ['alert' => null, 'occurrence' => null];
            }

            if (! $alert) {
                $alert = AssetAlert::query()->createOrFirst(['fingerprint' => $fingerprint], [
                    'asset_id' => $asset->id,
                    'integration_type' => (string) $observation['integration_type'],
                    'external_check_id' => $observation['external_check_id'] ?? null,
                    'external_alert_id' => $observation['external_alert_id'] ?? null,
                    'title' => Str::limit(trim((string) ($observation['title'] ?? 'RMM check failing')), 255, ''),
                    'message' => trim((string) ($observation['message'] ?? '')),
                    'status' => 'active',
                    'severity' => $severity,
                    'provider_context' => $context,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);

                if ($alert->wasRecentlyCreated) {
                    $occurrence = $this->createOccurrence($alert, 1, 'triggered');
                } else {
                    $alert = AssetAlert::query()->whereKey($alert->id)->lockForUpdate()->firstOrFail();
                    $occurrence = $this->refreshActiveLocked($alert, $asset, $observation, $severity, $context);
                }
            } elseif ($active) {
                $occurrence = $this->refreshActiveLocked($alert, $asset, $observation, $severity, $context);
            } else {
                $this->assertSameIdentity($alert, $asset, $observation);
                $this->resolveLocked($alert);
            }

            return ['alert' => $alert->fresh(), 'occurrence' => $occurrence];
        });

        if ($result['occurrence']) {
            $this->processSafely($result['occurrence']);
        }

        return $result['alert']?->fresh();
    }

    public function resolve(AssetAlert $alert): AssetAlert
    {
        return DB::transaction(function () use ($alert): AssetAlert {
            $locked = AssetAlert::query()->lockForUpdate()->findOrFail($alert->id);
            $this->resolveLocked($locked);

            return $locked->fresh();
        });
    }

    /** @param array<string, mixed> $observation @param array<string, mixed> $context */
    private function refreshActiveLocked(
        AssetAlert $alert,
        Asset $asset,
        array $observation,
        string $severity,
        array $context,
    ): ?RmmAlertOccurrence {
        $this->assertSameIdentity($alert, $asset, $observation);
        $this->terminalizeInterruptedProcessing($alert);
        $wasResolved = $alert->status === 'resolved';
        $alert->forceFill([
            'external_check_id' => $observation['external_check_id'] ?? $alert->external_check_id,
            'external_alert_id' => $observation['external_alert_id'] ?? $alert->external_alert_id,
            'title' => Str::limit(trim((string) ($observation['title'] ?? $alert->title)), 255, ''),
            'message' => trim((string) ($observation['message'] ?? $alert->message)),
            'status' => 'active',
            'severity' => $severity,
            'provider_context' => $context,
            'resolved_at' => null,
            'last_seen_at' => now(),
        ])->save();

        if ($wasResolved) {
            $nextSequence = ((int) $alert->occurrences()->max('sequence')) + 1;

            return $this->createOccurrence($alert, $nextSequence, 'reopened');
        }

        // Only a structural failure before the first immutable execution may retry.
        return $alert->occurrences()
            ->whereDoesntHave('executions')
            ->whereNull('processed_at')
            ->where(function ($query): void {
                $query->where('processing_status', 'pending')
                    ->orWhere('processing_status', 'failed')
                    ->orWhere(function ($stale): void {
                        $stale->where('processing_status', 'processing')
                            ->where('processing_started_at', '<=', now()->subMinutes(15));
                    });
            })
            ->latest('sequence')
            ->first();
    }

    /** @param array<string, mixed> $observation */
    private function assertSameIdentity(AssetAlert $alert, Asset $asset, array $observation): void
    {
        if ((int) $alert->asset_id !== (int) $asset->id
            || $alert->integration_type !== (string) ($observation['integration_type'] ?? '')) {
            throw new \RuntimeException('An RMM fingerprint was observed for a different provider or Asset.');
        }
    }

    private function terminalizeInterruptedProcessing(
        AssetAlert $alert,
        bool $settleWithoutExecution = false,
    ): void {
        $query = $alert->occurrences()
            ->whereNull('processed_at')
            ->latest('sequence');
        if ($settleWithoutExecution) {
            $query->where(function ($unfinished): void {
                $unfinished->whereIn('processing_status', ['pending', 'failed'])
                    ->orWhere(function ($stale): void {
                        $stale->where('processing_status', 'processing')
                            ->where('processing_started_at', '<=', now()->subMinutes(15));
                    });
            });
        } else {
            $query->where('processing_status', 'processing')
                ->where('processing_started_at', '<=', now()->subMinutes(15))
                ->whereHas('executions');
        }

        $occurrence = $query->lockForUpdate()->first();
        if (! $occurrence) {
            return;
        }

        $executions = $occurrence->executions()->lockForUpdate()->get();
        if ($executions->isEmpty() && ! $settleWithoutExecution) {
            return;
        }

        $now = now();
        $evaluating = $executions->where('status', 'evaluating');
        $error = $executions->isEmpty()
            ? 'RMM alert resolved after pre-routing processing was interrupted; no action was executed.'
            : 'RMM processing was interrupted before occurrence completion after an execution started; automatic replay is disabled.';

        if ($evaluating->isNotEmpty()) {
            $occurrence->executions()
                ->whereKey($evaluating->modelKeys())
                ->where('status', 'evaluating')
                ->update([
                    'status' => 'failed',
                    'error' => $error,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);
        }

        $occurrence->forceFill([
            'processing_status' => $executions->isEmpty()
                ? 'failed'
                : 'completed_with_failures',
            'processing_started_at' => null,
            'processing_token' => null,
            'processed_at' => $now,
            'processing_error' => $error,
        ])->save();
    }

    private function resolveLocked(AssetAlert $alert): void
    {
        // A resolved provider state must not leave an abandoned routing lease forever.
        // Fresh workers are allowed to finish; only stale leases are settled here.
        $this->terminalizeInterruptedProcessing($alert, true);

        if ($alert->status !== 'active') {
            return;
        }

        $resolvedAt = now();
        $alert->forceFill([
            'status' => 'resolved',
            'resolved_at' => $resolvedAt,
            'last_seen_at' => $resolvedAt,
        ])->save();
        $alert->occurrences()
            ->whereNull('resolved_at')
            ->latest('sequence')
            ->limit(1)
            ->update(['resolved_at' => $resolvedAt, 'updated_at' => $resolvedAt]);
    }

    private function createOccurrence(AssetAlert $alert, int $sequence, string $eventType): RmmAlertOccurrence
    {
        $alert->loadMissing(['asset.client', 'asset.site']);
        $asset = $alert->asset;

        return $alert->occurrences()->create([
            'sequence' => $sequence,
            'event_type' => $eventType,
            'integration_type' => $alert->integration_type,
            'fingerprint' => $alert->fingerprint,
            'severity' => $alert->severity,
            'title' => $alert->title,
            'context' => [
                'provider' => $alert->provider_context ?? [],
                'asset_id' => $asset?->id,
                'asset_name' => Str::limit((string) ($asset?->hostname ?: $asset?->name), 255, ''),
                'client_id' => $asset?->client_id,
                'client_name' => Str::limit((string) $asset?->client?->name, 255, ''),
                'site_id' => $asset?->site_id,
                'site_name' => Str::limit((string) $asset?->site?->name, 255, ''),
            ],
            'occurred_at' => now(),
            'processing_status' => 'pending',
        ]);
    }

    /** @param array<string, mixed> $context */
    private function safeContext(array $context): array
    {
        return collect(Arr::only($context, [
            'check_type',
            'raw_severity',
            'provider_status',
            'return_code',
            'failure_count',
            'failures_before_alert',
        ]))->map(function (mixed $value): mixed {
            return is_string($value) ? Str::limit($value, 255, '') : $value;
        })->filter(fn (mixed $value): bool => $value !== null && $value !== '')->all();
    }

    private function processSafely(RmmAlertOccurrence $occurrence): void
    {
        try {
            $this->rules->handle($occurrence);
        } catch (Throwable $exception) {
            Log::error('RMM Alert Rules processing failed.', [
                'occurrence_id' => $occurrence->id,
                'asset_alert_id' => $occurrence->asset_alert_id,
                'exception' => $exception::class,
            ]);
        }
    }
}
